<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Shipard\Api\AlertCheckLoader;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AlertsController;
use Shipard\Api\Controller\AuthController;
use Shipard\Api\Controller\CrudController;
use Shipard\Api\Controller\DashboardController;
use Shipard\Api\Controller\ExchangeController;
use Shipard\Api\Controller\MetaController;
use Shipard\Api\Controller\NavigationController;
use Shipard\Api\Controller\SettingsController;
use Shipard\Api\Controller\OpenApiController;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Controller\AttachmentController;
use Shipard\Api\Controller\ChatController;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Api\Controller\MailController;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Controller\PersonsRegistryController;
use Shipard\Api\DataSourceResolver;
use Shipard\Api\DocumentLoader;
use Shipard\Api\FormLoader;
use Shipard\Api\LookupLoader;
use Shipard\Api\Exception\UnknownDataSourceException;
use Shipard\Api\Exception\UnknownHostException;
use Shipard\Api\Middleware\AuthMiddleware;
use Shipard\Api\Middleware\CorsMiddleware;
use Shipard\Api\Middleware\RateLimitMiddleware;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;
use Shipard\Api\TableLoader;
use Shipard\Api\ViewerLoader;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Viewer\ViewerRegistry;

$request        = Request::fromGlobals();

// Set request context immediately so even early errors carry it
ErrorLogger::setRequestContext($request->getMethod() . ' ' . $request->getPath());

$corsMiddleware = new CorsMiddleware();

// ── 1. CORS preflight ────────────────────────────────────────────────────────
$corsResult = $corsMiddleware->handle($request);
if ($corsResult !== null) {
	$corsResult->send();
	exit;
}

$serverConfig = null;

