<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\HostingConfig;
use Shipard\Core\Server\HostingSyncRunner;
use Shipard\Core\Version;

class StubHostingSyncRunner extends HostingSyncRunner
{
    /** @var list<array{method: string, url: string, body: ?array}> */
    public array $httpCalls = [];
    /** @var list<array{argv: list<string>, cwd: ?string}> */
    public array $processCalls = [];
    /** @var array<string, array{statusCode: int, body: string, error: ?string}> URL substring → odpověď */
    public array $httpResponses = [];
    public ?\Closure $onProcess = null;

    protected function performHttpRequest(string $method, string $url, ?array $body): array
    {
        $this->httpCalls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        foreach ($this->httpResponses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }
        return [
            'statusCode' => 200,
            'body' => (string) json_encode(['success' => true, 'data' => ['ok' => true]]),
            'error' => null,
        ];
    }

    protected function runProcess(array $argv, ?string $cwd): array
    {
        $this->processCalls[] = ['argv' => $argv, 'cwd' => $cwd];
        return $this->onProcess !== null
            ? ($this->onProcess)($argv, $cwd)
            : ['exitCode' => 0, 'output' => ''];
    }
}

class HostingSyncRunnerTest extends TestCase
{
    private const EXISTING_DS = 'aaaa-aaaa-aaaa-aaaa';
    private const NEW_DS = 'bbbb-bbbb-bbbb-bbbb';
    private const ISSUER = 'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/oidc';

    private string $dataSourcesDir;

