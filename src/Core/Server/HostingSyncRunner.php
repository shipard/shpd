<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Auth\OidcProviderConfig;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Core\Version;

/**
 * Jeden běh pull agenta hosting-sync (D3, docs/hosting.md §5.2):
 *
 *   1. Reconcile — POST inventura lokálních DS (id, name, modules) + verze.
 *   2. Queue — GET fronta požadavků; pro každý postupně (chyba jednoho
 *      nezastaví další): ds-create --ds-id → ds-upgrade → domain-add →
 *      merge auth.providers do main.json → user-create ownera
 *      s předpropojenou identitou → confirm ok/failed.
 *   3. Stats push (D7) — Fáze 5, zatím nic.
 *
 * Idempotence: existující adresář DS = ds-create se přeskočí, ostatní
 * kroky jsou idempotentní samy (ds-upgrade, domain-add no-op na stejný
 * host→DS, merge providera dle id, user-create --if-not-exists).
 *
 * HTTP jen https (http pro localhost dev — stejné pravidlo jako OIDC RP);
 * subprocesy přes argv pole (žádný shell). `performHttpRequest`
 * a `runProcess` jsou protected seams pro testy.
 */
class HostingSyncRunner
{
    public const PROVIDER_ID = 'shipard-id';

    private const HTTP_TIMEOUT_SECONDS = 10;
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;
    private const STEP_TIMEOUT_SECONDS = 600;

    /** Kolik posledních bajtů výstupu kroku se drží pro confirm failed. */
    private const OUTPUT_TAIL_BYTES = 4096;

    private \Closure $log;