try {
	// ── 2. Server config ─────────────────────────────────────────────────────
	$serverConfig = new ServerConfig();
	$serverConfig->load();

	// Configure logger from server config — must happen as early as possible
	ErrorLogger::setLogPath($serverConfig->getLogFile());
	ErrorLogger::setLogLevel($serverConfig->getLogLevel());

	// ── 2a. Module path resolver + class autoloader ──────────────────────────
	$modulePathResolver = ModulePathResolver::fromServerConfig(
		$serverConfig, dirname(__DIR__) . '/modules',
	);
	ModuleClassLoader::register($modulePathResolver);

	// ── 2.5. Dev dashboard ───────────────────────────────────────────────────
	if ($serverConfig->getMode() === 'development') {
		$path = $request->getPath();
		if ($path === '/' || $path === '/_dev' || str_starts_with($path, '/_dev/')) {
			$response = (new \Shipard\Api\Controller\DevDashboardController(
				'/opt/shipard/data-sources',
				$serverConfig->getLogFile(),
				$modulePathResolver,
			))->dispatch($request);
			$corsMiddleware->applyTo($response)->send();
			exit;
		}
	}

	// ── 3. Resolve data source ────────────────────────────────────────────────
	$resolver = new DataSourceResolver($serverConfig->getDomainsFile());
	$resolved = $resolver->resolve($request->getHost(), $request->getPath());

	// DS is now known — propagate to logger
	ErrorLogger::setDsId($resolved->config->getId());

	// ── 4. Load table definitions (localized) ─────────────────────────────────
	$language = resolveLanguage($request, $resolved->config);
	$tables   = TableLoader::load($resolved->config, $modulePathResolver, $language);

	// ── 4b. Build viewer registry ─────────────────────────────────────────────
	$viewerRegistry = ViewerLoader::load($resolved->config, $modulePathResolver, $language);

	// ── 4c. Build form registry ───────────────────────────────────────────────
	$formRegistry = FormLoader::load($resolved->config, $modulePathResolver);

	// ── 4c2. Build lookup registry ───────────────────────────────────────────
	$lookupRegistry = LookupLoader::load($resolved->config, $modulePathResolver);

	// ── 4d. Build document registry ───────────────────────────────────────────
	$documentRegistry = DocumentLoader::load($resolved->config, $modulePathResolver);

	// ── 4e. Build alert check registry ────────────────────────────────────────
	$alertCheckRegistry = AlertCheckLoader::load($resolved->config, $modulePathResolver, $language);

	// ── 5. Route ──────────────────────────────────────────────────────────────
	$router      = new Router();
	$routeResult = $router->resolve($resolved->normalizedPath, $request->getMethod());
	if ($routeResult instanceof Response) {
		$corsMiddleware->applyTo($routeResult)->send();
		exit;
	}
	/** @var Route $route */
	$route = $routeResult;

	// ── 6. Auth ───────────────────────────────────────────────────────────────
	$openApiPublic  = (bool) ($resolved->config->getModules()['api']['openApiPublic'] ?? true);
	$authMiddleware = new AuthMiddleware();
	$authResult     = $authMiddleware->handle($request, $route, $resolved->connection, $openApiPublic);
	if ($authResult instanceof Response) {
		$corsMiddleware->applyTo($authResult)->send();
		exit;
	}
	/** @var AuthContext $auth */
	$auth = $authResult;

	// ── 7. Rate limiting ──────────────────────────────────────────────────────
	$rateLimiter = new RateLimitMiddleware();
	$rateResult  = $rateLimiter->handle($request, $auth, $route, $resolved->connection);
	if ($rateResult instanceof Response) {
		applyAllHeaders($corsMiddleware, $rateLimiter, $rateResult)->send();
		exit;
	}

	// ── 8. Load compiled config (best-effort) ────────────────────────────────
	$configRuntime = null;
	try {
		$configRuntime = \Shipard\Core\Config\ConfigRuntime::load(
			$resolved->config->getDataSourceDir(),
			$language,
		);
	} catch (\Throwable) {
		// Config may not be compiled yet — doc state and enum features degrade gracefully
	}

	// ── 9. Dispatch to controller ─────────────────────────────────────────────
	$host     = $request->getHost();
	$response = dispatch(
		$route, $request, $auth, $tables,
		$resolved->connection, $openApiPublic,
		$host, $resolved, $modulePathResolver,
		$viewerRegistry, $configRuntime, $formRegistry, $documentRegistry,
		$lookupRegistry, $alertCheckRegistry, $serverConfig,
	);

	// ── 10. Apply headers and send ────────────────────────────────────────────
	applyAllHeaders($corsMiddleware, $rateLimiter, $response)->send();

} catch (UnknownDataSourceException $e) {
	$corsMiddleware->applyTo(
		Response::error('UNKNOWN_DATASOURCE', "Unknown data source: {$e->dsId}", 404),
	)->send();
} catch (UnknownHostException) {
	$corsMiddleware->applyTo(
		Response::error('UNKNOWN_HOST', 'Unknown host', 404),
	)->send();
} catch (\Throwable $e) {
	// Always log — request context and ds id were set on the logger earlier
	// (see bootstrap), so the JSON entry carries everything needed for triage.
	ErrorLogger::logException($e);

	$isDev = $serverConfig !== null && $serverConfig->getMode() === 'development';
	$details = $isDev
		? [
			[
				'field'   => '_exception',
				'code'    => get_class($e),
				'message' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
				// Trim trace to keep the response readable; full trace is in the log file
				'trace'   => array_slice(
					preg_split('/\r?\n/', $e->getTraceAsString()) ?: [],
					0,
					10,
				),
			],
		]
		: [];
	$corsMiddleware->applyTo(
		Response::error('INTERNAL_ERROR', 'Internal server error', 500, $details),
	)->send();
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function resolveLanguage(Request $request, ?\Shipard\Core\Config\DataSourceConfig $config = null): string
{
	$fallback = $config?->getDefaultLanguage() ?? 'en';

	$header = $request->getHeader('Accept-Language');
	if ($header === null) {
		return $fallback;
	}
	// Parse first language tag: "cs-CZ,cs;q=0.9,en;q=0.8" → "cs"
	$first = explode(',', $header)[0];
	$first = explode(';', $first)[0];
	$first = explode('-', trim($first))[0];
	return $first !== '' ? strtolower($first) : $fallback;
}

function applyAllHeaders(CorsMiddleware $cors, RateLimitMiddleware $rateLimit, Response $response): Response
{
	$response = $cors->applyTo($response);
	foreach ($rateLimit->getHeaders() as $name => $value) {
		$response = $response->withHeader($name, $value);
	}
	return $response;
}

function dispatch(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	bool $openApiPublic,
	string $host,
	\Shipard\Api\ResolvedDataSource $resolved,
	ModulePathResolver $modulePathResolver,
	ViewerRegistry $viewerRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?FormRegistry $formRegistry = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	?LookupRegistry $lookupRegistry = null,
	?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null,
	?ServerConfig $serverConfig = null,
): Response {
	$baseUrl = $resolved->isDevMode()
		? 'http://' . $host . '/' . $resolved->config->getId()
		: 'https://' . $host;

	return match ($route->controller) {
		'auth'    => dispatchAuth($route->action, $request, $auth, $db),
		'crud'       => dispatchCrud($route, $request, $tables, $db, $configRuntime),
		'attachment'  => dispatchAttachment($route, $request, $auth, $tables, $db, $resolved),
		'chat'    => dispatchChat($route, $request, $auth, $db, $tables, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry()),
		'meta'    => dispatchMeta($route->action, $route->table, $tables, resolveLanguage($request, $resolved->config)),
		'ui'      => dispatchUi($route->action, $resolved->config, $modulePathResolver, resolveLanguage($request, $resolved->config)),
		'dashboard' => dispatchDashboard($route, $db, $viewerRegistry, $configRuntime, resolveLanguage($request, $resolved->config)),
		'settings' => dispatchSettings($route->action, $resolved->config, $modulePathResolver, resolveLanguage($request, $resolved->config), $configRuntime),
		'form'    => dispatchForm($route, $request, $auth, $tables, $db, $formRegistry ?? new FormRegistry(), $configRuntime, $modulePathResolver, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), resolveLanguage($request, $resolved->config), $resolved->config, $lookupRegistry ?? new LookupRegistry()),
		'lookup'  => dispatchLookup($route, $request, $tables, $db, $lookupRegistry ?? new LookupRegistry(), $configRuntime),
		'viewer'  => dispatchViewer($route, $request, $viewerRegistry, $db, $configRuntime, resolveLanguage($request, $resolved->config)),
		'mail'    => dispatchMail($route, $request, $auth, $tables, $db, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $configRuntime),
		'analysis' => dispatchAnalysis($route, $request, $auth, $tables, $db, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry()),
		'exchange' => dispatchExchange($route, $request, $tables, $db, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry()),
		'alerts' => dispatchAlerts($route, $request, $db, $alertCheckRegistry, $configRuntime, resolveLanguage($request, $resolved->config)),
		'personsRegistry' => dispatchPersonsRegistry($route, $request, $tables, $db, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(), $serverConfig),
		'mcp'     => dispatchMcp($request, $auth, $resolved->connection, $tables, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry()),
		'openapi' => (new OpenApiController())->spec($auth, $openApiPublic, $tables, $baseUrl),
		default   => Response::error('INTERNAL_ERROR', "Unknown controller: {$route->controller}", 500),
	};
}

