<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Unit testy pro AnalysisController. Pokrývají auth gate, validace,
 * branch logiku. Plnou SQL cestu (insert do více tabulek v jedné tx)
 * pokrývají integration testy proti reálné DB.
 *
 * DsSecretCipher používáme reálný (s tmp secrets.key), abychom otestovali
 * decrypt path v /claim a chybový stav když klíč chybí.
 */
class AnalysisControllerTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_analysis_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Analysis Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    private function controller(DataSourceConnection $db): AnalysisController
    {
        return new AnalysisController(
            $db,
            $this->config,
            $this->tmpDir,
            [],
            new DocumentRegistry(),
        );
    }

    private function request(string $method, string $path, array $headers = [], array $body = []): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $rawBody = $body === [] ? '' : (string) json_encode($body);
        return Request::fromArray($method, $path, [], $rawBody, $server);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    private function analyzerAuth(): AuthContext
    {
        return new AuthContext(true, 7, 'api_key', 'shpd_ak_xxx');
    }

    private function userAuth(): AuthContext
    {
        return new AuthContext(true, 100, 'session', 'shpd_st_xxx');
    }

    // -------------------------------------------------------------------
    // Auth gate (analyzer endpoints)
    // -------------------------------------------------------------------

    public function testQueueRejectsAnonymous(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->queue(AuthContext::anonymous(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testQueueRejectsSessionToken(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->queue($this->userAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testQueueRejectsWrongUser(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'admin']);
        $ctrl = $this->controller($db);

        $response = $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(403, $this->statusOf($response));
        $this->assertSame('FORBIDDEN', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // /queue
    // -------------------------------------------------------------------

    public function testQueueReturnsEmptyWhenNoMessages(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],   // auth user
            ['id' => 1],                   // default profile
        );
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchSingle')->willReturn(0);

        $ctrl = $this->controller($db);
        $response = $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(200, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']['messages']);
        $this->assertSame(0, $payload['data']['total_available']);
    }

    public function testQueueReturnsMessagesWithRecommendedProfile(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            ['id' => 17],  // default profile
        );
        $db->method('fetchAll')->willReturn([
            [
                'ndx' => 100,
                'received_at' => '2026-04-26 10:00:00',
                'subject' => 'Faktura č. 1',
                'sender_email' => 'a@example.com',
                'profile_override' => null,
                'raw_source_attachment' => 5,
            ],
            [
                'ndx' => 101,
                'received_at' => '2026-04-26 10:05:00',
                'subject' => 'Faktura č. 2',
                'sender_email' => 'b@example.com',
                'profile_override' => 42,   // explicit override on this msg
                'raw_source_attachment' => 6,
            ],
        ]);
        $db->method('fetchSingle')->willReturn(2); // attachment count + total

        $ctrl = $this->controller($db);
        $response = $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $payload = $response->getPayload();
        $this->assertCount(2, $payload['data']['messages']);
        $this->assertSame(17, $payload['data']['messages'][0]['recommended_profile_ndx']);
        $this->assertSame(42, $payload['data']['messages'][1]['recommended_profile_ndx']);
        $this->assertTrue($payload['data']['messages'][0]['has_raw_source']);
    }

    // -------------------------------------------------------------------
    // /claim — pre-decrypt branches (no real DB needed)
    // -------------------------------------------------------------------

    public function testClaimRequiresAnalyzerId(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);

        $ctrl = $this->controller($db);
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], []), 42);

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testClaimReturns404WhenMessageNotFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(null);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testClaimReturns409WhenWrongState(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42,
            'analysis_state' => 30,  // already analyzed
            'profile_override' => null,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testClaimReturns409WhenAlreadyClaimed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'profile_override' => null]),
            new \Dibi\Row(['id' => 99]), // active claim already exists
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('ALREADY_CLAIMED', $response->getPayload()['error']['code']);
    }

    public function testClaimReturnsErrorWhenNoActiveProfile(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // 1) auth, 2) profile resolution returns null
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            null, // no default profile
        );
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'profile_override' => null]),
            null,
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('NO_PROFILE', $response->getPayload()['error']['code']);
    }

    public function testClaimReturnsErrorWhenBackendKeyMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            // Profile
            ['id' => 17, 'profile_id' => 'czech', 'backend' => 5,
             'prompt_version' => 'v1', 'prompt_template' => 't',
             'output_schema' => '{}', 'supported_doc_types' => '[]',
             'language' => 'cs', 'confidence_thresholds' => '{}'],
            // Backend (api_key=null)
            ['id' => 5, 'backend_id' => 'default', 'provider' => 'anthropic',
             'model' => 'claude', 'api_key' => null, 'base_url' => null,
             'max_tokens' => 4096, 'temperature' => 0.0, 'is_active' => 1],
        );
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'profile_override' => null]),
            null,
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('BACKEND_KEY_MISSING', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // /payload
    // -------------------------------------------------------------------

    public function testPayloadRequiresClaimTokenHeader(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $ctrl = $this->controller($db);

        $response = $ctrl->payload($this->analyzerAuth(), $this->request('GET', '/x'), 42);

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('MISSING_CLAIM_TOKEN', $response->getPayload()['error']['code']);
    }

    public function testPayloadRejectsExpiredClaim(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 42,
                'released' => 0,
                'expires_at' => '2020-01-01 00:00:00',  // expired
            ],
        );
        $ctrl = $this->controller($db);

        $response = $ctrl->payload(
            $this->analyzerAuth(),
            $this->request('GET', '/x', ['X-Claim-Token' => 'ct_abc']),
            42,
        );

        $this->assertSame(410, $this->statusOf($response));
        $this->assertSame('CLAIM_EXPIRED', $response->getPayload()['error']['code']);
    }

    public function testPayloadRejectsTokenForDifferentMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 999,    // different message
                'released' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ],
        );
        $ctrl = $this->controller($db);

        $response = $ctrl->payload(
            $this->analyzerAuth(),
            $this->request('GET', '/x', ['X-Claim-Token' => 'ct_abc']),
            42,
        );

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('CLAIM_TOKEN_MISMATCH', $response->getPayload()['error']['code']);
    }

    public function testPayloadReturnsMessageAndAttachments(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 42,
                'released' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ],
            [
                'subject' => 'Faktura',
                'sender_email' => 'a@b.cz',
                'sender_name' => 'A. Builder',
                'body_plain' => 'Body text',
                'body_html' => null,
                'received_at' => '2026-04-26 10:00:00',
                'raw_source_attachment' => 5,
            ],
        );
        $db->method('fetchAll')->willReturn([
            ['id' => 7, 'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024],
        ]);

        $ctrl = $this->controller($db);
        $response = $ctrl->payload(
            $this->analyzerAuth(),
            $this->request('GET', '/x', ['X-Claim-Token' => 'ct_abc']),
            42,
        );

        $this->assertSame(200, $this->statusOf($response));
        $data = $response->getPayload()['data'];
        $this->assertSame('Faktura', $data['message']['subject']);
        $this->assertCount(1, $data['attachments']);
        $this->assertSame(7, $data['attachments'][0]['ndx']);
    }

    // -------------------------------------------------------------------
    // /reanalyze (UI auth — different gate)
    // -------------------------------------------------------------------

    public function testReanalyzeRejectsAnonymous(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->reanalyze(AuthContext::anonymous(), $this->request('POST', '/x'), 42);

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testReanalyzeRejectsInvalidAnalysisState(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        // analysis_state=10 (ve frontě) — reanalyze vyžaduje 30 nebo 70
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42, 'docState' => 10, 'analysis_state' => 10,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testReanalyzeRejectsArchivedMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        // analysis_state validní (70), ale zpráva v Archivu → 409
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42, 'docState' => 80, 'analysis_state' => 70,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testReanalyzeRejectsInvalidProfileOverride(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'docState' => 10, 'analysis_state' => 30]),
            null, // profile_override invalid
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['profile_override_ndx' => 999];
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('INVALID_PROFILE', $response->getPayload()['error']['code']);
    }

    public function testReanalyzeReturns404WhenMessageMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(null);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(404, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // /failed
    // -------------------------------------------------------------------

    public function testFailedRequiresClaimToken(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);

        $ctrl = $this->controller($db);
        $response = $ctrl->failed($this->analyzerAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('MISSING_CLAIM_TOKEN', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // /result
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // /apply + /reject
    // -------------------------------------------------------------------

    public function testApplyExtractedRejectsAnonymous(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->applyExtracted(
            AuthContext::anonymous(),
            $this->request('POST', '/x'),
            42,
        );

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testApplyExtractedReturns404WhenNotFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = $this->controller($db);
        $response = $ctrl->applyExtracted($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testApplyExtractedRejectsAlreadyApplied(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 42, 'message' => 5, 'status' => 40]); // already applied

        $ctrl = $this->controller($db);
        $response = $ctrl->applyExtracted($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testRejectExtractedRequiresReason(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->rejectExtracted(
            $this->userAuth(),
            $this->request('POST', '/x', [], []),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testRejectExtractedRequiresNonEmptyReason(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->rejectExtracted(
            $this->userAuth(),
            $this->request('POST', '/x', [], ['reason' => '   ']),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // message_classification (spec mail-states-and-classification §B1)
    //
    // Plný /result flow potřebuje reálnou DB (insert do 3 tabulek) — tady
    // testujeme helper applyMessageClassification přes reflection, stejný
    // vzor jako validateAndStoreCanonical v AnalysisControllerExchangeTest.
    // -------------------------------------------------------------------

    private function callApplyClassification(\Dibi\Connection $dibi, array $body): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);
        $ref = new \ReflectionClass($ctrl);
        $method = $ref->getMethod('applyMessageClassification');
        $method->invoke($ctrl, $dibi, 42, $body);
    }

    public function testClassificationUpdatesPrimaryTypeWithAiSource(): void
    {
        $whereCalls = [];
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnCallback(
            function (string $name, array $args) use (&$whereCalls, $fluent) {
                if ($name === 'where') {
                    $whereCalls[] = $args;
                }
                return $fluent;
            },
        );
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'other', 'primary_type_source' => 'ai'],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.97],
        ]);

        // WHERE musí obsahovat guard proti přepsání uživatelské volby
        $flat = array_map(static fn(array $args): string => implode('|', array_map('strval', $args)), $whereCalls);
        $this->assertContains('primary_type_source != %s|user', $flat);
    }

    public function testClassificationFallsBackToAnalysisJson(): void
    {
        // Analyzer daemon (bez změn) top-level pole neposílá — klasifikace
        // dorazí jen uvnitř analysis_json (celý model output).
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'invoiceReceived', 'primary_type_source' => 'ai'],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'analysis_json' => [
                'overall_confidence' => 0.95,
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.98],
                'documents' => [],
            ],
        ]);
    }

    public function testClassificationPrefersTopLevelFieldOverAnalysisJson(): void
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'other', 'primary_type_source' => 'ai'],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.9],
            'analysis_json' => [
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.98],
            ],
        ]);
    }

    public function testClassificationIgnoresUnknownPrimaryType(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->never())->method('update');

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'spam', 'confidence' => 0.9],
        ]);
    }

    public function testClassificationSkipsWhenFieldMissing(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->never())->method('update');

        $this->callApplyClassification($dibi, ['model_name' => 'claude']);
    }

    public function testKnownPrimaryTypesFallbackWithoutConfig(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db); // configRuntime = null
        $ref = new \ReflectionClass($ctrl);
        $types = $ref->getMethod('knownPrimaryTypes')->invoke($ctrl);

        $this->assertContains('invoiceReceived', $types);
        $this->assertContains('other', $types);
        $this->assertContains('creditNote', $types); // enabled:false typy se tolerují
    }

    public function testResultRequiresModelAndPromptVersion(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 42,
                'released' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ],
        );

        $ctrl = $this->controller($db);
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], []),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }
}