    protected function setUp(): void
    {
        $this->dataSourcesDir = sys_get_temp_dir() . '/shpd_hostingsync_test_' . uniqid();
        mkdir($this->dataSourcesDir, 0755, true);
        $this->createDs(self::EXISTING_DS, 'Existing DS', ['install.base', 'core.mail']);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->dataSourcesDir);
    }

    private function createDs(string $dsId, string $name, array $modules): void
    {
        mkdir($this->dataSourcesDir . '/' . $dsId . '/config', 0755, true);
        file_put_contents(
            $this->dataSourcesDir . '/' . $dsId . '/config/main.json',
            (string) json_encode([
                'id' => $dsId,
                'name' => $name,
                'modules' => $modules,
                'database_name' => 'db',
                'database_user' => 'user',
                'database_password' => 'pw',
            ], JSON_PRETTY_PRINT),
        );
    }

    private function makeRunner(string $url = 'http://127.0.0.1/gggg-gggg-gggg-gggg'): StubHostingSyncRunner
    {
        return new StubHostingSyncRunner(
            new HostingConfig($url, 1, 'shpd_hk_' . str_repeat('a', 43)),
            $this->dataSourcesDir,
            '/fake/bin/shpd-server',
            '/fake/bin/shpd-ds',
        );
    }

    /** @return array<string, mixed> */
    private function queueItem(array $overrides = []): array
    {
        return array_merge([
            'request_id' => 12,
            'ds_id' => self::NEW_DS,
            'name' => 'Nová firma',
            'install_module' => 'install.base',
            'web_id' => 'nova',
            'host' => 'nova.shpd.dev',
            'owner' => ['email' => 'owner@example.com', 'name' => 'Owner User', 'sub' => '7'],
            'oidc' => [
                'issuer' => self::ISSUER,
                'client_id' => self::NEW_DS,
                'client_secret' => 'plain-secret',
                'label' => 'Shipard',
            ],
        ], $overrides);
    }

    private function queueResponse(array $items): array
    {
        return [
            'statusCode' => 200,
            'body' => (string) json_encode(['success' => true, 'data' => ['requests' => $items]]),
            'error' => null,
        ];
    }

    public function testHappyPathProvisionsAndConfirmsOk(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);
        // ds-create simulace: založí adresář s main.json (jako reálný příkaz).
        $runner->onProcess = function (array $argv): array {
            if ($argv[1] === 'ds-create') {
                $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
            }
            return ['exitCode' => 0, 'output' => ''];
        };

        $this->assertTrue($runner->run());

        // Reconcile: inventura existujících DS + verze.
        $reconcile = $runner->httpCalls[0];
        $this->assertSame('POST', $reconcile['method']);
        $this->assertStringContainsString('/api/v1/_hosting/server/reconcile', $reconcile['url']);
        $this->assertSame(Version::VERSION, $reconcile['body']['version']);
        $this->assertSame(self::EXISTING_DS, $reconcile['body']['dataSources'][0]['ds_id']);
        $this->assertSame(['install.base', 'core.mail'], $reconcile['body']['dataSources'][0]['modules']);

        // Kroky v pořadí: ds-create → ds-upgrade → domain-add → user-create.
        $labels = array_map(static fn(array $c): string => $c['argv'][1], $runner->processCalls);
        $this->assertSame(['ds-create', 'ds-upgrade', 'domain-add', 'user-create'], $labels);

        [$dsCreate, $dsUpgrade, $domainAdd, $userCreate] = $runner->processCalls;
        $this->assertSame('/fake/bin/shpd-server', $dsCreate['argv'][0]);
        $this->assertContains('--ds-id', $dsCreate['argv']);
        $this->assertContains(self::NEW_DS, $dsCreate['argv']);
        $this->assertSame($this->dataSourcesDir . '/' . self::NEW_DS, $dsUpgrade['cwd']);
        $this->assertContains('nova.shpd.dev', $domainAdd['argv']);
        $this->assertContains('--if-not-exists', $userCreate['argv']);
        $this->assertContains('--identity-subject', $userCreate['argv']);
        $this->assertContains('7', $userCreate['argv']);
        $this->assertContains(HostingSyncRunner::PROVIDER_ID, $userCreate['argv']);

        // auth.providers v main.json nového DS, mode 0600, ostatní klíče netknuté.
        $mainFile = $this->dataSourcesDir . '/' . self::NEW_DS . '/config/main.json';
        $config = json_decode((string) file_get_contents($mainFile), true);
        $provider = $config['auth']['providers'][0];
        $this->assertSame('shipard-id', $provider['id']);
        $this->assertSame(self::ISSUER, $provider['issuer']);
        $this->assertSame(self::NEW_DS, $provider['clientId']);
        $this->assertSame('plain-secret', $provider['clientSecret']);
        $this->assertFalse($provider['autoLinkEmail']);
        $this->assertSame('db', $config['database_name']);
        $this->assertSame(0600, fileperms($mainFile) & 0777);

        // Confirm ok.
        $confirm = $runner->httpCalls[2];
        $this->assertStringContainsString('/confirm', $confirm['url']);
        $this->assertSame(['request_id' => 12, 'ds_id' => self::NEW_DS, 'status' => 'ok'], $confirm['body']);
    }

    public function testExistingDsDirectorySkipsDsCreate(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([$this->queueItem()]);

        $this->assertTrue($runner->run());

        $labels = array_map(static fn(array $c): string => $c['argv'][1], $runner->processCalls);
        $this->assertSame(['ds-upgrade', 'domain-add', 'user-create'], $labels);
        $this->assertSame('ok', $runner->httpCalls[2]['body']['status']);
    }

    public function testStepFailureConfirmsFailedAndContinuesWithNextRequest(): void
    {
        $this->createDs(self::NEW_DS, 'Nová firma', ['install.base']);
        $this->createDs('cccc-cccc-cccc-cccc', 'Druhá firma', ['install.base']);
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            $this->queueItem(),
            $this->queueItem([
                'request_id' => 13,
                'ds_id' => 'cccc-cccc-cccc-cccc',
                'host' => 'druha.shpd.dev',
            ]),
        ]);
        $runner->onProcess = static function (array $argv, ?string $cwd): array {
            if ($argv[1] === 'domain-add' && in_array('nova.shpd.dev', $argv, true)) {
                return ['exitCode' => 1, 'output' => "Host 'nova.shpd.dev' is already mapped to data source 'zzzz-zzzz-zzzz-zzzz'"];
            }
            return ['exitCode' => 0, 'output' => ''];
        };

        $this->assertFalse($runner->run());

        // První požadavek: failed s výstižnou zprávou, user-create už neběžel.
        $confirms = array_values(array_filter(
            $runner->httpCalls,
            static fn(array $c): bool => str_contains($c['url'], '/confirm'),
        ));
        $this->assertCount(2, $confirms);
        $this->assertSame('failed', $confirms[0]['body']['status']);
        $this->assertStringContainsString('domain-add failed', $confirms[0]['body']['error']);
        $this->assertStringContainsString('already mapped', $confirms[0]['body']['error']);

        // Druhý požadavek doběhl ok.
        $this->assertSame('ok', $confirms[1]['body']['status']);
        $this->assertSame(13, $confirms[1]['body']['request_id']);
    }

    public function testDryRunUsesPeekAndDoesNothing(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            ['request_id' => 12, 'ds_id' => self::NEW_DS, 'name' => 'Nová firma', 'install_module' => 'install.base'],
        ]);

        $this->assertTrue($runner->run(dryRun: true));

        $this->assertStringContainsString('queue?peek=1', $runner->httpCalls[1]['url']);
        $this->assertSame([], $runner->processCalls);
        $this->assertCount(2, $runner->httpCalls); // jen reconcile + queue, žádný confirm
    }

    public function testReconcileFailureAbortsRun(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['reconcile'] = ['statusCode' => 401, 'body' => '', 'error' => null];

        $this->assertFalse($runner->run());
        $this->assertCount(1, $runner->httpCalls);
        $this->assertSame([], $runner->processCalls);
    }

    public function testInvalidDsIdInPayloadConfirmsFailedWithoutTouchingDisk(): void
    {
        $runner = $this->makeRunner();
        $runner->httpResponses['queue'] = $this->queueResponse([
            $this->queueItem(['ds_id' => '../../etc/passwd']),
        ]);

        $this->assertFalse($runner->run());

        $this->assertSame([], $runner->processCalls);
        $confirm = $runner->httpCalls[2];
        $this->assertSame('failed', $confirm['body']['status']);
        $this->assertStringContainsString('Invalid ds_id', $confirm['body']['error']);
    }

    public function testNonLocalhostHttpUrlIsRejected(): void
    {
        $runner = $this->makeRunner('http://portal.example.com');

        $this->assertFalse($runner->run());
        $this->assertSame([], $runner->httpCalls);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