function dispatchMcp(
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
): Response {
	$registry = buildMcpRegistry($db, $tables, $configRuntime, $resolved, $documentRegistry);
	$ctrl = new \Shipard\Api\Controller\McpController($registry);
	return $ctrl->rpc($request, $auth, $db, $tables, $configRuntime);
}

/**
 * Builds the in-process MCP tool registry shared by /_mcp (dispatchMcp) and the
 * chat tool-use loop (dispatchChat). All five tools are registered; the chat
 * loop filters to read-only tools itself via McpTool::isReadOnly().
 *
 * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
 */
function buildMcpRegistry(
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
): \Shipard\Api\Mcp\McpToolRegistry {
	$registry = new \Shipard\Api\Mcp\McpToolRegistry();
	$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsSearchTool());
	$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsGetTool());
	$registry->register(new \Shipard\Module\Docs\Core\Mcp\DocumentsSearchTool());
	$registry->register(new \Shipard\Module\Core\Mail\Mcp\MailListPendingTool());

	// Zápisový nástroj mail_draft_document nad sdílenou apply službou.
	// DocumentApplier vyžaduje ConfigRuntime (jako dispatchExchange/Analysis);
	// bez něj injektujeme null → nástroj degraduje na graceful obálku.
	$draftApplier = $configRuntime !== null
		? new \Shipard\Module\Core\Mail\ExtractedDocumentApplier(
			$db,
			\Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
				$db->getDibiConnection(),
				$configRuntime,
				$resolved->config,
				$documentRegistry,
				$tables,
			),
		)
		: null;
	$registry->register(new \Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool($draftApplier));

	return $registry;
}

