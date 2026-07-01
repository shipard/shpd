<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DashboardController;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\ViewerRegistry;

/**
 * Unit testy pro DashboardController.
 *
 * Pokrývají:
 *   - happy path: 3 widgety, agregovaný summary, items převedené z renderRow()
 *   - prázdná data: 0 items / 0 count u všech widgetů
 *   - flattenTextField — všechny varianty t1/t2 (null, string, {text,class}, list)
 *   - renderRowToWidgetItem — fallback na widget icon, action templates
 *     (open_viewer i open_form), title fallback `#id` při prázdném t1
 *   - countActiveByDocState přes mockovaný ConfigRuntime
 */
final class DashboardControllerTest extends TestCase
{
    public function testFlattenString(): void
    {
        $ctrl = new DashboardController();
        $this->assertSame('hello', $ctrl->flattenTextField('hello', ' '));
    }

    public function testFlattenNullAndEmpty(): void
    {
        $ctrl = new DashboardController();
        $this->assertNull($ctrl->flattenTextField(null, ' '));
        $this->assertNull($ctrl->flattenTextField('', ' '));
    }

    public function testFlattenStyledSpanObject(): void
    {
        $ctrl = new DashboardController();
        $val = ['text' => 'Subject', 'class' => 'bold'];
        $this->assertSame('Subject', $ctrl->flattenTextField($val, ' '));
    }

    public function testFlattenListOfMixedSpans(): void
    {
        $ctrl = new DashboardController();
        $val = [
            ['text' => 'Warning', 'class' => 'danger'],
            'Plain',
            ['text' => 'core.alerts.missing_person'],
        ];
        $this->assertSame('Warning · Plain · core.alerts.missing_person', $ctrl->flattenTextField($val, ' · '));
    }

    public function testFlattenEmptyListReturnsNull(): void
    {
        $ctrl = new DashboardController();
        $this->assertNull($ctrl->flattenTextField([], ' '));
    }

    public function testRenderRowToWidgetItemUsesT1WhenPresent(): void
    {
        $ctrl = new DashboardController();
        $rendered = [
            'id'         => 42,
            't1'         => 'Hello world',
            't2'         => [['text' => 'warning', 'class' => 'danger']],
            'stateStyle' => 'edit',
            'icon'       => 'alert',
        ];

        $item = $ctrl->renderRowToWidgetItem(
            $rendered,
            ['kind' => 'open_viewer', 'viewerId' => 'core.alerts.alerts'],
            'fallback-icon',
        );

        $this->assertSame(42, $item['id']);
        $this->assertSame('Hello world', $item['title']);
        $this->assertSame('warning', $item['subtitle']);
        $this->assertSame('edit', $item['stateStyle']);
        $this->assertSame('alert', $item['icon']);
        $this->assertSame([
            'kind'     => 'open_viewer',
            'viewerId' => 'core.alerts.alerts',
            'recordId' => 42,
        ], $item['action']);
    }

    public function testRenderRowToWidgetItemFallsBackOnEmptyT1AndWidgetIcon(): void
    {
        $ctrl = new DashboardController();
        $rendered = ['id' => 17];  // No t1, no icon

        $item = $ctrl->renderRowToWidgetItem(
            $rendered,
            ['kind' => 'open_form', 'table' => 'tasks_core_tasks'],
            'list-check',
        );

        $this->assertSame('#17', $item['title']);
        $this->assertNull($item['subtitle']);
        $this->assertSame('list-check', $item['icon']);  // Falls back to widget icon
        $this->assertNull($item['stateStyle']);
    }

    public function testRenderRowToWidgetItemWithFormAction(): void
    {
        $ctrl = new DashboardController();
        $rendered = ['id' => 33, 't1' => 'Připravit reporty'];

        $item = $ctrl->renderRowToWidgetItem(
            $rendered,
            ['kind' => 'open_form', 'table' => 'tasks_core_tasks'],
            'list-check',
        );

        $this->assertSame([
            'kind'     => 'open_form',
            'table'    => 'tasks_core_tasks',
            'recordId' => 33,
        ], $item['action']);
        $this->assertArrayNotHasKey('viewerId', $item['action']);
    }

    public function testRenderRowToWidgetItemWithViewerAction(): void
    {
        $ctrl = new DashboardController();
        $rendered = ['id' => 7, 't1' => 'Chybí vlastní Osoba'];

        $item = $ctrl->renderRowToWidgetItem(
            $rendered,
            ['kind' => 'open_viewer', 'viewerId' => 'core.alerts.alerts'],
            'alert',
        );

        $this->assertSame([
            'kind'     => 'open_viewer',
            'viewerId' => 'core.alerts.alerts',
            'recordId' => 7,
        ], $item['action']);
        $this->assertArrayNotHasKey('table', $item['action']);
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
        // Prázdný registry + DB mock vracející prázdné sady → žádné karty.
        $registry = new ViewerRegistry();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($registry, $db, null, 'cs');

        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $data = $payload['data'];

        $this->assertArrayHasKey('generatedAt', $data);
        $this->assertNull($data['summary']['aiText']);
        $this->assertSame(['urgent' => 0, 'review' => 0, 'ready' => 0], $data['summary']['counts']);
        $this->assertSame([], $data['cards']);
        $this->assertArrayHasKey('tasks', $data);
        $this->assertArrayNotHasKey('widgets', $data);
    }

    public function testDashboardWiresBothSourcesAndSorts(): void
    {
        $registry = new ViewerRegistry();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return [[
                        'id' => 7, 'check_id' => 'chk', 'title' => 'Chyba', 'message' => 'm',
                        'severity' => 30, 'actions' => null,
                        'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'extracted_documents')) {
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
        $data = $ctrl->dashboard($registry, $db, null, 'cs')->getPayload()['data'];

        $this->assertCount(2, $data['cards']);
        // urgent (alert) před ready (mail)
        $this->assertSame('alert:7', $data['cards'][0]['id']);
        $this->assertSame('mail_extracted:1', $data['cards'][1]['id']);
        $this->assertSame(['urgent' => 1, 'review' => 0, 'ready' => 1], $data['summary']['counts']);
    }

    public function testDashboardAppendsAndMoreCardWhenTruncated(): void
    {
        $registry = new ViewerRegistry();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'extracted_documents')) {
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
        $data = $ctrl->dashboard($registry, $db, null, 'cs')->getPayload()['data'];

        $this->assertCount(31, $data['cards']); // 30 + „a další" karta
        $last = $data['cards'][30];
        $this->assertSame('mail_more', $last['id']);
        $this->assertSame('info', $last['kind']);
        $this->assertSame('open_viewer', $last['actions'][0]['kind']);
    }
}
