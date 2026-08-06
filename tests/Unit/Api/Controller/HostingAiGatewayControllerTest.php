<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\HostingAiGatewayController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Tests\Fixtures\Module\Hosting\InMemoryHostingAiGatewayDb;

/**
 * Testovací subclass — přepisuje všechny protected seamy: upstream curl
 * (fixture replay), org klíč, raw body, emisi status/content-type
 * a detekci odpojení klienta.
 */
class StubHostingAiGatewayController extends HostingAiGatewayController
{
    public bool $orgKeyExists = true;
    public string $orgKey = 'sk-ant-org-key';
    public string $rawBody = '{"model":"claude-sonnet-4-5","messages":[]}';

    public int $upstreamStatus = 200;
    public string $upstreamContentType = 'text/event-stream; charset=utf-8';
    /** @var list<string> */
    public array $upstreamChunks = [];
    public int $upstreamErrno = 0;
    public string $upstreamError = '';

    /** Po kolika chuncích simulovat odpojení klienta (-1 = nikdy). */
    public int $abortAfterChunks = -1;

    public ?string $capturedUrl = null;
    /** @var list<string>|null */
    public ?array $capturedHeaders = null;
    public ?string $capturedBody = null;
    /** @var list<array{0: int, 1: string}> */
    public array $metaCalls = [];
    public int $chunksDelivered = 0;

    private bool $clientGone = false;

    protected function orgKeyAvailable(): bool
    {
        return $this->orgKeyExists;
    }

    protected function readOrgKey(): string
    {
        return $this->orgKey;
    }

    protected function rawRequestBody(): string
    {
        return $this->rawBody;
    }

    protected function emitResponseMeta(int $status, string $contentType): void
    {
        $this->metaCalls[] = [$status, $contentType];
    }

    protected function clientAborted(): bool
    {
        return $this->clientGone;
    }

    protected function forwardToUpstream(
        string $url,
        array $headers,
        string $body,
        callable $onHeaders,
        callable $onChunk,
    ): array {
        $this->capturedUrl = $url;
        $this->capturedHeaders = $headers;
        $this->capturedBody = $body;

        if ($this->upstreamStatus === 0) {
            return ['status' => 0, 'errno' => $this->upstreamErrno, 'error' => $this->upstreamError];
        }

        $onHeaders($this->upstreamStatus, $this->upstreamContentType);

        foreach ($this->upstreamChunks as $chunk) {
            if (!$onChunk($chunk)) {
                break;
            }
            $this->chunksDelivered++;
            if ($this->abortAfterChunks >= 0 && $this->chunksDelivered >= $this->abortAfterChunks) {
                $this->clientGone = true;
            }
        }

        return ['status' => $this->upstreamStatus, 'errno' => $this->upstreamErrno, 'error' => $this->upstreamError];
    }
}

class HostingAiGatewayControllerTest extends TestCase
{
    private const TOKEN = 'shpd_gw_' . 'abcdefghijkl' . 'mnopqrstuvwxyz0123456789ABCDEFG';

    private InMemoryHostingAiGatewayDb $db;
    private StubHostingAiGatewayController $ctrl;
    private int $dsNdx;

    protected function setUp(): void
    {
        $this->db = InMemoryHostingAiGatewayDb::create();
        $this->dsNdx = $this->db->addDataSource([]);
        $this->db->addToken([
            'data_source' => $this->dsNdx,
            'token_prefix' => substr(substr(self::TOKEN, strlen('shpd_gw_')), 0, 12),
            'token_hash' => hash('sha256', self::TOKEN),
        ]);
        $this->ctrl = new StubHostingAiGatewayController($this->createMock(DataSourceConfig::class));
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        $make = fn (string $name, int $tableId) => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name' => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);
        return [
            'hosting_core_ai_tokens'    => $make('hosting_core_ai_tokens', 435),
            'hosting_core_ai_usage'     => $make('hosting_core_ai_usage', 436),
            'hosting_core_data_sources' => $make('hosting_core_data_sources', 431),
        ];
    }