function dispatchAlerts(
	Route $route,
	Request $request,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Alerts\AlertCheckRegistry $registry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	string $language,
): Response {
	if ($registry === null) {
		return Response::error('INTERNAL_ERROR', 'AlertCheckRegistry is required for /_alerts endpoints', 500);
	}
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_alerts endpoints', 500);
	}

	$ctrl = new AlertsController($db, $registry, $configRuntime, $language);
	return match ($route->action) {
		'registry'  => $ctrl->registry(),
		'runDue'    => $ctrl->runDue(),
		'runCheck'  => $ctrl->runCheck($route->table ?? ''),
		'snooze'    => $ctrl->snooze((int) $route->id, $request),
		'dismiss'   => $ctrl->dismiss((int) $route->id),
		'unsnooze'  => $ctrl->unsnooze((int) $route->id),
		default     => Response::error('INTERNAL_ERROR', "Unknown alerts action: {$route->action}", 500),
	};
}

function dispatchExchange(
	Route $route,
	Request $request,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /_exchange endpoints', 500);
	}

	$applier = \Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
	);
	$personApplier = \Shipard\Module\Core\Exchange\Person\PersonApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
	);
	$itemApplier = \Shipard\Module\Core\Exchange\Item\ItemApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
		$personApplier,
	);
	$ctrl = new ExchangeController($applier, $personApplier, $itemApplier);

	return match ($route->action) {
		'validate'        => $ctrl->validate($request),
		'preview'         => $ctrl->preview($request),
		'apply'            => $ctrl->apply($request),
		'person:validate' => $ctrl->validatePerson($request),
		'person:preview'  => $ctrl->previewPerson($request),
		'person:apply'    => $ctrl->applyPerson($request),
		'item:validate'   => $ctrl->validateItem($request),
		'item:preview'    => $ctrl->previewItem($request),
		'item:apply'      => $ctrl->applyItem($request),
		default           => Response::error('INTERNAL_ERROR', "Unknown exchange action: {$route->action}", 500),
	};
}

function dispatchPersonsRegistry(
	Route $route,
	Request $request,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?ServerConfig $serverConfig,
): Response {
	if ($configRuntime === null) {
		return Response::error('INTERNAL_ERROR', 'ConfigRuntime is required for /persons/registry endpoints', 500);
	}
	if ($serverConfig === null) {
		return Response::error('INTERNAL_ERROR', 'ServerConfig is required for /persons/registry endpoints', 500);
	}

	$client = \Shipard\Module\Base\Persons\Registry\PersonsRegistryClient::fromServerConfig($serverConfig);

	$personApplier = \Shipard\Module\Core\Exchange\Person\PersonApplier::create(
		$db->getDibiConnection(),
		$configRuntime,
		$resolved->config,
		$documentRegistry,
		$tables,
	);
	$importer = new \Shipard\Module\Base\Persons\Registry\RegistryPersonImporter(
		$client, $personApplier,
	);

	$ctrl = new PersonsRegistryController($client, $importer, $db);

	return match ($route->action) {
		'search'      => $ctrl->search($request),
		'fetchPerson' => (function () use ($ctrl, $route): Response {
			$parts = explode(':', (string) $route->table, 2);
			if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			return $ctrl->fetchPerson($parts[0], $parts[1]);
		})(),
		'import'      => $ctrl->import($request),
		default       => Response::error('INTERNAL_ERROR', "Unknown personsRegistry action: {$route->action}", 500),
	};
}

function dispatchMail(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();
	$ctrl = new MailController($db, $dsPath, $tables, $documentRegistry, $configRuntime);
	return match ($route->action) {
		'receiveIncoming' => $ctrl->receiveIncoming($auth, $request),
		'importMessage'   => $ctrl->importMessage($auth, $request),
		default           => Response::error('INTERNAL_ERROR', "Unknown mail action: {$route->action}", 500),
	};
}

function dispatchAnalysis(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	\Shipard\Api\ResolvedDataSource $resolved,
	\Shipard\Core\Document\DocumentRegistry $documentRegistry,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();

	// Exchange wiring: SchemaValidator for /result canonical validation,
	// DocumentApplier for /applyExtracted. Both require ConfigRuntime; if
	// the compiled config is missing we degrade gracefully (controller
	// falls back to legacy behaviour). See Phase 2 spec.
	$schemaValidator = new \Shipard\Module\Core\Exchange\Schema\SchemaValidator(
		\Shipard\Module\Core\Exchange\Schema\SchemaLoader::default(),
	);
	$applier = $configRuntime !== null
		? \Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
			$db->getDibiConnection(),
			$configRuntime,
			$resolved->config,
			$documentRegistry,
			$tables,
		)
		: null;

	$ctrl = new AnalysisController(
		$db, $resolved->config, $dsPath, $tables, $documentRegistry,
		$schemaValidator, $applier,
	);
	return match ($route->action) {
		'queue'             => $ctrl->queue($auth, $request),
		'claim'             => $ctrl->claim($auth, $request, (int) $route->id),
		'payload'           => $ctrl->payload($auth, $request, (int) $route->id),
		'attachmentContent' => $ctrl->attachmentContent($auth, $request, (int) $route->id, (int) $route->secondaryId),
		'result'            => $ctrl->result($auth, $request, (int) $route->id),
		'failed'            => $ctrl->failed($auth, $request, (int) $route->id),
		'reanalyze'         => $ctrl->reanalyze($auth, $request, (int) $route->id),
		'applyExtracted'    => $ctrl->applyExtracted($auth, $request, (int) $route->id),
		'rejectExtracted'   => $ctrl->rejectExtracted($auth, $request, (int) $route->id),
		'previewExtracted'  => $ctrl->previewExtracted($auth, $request, (int) $route->id),
		default             => Response::error('INTERNAL_ERROR', "Unknown analysis action: {$route->action}", 500),
	};
}

