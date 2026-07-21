<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DashboardController;
use Shipard\Api\Response;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Unit testy pro DashboardController.
 *
 * Pokrývají:
 *   - sortAndCap / countByKind (čisté transformace)
 *   - dashboard() — tvar feedu, zapojení zdrojů, ořez + „a další“ karta
 *   - summary() — SSE události (text/done/error), degradace
 */
final class DashboardControllerTest extends TestCase
{
    // ── sortAndCap / countByKind (čisté funkce) ──────────────────────────────

    /** @return array<string,mixed> */
    private function card(string $kind, ?string $timestamp, string $id = 'x'): array
    {
        return ['id' => $id, 'kind' => $kind, 'timestamp' => $timestamp];
    }

    public function testSortAndCapOrdersByKindBand(): void
    {
        $ctrl = new DashboardController();
        $input = [
            $this->card('info', '2026-06-28T10:00:00+00:00', 'i'),
            $this->card('ready', '2026-06-28T10:00:00+00:00', 'r'),
            $this->card('urgent', '2026-06-28T10:00:00+00:00', 'u'),
            $this->card('review', '2026-06-28T10:00:00+00:00', 'v'),
        ];
        [$sorted, $truncated] = $ctrl->sortAndCap($input, 30);

        $this->assertFalse($truncated);
        $this->assertSame(['u', 'v', 'r', 'i'], array_column($sorted, 'id'));
    }

    public function testSortAndCapTimestampDescWithinBand(): void
    {
        $ctrl = new DashboardController();
        $input = [
            $this->card('ready', '2026-06-01T10:00:00+00:00', 'old'),
            $this->card('ready', '2026-06-28T10:00:00+00:00', 'new'),
            $this->card('ready', null, 'notime'),
        ];
        [$sorted] = $ctrl->sortAndCap($input, 30);

        // Nejnovější první, karta bez timestampu naspod pásma.
        $this->assertSame(['new', 'old', 'notime'], array_column($sorted, 'id'));
    }

    public function testSortAndCapCapsAndFlagsTruncation(): void
    {
        $ctrl = new DashboardController();
        $input = [];
        for ($i = 0; $i < 35; $i++) {
            $input[] = $this->card('ready', '2026-06-28T10:00:00+00:00', "c$i");
        }
        [$sorted, $truncated] = $ctrl->sortAndCap($input, 30);

        $this->assertTrue($truncated);
        $this->assertCount(30, $sorted);
    }

    public function testCountByKindCountsOnlyActionable(): void
    {
        $ctrl = new DashboardController();
        $cards = [
            $this->card('urgent', null),
            $this->card('urgent', null),
            $this->card('review', null),
            $this->card('ready', null),
            $this->card('info', null),   // nezapočítává se
        ];
        $this->assertSame(['urgent' => 2, 'review' => 1, 'ready' => 1], $ctrl->countByKind($cards));
    }

    // ── dashboard() feed tvar ────────────────────────────────────────────────

    public function testDashboardEmptyFeedShape(): void
    {
        // DB mock vracející prázdné sady → žádné karty.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($db, null, 'cs');

        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $data = $payload['data'];

        $this->assertArrayHasKey('generatedAt', $data);
        $this->assertNull($data['summary']['aiText']);
        $this->assertSame(['urgent' => 0, 'review' => 0, 'ready' => 0], $data['summary']['counts']);
        $this->assertSame([], $data['cards']);
        $this->assertArrayNotHasKey('tasks', $data);
        $this->assertArrayNotHasKey('widgets', $data);
    }

