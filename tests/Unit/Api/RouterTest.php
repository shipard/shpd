<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;

class RouterTest extends TestCase
{
	private Router $router;

	protected function setUp(): void
	{
		$this->router = new Router();
	}

	private function assertRoute(Route $route, string $controller, string $action, ?string $table = null, ?int $id = null): void
	{
		$this->assertSame($controller, $route->controller);
		$this->assertSame($action, $route->action);
		$this->assertSame($table, $route->table);
		$this->assertSame($id, $route->id);
	}

	// Meta endpoints
	public function testMetaTables(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'meta', 'tables');
	}

	public function testMetaTablesSingle(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables/core_system_users', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'meta', 'table', 'core_system_users');
	}

	// OpenAPI
	public function testOpenApiSpec(): void
	{
		$result = $this->router->resolve('/api/v1/_openapi.json', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'openapi', 'spec');
	}

	// Auth endpoints
	public function testAuthLogin(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/login', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'login');
	}

	public function testAuthRefresh(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/refresh', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'refresh');
	}

	public function testAuthLogout(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/logout', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'logout');
	}

	// CRUD list & create
	public function testCrudList(): void
	{
		$result = $this->router->resolve('/api/v1/economy_docs_heads', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'list', 'economy_docs_heads');
	}

	public function testCrudCreate(): void
	{
		$result = $this->router->resolve('/api/v1/economy_docs_heads', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'create', 'economy_docs_heads');
	}

	// CRUD with id
	public function testCrudShow(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/42', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'show', 'core_system_users', 42);
	}

	public function testCrudUpdate(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'PUT');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'update', 'core_system_users', 5);
	}

	public function testCrudPatch(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'PATCH');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'patch', 'core_system_users', 5);
	}

	public function testCrudDelete(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'delete', 'core_system_users', 5);
	}

	// Special endpoints not treated as generic table
	public function testMetaNotTreatedAsTable(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('meta', $result->controller);
	}

	// Error cases
	public function testUnknownPath(): void
	{
		$result = $this->router->resolve('/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertFalse($payload['success']);
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
	}

	public function testMethodNotAllowedOnAuthLogin(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/login', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertSame('METHOD_NOT_ALLOWED', $payload['error']['code']);
	}

	public function testNonNumericIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/users/abc', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
	}

	public function testZeroIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/users/0', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testMethodNotAllowedOnCrudList(): void
	{
		$result = $this->router->resolve('/api/v1/some_table', 'DELETE');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testMethodNotAllowedOnOpenApi(): void
	{
		$result = $this->router->resolve('/api/v1/_openapi.json', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testTableNamePreserved(): void
	{
		$result = $this->router->resolve('/api/v1/economy_codebooks_warehouses', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('economy_codebooks_warehouses', $result->table);
	}

	public function testDocStateOptions(): void
	{
		$result = $this->router->resolve('/api/v1/base_persons_persons/42/doc-state-options', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'docStateOptions', 'base_persons_persons', 42);
	}

	public function testDocStateOptionsMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/base_persons_persons/42/doc-state-options', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testUnknownSubResource(): void
	{
		$result = $this->router->resolve('/api/v1/users/5/unknown-action', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Form routes

	public function testFormMetaNew(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/meta', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'meta', 'core_system_users');
	}

	public function testFormMetaWithId(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/base_persons_persons/meta/42', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'meta', 'base_persons_persons', 42);
	}

	public function testFormSaveCreate(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'save', 'core_system_users');
	}

	public function testFormSaveUpdate(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save/5', 'PUT');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'save', 'core_system_users', 5);
	}

	public function testFormRecalculate(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/recalculate', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'recalculate', 'core_system_users');
	}

	public function testFormMetaMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/meta', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormSaveGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormSaveUpdatePostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save/5', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormRecalculateGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/recalculate', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormMetaInvalidId(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/meta/0', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testFormUnknownAction(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Attachment routes

	public function testAttachmentUpload(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/upload', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'upload');
	}

	public function testAttachmentUploadMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/upload', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentList(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'list');
	}

	public function testAttachmentListWithTrailingSlash(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'list');
	}

	public function testAttachmentListMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentDownload(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/download', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'download', null, 42);
	}

	public function testAttachmentDownloadMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/download', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentThumbnail(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/thumbnail', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'thumbnail', null, 42);
	}

	public function testAttachmentPatch(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42', 'PATCH');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'patch', null, 42);
	}

	public function testAttachmentDelete(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'delete', null, 42);
	}

	public function testAttachmentRestore(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/restore', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'restore', null, 42);
	}

	public function testAttachmentRestoreMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/restore', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentUnknownAction(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testAttachmentZeroIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/0/download', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Mail routes

	public function testMailIncomingPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/incoming', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'mail', 'receiveIncoming');
	}

	public function testMailIncomingGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/incoming', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Analysis routes (Fáze 3a)

	public function testAnalysisQueueGet(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/queue', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'analysis', 'queue');
	}

	public function testAnalysisQueuePostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/queue', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAnalysisClaimPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/12345/claim', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('claim', $result->action);
		$this->assertSame(12345, $result->id);
	}

	public function testAnalysisClaimZeroNdxReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/0/claim', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testAnalysisPayloadGet(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/payload', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('payload', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testAnalysisAttachmentContentGet(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/attachments/7/content', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('attachmentContent', $result->action);
		$this->assertSame(42, $result->id);
		$this->assertSame(7, $result->secondaryId);
	}

	public function testAnalysisResultPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/result', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('result', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testAnalysisFailedPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/failed', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('failed', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testAnalysisUnknownActionReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/whatever', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testMailMessagesReanalyzePost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/42/reanalyze', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('reanalyze', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testMailMessagesReanalyzeGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/42/reanalyze', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testExtractedDocumentApplyPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/extracted-documents/55/apply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('applyExtracted', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testExtractedDocumentRejectPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/extracted-documents/55/reject', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('rejectExtracted', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testExtractedDocumentPreviewPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/extracted-documents/55/preview', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('previewExtracted', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testExtractedDocumentPreviewGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/extracted-documents/55/preview', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testExtractedDocumentUnknownActionReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/extracted-documents/55/whatever', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testExtractedDocumentApplyGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/extracted-documents/55/apply', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Exchange endpoints
	public function testExchangeValidate(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/validate', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'validate');
	}

	public function testExchangePreview(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/preview', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'preview');
	}

	public function testExchangeApply(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/apply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'apply');
	}

	public function testExchangeGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/apply', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testExchangeUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/explode', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Lookup routes

	public function testLookupSearch(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/search', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'lookup', 'search', 'base_persons_persons');
	}

	public function testLookupResolve(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/resolve', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'lookup', 'resolve', 'base_persons_persons');
	}

	public function testLookupSearchPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/search', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testLookupUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/explode', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testLookupMissingActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testLookupExtraSegmentIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/foo/search/extra', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}
}