function dispatchAuth(
	string $action,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
): Response {
	$ctrl = new AuthController();
	return match ($action) {
		'login'   => $ctrl->login($request, $db),
		'refresh' => $ctrl->refresh($request, $auth, $db),
		'logout'  => $ctrl->logout($request, $auth, $db),
		default   => Response::error('INTERNAL_ERROR', "Unknown auth action: {$action}", 500),
	};
}

function dispatchAttachment(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	\Shipard\Api\ResolvedDataSource $resolved,
): Response {
	$dsPath = $resolved->config->getDataSourceDir();
	$ctrl   = new AttachmentController($db, $dsPath, $tables);
	return match ($route->action) {
		'upload'    => $ctrl->upload($auth),
		'download'  => $ctrl->download((int) $route->id, $request),
		'thumbnail' => $ctrl->thumbnail((int) $route->id, $request),
		'list'      => $ctrl->list($request),
		'patch'     => $ctrl->patch((int) $route->id, $request),
		'delete'    => $ctrl->delete((int) $route->id),
		'restore'   => $ctrl->restore((int) $route->id),
		default     => Response::error('INTERNAL_ERROR', "Unknown attachment action: {$route->action}", 500),
	};
}

function dispatchChat(
	Route $route,
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables = [],
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
	?\Shipard\Api\ResolvedDataSource $resolved = null,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
): Response {
	$registry = $resolved !== null
		? buildMcpRegistry($db, $tables, $configRuntime, $resolved, $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry())
		: null;
	$ctrl = new ChatController($db, $configRuntime, $resolved?->config, new AnthropicLlmClient(), $tables, $registry);
	return match ($route->action) {
		'list'        => $ctrl->list($auth, $request),
		'create'      => $ctrl->create($auth, $request),
		'show'        => $ctrl->show($auth, (int) $route->id),
		'rename'      => $ctrl->rename($auth, (int) $route->id, $request),
		'delete'      => $ctrl->delete($auth, (int) $route->id),
		'sendMessage' => $ctrl->sendMessage($auth, (int) $route->id, $request),
		default       => Response::error('INTERNAL_ERROR', "Unknown chat action: {$route->action}", 500),
	};
}

function dispatchCrud(
	Route $route,
	Request $request,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
): Response {
	$ctrl  = new CrudController($db, $tables, $configRuntime);
	$table = $route->table ?? '';
	$id    = $route->id;
	return match ($route->action) {
		'list'            => $ctrl->list($table, $request),
		'show'            => $ctrl->show($table, (int) $id, $request),
		'create'          => $ctrl->create($table, $request),
		'update'          => $ctrl->update($table, (int) $id, $request),
		'patch'           => $ctrl->patch($table, (int) $id, $request),
		'delete'          => $ctrl->delete($table, (int) $id),
		'docStateOptions' => $ctrl->docStateOptions($table, (int) $id),
		default           => Response::error('INTERNAL_ERROR', "Unknown CRUD action: {$route->action}", 500),
	};
}

function dispatchMeta(string $action, ?string $tableName, array $tables, string $language): Response
{
	$ctrl = new MetaController();
	return match ($action) {
		'tables' => $ctrl->tables($tables, $language),
		'table'  => $ctrl->table((string) $tableName, $tables, $language),
		default  => Response::error('INTERNAL_ERROR', "Unknown meta action: {$action}", 500),
	};
}