    public function testDashboardWiresBothSourcesAndSorts(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                // Agregátní fáze AlertsSource (GROUP BY) — jeden check pod prahem.
                if (str_contains($sql, 'core_alerts_alerts') && str_contains($sql, 'GROUP BY')) {
                    return [[
                        'check_id' => 'chk', 'cnt' => 1, 'max_severity' => 30,
                        'last_at' => '2026-06-28 08:00:00', 'first_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return [[
                        'id' => 7, 'check_id' => 'chk', 'title' => 'Chyba', 'message' => 'm',
                        'severity' => 30, 'actions' => null,
                        'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                    ]];
                }
                // NOT EXISTS = dotaz na karty „Není faktura" — tady prázdný
                if (str_contains($sql, 'extracted_documents') && !str_contains($sql, 'NOT EXISTS')) {
                    return [[
                        'extracted_ndx' => 1, 'message_ndx' => 2, 'doc_type' => 'invoiceReceived',
                        'confidence' => 0.9, 'status' => 10, 'subject' => 'Faktura',
                        'sender_name' => 'X', 'received_at' => '2026-06-28 09:00:00',
                        'extracted_json' => '{}',
                    ]];
                }
                return [];
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs')->getPayload()['data'];

        $this->assertCount(2, $data['cards']);
        // urgent (alert) před ready (mail)
        $this->assertSame('alert:7', $data['cards'][0]['id']);
        $this->assertSame('mail_extracted:1', $data['cards'][1]['id']);
        $this->assertSame(['urgent' => 1, 'review' => 0, 'ready' => 1], $data['summary']['counts']);
    }

    public function testDashboardAppendsAndMoreCardWhenTruncated(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'extracted_documents') || str_contains($sql, 'NOT EXISTS')) {
                    return [];
                }
                $rows = [];
                for ($i = 1; $i <= 35; $i++) {
                    $rows[] = [
                        'extracted_ndx' => $i, 'message_ndx' => 100 + $i, 'doc_type' => 'invoiceReceived',
                        'confidence' => 0.9, 'status' => 10, 'subject' => "F$i",
                        'sender_name' => 'X', 'received_at' => '2026-06-28 09:00:00',
                        'extracted_json' => '{}',
                    ];
                }
                return $rows;
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs')->getPayload()['data'];

        $this->assertCount(31, $data['cards']); // 30 + „a další" karta
        $last = $data['cards'][30];
        $this->assertSame('mail_more', $last['id']);
        $this->assertSame('info', $last['kind']);
        $this->assertSame('open_viewer', $last['actions'][0]['kind']);
    }

    // ── summary() — SSE AI shrnutí (fáze 2b) ─────────────────────────────────

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

    /** DB mock: jedna mail karta ve feedu, žádná cache (fetchRow → null). */
    private function summaryDb(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'extracted_documents') || str_contains($sql, 'NOT EXISTS')) {
                    return [];
                }
                return [[
                    'extracted_ndx' => 1, 'message_ndx' => 2, 'doc_type' => 'invoiceReceived',
                    'confidence' => 0.9, 'status' => 10, 'subject' => 'Faktura',
                    'sender_name' => 'X', 'received_at' => '2026-06-28 09:00:00',
                    'extracted_json' => '{}',
                ]];
            },
        );
        return $db;
    }

    private function summaryService(DataSourceConnection $db, LlmClient $llm): DashboardSummaryService
    {
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn([
            'provider' => 'anthropic', 'model' => 'claude-x', 'base_url' => null,
        ]);
        $backends->method('apiKey')->willReturn('sk-test');
        return new DashboardSummaryService($db, $llm, $backends);
    }

    public function testSummaryEmptyFeedEmitsDoneNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $ctrl = new DashboardController();
        $out  = $this->runProducer(
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs'),
        );

        $this->assertStringContainsString('event: done', $out);
        $this->assertStringContainsString('"text":null', $out);
        $this->assertStringNotContainsString('event: text', $out);
        $this->assertStringNotContainsString('event: error', $out);
    }

    public function testSummaryStreamsDeltasAndDone(): void
    {
        $db  = $this->summaryDb();
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->once())->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta): LlmChatResult {
                $onDelta('Dnes máte ');
                $onDelta('jednu fakturu.');
                return new LlmChatResult('Dnes máte jednu fakturu.', 100, 20, 'end_turn', 'claude-x');
            },
        );

        $ctrl = new DashboardController();
        $out  = $this->runProducer(
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs'),
        );

        $this->assertStringContainsString('event: text', $out);
        $this->assertStringContainsString('"delta":"Dnes máte "', $out);
        $this->assertStringContainsString('event: done', $out);
        $this->assertStringContainsString('"text":"Dnes máte jednu fakturu."', $out);
        $this->assertStringContainsString('"cached":false', $out);
    }

    public function testSummaryLlmErrorEmitsErrorEvent(): void
    {
        $db  = $this->summaryDb();
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willThrowException(
            new LlmApiException(500, 'api_error', 'model exploded'),
        );

        $ctrl = new DashboardController();
        $out  = $this->runProducer(
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs'),
        );

        $this->assertStringContainsString('event: error', $out);
        $this->assertStringContainsString('model exploded', $out);
        $this->assertStringNotContainsString('event: done', $out);
    }
}