    private function req(string|false $token = self::TOKEN, array $extraServer = []): Request
    {
        $server = [
            'HTTP_HOST' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ANTHROPIC_VERSION' => '2023-06-01',
        ];
        if ($token !== false) {
            $server['HTTP_X_API_KEY'] = $token;
        }
        return Request::fromArray(
            'POST',
            '/api/v1/_hosting/ai-gw/v1/messages',
            [],
            '',
            array_merge($server, $extraServer),
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    private function runProducer(Response $response): string
    {
        $ref = new \ReflectionClass($response);
        $producer = $ref->getProperty('streamProducer')->getValue($response);
        $this->assertIsCallable($producer);

        ob_start();
        try {
            $producer();
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }

    private function assertAnthropicAuthError(Response $resp): void
    {
        $this->assertSame(401, $this->getStatus($resp));
        $payload = $resp->getPayload();
        $this->assertSame('error', $payload['type']);
        $this->assertSame('authentication_error', $payload['error']['type']);
    }

    /** SSE fixture s cache poli. */
    private function sseChunks(): array
    {
        return [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"claude-sonnet-4-5\","
            . "\"usage\":{\"input_tokens\":42,\"cache_creation_input_tokens\":100,\"cache_read_input_tokens\":200}}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,"
            . "\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n",
            "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},"
            . "\"usage\":{\"output_tokens\":7}}\n\n",
        ];
    }

    // -------------------------------------------------------------------------
    // Gating
    // -------------------------------------------------------------------------

    public function testMissingTablesReturns404(): void
    {
        $resp = $this->ctrl->messages($this->req(), $this->db, []);
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testMissingOrgKeyReturns404(): void
    {
        $this->ctrl->orgKeyExists = false;
        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $this->assertSame(404, $this->getStatus($resp));
    }

    // -------------------------------------------------------------------------
    // Auth matice — vždy 401 v Anthropic formátu
    // -------------------------------------------------------------------------

    public function testMissingApiKeyHeaderReturns401(): void
    {
        $resp = $this->ctrl->messages($this->req(false), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);
    }

    public function testWrongPrefixReturns401(): void
    {
        $resp = $this->ctrl->messages(
            $this->req('shpd_hk_' . str_repeat('a', 43)),
            $this->db,
            $this->tables(),
        );
        $this->assertAnthropicAuthError($resp);
    }

    public function testUnknownPrefixReturns401(): void
    {
        $resp = $this->ctrl->messages(
            $this->req('shpd_gw_' . str_repeat('z', 43)),
            $this->db,
            $this->tables(),
        );
        $this->assertAnthropicAuthError($resp);
    }

    public function testWrongHashReturns401(): void
    {
        // Správný prefix (najde řádek), jiný zbytek tokenu → hash nesedí.
        $tampered = substr(self::TOKEN, 0, -5) . 'XXXXX';
        $resp = $this->ctrl->messages($this->req($tampered), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);
    }

    public function testRevokedTokenReturns401(): void
    {
        $this->db->tokens[array_key_first($this->db->tokens)]['active'] = 0;
        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);
    }

    public function testArchivedTokenReturns401(): void
    {
        $this->db->tokens[array_key_first($this->db->tokens)]['docState'] = 90;
        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);
    }

    public function testInactiveDataSourceLifecycleReturns401(): void
    {
        $this->db->dataSources[$this->dsNdx]['lifecycle'] = 'request';
        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);

        $this->db->dataSources[$this->dsNdx]['lifecycle'] = 'suspended';
        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);
    }

    public function testArchivedDataSourceReturns401(): void
    {
        $this->db->dataSources[$this->dsNdx]['docState'] = 90;
        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $this->assertAnthropicAuthError($resp);
    }

    // -------------------------------------------------------------------------
    // Passthrough + metering
    // -------------------------------------------------------------------------

    public function testHappySsePassthroughEchoesBytesAndRecordsUsage(): void
    {
        $this->ctrl->upstreamChunks = $this->sseChunks();

        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $out = $this->runProducer($resp);

        // Output je byte-identický s upstream chunky.
        $this->assertSame(implode('', $this->sseChunks()), $out);

        // Status + content-type upstreamu propagované před prvním bytem.
        $this->assertSame([[200, 'text/event-stream; charset=utf-8']], $this->ctrl->metaCalls);

        // Usage řádek sedí s fixture streamem.
        $this->assertCount(1, $this->db->usage);
        $row = $this->db->usage[0];
        $this->assertSame($this->dsNdx, $row['data_source']);
        $this->assertSame('claude-sonnet-4-5', $row['model']);
        $this->assertSame(42, $row['input_tokens']);
        $this->assertSame(7, $row['output_tokens']);
        $this->assertSame(100, $row['cache_creation_tokens']);
        $this->assertSame(200, $row['cache_read_tokens']);
        $this->assertSame(200, $row['http_status']);
        $this->assertSame(1, $row['stream']);
        $this->assertIsInt($row['duration_ms']);

        // last_used nastaveno (throttle: bylo NULL).
        $this->assertNotNull($this->db->tokens[array_key_first($this->db->tokens)]['last_used']);
    }