function dispatchUi(string $action, \Shipard\Core\Config\DataSourceConfig $config, ModulePathResolver $modulePathResolver, string $language): Response
{
	$ctrl = new NavigationController();
	return match ($action) {
		'navigation' => $ctrl->navigation($config, $modulePathResolver, $language),
		default      => Response::error('INTERNAL_ERROR', "Unknown UI action: {$action}", 500),
	};
}

function dispatchDashboard(
	Route $route,
	\Shipard\Core\Database\DataSourceConnection $db,
	ViewerRegistry $registry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	string $language,
): Response {
	$ctrl = new DashboardController();
	return match ($route->action) {
		'index'  => $ctrl->dashboard($registry, $db, $configRuntime, $language),
		default  => Response::error('INTERNAL_ERROR', "Unknown dashboard action: {$route->action}", 500),
	};
}

function dispatchSettings(
	string $action,
	\Shipard\Core\Config\DataSourceConfig $config,
	ModulePathResolver $modulePathResolver,
	string $language,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
): Response {
	$ctrl = new SettingsController();
	return match ($action) {
		'navigation' => $ctrl->navigation($config, $modulePathResolver, $language, $configRuntime),
		default      => Response::error('INTERNAL_ERROR', "Unknown settings action: {$action}", 500),
	};
}

function dispatchViewer(
	Route $route,
	Request $request,
	ViewerRegistry $registry,
	\Shipard\Core\Database\DataSourceConnection $db,
	?\Shipard\Core\Config\ConfigRuntime $config = null,
	string $language = 'en',
): Response {
	$ctrl     = new ViewerController();
	$viewerId = $route->table ?? '';
	return match ($route->action) {
		'meta'   => $ctrl->meta($viewerId, $registry, $db, $config, $language),
		'rows'   => $ctrl->rows($viewerId, $request, $registry, $db, $config, $language),
		'detail' => $ctrl->detail($viewerId, (int) $route->id, $registry, $db, $config, $language),
		default  => Response::error('INTERNAL_ERROR', "Unknown viewer action: {$route->action}", 500),
	};
}

function dispatchForm(
	Route $route,
	Request $request,
	AuthContext $auth,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	FormRegistry $formRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
	ModulePathResolver $modulePathResolver,
	?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
	string $language = 'en',
	?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
	?LookupRegistry $lookupRegistry = null,
): Response {
	$ctrl  = new FormController();
	$table = $route->table ?? '';

	// New-record prefill from per-type viewer (e.g. doc_type=invno) arrives
	// as ?defaults[<key>]=<value>. Only forwarded to meta — save/recalculate
	// receive the merged data via JSON body.
	$queryDefaults = [];
	if ($route->action === 'meta' && $route->id === null) {
		$qp = $request->getQueryParams();
		if (isset($qp['defaults']) && is_array($qp['defaults'])) {
			foreach ($qp['defaults'] as $k => $v) {
				if (is_string($k) && $k !== '' && (is_string($v) || is_numeric($v) || is_bool($v))) {
					$queryDefaults[$k] = $v;
				}
			}
		}
	}

	$lookupReg = $lookupRegistry ?? new LookupRegistry();
	return match ($route->action) {
		'meta'        => $ctrl->meta($table, $route->id, $tables, $db, $formRegistry, $configRuntime, $lookupReg, $modulePathResolver, $language, $queryDefaults),
		'save'        => $ctrl->save($table, $route->id, $request, $tables, $db, $configRuntime, $formRegistry, $modulePathResolver, $lookupReg, $language, $documentRegistry, $dsConfig, $auth),
		'recalculate' => $ctrl->recalculate($table, $request, $tables, $db, $formRegistry, $configRuntime, $lookupReg, $modulePathResolver, $language),
		default       => Response::error('INTERNAL_ERROR', "Unknown form action: {$route->action}", 500),
	};
}

function dispatchLookup(
	Route $route,
	Request $request,
	array $tables,
	\Shipard\Core\Database\DataSourceConnection $db,
	LookupRegistry $lookupRegistry,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
): Response {
	$ctrl  = new \Shipard\Api\Controller\LookupController();
	$table = $route->table ?? '';
	return match ($route->action) {
		'search'  => $ctrl->search($table, $request, $tables, $db, $lookupRegistry, $configRuntime),
		'resolve' => $ctrl->resolve($table, $request, $tables, $db, $lookupRegistry, $configRuntime),
		default   => Response::error('INTERNAL_ERROR', "Unknown lookup action: {$route->action}", 500),
	};
}
