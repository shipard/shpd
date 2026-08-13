<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\DashboardController;
use Shipard\Api\Response;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Logging\ErrorLogger;

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
    private string $logPath;

    protected function setUp(): void
    {
        // Per-source izolace loguje výjimky — log do tempu, ne /opt.
        $this->logPath = sys_get_temp_dir() . '/shpd_dashctrl_' . uniqid('', true) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logPath);
    }

    /**
     * Runtime mapa tabulek pro dashboard() — hodnoty jsou minimální
     * TableDefinition, controller dělá jen isset().
     *
     * @return array<string, TableDefinition>
     */
    private function tables(string ...$names): array
    {
        $map = [];
        foreach ($names as $name) {
            $map[$name] = new TableDefinition(
                tableId: 999,
                name: $name,
                displayPattern: null,
                columnGroups: [],
                columns: [],
                indexes: [],
                childTables: [],
                docStates: null,
            );
        }
        return $map;
    }

    /** Mapa běžného DS — mail i alerty aktivní. */
    private function fullTables(): array
    {
        return $this->tables('core_mail_incoming_messages', 'core_alerts_alerts');
    }

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
        $response = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables());

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

    /**
     * Řádek návrhové karty MailSuggestionsSource — zpráva s otevřeným
     * dokumentovým návrhem poslední úspěšné analýzy (message-centricky,
     * canonical na řádku analýzy).
     *
     * @return array<string,mixed>
     */
    private static function suggestionRow(int $messageNdx, int $analysisNdx, string $subject): array
    {
        return [
            'message_ndx' => $messageNdx, 'subject' => $subject, 'sender_name' => 'X',
            'received_at' => '2026-06-28 09:00:00', 'raw_source_attachment' => null,
            'analysis_ndx' => $analysisNdx, 'proposed_type' => 'invoiceReceived',
            'canonical_json' => '{}', 'analysis_json' => null,
            'confidence' => 0.95, 'profile' => null,
        ];
    }

    public function testDashboardWiresBothSourcesAndSorts(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // Prahy pásem (AnalysisConfidenceResolver) — fetchRow → null = defaulty.
        $db->method('fetchRow')->willReturn(null);
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
                // `proposed_type` nese jen dotaz na návrhové karty (JOIN na
                // poslední úspěšnou analýzu); error/notInvoice/attachments ne.
                if (str_contains($sql, 'proposed_type')) {
                    return [self::suggestionRow(2, 1, 'Faktura')];
                }
                return [];
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $this->assertCount(2, $data['cards']);
        // urgent (alert) před ready (mail)
        $this->assertSame('alert:7', $data['cards'][0]['id']);
        $this->assertSame('mail_suggestion:2', $data['cards'][1]['id']);
        $this->assertSame(['urgent' => 1, 'review' => 0, 'ready' => 1], $data['summary']['counts']);
    }

    public function testDashboardAppendsAndMoreCardWhenTruncated(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'proposed_type')) {
                    return [];
                }
                $rows = [];
                for ($i = 1; $i <= 35; $i++) {
                    $rows[] = self::suggestionRow(100 + $i, $i, "F$i");
                }
                return $rows;
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $this->assertCount(31, $data['cards']); // 30 + „a další" karta
        $last = $data['cards'][30];
        $this->assertSame('mail_more', $last['id']);
        $this->assertSame('info', $last['kind']);
        $this->assertSame('open_viewer', $last['actions'][0]['kind']);
    }

    // ── degradace dle modulů + per-source izolace + capabilities (07b) ──────

    /** DB mock z testDashboardWiresBothSourcesAndSorts — mail i alert karta. */
    private function bothSourcesDb(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
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
                if (str_contains($sql, 'proposed_type')) {
                    return [self::suggestionRow(2, 1, 'Faktura')];
                }
                return [];
            },
        );
        return $db;
    }

    public function testMailSourcesSkippedWithoutMailTable(): void
    {
        // DS bez core.mail (hosting DS): mail zdroje se nezaregistrují —
        // žádný dotaz na mail tabulky, response 200 jen s alert kartami.
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->bothSourcesDb(),
            null,
            'cs',
            null,
            $this->tables('core_alerts_alerts'),
        )->getPayload()['data'];

        $this->assertSame(['alert:7'], array_column($data['cards'], 'id'));
    }

    public function testAlertsSourceSkippedWithoutAlertsTable(): void
    {
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->bothSourcesDb(),
            null,
            'cs',
            null,
            $this->tables('core_mail_incoming_messages'),
        )->getPayload()['data'];

        $this->assertSame(['mail_suggestion:2'], array_column($data['cards'], 'id'));
    }

    public function testNoTablesYieldsEmptyFeedNotError(): void
    {
        // Prázdná mapa (degradovaný kontext) = žádné zdroje, žádný dotaz,
        // žádná chyba — DB mock bez stubů by na volání spadl.
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->createMock(DataSourceConnection::class),
            null,
            'cs',
        )->getPayload()['data'];

        $this->assertSame([], $data['cards']);
    }

    public function testFailingSourceIsIsolated(): void
    {
        // Mail dotaz vyhodí výjimku (vzor: chybějící tabulka → Dibi
        // exception) — zaloguje se a feed pokračuje alert kartami.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
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
                throw new \RuntimeException("Table 'x.core_mail_incoming_messages' doesn't exist");
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $this->assertSame(['alert:7'], array_column($data['cards'], 'id'));
        $this->assertStringContainsString('Dashboard feed source failed', (string) file_get_contents($this->logPath));
    }

    public function testCapabilitiesReflectTablesAndAuth(): void
    {
        $ctrl  = new DashboardController();
        $db    = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);
        $admin    = new AuthContext(isAuthenticated: true, userId: 1, isAdmin: true);
        $nonAdmin = new AuthContext(isAuthenticated: true, userId: 2, isAdmin: false);

        $caps = fn(array $tables, ?AuthContext $auth) => $ctrl
            ->dashboard($db, null, 'cs', null, $tables, $auth)
            ->getPayload()['data']['capabilities'];

        // Běžný DS s mail + chat: obě true (bez ohledu na admin flag).
        $this->assertSame(
            ['mailUpload' => true, 'chat' => true],
            $caps($this->tables('core_mail_incoming_messages', 'core_chat_conversations'), $nonAdmin),
        );
        // DS bez mail/chat modulů: obě false.
        $this->assertSame(
            ['mailUpload' => false, 'chat' => false],
            $caps($this->tables('core_alerts_alerts'), $admin),
        );
        // Aktivní chat + hosting: ne-admin false (D5), admin true.
        $hostingTables = $this->tables('core_chat_conversations', 'hosting_core_data_sources');
        $this->assertFalse($caps($hostingTables, $nonAdmin)['chat']);
        $this->assertTrue($caps($hostingTables, $admin)['chat']);
        // $auth null (degradace) = fail-closed jako ne-admin.
        $this->assertFalse($caps($hostingTables, null)['chat']);
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
                if (!str_contains($sql, 'proposed_type')) {
                    return [];
                }
                return [self::suggestionRow(2, 1, 'Faktura')];
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
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs', null, $this->fullTables()),
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
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs', null, $this->fullTables()),
        );

        $this->assertStringContainsString('event: error', $out);
        $this->assertStringContainsString('model exploded', $out);
        $this->assertStringNotContainsString('event: done', $out);
    }
}
