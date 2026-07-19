<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;

/**
 * Stub list-only vieweru — grid metody nechává na defaultech TableVieweru.
 */
class ListOnlyStubViewer extends TableViewer
{
	public function selectRows(?string $search, array $filters, int $pageNumber): array
	{
		return [['id' => 1], ['id' => 2]];
	}

	public function renderRow(array $rowData): array
	{
		return ['id' => (int) $rowData['id'], 't1' => 'row ' . $rowData['id']];
	}
}

/**
 * Stub grid vieweru — deklaruje sloupce, grid render i footer.
 */
class GridStubViewer extends TableViewer
{
	public function selectRows(?string $search, array $filters, int $pageNumber): array
	{
		return [['id' => 1, 'amount' => 10], ['id' => 2, 'amount' => 20]];
	}

	public function renderRow(array $rowData): array
	{
		return ['id' => (int) $rowData['id'], 't1' => 'row ' . $rowData['id']];
	}

	public function getGridColumns(): ?array
	{
		return [
			['id' => 'name', 'label' => 'Name', 'grow' => true],
			['id' => 'amount', 'label' => 'Amount', 'width' => 110, 'align' => 'right'],
		];
	}

	public function getGridOptions(): array
	{
		return ['showIndex' => false];
	}

	public function getDefaultLayout(): string
	{
		return 'grid';
	}

	public function renderGridRow(array $rowData): array
	{
		return [
			'id'    => (int) $rowData['id'],
			'cells' => ['name' => 'row ' . $rowData['id'], 'amount' => (string) $rowData['amount']],
		];
	}

	public function renderGridFooter(?string $search, array $filters): ?array
	{
		return ['amount' => ['text' => '30', 'class' => 'amount']];
	}
}

/**
 * Stub vieweru s nevalidním defaultLayout — grid nepodporuje, ale hlásí ho.
 */
class BadDefaultLayoutStubViewer extends ListOnlyStubViewer
{
	public function getDefaultLayout(): string
	{
		return 'grid';
	}
}

/**
 * Layout větev ViewerControlleru (docs/viewer-grid.md §3.5): meta klíče
 * layouts/defaultLayout/grid, rows s layout=grid (cells tvar, bez ikon),
 * guard LAYOUT_NOT_SUPPORTED, footer jen na page 0.
 */
class ViewerControllerLayoutTest extends TestCase
{
	private DataSourceConnection $db;
	private ViewerRegistry $registry;
	private ViewerController $ctrl;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();

		$this->registry = new ViewerRegistry();
		$this->registry->register(new ViewerDefinition(
			id: 'test.listOnly',
			name: 'List only',
			table: 'test_list_rows',
			class: ListOnlyStubViewer::class,
			moduleId: 'test',
			icon: 'table',
		));
		$this->registry->register(new ViewerDefinition(
			id: 'test.grid',
			name: 'Grid',
			table: 'test_grid_rows',
			class: GridStubViewer::class,
			moduleId: 'test',
			icon: 'table',
		));
		$this->registry->register(new ViewerDefinition(
			id: 'test.badDefault',
			name: 'Bad default',
			table: 'test_list_rows',
			class: BadDefaultLayoutStubViewer::class,
			moduleId: 'test',
			icon: null,
		));

		$this->ctrl = new ViewerController();
	}

	private function auth(): AuthContext
	{
		return new AuthContext(true, 2, 'session', 'shpd_st_x');
	}

	private function rowsRequest(array $query): Request
	{
		return Request::fromArray('GET', '/_ui/viewer/x/rows', $query, '', []);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// ── meta ────────────────────────────────────────────────────────────────

	public function testMetaListOnlyViewerHasOnlyListLayoutAndNoGridKey(): void
	{
		$response = $this->ctrl->meta('test.listOnly', $this->auth(), $this->registry, $this->db);
		$data     = $response->getPayload()['data'];

		$this->assertSame(['list'], $data['layouts']);
		$this->assertSame('list', $data['defaultLayout']);
		$this->assertArrayNotHasKey('grid', $data);
	}

	public function testMetaGridViewerHasBothLayoutsColumnsAndShowIndex(): void
	{
		$response = $this->ctrl->meta('test.grid', $this->auth(), $this->registry, $this->db);
		$data     = $response->getPayload()['data'];

		$this->assertSame(['list', 'grid'], $data['layouts']);
		$this->assertSame('grid', $data['defaultLayout']);
		$this->assertSame(['name', 'amount'], array_column($data['grid']['columns'], 'id'));
		$this->assertFalse($data['grid']['showIndex']);
	}

	public function testMetaInvalidDefaultLayoutFallsBackToList(): void
	{
		$response = $this->ctrl->meta('test.badDefault', $this->auth(), $this->registry, $this->db);
		$data     = $response->getPayload()['data'];

		$this->assertSame(['list'], $data['layouts']);
		$this->assertSame('list', $data['defaultLayout']);
	}

	// ── rows ────────────────────────────────────────────────────────────────

	public function testRowsGridLayoutReturnsCellsShapeWithoutIconAndFooter(): void
	{
		$response = $this->ctrl->rows('test.grid', $this->rowsRequest(['layout' => 'grid']), $this->auth(), $this->registry, $this->db);
		$data     = $response->getPayload()['data'];

		$this->assertSame(200, $this->getStatus($response));
		$this->assertCount(2, $data['rows']);
		$this->assertSame(['name' => 'row 1', 'amount' => '10'], $data['rows'][0]['cells']);
		$this->assertArrayNotHasKey('icon', $data['rows'][0], 'grid řádky default ikonu nedostávají');
		$this->assertSame(['text' => '30', 'class' => 'amount'], $data['footer']['amount']);
	}

	public function testRowsGridFooterOnlyOnPageZero(): void
	{
		$response = $this->ctrl->rows('test.grid', $this->rowsRequest(['layout' => 'grid', 'page' => '1']), $this->auth(), $this->registry, $this->db);
		$data     = $response->getPayload()['data'];

		$this->assertArrayNotHasKey('footer', $data);
	}

	public function testRowsWithoutLayoutParamKeepsListShapeAndDefaultIcon(): void
	{
		$response = $this->ctrl->rows('test.grid', $this->rowsRequest([]), $this->auth(), $this->registry, $this->db);
		$data     = $response->getPayload()['data'];

		$this->assertSame('row 1', $data['rows'][0]['t1']);
		$this->assertSame('table', $data['rows'][0]['icon']);
		$this->assertArrayNotHasKey('footer', $data);
	}

	public function testRowsGridLayoutOnListOnlyViewerIsRejected(): void
	{
		$response = $this->ctrl->rows('test.listOnly', $this->rowsRequest(['layout' => 'grid']), $this->auth(), $this->registry, $this->db);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('LAYOUT_NOT_SUPPORTED', $response->getPayload()['error']['code']);
	}
}
