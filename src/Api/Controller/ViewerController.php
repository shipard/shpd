<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\ViewerRegistry;

class ViewerController
{
	public function meta(string $viewerId, ViewerRegistry $registry, DataSourceConnection $db, ?ConfigRuntime $config = null): Response
	{
		$def = $registry->get($viewerId);
		if ($def === null) {
			return Response::error('VIEWER_NOT_FOUND', "Viewer '{$viewerId}' not found", 404);
		}

		$viewer = $registry->createViewer($viewerId, $db, $config);
		if ($viewer === null) {
			return Response::error('VIEWER_CLASS_NOT_FOUND', "Viewer class for '{$viewerId}' not found", 500);
		}

		return Response::success([
			'id'         => $def->id,
			'name'       => $def->name,
			'table'      => $def->table,
			'filters'    => $viewer->getFilters(),
			'toolbar'    => $viewer->getToolbarActions(null),
			'viewGroups' => $viewer->getViewGroups(),
		]);
	}

	public function rows(string $viewerId, Request $request, ViewerRegistry $registry, DataSourceConnection $db, ?ConfigRuntime $config = null): Response
	{
		$viewer = $registry->createViewer($viewerId, $db, $config);
		if ($viewer === null) {
			return Response::error('VIEWER_NOT_FOUND', "Viewer '{$viewerId}' not found", 404);
		}

		$params = $request->getQueryParams();
		$search = isset($params['search']) && is_string($params['search']) ? $params['search'] : null;
		$page   = max(0, (int) ($params['page'] ?? 0));

		$filters = [];
		if (isset($params['filter']) && is_array($params['filter'])) {
			foreach ($params['filter'] as $filterId => $value) {
				$filters[] = ['id' => $filterId, 'value' => $value];
			}
		}

		$rawRows  = $viewer->selectRows($search, $filters, $page);
		$pageSize = $viewer->getPageSize();
		$hasMore  = count($rawRows) > $pageSize;

		if ($hasMore) {
			$rawRows = array_slice($rawRows, 0, $pageSize);
		}

		$rows = [];
		foreach ($rawRows as $row) {
			$rows[] = $viewer->renderRow($row);
		}

		return Response::success([
			'rows'    => $rows,
			'hasMore' => $hasMore,
		]);
	}

	public function detail(string $viewerId, int $recordId, ViewerRegistry $registry, DataSourceConnection $db, ?ConfigRuntime $config = null): Response
	{
		$def = $registry->get($viewerId);
		if ($def === null) {
			return Response::error('VIEWER_NOT_FOUND', "Viewer '{$viewerId}' not found", 404);
		}

		$viewer = $registry->createViewer($viewerId, $db, $config);
		if ($viewer === null) {
			return Response::error('VIEWER_CLASS_NOT_FOUND', "Viewer class for '{$viewerId}' not found", 500);
		}

		$record = $db->fetchRow('SELECT * FROM `' . $def->table . '` WHERE `id` = %i', $recordId);
		if ($record === null) {
			return Response::error('RECORD_NOT_FOUND', "Record {$recordId} not found", 404);
		}

		return Response::success([
			'toolbar' => $viewer->getToolbarActions($record),
			'detail'  => $viewer->renderDetail($recordId),
		]);
	}
}
