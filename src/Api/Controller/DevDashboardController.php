<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;

final class DevDashboardController
{
	public function __construct(
		private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
		private readonly ?string $logFilePath = null,
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

		if ($path === '/_dev/logs' || $path === '/_dev/logs/') {
			return $this->logsPage();
		}

		if ($path === '/_dev/api/logs' && $request->getMethod() === 'GET') {
			return $this->getLogs($request);
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

	private function getLogs(Request $request): Response
	{
		if ($this->logFilePath === null) {
			return Response::error(
				'LOG_NOT_CONFIGURED',
				'Log file path not configured',
				503,
			);
		}

		$limit = (int) ($request->getQueryParams()['limit'] ?? 200);
		$limit = max(1, min(2000, $limit));

		$logFile = $this->logFilePath;

		if (!is_file($logFile)) {
			return Response::success([
				'entries'   => [],
				'logFile'   => $logFile,
				'available' => false,
				'limit'     => $limit,
			]);
		}

		$tail     = new \Shipard\Core\Logging\LogTail($logFile);
		$rawLines = $tail->readLast($limit);

		$entries = [];
		foreach ($rawLines as $line) {
			$parsed = json_decode($line, true);
			// Validate only the minimal contract — `ts` and `level`. Anything else
			// may be missing (older entries, partial migration).
			if (is_array($parsed) && isset($parsed['ts'], $parsed['level'])) {
				$entries[] = $parsed;
			}
			// Invalid lines are silently skipped (typically a partial first line
			// from the chunked read when limit ~= total line count).
		}

		return Response::success([
			'entries'   => $entries,
			'logFile'   => $logFile,
			'available' => true,
			'limit'     => $limit,
		]);
	}

	private function logsPage(): Response
	{
		$hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');
		return Response::html($this->renderLogsHtml($hostname));
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
		.open-btn, .logs-btn {
			display: inline-block;
			padding: 4px 12px;
			color: white;
			text-decoration: none;
			border-radius: 4px;
			font-size: 13px;
		}
		.open-btn { background: #2563eb; }
		.open-btn:hover { background: #1d4ed8; }
		.logs-btn { background: #6b7280; margin-left: 4px; }
		.logs-btn:hover { background: #4b5563; }
		header.app .host a {
			color: #93c5fd;
			text-decoration: none;
			margin-left: 12px;
		}
		header.app .host a:hover { text-decoration: underline; }
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
			<div class="host">Server: <code>{$hostname}</code><a href="/_dev/logs/">View Logs &rarr;</a></div>
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

				var logs = document.createElement('a');
				logs.className = 'logs-btn';
				logs.href = '/_dev/logs/?ds=' + encodeURIComponent(item.id);
				logs.target = '_blank';
				logs.rel = 'noopener';
				logs.textContent = 'Logs';
				tdAction.appendChild(logs);

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

	private function renderLogsHtml(string $hostname): string
	{
		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<title>Shipard Logs</title>
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
		header.app .meta { opacity: 0.9; font-size: 14px; }
		header.app .meta code { font-family: monospace; }
		header.app .meta a { color: #93c5fd; text-decoration: none; margin-left: 12px; }
		header.app .meta a:hover { text-decoration: underline; }
		main { max-width: 1400px; margin: 16px auto; padding: 0 16px; }
		.toolbar {
			background: white;
			border-radius: 6px;
			padding: 12px 14px;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			margin-bottom: 12px;
			display: flex;
			flex-wrap: wrap;
			gap: 10px 16px;
			align-items: center;
		}
		.toolbar-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
		.toolbar-label { color: #6b7280; font-size: 13px; }
		.level-chip {
			display: inline-block;
			padding: 3px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
			cursor: pointer;
			border: 1px solid transparent;
			user-select: none;
		}
		.level-chip.lc-debug { background: #e5e7eb; color: #374151; }
		.level-chip.lc-info  { background: #dbeafe; color: #1e40af; }
		.level-chip.lc-warn  { background: #fef3c7; color: #92400e; }
		.level-chip.lc-error { background: #fee2e2; color: #991b1b; }
		.level-chip.off { opacity: 0.35; }
		.level-chip:hover { border-color: #9ca3af; }
		select, input[type=text] {
			padding: 4px 8px;
			border: 1px solid #d1d5db;
			border-radius: 4px;
			font-size: 13px;
			background: white;
		}
		input[type=text].search { width: 280px; }
		button.tb {
			padding: 5px 12px;
			border: 1px solid #d1d5db;
			background: white;
			border-radius: 4px;
			cursor: pointer;
			font-size: 13px;
		}
		button.tb:hover { background: #f3f4f6; }
		button.tb.primary { background: #2563eb; color: white; border-color: #2563eb; }
		button.tb.primary:hover { background: #1d4ed8; }
		.pause-btn { display: inline-flex; align-items: center; gap: 6px; }
		.pause-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
		.pause-dot.run { background: #16a34a; }
		.pause-dot.paused { background: #dc2626; }
		.countdown { color: #6b7280; font-size: 13px; }
		.status-line {
			padding: 6px 4px;
			color: #6b7280;
			font-size: 12px;
			font-family: monospace;
		}
		.entries {
			background: white;
			border-radius: 6px;
			box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			overflow: hidden;
		}
		.entry {
			border-bottom: 1px solid #f1f5f9;
			padding: 6px 12px;
			cursor: pointer;
			display: grid;
			grid-template-columns: 80px 70px 70px 220px 1fr;
			gap: 8px;
			align-items: center;
			font-size: 13px;
		}
		.entry:hover { background: #f9fafb; }
		.entry .ts { font-family: monospace; color: #475569; font-size: 12px; }
		.entry .lvl { text-align: left; }
		.entry .ds-chip {
			font-family: monospace;
			font-size: 11px;
			padding: 1px 6px;
			background: #eef2ff;
			color: #3730a3;
			border-radius: 4px;
			cursor: pointer;
			display: inline-block;
		}
		.entry .ds-chip:hover { text-decoration: underline; }
		.entry .ds-chip.empty { background: #f3f4f6; color: #6b7280; }
		.entry .request { font-family: monospace; font-size: 12px; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.entry .msg { color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.entry-detail {
			padding: 12px 16px 14px 16px;
			background: #f9fafb;
			border-left: 3px solid #d1d5db;
			border-bottom: 1px solid #f1f5f9;
			font-size: 13px;
		}
		.entry-detail.bd-debug { border-left-color: #9ca3af; }
		.entry-detail.bd-info  { border-left-color: #3b82f6; }
		.entry-detail.bd-warn  { border-left-color: #d97706; }
		.entry-detail.bd-error { border-left-color: #dc2626; }
		.entry-detail .det-head {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			margin-bottom: 8px;
		}
		.entry-detail .det-head h3 { margin: 0; font-size: 13px; color: #6b7280; font-weight: 600; }
		.entry-detail .close-btn {
			border: none;
			background: none;
			color: #6b7280;
			cursor: pointer;
			font-size: 18px;
			line-height: 1;
		}
		.entry-detail .field { margin: 4px 0; }
		.entry-detail .field .k { color: #6b7280; font-size: 12px; margin-right: 6px; }
		.entry-detail .field .v { font-family: monospace; font-size: 12px; }
		.entry-detail .field .v.msg-full { font-family: inherit; font-size: 14px; color: #111827; white-space: pre-wrap; }
		.entry-detail pre {
			background: #1f2937;
			color: #e5e7eb;
			padding: 10px 12px;
			border-radius: 4px;
			font-family: monospace;
			font-size: 12px;
			line-height: 1.45;
			overflow-x: auto;
			margin: 6px 0 0 0;
			white-space: pre;
		}
		.exc {
			background: white;
			border: 1px solid #e5e7eb;
			border-radius: 4px;
			padding: 10px 12px;
			margin-top: 6px;
		}
		.exc .exc-title { font-family: monospace; font-size: 13px; color: #991b1b; word-break: break-all; }
		.exc .exc-loc { font-family: monospace; font-size: 12px; color: #6b7280; margin-top: 2px; }
		.exc .toggle-frames {
			border: none;
			background: none;
			color: #2563eb;
			cursor: pointer;
			padding: 4px 0;
			font-size: 12px;
			margin-top: 4px;
		}
		.exc .caused-by { margin-top: 10px; padding-left: 12px; border-left: 2px solid #e5e7eb; color: #6b7280; font-size: 12px; }
		.empty-state, .error-state {
			padding: 24px;
			text-align: center;
			color: #6b7280;
		}
		.error-state { color: #b91c1c; }
		.empty-state code { font-family: monospace; }
		.load-older {
			margin: 12px 0 24px 0;
			text-align: center;
		}
		.load-older button {
			padding: 8px 18px;
			border: 1px solid #d1d5db;
			background: white;
			border-radius: 4px;
			cursor: pointer;
			font-size: 13px;
		}
		.load-older button:hover:not(:disabled) { background: #f3f4f6; }
		.load-older button:disabled { color: #9ca3af; cursor: default; }
		.lvl-badge {
			display: inline-block;
			padding: 1px 8px;
			border-radius: 4px;
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
		}
		.lvl-badge.lc-debug { background: #e5e7eb; color: #374151; }
		.lvl-badge.lc-info  { background: #dbeafe; color: #1e40af; }
		.lvl-badge.lc-warn  { background: #fef3c7; color: #92400e; }
		.lvl-badge.lc-error { background: #fee2e2; color: #991b1b; }
		.lvl-badge.clickable { cursor: pointer; }
		.lvl-badge.clickable:hover { outline: 1px solid currentColor; }
		</style>
		</head>
		<body>
		<div class="banner">⚠️  DEVELOPMENT MODE — do not deploy publicly</div>
		<header class="app">
			<h1>Shipard Logs</h1>
			<div class="meta">
				Server: <code>{$hostname}</code>
				<a href="/_dev/">&larr; Dashboard</a>
			</div>
		</header>
		<main>
			<div class="toolbar">
				<div class="toolbar-row">
					<span class="toolbar-label">Levels:</span>
					<span class="level-chip lc-debug" data-level="debug">DEBUG</span>
					<span class="level-chip lc-info"  data-level="info">INFO</span>
					<span class="level-chip lc-warn"  data-level="warn">WARN</span>
					<span class="level-chip lc-error" data-level="error">ERROR</span>
				</div>
				<div class="toolbar-row">
					<span class="toolbar-label">DS:</span>
					<select id="dsSelect"><option value="">All</option></select>
				</div>
				<div class="toolbar-row" style="flex: 1; min-width: 240px;">
					<span class="toolbar-label">Search:</span>
					<input type="text" class="search" id="searchInput" placeholder="msg or exception">
				</div>
				<div class="toolbar-row">
					<button type="button" class="tb primary" id="refreshBtn">Refresh</button>
					<button type="button" class="tb pause-btn" id="pauseBtn">
						<span class="pause-dot run" id="pauseDot"></span>
						<span id="pauseLabel">Pause</span>
					</button>
					<span class="countdown">Auto-refresh in <span id="countdown">5</span>s</span>
				</div>
			</div>
			<div class="status-line" id="statusLine">Loading…</div>
			<div class="entries" id="entriesEl">
				<div class="empty-state">Loading…</div>
			</div>
			<div class="load-older">
				<button type="button" id="loadOlderBtn">Load older (currently 200)</button>
			</div>
		</main>
		<script>
		(function () {
			var REFRESH_INTERVAL = 5;
			var DOCS_URL = 'https://github.com/shipard/shpd/blob/main/docs/logging.md';
			var DS_NULL = '__null__';

			var state = {
				limit: 200,
				levels: { debug: false, info: false, warn: true, error: true },
				ds: '',
				search: '',
				paused: false,
				expanded: new Set(),
				countdown: REFRESH_INTERVAL,
				entries: [],
				logFile: '',
				available: false,
				lastError: null,
			};

			var entriesEl   = document.getElementById('entriesEl');
			var statusEl    = document.getElementById('statusLine');
			var dsSelect    = document.getElementById('dsSelect');
			var searchInput = document.getElementById('searchInput');
			var refreshBtn  = document.getElementById('refreshBtn');
			var pauseBtn    = document.getElementById('pauseBtn');
			var pauseDot    = document.getElementById('pauseDot');
			var pauseLabel  = document.getElementById('pauseLabel');
			var countdownEl = document.getElementById('countdown');
			var loadOlderBtn = document.getElementById('loadOlderBtn');

			// Pre-fill DS filter from URL
			var urlDs = new URLSearchParams(location.search).get('ds');
			if (urlDs) state.ds = urlDs;

			function formatTimeCompact(iso) {
				var m = String(iso).match(/T(\d{2}:\d{2}:\d{2})/);
				return m ? m[1] : String(iso);
			}
			function formatTimeFull(iso) {
				return String(iso).replace('T', ' ');
			}

			function applyFilters(entries) {
				var q = state.search.trim().toLowerCase();
				return entries.filter(function (e) {
					if (!state.levels[e.level]) return false;
					if (state.ds === DS_NULL) {
						if (e.ds !== null && e.ds !== undefined) return false;
					} else if (state.ds) {
						if (e.ds !== state.ds) return false;
					}
					if (q) {
						var hay = ((e.msg || '') + ' ' + ((e.exception && e.exception.message) || '')).toLowerCase();
						if (hay.indexOf(q) === -1) return false;
					}
					return true;
				});
			}

			function rebuildDsSelect() {
				var seen = new Map();
				var hasNull = false;
				state.entries.forEach(function (e) {
					if (e.ds === null || e.ds === undefined) hasNull = true;
					else seen.set(e.ds, true);
				});
				var ids = Array.from(seen.keys()).sort();
				var prev = state.ds;

				while (dsSelect.firstChild) dsSelect.removeChild(dsSelect.firstChild);

				var optAll = document.createElement('option');
				optAll.value = '';
				optAll.textContent = 'All';
				dsSelect.appendChild(optAll);

				if (hasNull) {
					var optNull = document.createElement('option');
					optNull.value = DS_NULL;
					optNull.textContent = '(none)';
					dsSelect.appendChild(optNull);
				}

				ids.forEach(function (id) {
					var o = document.createElement('option');
					o.value = id;
					o.textContent = id;
					dsSelect.appendChild(o);
				});

				// If current selection is not present in new options, keep it as
				// disabled placeholder so the filter still applies.
				if (prev && prev !== DS_NULL && !seen.has(prev)) {
					var ph = document.createElement('option');
					ph.value = prev;
					ph.textContent = prev + ' (no entries)';
					dsSelect.appendChild(ph);
				}

				dsSelect.value = prev;
			}

			function renderLevelChips() {
				document.querySelectorAll('.level-chip').forEach(function (chip) {
					var lvl = chip.getAttribute('data-level');
					if (state.levels[lvl]) chip.classList.remove('off');
					else chip.classList.add('off');
				});
			}

			function updatePauseButton() {
				if (state.paused) {
					pauseDot.classList.remove('run');
					pauseDot.classList.add('paused');
					pauseLabel.textContent = 'Resume';
				} else {
					pauseDot.classList.add('run');
					pauseDot.classList.remove('paused');
					pauseLabel.textContent = 'Pause';
				}
			}

			function updateLoadOlder() {
				if (state.limit >= 2000) {
					loadOlderBtn.textContent = 'Showing maximum 2000 entries — use grep for older';
					loadOlderBtn.disabled = true;
				} else {
					loadOlderBtn.textContent = 'Load older (currently ' + state.limit + ')';
					loadOlderBtn.disabled = false;
				}
			}

			function updateStatusLine(filteredCount) {
				if (state.lastError) {
					statusEl.textContent = state.lastError;
					return;
				}
				var parts = [];
				parts.push('log: ' + (state.logFile || '(unknown)'));
				if (!state.available) parts.push('not available');
				else parts.push('shown: ' + filteredCount + ' / ' + state.entries.length);
				statusEl.textContent = parts.join('  •  ');
			}

			function setExpanded(idx, expand) {
				if (expand) state.expanded.add(idx);
				else state.expanded.delete(idx);
				state.paused = state.expanded.size > 0;
				updatePauseButton();
				renderEntries();
			}

			function makeDsChip(ds) {
				var span = document.createElement('span');
				span.className = 'ds-chip';
				if (ds === null || ds === undefined) {
					span.classList.add('empty');
					span.textContent = '(none)';
					span.title = 'No data source';
					span.addEventListener('click', function (ev) {
						ev.stopPropagation();
						state.ds = DS_NULL;
						dsSelect.value = DS_NULL;
						renderEntries();
					});
				} else {
					var compact = ds.length > 5 ? ds.substring(0, 4) + '…' : ds;
					span.textContent = compact;
					span.title = ds;
					span.addEventListener('click', function (ev) {
						ev.stopPropagation();
						state.ds = ds;
						dsSelect.value = ds;
						renderEntries();
					});
				}
				return span;
			}

			function makeLevelBadge(level, clickable) {
				var span = document.createElement('span');
				span.className = 'lvl-badge lc-' + level;
				if (clickable) span.classList.add('clickable');
				span.textContent = level;
				if (clickable) {
					span.addEventListener('click', function (ev) {
						ev.stopPropagation();
						// When user clicks a level badge in a row, filter to that
						// level only (turn off others). Familiar pattern.
						Object.keys(state.levels).forEach(function (k) {
							state.levels[k] = (k === level);
						});
						renderLevelChips();
						renderEntries();
					});
				}
				return span;
			}

			function renderCompactEntry(e, displayIndex) {
				var row = document.createElement('div');
				row.className = 'entry';
				row.addEventListener('click', function () {
					setExpanded(displayIndex, !state.expanded.has(displayIndex));
				});

				var ts = document.createElement('span');
				ts.className = 'ts';
				ts.textContent = formatTimeCompact(e.ts);
				row.appendChild(ts);

				var lvl = document.createElement('span');
				lvl.className = 'lvl';
				lvl.appendChild(makeLevelBadge(e.level, true));
				row.appendChild(lvl);

				var dsCell = document.createElement('span');
				dsCell.appendChild(makeDsChip(e.ds === undefined ? null : e.ds));
				row.appendChild(dsCell);

				var req = document.createElement('span');
				req.className = 'request';
				req.textContent = e.request || '';
				req.title = e.request || '';
				row.appendChild(req);

				var msg = document.createElement('span');
				msg.className = 'msg';
				var msgText = e.msg || '';
				if (e.exception && e.exception.message) {
					msgText = msgText + (msgText ? ' — ' : '') + e.exception.class + ': ' + e.exception.message;
				}
				msg.textContent = msgText;
				msg.title = msgText;
				row.appendChild(msg);

				return row;
			}

			function renderException(exc, depth) {
				var box = document.createElement('div');
				box.className = 'exc';

				if (depth > 0) {
					var cb = document.createElement('div');
					cb.className = 'caused-by';
					cb.textContent = 'Caused by:';
					box.appendChild(cb);
				}

				var title = document.createElement('div');
				title.className = 'exc-title';
				title.textContent = (exc['class'] || 'Exception') + ': ' + (exc.message || '');
				box.appendChild(title);

				if (exc.at) {
					var loc = document.createElement('div');
					loc.className = 'exc-loc';
					loc.textContent = 'at ' + exc.at;
					box.appendChild(loc);
				}

				if (Array.isArray(exc.trace) && exc.trace.length > 0) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'toggle-frames';
					btn.textContent = 'Show ' + exc.trace.length + ' frames ▾';
					box.appendChild(btn);

					var pre = document.createElement('pre');
					pre.style.display = 'none';
					pre.textContent = exc.trace.map(function (f, i) {
						return '#' + i + ' ' + f;
					}).join('\\n');
					box.appendChild(pre);

					btn.addEventListener('click', function (ev) {
						ev.stopPropagation();
						if (pre.style.display === 'none') {
							pre.style.display = 'block';
							btn.textContent = 'Hide trace ▴';
						} else {
							pre.style.display = 'none';
							btn.textContent = 'Show ' + exc.trace.length + ' frames ▾';
						}
					});
				}

				if (exc.previous) {
					box.appendChild(renderException(exc.previous, depth + 1));
				}

				return box;
			}

			function renderExpandedDetail(e, displayIndex) {
				var det = document.createElement('div');
				det.className = 'entry-detail bd-' + e.level;
				det.addEventListener('click', function (ev) { ev.stopPropagation(); });

				var head = document.createElement('div');
				head.className = 'det-head';
				var h3 = document.createElement('h3');
				h3.textContent = formatTimeFull(e.ts) + '  •  ' + e.level.toUpperCase();
				head.appendChild(h3);
				var close = document.createElement('button');
				close.type = 'button';
				close.className = 'close-btn';
				close.textContent = '×';
				close.title = 'Collapse';
				close.addEventListener('click', function () { setExpanded(displayIndex, false); });
				head.appendChild(close);
				det.appendChild(head);

				if (e.msg) {
					var f = document.createElement('div');
					f.className = 'field';
					var v = document.createElement('div');
					v.className = 'v msg-full';
					v.textContent = e.msg;
					f.appendChild(v);
					det.appendChild(f);
				}

				function appendField(label, value) {
					var f = document.createElement('div');
					f.className = 'field';
					var k = document.createElement('span');
					k.className = 'k';
					k.textContent = label + ':';
					var v = document.createElement('span');
					v.className = 'v';
					v.textContent = value;
					f.appendChild(k);
					f.appendChild(v);
					det.appendChild(f);
				}

				appendField('Request', e.request || '(none)');
				appendField('DS', (e.ds === null || e.ds === undefined) ? '(none)' : e.ds);

				if (e.ctx && typeof e.ctx === 'object' && Object.keys(e.ctx).length > 0) {
					var k = document.createElement('div');
					k.className = 'field';
					var lbl = document.createElement('span');
					lbl.className = 'k';
					lbl.textContent = 'Context:';
					k.appendChild(lbl);
					det.appendChild(k);
					var pre = document.createElement('pre');
					pre.textContent = JSON.stringify(e.ctx, null, 2);
					det.appendChild(pre);
				}

				if (e.exception) {
					var lbl2 = document.createElement('div');
					lbl2.className = 'field';
					var k2 = document.createElement('span');
					k2.className = 'k';
					k2.textContent = 'Exception:';
					lbl2.appendChild(k2);
					det.appendChild(lbl2);
					det.appendChild(renderException(e.exception, 0));
				}

				return det;
			}

			function renderEntries() {
				while (entriesEl.firstChild) entriesEl.removeChild(entriesEl.firstChild);

				if (state.lastError) {
					var err = document.createElement('div');
					err.className = 'error-state';
					err.textContent = 'Failed to load logs. Check console.';
					entriesEl.appendChild(err);
					updateStatusLine(0);
					return;
				}

				if (!state.available) {
					var es = document.createElement('div');
					es.className = 'empty-state';
					es.appendChild(document.createTextNode('Log file does not exist yet at '));
					var c = document.createElement('code');
					c.textContent = state.logFile;
					es.appendChild(c);
					es.appendChild(document.createTextNode('. See '));
					var a = document.createElement('a');
					a.href = DOCS_URL;
					a.target = '_blank';
					a.rel = 'noopener';
					a.textContent = 'docs/logging.md';
					es.appendChild(a);
					es.appendChild(document.createTextNode(' for setup.'));
					entriesEl.appendChild(es);
					updateStatusLine(0);
					return;
				}

				if (state.entries.length === 0) {
					var es2 = document.createElement('div');
					es2.className = 'empty-state';
					es2.textContent = 'No log entries yet.';
					entriesEl.appendChild(es2);
					updateStatusLine(0);
					return;
				}

				// Newest first.
				var ordered = state.entries.slice().reverse();
				var filtered = applyFilters(ordered);

				if (filtered.length === 0) {
					var es3 = document.createElement('div');
					es3.className = 'empty-state';
					es3.textContent = 'No entries match current filters.';
					entriesEl.appendChild(es3);
					updateStatusLine(0);
					return;
				}

				var frag = document.createDocumentFragment();
				filtered.forEach(function (e, idx) {
					frag.appendChild(renderCompactEntry(e, idx));
					if (state.expanded.has(idx)) {
						frag.appendChild(renderExpandedDetail(e, idx));
					}
				});
				entriesEl.appendChild(frag);

				updateStatusLine(filtered.length);
			}

			function loadEntries() {
				var url = '/_dev/api/logs?limit=' + state.limit;
				fetch(url, { headers: { 'Accept': 'application/json' } })
					.then(function (r) {
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					})
					.then(function (json) {
						if (!json || !json.success || !json.data) {
							throw new Error('Bad response shape');
						}
						state.entries = json.data.entries || [];
						state.logFile = json.data.logFile || '';
						state.available = !!json.data.available;
						state.lastError = null;
						state.expanded.clear();
						rebuildDsSelect();
						renderEntries();
					})
					.catch(function (err) {
						console.error(err);
						state.lastError = 'Failed to load logs: ' + err.message;
						renderEntries();
					});
			}

			function tick() {
				if (state.paused) return;
				state.countdown -= 1;
				countdownEl.textContent = Math.max(0, state.countdown);
				if (state.countdown <= 0) {
					loadEntries();
					state.countdown = REFRESH_INTERVAL;
					countdownEl.textContent = state.countdown;
				}
			}

			// Wire up controls
			document.querySelectorAll('.level-chip').forEach(function (chip) {
				chip.addEventListener('click', function () {
					var lvl = chip.getAttribute('data-level');
					state.levels[lvl] = !state.levels[lvl];
					renderLevelChips();
					renderEntries();
				});
			});
			renderLevelChips();

			dsSelect.addEventListener('change', function () {
				state.ds = dsSelect.value;
				renderEntries();
			});

			searchInput.addEventListener('input', function () {
				state.search = searchInput.value;
				renderEntries();
			});

			refreshBtn.addEventListener('click', function () {
				state.countdown = REFRESH_INTERVAL;
				countdownEl.textContent = state.countdown;
				loadEntries();
			});

			pauseBtn.addEventListener('click', function () {
				if (state.expanded.size > 0 && state.paused) {
					// User wants to resume but has expansions — collapse them so
					// the next refresh doesn't replace content under their cursor.
					state.expanded.clear();
				}
				state.paused = !state.paused;
				if (!state.paused) {
					state.countdown = REFRESH_INTERVAL;
					countdownEl.textContent = state.countdown;
				}
				updatePauseButton();
				renderEntries();
			});

			loadOlderBtn.addEventListener('click', function () {
				if (state.limit >= 2000) return;
				state.limit = Math.min(2000, state.limit * 2);
				updateLoadOlder();
				loadEntries();
			});

			updatePauseButton();
			updateLoadOlder();
			loadEntries();
			setInterval(tick, 1000);
		})();
		</script>
		</body>
		</html>
		HTML;
	}
}
