<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\HostingAiGatewayTokenAuthenticator;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Hosting\AiGwKeyStore;
use Shipard\Core\Hosting\Exception\AiGwKeyInsecureException;
use Shipard\Core\Hosting\Exception\AiGwKeyMissingException;
use Shipard\Core\Hosting\GwUsageExtractor;
use Shipard\Core\Logging\ErrorLogger;

/**
 * AI gateway (D5) — reverse proxy POST /_hosting/ai-gw/v1/messages.
 *
 * Ověří gateway token (`x-api-key`, shpd_gw_), nahradí ho org klíčem ze
 * secrets/ai-gw-anthropic.key a přeposílá 1:1 na api.anthropic.com včetně
 * SSE; z odpovědi paralelně těží usage (GwUsageExtractor) do
 * hosting_core_ai_usage. Selhání meteringu nikdy neshodí odpověď.
 *
 * Streamování: Response::stream() emituje headery s placeholder statusem,
 * ale PHP je odešle až s prvním bytem outputu — emitResponseMeta() uvnitř
 * produceru (z hlaviček upstreamu, před prvním echo) je bezpečně přepíše.
 * Vědomý limit v1: streamované spojení drží PHP-FPM worker (docs/hosting.md
 * §5.5).
 *
 * Forward headers jsou allowlist (content-type, anthropic-version,
 * anthropic-beta) — authorization/cookies/klientský x-api-key se
 * nepropustí z konstrukce; accept-encoding záměrně ne (identity odpověď,
 * aby tee parsoval plaintext a echo bylo byte-exact).
 */
class HostingAiGatewayController
{
    private const string UPSTREAM_URL = 'https://api.anthropic.com/v1/messages';
    private const array FORWARD_HEADERS = ['content-type', 'anthropic-version', 'anthropic-beta'];
    private const int UPSTREAM_TIMEOUT_S = 600;
    private const int UPSTREAM_CONNECT_TIMEOUT_S = 10;

    public function __construct(
        private readonly DataSourceConfig $config,
    ) {}

    /**
     * @param array<string, mixed> $tables
     */
    public function messages(Request $request, DataSourceConnection $db, array $tables): Response
    {
        // Gating: bez hosting tabulek nebo org klíče vypadá endpoint jako
        // neexistující (hosting.core neaktivní / gateway nezřízená).
        if (!isset($tables['hosting_core_ai_tokens'])
            || !isset($tables['hosting_core_ai_usage'])
            || !isset($tables['hosting_core_data_sources'])
            || !$this->orgKeyAvailable()
        ) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $auth = (new HostingAiGatewayTokenAuthenticator())->authenticate($request, $db);
        if ($auth instanceof Response) {
            return $auth;
        }
        $dsNdx = (int) $auth['data_source'];

        try {
            $orgKey = $this->readOrgKey();
        } catch (AiGwKeyMissingException) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        } catch (AiGwKeyInsecureException $e) {
            ErrorLogger::warn('ai-gw: org key insecure', ['error' => $e->getMessage()]);
            return HostingAiGatewayTokenAuthenticator::anthropicError(
                'api_error', 'AI gateway is misconfigured', 500,
            );
        }

        $body = $this->rawRequestBody();
        $forwardHeaders = $this->buildForwardHeaders($request, $orgKey);