    public function testForwardHeadersAllowlist(): void
    {
        $this->ctrl->upstreamChunks = $this->sseChunks();

        $resp = $this->ctrl->messages(
            $this->req(self::TOKEN, [
                'HTTP_AUTHORIZATION' => 'Bearer shpd_st_secret-session-token',
                'HTTP_COOKIE' => 'session=abc',
                'HTTP_ACCEPT_ENCODING' => 'gzip',
                'HTTP_ANTHROPIC_BETA' => 'beta-flag',
            ]),
            $this->db,
            $this->tables(),
        );
        $this->runProducer($resp);

        $headers = $this->ctrl->capturedHeaders;
        $this->assertNotNull($headers);

        $this->assertContains('content-type: application/json', $headers);
        $this->assertContains('anthropic-version: 2023-06-01', $headers);
        $this->assertContains('anthropic-beta: beta-flag', $headers);
        $this->assertContains('x-api-key: sk-ant-org-key', $headers);
        $this->assertContains('Expect:', $headers);
        $this->assertCount(5, $headers);

        // Klientský token, authorization ani cookies se nikdy nepropustí.
        $joined = implode("\n", $headers);
        $this->assertStringNotContainsString(self::TOKEN, $joined);
        $this->assertStringNotContainsStringIgnoringCase('authorization', $joined);
        $this->assertStringNotContainsStringIgnoringCase('cookie', $joined);
        $this->assertStringNotContainsStringIgnoringCase('accept-encoding', $joined);

        // Body jde upstream raw, beze změny.
        $this->assertSame($this->ctrl->rawBody, $this->ctrl->capturedBody);
        $this->assertSame('https://api.anthropic.com/v1/messages', $this->ctrl->capturedUrl);
    }

    public function testErrorResponseIsPassedThroughAndRecorded(): void
    {
        $errorBody = '{"type":"error","error":{"type":"rate_limit_error","message":"Rate limited"}}';
        $this->ctrl->upstreamStatus = 429;
        $this->ctrl->upstreamContentType = 'application/json';
        $this->ctrl->upstreamChunks = [$errorBody];

        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $out = $this->runProducer($resp);

        $this->assertSame($errorBody, $out);
        $this->assertSame([[429, 'application/json']], $this->ctrl->metaCalls);

        $this->assertCount(1, $this->db->usage);
        $row = $this->db->usage[0];
        $this->assertSame(429, $row['http_status']);
        $this->assertSame(0, $row['input_tokens']);
        $this->assertSame(0, $row['output_tokens']);
        $this->assertSame(0, $row['stream']);
    }

    public function testTransportFailureEmits502AndRecordsUsage(): void
    {
        $this->ctrl->upstreamStatus = 0;
        $this->ctrl->upstreamErrno = 7;
        $this->ctrl->upstreamError = 'Failed to connect';

        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $out = $this->runProducer($resp);

        $this->assertSame([[502, 'application/json']], $this->ctrl->metaCalls);
        $decoded = json_decode($out, true);
        $this->assertSame('error', $decoded['type']);
        $this->assertSame('api_error', $decoded['error']['type']);

        $this->assertCount(1, $this->db->usage);
        $this->assertSame(502, $this->db->usage[0]['http_status']);
        $this->assertSame(0, $this->db->usage[0]['input_tokens']);
    }

    public function testMeteringInsertFailureDoesNotBreakResponse(): void
    {
        $this->ctrl->upstreamChunks = $this->sseChunks();
        $this->db->failUsageInsert = true;

        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $out = $this->runProducer($resp);

        // Odpověď klientovi je nedotčená, výjimka nepropadne.
        $this->assertSame(implode('', $this->sseChunks()), $out);
        $this->assertSame([], $this->db->usage);
    }

    public function testClientAbortStopsTransferAndStillRecordsUsage(): void
    {
        $this->ctrl->upstreamChunks = $this->sseChunks();
        $this->ctrl->abortAfterChunks = 1;

        $resp = $this->ctrl->messages($this->req(), $this->db, $this->tables());
        $out = $this->runProducer($resp);

        // Echo druhého chunku ještě proběhne (do mrtvého socketu — neškodné),
        // jeho onChunk už ale vrátí false a třetí chunk se nepřenáší.
        $this->assertSame($this->sseChunks()[0] . $this->sseChunks()[1], $out);
        $this->assertSame(1, $this->ctrl->chunksDelivered);

        // Usage řádek se přesto zapsal — s tím, co extractor stihl.
        $this->assertCount(1, $this->db->usage);
        $row = $this->db->usage[0];
        $this->assertSame(42, $row['input_tokens']);
        $this->assertSame(0, $row['output_tokens']);
    }

    public function testLastUsedThrottle(): void
    {
        $tokenId = array_key_first($this->db->tokens);
        $this->ctrl->upstreamChunks = $this->sseChunks();

        // Čerstvé last_used → žádný update.
        $fresh = date('Y-m-d H:i:s', time() - 10);
        $this->db->tokens[$tokenId]['last_used'] = $fresh;
        $this->runProducer($this->ctrl->messages($this->req(), $this->db, $this->tables()));
        $this->assertSame($fresh, $this->db->tokens[$tokenId]['last_used']);

        // Starší než 60 s → update.
        $stale = date('Y-m-d H:i:s', time() - 120);
        $this->db->tokens[$tokenId]['last_used'] = $stale;
        $this->runProducer($this->ctrl->messages($this->req(), $this->db, $this->tables()));
        $this->assertNotSame($stale, $this->db->tokens[$tokenId]['last_used']);
    }
}
