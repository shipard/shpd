<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;

final class DevDashboardController
{
	public function __construct(
		private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
	) {}

	public function dispatch(Request $request): Response
	{
		$path = $request->getPath();

		if ($path === '/') {
			return Response::redirect('/_dev/');
		}

		if ($path === '/_dev' || $path === '/_dev/') {
			return $this->page($request);
		}

		if ($path === '/_dev/api/data-sources' && $request->getMethod() === 'GET') {
			return $this->listDataSources();
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function listDataSources(): Response
	{
		$items = [];
		$dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
		foreach ($dirs as $dsDir) {
			$configFile = $dsDir . '/config/main.json';
			if (!is_file($configFile)) {
				continue;
			}

			$content = @file_get_contents($configFile);
			if ($content === false) {
				continue;
			}
			$config = json_decode($content, true);
			if (!is_array($config) || !isset($config['id'], $config['name'])) {
				continue;
			}

			$items[] = [
				'id'            => $config['id'],
				'name'          => $config['name'],
				'created'       => $config['created'] ?? null,
				'database_name' => $config['database_name'] ?? null,
			];
		}

		usort($items, fn($a, $b) => strcmp(
			mb_strtolower((string) $a['name']),
			mb_strtolower((string) $b['name']),
		));

		return Response::success($items);
	}

	private function page(Request $request): Response
	{
		$hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');

		$refresh = 60;
		$qp = $request->getQueryParams();
		if (isset($qp['refresh']) && is_numeric($qp['refresh'])) {
			$candidate = (int) $qp['refresh'];
			if ($candidate >= 5 && $candidate <= 3600) {
				$refresh = $candidate;
			}
		}

		return Response::html($this->renderHtml($hostname, $refresh));
	}

	private function renderHtml(string $hostname, int $refreshSeconds): string
	{
		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<title>Shipard Dev Dashboard</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: system-ui, -apple-system, sans-serif;
			background: #f3f4f6;
			color: #111827;
		}
		.banner {
			background: #d97706;
			color: white;
			padding: 8px 16px;
			text-align: center;
			font-weight: 600;
			font-size: 14px;
		}
		header.app {
			background: #1f2937;
			color: white;
			padding: 16px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		header.app h1 { margin: 0; font-size: 18px; font-weight: 600; }
		header.app .host { opacity: 0.8; font-size: 14px; }
		header.app .host code { font-family: monospace; }
		main {
			max-width: 1200px;
			margin: 24px auto;
			padding: 0 16px;
		}
		.toolbar {
			display: flex;
			align-items: center;
			gap: 16px;
			margin-bottom: 12px;
		}
		.toolbar button {
			padding: 6px 14px;
			font-size: 14px;
			cursor: pointer;
		}
		.toolbar .countdown {
			color: #6b7280;
			font-size: 13px;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			background: white;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
		}
		th, td {
			padding: 10px 12px;
			text-align: left;
			border-bottom: 1px solid #e5e7eb;
			font-size: 14px;
		}
		th {
			background: #f9fafb;
			font-weight: 600;
			color: #374151;
		}
		tbody tr:nth-child(even) { background: #fafbfc; }
		tbody tr:hover { background: #f1f5f9; }
		td.mono { font-family: monospace; font-size: 13px; }
		.copy-btn {
			background: none;
			border: none;
			cursor: pointer;
			margin-left: 4px;
			opacity: 0.6;
			font-size: 14px;
		}
		.copy-btn:hover { opacity: 1; }
		.open-btn {
			display: inline-block;
			padding: 4px 12px;
			background: #2563eb;
			color: white;
			text-decoration: none;
			border-radius: 4px;
			font-size: 13px;
		}
		.open-btn:hover { background: #1d4ed8; }
		.empty, .error {
			text-align: center;
			color: #6b7280;
			padding: 24px !important;
		}
		.error { color: #b91c1c; }
		</style>
		</head>
		<body>
		<div class="banner">⚠️  DEVELOPMENT MODE — do not deploy publicly</div>
		<header class="app">
			<h1>Shipard Dev Dashboard</h1>
			<div class="host">Server: <code>{$hostname}</code></div>
		</header>
		<main>
			<div class="toolbar">
				<button id="refreshBtn" type="button">Refresh</button>
				<span class="countdown">Auto-refresh in <span id="countdown">{$refreshSeconds}</span>s</span>
			</div>
			<table>
				<thead>
					<tr>
						<th>ID</th>
						<th>Name</th>
						<th>Created</th>
						<th>Database</th>
						<th></th>
					</tr>
				</thead>
				<tbody id="dsBody">
					<tr><td colspan="5" class="empty">Loading…</td></tr>
				</tbody>
			</table>
		</main>
		<script>
		(function () {
			var REFRESH_SECONDS = {$refreshSeconds};
			var countdownEl = document.getElementById('countdown');
			var bodyEl = document.getElementById('dsBody');
			var refreshBtn = document.getElementById('refreshBtn');
			var remaining = REFRESH_SECONDS;

			function setStatusRow(text, cls) {
				var tr = document.createElement('tr');
				var td = document.createElement('td');
				td.colSpan = 5;
				td.className = cls || 'empty';
				td.textContent = text;
				tr.appendChild(td);
				bodyEl.replaceChildren(tr);
			}

			function setEmptyRow() {
				var tr = document.createElement('tr');
				var td = document.createElement('td');
				td.colSpan = 5;
				td.className = 'empty';
				td.appendChild(document.createTextNode('No data sources found. Run '));
				var code = document.createElement('code');
				code.textContent = 'sudo shpd-server ds-create --name <n>';
				td.appendChild(code);
				tr.appendChild(td);
				bodyEl.replaceChildren(tr);
			}

			function makeCopyBtn(text) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'copy-btn';
				btn.title = 'Copy ID';
				btn.textContent = '📋';
				btn.addEventListener('click', function () {
					if (!navigator.clipboard) return;
					navigator.clipboard.writeText(text).then(function () {
						var prev = btn.textContent;
						btn.textContent = '✓';
						setTimeout(function () { btn.textContent = prev; }, 1000);
					});
				});
				return btn;
			}

			function renderRow(item) {
				var tr = document.createElement('tr');

				var tdId = document.createElement('td');
				tdId.className = 'mono';
				tdId.appendChild(document.createTextNode(item.id));
				tdId.appendChild(makeCopyBtn(item.id));
				tr.appendChild(tdId);

				var tdName = document.createElement('td');
				tdName.textContent = item.name || '';
				tr.appendChild(tdName);

				var tdCreated = document.createElement('td');
				tdCreated.textContent = item.created || '—';
				tr.appendChild(tdCreated);

				var tdDb = document.createElement('td');
				tdDb.className = 'mono';
				tdDb.textContent = item.database_name || '—';
				tr.appendChild(tdDb);

				var tdAction = document.createElement('td');
				var a = document.createElement('a');
				a.className = 'open-btn';
				a.href = '/' + item.id + '/app/';
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'Open';
				tdAction.appendChild(a);
				tr.appendChild(tdAction);

				return tr;
			}

			function loadData() {
				fetch('/_dev/api/data-sources', { headers: { 'Accept': 'application/json' } })
					.then(function (r) {
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					})
					.then(function (json) {
						var items = (json && json.data) || [];
						if (!items.length) {
							setEmptyRow();
							return;
						}
						var frag = document.createDocumentFragment();
						items.forEach(function (it) { frag.appendChild(renderRow(it)); });
						bodyEl.replaceChildren(frag);
					})
					.catch(function (e) {
						console.error(e);
						setStatusRow('Failed to load data sources', 'error');
					});
			}

			function tick() {
				remaining -= 1;
				if (remaining <= 0) {
					remaining = REFRESH_SECONDS;
					loadData();
				}
				countdownEl.textContent = remaining;
			}

			refreshBtn.addEventListener('click', function () {
				remaining = REFRESH_SECONDS;
				countdownEl.textContent = remaining;
				loadData();
			});

			loadData();
			setInterval(tick, 1000);
		})();
		</script>
		</body>
		</html>
		HTML;
	}
}