    public function __construct(
        private readonly HostingConfig $hosting,
        private readonly string $dataSourcesDir,
        private readonly string $shpdServerPath,
        private readonly string $shpdDsPath,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $message): void {};
    }

    public function run(bool $dryRun = false): bool
    {
        if (!OidcProviderConfig::isAllowedIssuerUrl($this->hosting->url)) {
            $this->logLine('<error>hosting.url must be https (http only for localhost): ' . $this->hosting->url . '</error>');
            return false;
        }

        // 1. Reconcile — když neprojde, nemá smysl pokračovat (spojení
        // nefunguje) a hosting by neměl aktuální last_seen kontext.
        $inventory = $this->buildInventory();
        $this->logLine(sprintf('Reconcile: %d data source(s), version %s', count($inventory), Version::VERSION));
        $reconcile = $this->callHosting('POST', 'reconcile', [
            'version' => Version::VERSION,
            'dataSources' => $inventory,
        ]);
        if ($reconcile === null) {
            return false;
        }

        // 2. Queue — dry-run přes peek=1: hosting frontu nepřeklápí na
        // creating a payload neobsahuje client_secret.
        $queueAction = $dryRun ? 'queue?peek=1' : 'queue';
        $queue = $this->callHosting('GET', $queueAction, null);
        if ($queue === null) {
            return false;
        }
        $requests = is_array($queue['requests'] ?? null) ? $queue['requests'] : [];
        $this->logLine(sprintf('Queue: %d request(s)', count($requests)));

        if ($dryRun) {
            foreach ($requests as $item) {
                $this->logLine(sprintf(
                    '  [dry-run] #%d %s "%s" (%s)',
                    (int) ($item['request_id'] ?? 0),
                    (string) ($item['ds_id'] ?? '?'),
                    (string) ($item['name'] ?? ''),
                    (string) ($item['install_module'] ?? ''),
                ));
            }
            return true;
        }

        $allOk = true;
        foreach ($requests as $item) {
            if (!is_array($item)) {
                continue;
            }
            $requestId = (int) ($item['request_id'] ?? 0);
            $dsId = (string) ($item['ds_id'] ?? '');
            $this->logLine(sprintf('Provisioning #%d %s "%s"…', $requestId, $dsId, (string) ($item['name'] ?? '')));

            $error = $this->provisionRequest($item);

            $confirmBody = ['request_id' => $requestId, 'ds_id' => $dsId, 'status' => $error === null ? 'ok' : 'failed'];
            if ($error !== null) {
                $confirmBody['error'] = mb_substr($error, 0, self::OUTPUT_TAIL_BYTES);
                $this->logLine('<error>  failed: ' . $error . '</error>');
                $allOk = false;
            } else {
                $this->logLine('  done.');
            }
            if ($this->callHosting('POST', 'confirm', $confirmBody) === null) {
                $allOk = false;
            }
        }

        // 3. Stats push (D7) — Fáze 5: agregát per DS (počty z feedu/alertů).

        return $allOk;
    }

    // -------------------------------------------------------------------------
    // Provisioning jednoho požadavku
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $item
     * @return string|null chybová zpráva pro confirm failed, null = úspěch
     */
    private function provisionRequest(array $item): ?string
    {
        $dsId = (string) ($item['ds_id'] ?? '');
        $name = (string) ($item['name'] ?? '');
        $module = (string) ($item['install_module'] ?? '');
        $host = (string) ($item['host'] ?? '');
        $owner = is_array($item['owner'] ?? null) ? $item['owner'] : [];
        $oidc = is_array($item['oidc'] ?? null) ? $item['oidc'] : [];

        // Hodnoty jdou do cest a argv — validovat dřív, než se čehokoli dotknou.
        if (!preg_match(IdGenerator::ID_PATTERN, $dsId)) {
            return "Invalid ds_id in queue payload: '{$dsId}'";
        }
        if ($name === '' || $module === '') {
            return 'Queue payload is missing name or install_module';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
            return "Invalid host in queue payload: '{$host}'";
        }
        $ownerEmail = (string) ($owner['email'] ?? '');
        $ownerName = (string) ($owner['name'] ?? '');
        $ownerSub = (string) ($owner['sub'] ?? '');
        if ($ownerEmail === '' || $ownerName === '' || $ownerSub === '') {
            return 'Queue payload is missing owner email/name/sub';
        }
        $issuer = rtrim((string) ($oidc['issuer'] ?? ''), '/');
        $clientSecret = (string) ($oidc['client_secret'] ?? '');
        if ($issuer === '' || $clientSecret === '') {
            return 'Queue payload is missing oidc issuer/client_secret';
        }

        $dsDir = $this->dataSourcesDir . '/' . $dsId;

        // a. Založení DS — existující adresář = krok přeskočit (idempotence,
        //    dokončování po pádu agenta).
        if (!is_dir($dsDir)) {
            $error = $this->runStep(
                'ds-create',
                [$this->shpdServerPath, 'ds-create', '--ds-id', $dsId, '--name', $name, '--module', $module],
                null,
            );
            if ($error !== null) {
                return $error;
            }
        } else {
            $this->logLine('  ds-create skipped — directory exists.');
        }

        // b. Schéma + compiled config.
        $error = $this->runStep('ds-upgrade', [$this->shpdDsPath, 'ds-upgrade'], $dsDir);
        if ($error !== null) {
            return $error;
        }

        // c. Doména (idempotentní: stejný host → stejný DS = no-op).
        $error = $this->runStep(
            'domain-add',
            [$this->shpdServerPath, 'domain-add', '--host', $host, '--ds', $dsId],
            null,
        );
        if ($error !== null) {
            return $error;
        }

        // d. auth.providers — OP hostingu (U2: autoLinkEmail false, identita
        //    se předpropojuje krokem e).
        $error = $this->patchAuthProvider($dsDir, [
            'id' => self::PROVIDER_ID,
            'label' => (string) ($oidc['label'] ?? 'Shipard'),
            'issuer' => $issuer,
            'clientId' => (string) ($oidc['client_id'] ?? $dsId),
            'clientSecret' => $clientSecret,
            'autoLinkEmail' => false,
        ]);
        if ($error !== null) {
            return $error;
        }

        // e. Admin účet vlastníka bez hesla + předpropojená identita (U1+U2).
        return $this->runStep('user-create', [
            $this->shpdDsPath, 'user-create',
            '--login', $ownerEmail,
            '--email', $ownerEmail,
            '--name', $ownerName,
            '--admin',
            '--if-not-exists',
            '--identity-provider', self::PROVIDER_ID,
            '--identity-issuer', $issuer,
            '--identity-subject', $ownerSub,
        ], $dsDir);
    }

    /**
     * Merge providera do main.json — čistou transformaci dělá
     * MainConfigPatcher, tady jen read-modify-write (atomicky tmp + rename,
     * chmod 0600).
     *
     * @param array<string, mixed> $provider
     */
    private function patchAuthProvider(string $dsDir, array $provider): ?string
    {
        $file = $dsDir . '/config/main.json';
        $content = @file_get_contents($file);
        if ($content === false) {
            return "auth.providers: cannot read {$file}";
        }
        $config = json_decode($content, true);
        if (!is_array($config)) {
            return "auth.providers: invalid JSON in {$file}";
        }

        $config = MainConfigPatcher::mergeAuthProvider($config, $provider);

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return "auth.providers: cannot write {$tmp}";
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return "auth.providers: cannot replace {$file}";
        }
        $this->logLine('  auth.providers merged (' . self::PROVIDER_ID . ').');
        return null;
    }

    // -------------------------------------------------------------------------
    // Inventura
    // -------------------------------------------------------------------------

    /**
     * @return list<array{ds_id: string, name: string, modules: list<string>}>
     */
    private function buildInventory(): array
    {
        $dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $inventory = [];
        foreach ($dirs as $dir) {
            $file = $dir . '/config/main.json';
            if (!is_file($file)) {
                continue;
            }
            $config = json_decode((string) @file_get_contents($file), true);
            if (!is_array($config)) {
                continue;
            }
            $modules = is_array($config['modules'] ?? null) ? $config['modules'] : [];
            $inventory[] = [
                'ds_id' => (string) ($config['id'] ?? basename($dir)),
                'name' => (string) ($config['name'] ?? ''),
                'modules' => array_values(array_map(strval(...), $modules)),
            ];
        }
        return $inventory;
    }

    // -------------------------------------------------------------------------
    // HTTP + subprocesy
    // -------------------------------------------------------------------------

    /**
     * Zavolá hosting endpoint a vrátí `data` ze success envelope; chyba
     * (síť, ne-200, nevalidní JSON) → log + null.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function callHosting(string $method, string $action, ?array $body): ?array
    {
        $url = $this->hosting->url . '/api/v1/_hosting/server/' . $action;
        $result = $this->performHttpRequest($method, $url, $body);

        if ($result['error'] !== null || $result['statusCode'] !== 200) {
            $this->logLine(sprintf(
                '<error>Hosting call %s failed: HTTP %d%s</error>',
                $action,
                $result['statusCode'],
                $result['error'] !== null ? ' (' . $result['error'] . ')' : '',
            ));
            return null;
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            $this->logLine("<error>Hosting call {$action} returned unexpected payload</error>");
            return null;
        }
        $data = $decoded['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Jeden HTTP request — protected seam pro testy. Bearer = klíč serveru.
     *
     * @param array<string, mixed>|null $body JSON body (POST), null = bez těla
     * @return array{statusCode: int, body: string, error: ?string}
     */
    protected function performHttpRequest(string $method, string $url, ?array $body): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->hosting->apiKey,
        ];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'statusCode' => $statusCode,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'error' => $error,
        ];
    }

    /** Spustí krok a při neúspěchu vrátí zprávu pro confirm failed. */
    private function runStep(string $label, array $argv, ?string $cwd): ?string
    {
        $this->logLine('  ' . $label . '…');
        $result = $this->runProcess($argv, $cwd);
        if ($result['exitCode'] === 0) {
            return null;
        }
        $tail = trim($result['output']);
        return sprintf('%s failed (exit %d)%s', $label, $result['exitCode'], $tail !== '' ? ': ' . $tail : '');
    }

    /**
     * Subprocess přes argv pole (žádný shell) — protected seam pro testy.
     * Timeout: SIGTERM, 5 s grace, pak SIGKILL (vzor CronCommand::runJob).
     *
     * @param list<string> $argv
     * @return array{exitCode: int, output: string}
     */
    protected function runProcess(array $argv, ?string $cwd): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['exitCode' => -1, 'output' => 'proc_open failed'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + self::STEP_TIMEOUT_SECONDS;
        $output = '';
        $exitCode = -1;

        $drain = function () use (&$output, $pipes): void {
            foreach ([1, 2] as $i) {
                $chunk = @stream_get_contents($pipes[$i]);
                if (is_string($chunk) && $chunk !== '') {
                    $output = substr($output . $chunk, -self::OUTPUT_TAIL_BYTES);
                }
            }
        };

        while (true) {
            $drain();
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 15); // SIGTERM
                $grace = microtime(true) + 5.0;
                while (microtime(true) < $grace) {
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                }
                if ($status['running']) {
                    proc_terminate($process, 9); // SIGKILL
                }
                $output = substr($output . "\n[timeout]", -self::OUTPUT_TAIL_BYTES);
                break;
            }
            usleep(100_000);
        }

        $drain();
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($process);

        return ['exitCode' => $exitCode, 'output' => $output];
    }

    private function logLine(string $message): void
    {
        ($this->log)($message);
    }
}
