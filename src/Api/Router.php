<?php
declare(strict_types=1);

namespace Shipard\Api;

class Router
{
	private const string PREFIX = '/api/v1';

	public function resolve(string $path, string $method): Route|Response
	{

		if (!str_starts_with($path, self::PREFIX)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		$subpath = substr($path, strlen(self::PREFIX));

		// Exact special endpoints
		if ($subpath === '/_openapi.json') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('openapi', 'spec');
		}

		if ($subpath === '/_meta/tables') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('meta', 'tables');
		}

		if (str_starts_with($subpath, '/_meta/tables/')) {
			$table = substr($subpath, strlen('/_meta/tables/'));
			if ($table === '' || str_contains($table, '/')) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('meta', 'table', $table);
		}

		if ($subpath === '/_ui/navigation') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('ui', 'navigation');
		}

		if ($subpath === '/_ui/dashboard') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('dashboard', 'index');
		}

		// GET /_ui/dashboard/summary — generované AI shrnutí feedu (SSE)
		if ($subpath === '/_ui/dashboard/summary') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('dashboard', 'summary');
		}

		if ($subpath === '/_ui/settings/navigation') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('settings', 'navigation');
		}

		// Nastavení účtu — vlastní navigační strom. Sdílí controller `settings`
		// (page/savePage account stránek jde přes /_ui/settings/page/{id},
		// scope řeší definice), jen jiná action pro strom.
		if ($subpath === '/_ui/account/navigation') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('settings', 'accountNavigation');
		}

		if (str_starts_with($subpath, '/_ui/settings/page/')) {
			$pageId = substr($subpath, strlen('/_ui/settings/page/'));
			if ($pageId === '' || str_contains($pageId, '/')) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			// pageId jede ve slotu `table` (Route nemá dedikovaný string slot).
			return match ($method) {
				'GET'   => new Route('settings', 'page', $pageId),
				'POST'  => new Route('settings', 'savePage', $pageId),
				default => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		if ($subpath === '/_app/info') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('app', 'info');
		}

		if ($subpath === '/_app/avatar') {
			// Avatar nenese slot v URL — uživatel se bere z tokenu (AuthContext).
			// Všechny metody vyžadují auth (na rozdíl od brandingGet, který je
			// exempt) — avatar je per-uživatel a není veřejný.
			return match ($method) {
				'GET'    => new Route('app', 'avatarGet'),
				'POST'   => new Route('app', 'avatarUpload'),
				'DELETE' => new Route('app', 'avatarDelete'),
				default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		if (str_starts_with($subpath, '/_app/branding/')) {
			$slot = substr($subpath, strlen('/_app/branding/'));
			if ($slot === '' || str_contains($slot, '/')) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			// Akce per metoda — GET je veřejný (AuthMiddleware::isExempt
			// matchuje (controller, action)), POST/DELETE vyžadují auth.
			return match ($method) {
				'GET'    => new Route('app', 'brandingGet', $slot),
				'POST'   => new Route('app', 'brandingUpload', $slot),
				'DELETE' => new Route('app', 'brandingDelete', $slot),
				default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		if (str_starts_with($subpath, '/_ui/viewer/')) {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return $this->resolveViewerRoute($subpath);
		}

		if (str_starts_with($subpath, '/_ui/form/')) {
			return $this->resolveFormRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_ui/lookup/')) {
			return $this->resolveLookupRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_attachments')) {
			return $this->resolveAttachmentRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_chat/conversations')) {
			return $this->resolveChatRoute($subpath, $method);
		}

		if ($subpath === '/_mail/incoming') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('mail', 'receiveIncoming');
		}

		if ($subpath === '/_mail/import') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('mail', 'importMessage');
		}

		if (str_starts_with($subpath, '/_mail/analysis')) {
			return $this->resolveAnalysisRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_mail/messages/')) {
			return $this->resolveMailMessagesRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_mail/extracted-documents/')) {
			return $this->resolveExtractedDocumentsRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_mail/senders/')) {
			return $this->resolveMailSendersRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_mail/sender-rules/')) {
			return $this->resolveMailSenderRulesRoute($subpath, $method);
		}

		if ($subpath === '/_mail/auto-archive/undo') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('senderRules', 'undoAutoArchive');
		}

		// Portál hostingu (D10) — funguje jen s aktivním modulem hosting.core,
		// gating na chybějící tabulku dělá kontroler (router je module-unaware).
		if ($subpath === '/_hosting/portal/my-datasources') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('hostingPortal', 'myDatasources');
		}

		if (str_starts_with($subpath, '/_registry/')) {
			return $this->resolveRegistryRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_exchange/docs/document/')) {
			return $this->resolveExchangeRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_exchange/persons/person/')) {
			return $this->resolvePersonsExchangeRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_exchange/items/item/')) {
			return $this->resolveItemsExchangeRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_exchange/bank/statement/')) {
			return $this->resolveBankExchangeRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_alerts')) {
			return $this->resolveAlertsRoute($subpath, $method);
		}

		if (str_starts_with($subpath, '/_accbal')) {
			return $this->resolveAccbalRoute($subpath, $method);
		}

		// POST /_accounting/reaccount — přeúčtování dokladu ve stavu 40
		if ($subpath === '/_accounting/reaccount') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('accounting', 'reaccount');
		}

		// POST /_bank/import-statement — import bankovního výpisu (multipart)
		if ($subpath === '/_bank/import-statement') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('bank', 'importStatement');
		}

		// POST /_bank/reaccount — přeúčtování transakce ve stavu 40
		if ($subpath === '/_bank/reaccount') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('bank', 'reaccount');
		}

		if ($subpath === '/_mcp') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('mcp', 'rpc');
		}

		if ($subpath === '/persons/registry' || str_starts_with($subpath, '/persons/registry/')) {
			return $this->resolvePersonsRegistryRoute($subpath, $method);
		}

		if ($subpath === '/_auth/login') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'login');
		}

		if ($subpath === '/_auth/refresh') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'refresh');
		}

		if ($subpath === '/_auth/logout') {
			if ($method !== 'DELETE') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'logout');
		}

		if ($subpath === '/_auth/oidc/start') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'oidcStart');
		}

		if ($subpath === '/_auth/oidc/callback') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'oidcCallback');
		}

		if ($subpath === '/_auth/oidc/exchange') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'oidcExchange');
		}

		if ($subpath === '/_auth/password/forgot') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'forgot');
		}

		if ($subpath === '/_auth/password/reset') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'reset');
		}

		if ($subpath === '/_auth/password/change') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'change');
		}

		if ($subpath === '/_auth/sessions') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'sessions');
		}

		if ($subpath === '/_auth/sessions/revoke-others') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'sessionsRevokeOthers');
		}

		if (preg_match('#^/_auth/sessions/(\d+)$#', $subpath, $m) === 1) {
			if ($method !== 'DELETE') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'sessionDelete', null, (int) $m[1]);
		}

		if (preg_match('#^/_users/(\d+)/invite$#', $subpath, $m) === 1) {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('password', 'invite', null, (int) $m[1]);
		}

		// Generic CRUD: /api/v1/{table} or /api/v1/{table}/{id}
		if (!str_starts_with($subpath, '/')) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		$rest = substr($subpath, 1);
		$parts = explode('/', $rest, 3);

		if (count($parts) === 1 && $parts[0] !== '') {
			$table = $parts[0];
			return match ($method) {
				'GET'  => new Route('crud', 'list', $table),
				'POST' => new Route('crud', 'create', $table),
				default => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		if (count($parts) === 3 && $parts[0] !== '' && $parts[1] !== '' && $parts[2] !== '') {
			$table  = $parts[0];
			$rawId  = $parts[1];
			$action = $parts[2];

			if (!ctype_digit($rawId) || (int) $rawId <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}

			if ($action === 'doc-state-options') {
				if ($method !== 'GET') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('crud', 'docStateOptions', $table, (int) $rawId);
			}

			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
			$table = $parts[0];
			$rawId = $parts[1];

			if (!ctype_digit($rawId) || (int) $rawId <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}

			$id = (int) $rawId;
			return match ($method) {
				'GET'    => new Route('crud', 'show', $table, $id),
				'PUT'    => new Route('crud', 'update', $table, $id),
				'PATCH'  => new Route('crud', 'patch', $table, $id),
				'DELETE' => new Route('crud', 'delete', $table, $id),
				default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveAnalysisRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_mail/analysis'));

		// GET /_mail/analysis/queue
		if ($rest === '/queue') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('analysis', 'queue');
		}

		// /_mail/analysis/{ndx}/...
		if (preg_match('#^/(\d+)/(.+)$#', $rest, $m)) {
			$ndx = (int) $m[1];
			$tail = $m[2];
			if ($ndx <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}

			if ($tail === 'claim') {
				if ($method !== 'POST') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('analysis', 'claim', null, $ndx);
			}

			if ($tail === 'payload') {
				if ($method !== 'GET') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('analysis', 'payload', null, $ndx);
			}

			if ($tail === 'result') {
				if ($method !== 'POST') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('analysis', 'result', null, $ndx);
			}

			if ($tail === 'failed') {
				if ($method !== 'POST') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('analysis', 'failed', null, $ndx);
			}

			// /_mail/analysis/{ndx}/attachments/{att_ndx}/content
			if (preg_match('#^attachments/(\d+)/content$#', $tail, $am)) {
				$attNdx = (int) $am[1];
				if ($attNdx <= 0) {
					return Response::error('NOT_FOUND', 'Not found', 404);
				}
				if ($method !== 'GET') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('analysis', 'attachmentContent', null, $ndx, $attNdx);
			}
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveMailSendersRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_mail/senders/'));
		if (preg_match('#^(\d+)/password$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('mail', 'setSenderPassword', null, $id);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveMailMessagesRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_mail/messages/'));
		if (preg_match('#^(\d+)/reanalyze$#', $rest, $m)) {
			$ndx = (int) $m[1];
			if ($ndx <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('analysis', 'reanalyze', null, $ndx);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveRegistryRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_registry/'));
		if ($rest === 'import') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('registry', 'import');
		}

		if (preg_match('#^from-message/(\d+)$#', $rest, $m)) {
			$ndx = (int) $m[1];
			if ($ndx <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('registry', 'fromMessage', null, $ndx);
		}

		if (preg_match('#^documents/(\d+)/extract-text$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('registry', 'extractText', null, $id);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveExtractedDocumentsRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_mail/extracted-documents/'));
		if (preg_match('#^(\d+)/(apply|unapply|reject|preview)$#', $rest, $m)) {
			$ndx = (int) $m[1];
			$action = $m[2];
			if ($ndx <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			$controllerAction = match ($action) {
				'apply'   => 'applyExtracted',
				'unapply' => 'unapplyExtracted',
				'reject'  => 'rejectExtracted',
				'preview' => 'previewExtracted',
			};
			return new Route('analysis', $controllerAction, null, $ndx);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveMailSenderRulesRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_mail/sender-rules/'));
		if (preg_match('#^(\d+)/(confirm|reject)$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			$controllerAction = match ($m[2]) {
				'confirm' => 'confirmRule',
				'reject'  => 'rejectRule',
			};
			return new Route('senderRules', $controllerAction, null, $id);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveExchangeRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_exchange/docs/document/'));
		if (!in_array($rest, ['validate', 'preview', 'apply'], true)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		if ($method !== 'POST') {
			return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
		}
		return new Route('exchange', $rest);
	}

	/**
	 * Person flow routes share the `exchange` dispatcher with documents so
	 * authentication and controller wiring stay in one place. Action is
	 * prefixed with `person:` to disambiguate.
	 */
	private function resolvePersonsExchangeRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_exchange/persons/person/'));
		if (!in_array($rest, ['validate', 'preview', 'apply'], true)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		if ($method !== 'POST') {
			return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
		}
		return new Route('exchange', "person:{$rest}");
	}

	/**
	 * Item flow routes share the `exchange` dispatcher with documents and
	 * persons. Action prefixed with `item:` to disambiguate.
	 */
	private function resolveItemsExchangeRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_exchange/items/item/'));
		if (!in_array($rest, ['validate', 'preview', 'apply'], true)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		if ($method !== 'POST') {
			return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
		}
		return new Route('exchange', "item:{$rest}");
	}

	/**
	 * Bank statement flow sdílí `exchange` dispatcher; akce s prefixem
	 * `bank:` (vzor person/item).
	 */
	private function resolveBankExchangeRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_exchange/bank/statement/'));
		if (!in_array($rest, ['validate', 'preview', 'apply'], true)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		if ($method !== 'POST') {
			return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
		}
		return new Route('exchange', "bank:{$rest}");
	}

	private function resolveAlertsRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_alerts'));

		// GET /_alerts/registry
		if ($rest === '/registry') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('alerts', 'registry');
		}

		// POST /_alerts/run-due
		if ($rest === '/run-due') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('alerts', 'runDue');
		}

		// POST /_alerts/checks/{checkId}/run
		if (preg_match('#^/checks/([a-z][a-z0-9_.]*)/run$#', $rest, $m)) {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			// Použijeme `table` slot pro checkId (Route nemá dedikovaný string slot).
			return new Route('alerts', 'runCheck', $m[1]);
		}

		// POST /_alerts/alerts/{id}/{snooze|dismiss|unsnooze}
		if (preg_match('#^/alerts/(\d+)/(snooze|dismiss|unsnooze)$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('alerts', $m[2], null, $id);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	/**
	 * POST /_accbal/match — dávkové párování úhrad saldokonta. Destruktivní
	 * cesty matcheru (unmatch, rematch-partner) API záměrně nevystavuje.
	 */
	private function resolveAccbalRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_accbal'));

		if ($rest === '/match') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('accbal', 'match');
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolvePersonsRegistryRoute(string $subpath, string $method): Route|Response
	{
		// GET /persons/registry — search
		if ($subpath === '/persons/registry') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('personsRegistry', 'search');
		}

		$rest = substr($subpath, strlen('/persons/registry/'));

		// POST /persons/registry/import
		if ($rest === 'import') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('personsRegistry', 'import');
		}

		// GET /persons/registry/{country}/{companyId}
		if (preg_match('#^([a-zA-Z]{2})/([^/]+)$#', $rest, $m)) {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			$country   = strtolower($m[1]);
			$companyId = $m[2];
			// Pack (country, companyId) into the `table` slot — Route has no
			// dedicated multi-string slot. Dispatcher splits on the colon.
			return new Route('personsRegistry', 'fetchPerson', $country . ':' . $companyId);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveChatRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_chat/conversations'));

		// /_chat/conversations
		if ($rest === '' || $rest === '/') {
			return match ($method) {
				'GET'  => new Route('chat', 'list'),
				'POST' => new Route('chat', 'create'),
				default => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		// /_chat/conversations/{id}
		if (preg_match('#^/(\d+)$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			return match ($method) {
				'GET'    => new Route('chat', 'show', null, $id),
				'PATCH'  => new Route('chat', 'rename', null, $id),
				'DELETE' => new Route('chat', 'delete', null, $id),
				default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		// POST /_chat/conversations/{id}/messages — streamed chat turn (SSE)
		if (preg_match('#^/(\d+)/messages$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('chat', 'sendMessage', null, $id);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveAttachmentRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_attachments'));

		// GET /_attachments (list)
		if ($rest === '' || $rest === '/') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('attachment', 'list');
		}

		// POST /_attachments/upload
		if ($rest === '/upload') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('attachment', 'upload');
		}

		// Routes with {id}: /_attachments/{id}/...
		if (preg_match('#^/(\d+)(/(.+))?$#', $rest, $m)) {
			$id = (int) $m[1];
			if ($id <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			$action = $m[3] ?? '';

			// GET /_attachments/{id}/download
			if ($action === 'download') {
				if ($method !== 'GET') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('attachment', 'download', null, $id);
			}

			// GET /_attachments/{id}/thumbnail
			if ($action === 'thumbnail') {
				if ($method !== 'GET') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('attachment', 'thumbnail', null, $id);
			}

			// POST /_attachments/{id}/restore
			if ($action === 'restore') {
				if ($method !== 'POST') {
					return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
				}
				return new Route('attachment', 'restore', null, $id);
			}

			// PATCH /_attachments/{id}
			if ($action === '' && $method === 'PATCH') {
				return new Route('attachment', 'patch', null, $id);
			}

			// DELETE /_attachments/{id}
			if ($action === '' && $method === 'DELETE') {
				return new Route('attachment', 'delete', null, $id);
			}
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveFormRoute(string $subpath, string $method): Route|Response
	{

		$rest = substr($subpath, strlen('/_ui/form/'));
		$parts = explode('/', $rest);
		$count = count($parts);

		if ($count < 2 || $parts[0] === '') {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		$table = $parts[0];
		$action = $parts[1];

		// GET /_ui/form/{table}/meta
		if ($count === 2 && $action === 'meta') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('form', 'meta', $table);
		}

		// GET /_ui/form/{table}/meta/{id}
		if ($count === 3 && $action === 'meta') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			$rawId = $parts[2];
			if (!ctype_digit($rawId) || (int) $rawId <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			return new Route('form', 'meta', $table, (int) $rawId);
		}

		// POST /_ui/form/{table}/save
		if ($count === 2 && $action === 'save') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('form', 'save', $table);
		}

		// PUT /_ui/form/{table}/save/{id}
		if ($count === 3 && $action === 'save') {
			if ($method !== 'PUT') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			$rawId = $parts[2];
			if (!ctype_digit($rawId) || (int) $rawId <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			return new Route('form', 'save', $table, (int) $rawId);
		}

		// POST /_ui/form/{table}/recalculate
		if ($count === 2 && $action === 'recalculate') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('form', 'recalculate', $table);
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}

	private function resolveLookupRoute(string $subpath, string $method): Route|Response
	{
		$rest = substr($subpath, strlen('/_ui/lookup/'));
		$parts = explode('/', $rest);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		[$table, $action] = $parts;

		if ($action !== 'search' && $action !== 'resolve') {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		if ($method !== 'GET') {
			return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
		}

		return new Route('lookup', $action, $table);
	}

	private function resolveViewerRoute(string $subpath): Route|Response
	{
		// Strip prefix: "/_ui/viewer/" → remaining path
		$rest = substr($subpath, strlen('/_ui/viewer/'));

		// Find the last "/" to split viewerId from action segment
		$lastSlash = strrpos($rest, '/');
		if ($lastSlash === false || $lastSlash === 0) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		$tail = substr($rest, $lastSlash + 1);

		// /_ui/viewer/{viewerId}/detail/{id}
		// Check if this is a detail route: the segment before the last "/" ends with /detail
		$beforeTail = substr($rest, 0, $lastSlash);
		$detailSlash = strrpos($beforeTail, '/');

		if ($detailSlash !== false) {
			$segment = substr($beforeTail, $detailSlash + 1);
			if ($segment === 'detail') {
				$viewerId = substr($beforeTail, 0, $detailSlash);
				if ($viewerId === '' || !ctype_digit($tail) || (int) $tail <= 0) {
					return Response::error('NOT_FOUND', 'Not found', 404);
				}
				return new Route('viewer', 'detail', $viewerId, (int) $tail);
			}
		}

		// /_ui/viewer/{viewerId}/meta or /_ui/viewer/{viewerId}/rows
		$viewerId = substr($rest, 0, $lastSlash);
		$action = $tail;

		if ($viewerId === '' || !in_array($action, ['meta', 'rows'], true)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		return new Route('viewer', $action, $viewerId);
	}
}
