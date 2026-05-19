<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DashboardController;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
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

    public function testDashboardEmptyAllWidgets(): void
    {
        // Registry without any matching viewer => createViewer returns null,
        // fetchWidgetItems returns []. get($viewerId) also returns null so
        // alerts count stays at 0.
        $registry = new ViewerRegistry();
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchSingle');

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($registry, $db, null, 'cs');

        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $data = $payload['data'];

        $this->assertArrayHasKey('generatedAt', $data);
        $this->assertSame(['alertsCount' => 0, 'incomingMailCount' => 0, 'tasksCount' => 0], $data['summary']);
        $this->assertCount(3, $data['widgets']);

        foreach ($data['widgets'] as $widget) {
            $this->assertSame(0, $widget['count']);
            $this->assertSame([], $widget['items']);
            $this->assertArrayHasKey('openAllAction', $widget);
        }
    }

    public function testDashboardHappyPathWithStubViewer(): void
    {
        $registry = new ViewerRegistry();
        $registry->register(new ViewerDefinition(
            id: 'core.alerts.alerts',
            name: 'Alerts',
            table: 'core_alerts_alerts',
            class: DashboardTestStubViewer::class,
            moduleId: 'core.alerts',
            icon: 'alert',
        ));

        $db = $this->createMock(DataSourceConnection::class);
        // Alerts: state=10 active count; mail/tasks: 0 because their viewer defs missing
        $db->expects($this->once())
            ->method('fetchSingle')
            ->willReturn(5);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($registry, $db, null, 'en');

        $data = $response->getPayload()['data'];

        $alertsWidget = $data['widgets'][0];
        $this->assertSame('alerts', $alertsWidget['id']);
        $this->assertSame('Alerts', $alertsWidget['title']);
        $this->assertSame('alert', $alertsWidget['icon']);
        $this->assertSame(5, $alertsWidget['count']);
        $this->assertCount(DashboardTestStubViewer::ROW_COUNT, $alertsWidget['items']);

        // First item structure
        $firstItem = $alertsWidget['items'][0];
        $this->assertSame(1, $firstItem['id']);
        $this->assertSame('Title 1', $firstItem['title']);
        $this->assertSame('open_viewer', $firstItem['action']['kind']);
        $this->assertSame('core.alerts.alerts', $firstItem['action']['viewerId']);
        $this->assertSame(1, $firstItem['action']['recordId']);

        // Summary aggregates only what's present
        $this->assertSame(5, $data['summary']['alertsCount']);
        $this->assertSame(0, $data['summary']['incomingMailCount']);
        $this->assertSame(0, $data['summary']['tasksCount']);
    }

    public function testDashboardLimitsItemsTo7PerWidget(): void
    {
        $registry = new ViewerRegistry();
        $registry->register(new ViewerDefinition(
            id: 'core.alerts.alerts',
            name: 'Alerts',
            table: 'core_alerts_alerts',
            class: DashboardTestStubViewerManyRows::class,
            moduleId: 'core.alerts',
        ));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(100);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($registry, $db, null, 'cs');
        $alerts = $response->getPayload()['data']['widgets'][0];

        $this->assertCount(7, $alerts['items'], 'Items limited to 7 server-side');
        $this->assertSame(100, $alerts['count'], 'Count can exceed items.length');
    }

    public function testDashboardCzechTitles(): void
    {
        $registry = new ViewerRegistry();
        $db = $this->createMock(DataSourceConnection::class);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($registry, $db, null, 'cs');
        $widgets = $response->getPayload()['data']['widgets'];

        $this->assertSame('Upozornění', $widgets[0]['title']);
        $this->assertSame('Aktuální došlá pošta', $widgets[1]['title']);
        $this->assertSame('Aktivní úkoly', $widgets[2]['title']);
    }

    public function testDashboardEnglishTitles(): void
    {
        $registry = new ViewerRegistry();
        $db = $this->createMock(DataSourceConnection::class);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($registry, $db, null, 'en');
        $widgets = $response->getPayload()['data']['widgets'];

        $this->assertSame('Alerts', $widgets[0]['title']);
        $this->assertSame('Recent incoming mail', $widgets[1]['title']);
        $this->assertSame('Active tasks', $widgets[2]['title']);
    }
}

/**
 * Stub viewer: vrací deterministickou sadu řádků pro testy.
 */
final class DashboardTestStubViewer extends TableViewer
{
    public const int ROW_COUNT = 3;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $rows = [];
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $rows[] = ['id' => $i, 'title' => "Title $i"];
        }
        return $rows;
    }

    public function renderRow(array $rowData): array
    {
        return [
            'id'         => (int) $rowData['id'],
            't1'         => $rowData['title'],
            't2'         => null,
            'stateStyle' => 'concept',
        ];
    }
}

/**
 * Stub viewer vracející 10 řádků — pro test limitu 7.
 */
final class DashboardTestStubViewerManyRows extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['id' => $i];
        }
        return $rows;
    }

    public function renderRow(array $rowData): array
    {
        return ['id' => (int) $rowData['id']];
    }
}