        return Response::stream(function () use ($db, $dsNdx, $forwardHeaders, $body): void {
            set_time_limit(0);
            // Bez ignore_user_abort by PHP po disconnectu klienta zabilo
            // skript na prvním echo+flush a usage řádek by se nezapsal.
            ignore_user_abort(true);

            $extractor = new GwUsageExtractor();
            $meteringDead = false;
            $headersEmitted = false;
            $t0 = microtime(true);

            $result = $this->forwardToUpstream(
                self::UPSTREAM_URL,
                $forwardHeaders,
                $body,
                function (int $status, string $contentType) use (&$headersEmitted, $extractor): void {
                    $this->emitResponseMeta($status, $contentType);
                    $headersEmitted = true;
                    $extractor->begin($contentType);
                },
                function (string $chunk) use ($extractor, &$meteringDead): bool {
                    echo $chunk;
                    @flush();
                    if (!$meteringDead) {
                        try {
                            $extractor->feed($chunk);
                        } catch (\Throwable $e) {
                            $meteringDead = true;
                            ErrorLogger::warn('ai-gw: usage extractor failed', ['error' => $e->getMessage()]);
                        }
                    }
                    return !$this->clientAborted();
                },
            );

            $httpStatus = (int) $result['status'];
            if ($httpStatus === 0) {
                // Transport selhal dřív, než dorazily hlavičky odpovědi.
                if (!$headersEmitted) {
                    $this->emitResponseMeta(502, 'application/json');
                    echo json_encode([
                        'type' => 'error',
                        'error' => ['type' => 'api_error', 'message' => 'Upstream connection failed'],
                    ]);
                }
                $httpStatus = 502;
                ErrorLogger::warn('ai-gw: upstream transport error', [
                    'errno' => $result['errno'],
                    'error' => $result['error'],
                ]);
            }

            $this->recordUsage(
                $db,
                $dsNdx,
                $extractor->finish(),
                $httpStatus,
                (int) round((microtime(true) - $t0) * 1000),
            );
        }, 200, 'application/json');
    }

    /**
     * @return list<string>
     */
    private function buildForwardHeaders(Request $request, string $orgKey): array
    {
        $headers = [];
        foreach (self::FORWARD_HEADERS as $name) {
            $value = $request->getHeader($name);
            if ($value !== null && $value !== '') {
                $headers[] = $name . ': ' . $value;
            }
        }
        $headers[] = 'x-api-key: ' . $orgKey;
        // Potlačit curl default `Expect: 100-continue` u těl > 1 KB —
        // zdržuje a vkládá interim 1xx hlavičkový blok.
        $headers[] = 'Expect:';

        return $headers;
    }

    /**
     * INSERT usage řádku — vždy, i pro chybové/aborted průchody; selhání
     * meteringu nesmí shodit odpověď klientovi.
     *
     * @param array{stream: bool, model: ?string, input_tokens: int,
     *              output_tokens: int, cache_creation_tokens: int,
     *              cache_read_tokens: int} $usage
     */
    private function recordUsage(
        DataSourceConnection $db,
        int $dsNdx,
        array $usage,
        int $httpStatus,
        int $durationMs,
    ): void {
        try {
            $db->insertRow('hosting_core_ai_usage', [
                'data_source'           => $dsNdx,
                'model'                 => (string) ($usage['model'] ?? ''),
                'input_tokens'          => $usage['input_tokens'],
                'output_tokens'         => $usage['output_tokens'],
                'cache_creation_tokens' => $usage['cache_creation_tokens'],
                'cache_read_tokens'     => $usage['cache_read_tokens'],
                'http_status'           => $httpStatus,
                'stream'                => $usage['stream'] ? 1 : 0,
                'duration_ms'           => $durationMs,
                'created'               => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            ErrorLogger::warn('ai-gw: usage insert failed', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Protected seams — overridable for tests
    // -------------------------------------------------------------------------

    protected function orgKeyAvailable(): bool
    {
        return AiGwKeyStore::exists($this->config->getDataSourceDir());
    }

    protected function readOrgKey(): string
    {
        return AiGwKeyStore::read($this->config);
    }

    protected function rawRequestBody(): string
    {
        // Raw byty — nikdy re-encode z Request::getBody() (php://input je
        // od PHP 5.6 znovu čitelný).
        return (string) file_get_contents('php://input');
    }

    /**
     * Propagace upstream statusu + content-type. Response::stream() poslal
     * placeholder headery, ale do prvního bytu outputu je PHP neodeslalo —
     * tady se bezpečně přepíšou.
     */
    protected function emitResponseMeta(int $status, string $contentType): void
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
    }

    protected function clientAborted(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * Streamovaný POST na upstream — jediné curl místo. $onHeaders se volá
     * právě jednou, jakmile jsou kompletní hlavičky finální (ne 1xx)
     * odpovědi — před prvním body chunkem, u odpovědi bez těla po
     * curl_exec. $onChunk vrací false pro přerušení přenosu (klient se
     * odpojil). Status 0 = žádné hlavičky nedorazily (transport failure).
     *
     * @param list<string> $headers
     * @param callable(int $status, string $contentType): void $onHeaders
     * @param callable(string $chunk): bool $onChunk
     * @return array{status: int, errno: int, error: string}
     */
    protected function forwardToUpstream(
        string $url,
        array $headers,
        string $body,
        callable $onHeaders,
        callable $onChunk,
    ): array {
        $status = 0;
        $contentType = '';
        $headersFired = false;

        $fireHeaders = function () use (&$status, &$contentType, &$headersFired, $onHeaders): void {
            if (!$headersFired && $status >= 200) {
                $headersFired = true;
                $onHeaders($status, $contentType !== '' ? $contentType : 'application/json');
            }
        };

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => self::UPSTREAM_TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => self::UPSTREAM_CONNECT_TIMEOUT_S,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status, &$contentType): int {
                // Status line resetuje capture — 1xx interim bloky (Expect
                // fallback, upgrady) nesmí zamrznout starý content-type.
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    $contentType = '';
                } elseif (preg_match('#^content-type:\s*(.+)$#i', trim($line), $m)) {
                    $contentType = trim($m[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use ($fireHeaders, $onChunk): int {
                $fireHeaders();
                // Návrat != délka chunku → curl přeruší přenos
                // (CURLE_WRITE_ERROR) — přestat pálit upstream tokeny.
                return $onChunk($chunk) ? strlen($chunk) : -1;
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // Odpověď bez těla (hlavičky dorazily, WRITEFUNCTION se nespustil).
        $fireHeaders();

        return ['status' => $status, 'errno' => $errno, 'error' => $error];
    }
}
