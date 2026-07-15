<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\McpController;
use Shipard\Api\Mcp\JsonRpcError;
use Shipard\Api\Mcp\McpToolRegistry;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Mcp\PersonsGetTool;
use Shipard\Module\Base\Persons\Mcp\PersonsSearchTool;
use Shipard\Module\Base\Registry\Mcp\RegistrySearchTool;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Mail\ExtractedDocumentApplier;
use Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool;
use Shipard\Module\Core\Mail\Mcp\MailListPendingTool;
use Shipard\Module\Docs\Core\Mcp\DocumentsSearchTool;

class McpControllerTest extends TestCase
{
	private function buildRequest(?array $body): Request
	{
		return Request::fromArray(
			'POST',
			'/api/v1/_mcp',
			[],
			$body !== null ? (string) json_encode($body) : '',
			['HTTP_HOST' => 'localhost'],
		);
	}

	private function rawBodyRequest(string $raw): Request
	{
		return Request::fromArray('POST', '/api/v1/_mcp', [], $raw, ['HTTP_HOST' => 'localhost']);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	private function controller(?DataSourceConnection $db = null): array
	{
		$registry = new McpToolRegistry();
		$registry->register(new PersonsSearchTool());
		$registry->register(new PersonsGetTool());
		$registry->register(new DocumentsSearchTool());
		$registry->register(new MailListPendingTool());
		$registry->register(new RegistrySearchTool());
		$ctrl = new McpController($registry);
		$db ??= $this->createMock(DataSourceConnection::class);
		return [$ctrl, $db];
	}

	private function call(McpController $ctrl, Request $request, DataSourceConnection $db, bool $auth = true): Response
	{
		$ctx = $auth ? new AuthContext(true, 1, 'api_key') : AuthContext::anonymous();
		return $ctrl->rpc($request, $ctx, $db, [], null);
	}

	// 1. initialize → serverInfo, capabilities.tools, echo protocolVersion
	public function testInitializeEchoesProtocolVersion(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
			'params' => ['protocolVersion' => '2099-01-01', 'capabilities' => [], 'clientInfo' => ['name' => 'x']],
		]), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$p = $resp->getPayload();
		$this->assertSame('2099-01-01', $p['result']['protocolVersion']);
		$this->assertSame('shipard', $p['result']['serverInfo']['name']);
		$this->assertArrayHasKey('tools', $p['result']['capabilities']);
	}

	public function testInitializeFallsBackToNominalVersion(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
		]), $db);
		$this->assertSame('2025-06-18', $resp->getPayload()['result']['protocolVersion']);
	}

	// 2. notifications/initialized → HTTP 202
	public function testNotificationInitializedReturns202(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
		]), $db);
		$this->assertSame(202, $this->getStatus($resp));
	}

	// 3. tools/list → obsahuje persons_search s inputSchema
	public function testToolsListContainsPersonsSearch(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
		]), $db);

		$tools = $resp->getPayload()['result']['tools'];
		$names = array_column($tools, 'name');
		$this->assertContains('persons_search', $names);
		$tool = $tools[array_search('persons_search', $names, true)];
		$this->assertSame('object', $tool['inputSchema']['type']);
		$this->assertContains('query', $tool['inputSchema']['required']);
	}

	// 4. tools/call persons_search → obálka + has_more při překročení limitu
	public function testToolsCallReturnsEnvelopeWithPagination(): void
	{
		// Seed limit+1 řádků (21 při limit 20) → tool ořízne na 20, has_more=true.
		$rows = [];
		for ($i = 1; $i <= 21; $i++) {
			$rows[] = [
				'id' => $i, 'full_name' => "Firma {$i}", 'person_type' => 2,
				'company_id' => "1000000{$i}", 'tax_id' => null, 'vat_id' => "CZ1000000{$i}",
				'email' => "f{$i}@x.cz", 'person_id' => "P{$i}",
			];
		}
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturn($rows);

		[$ctrl] = $this->controller($db);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
			'params' => ['name' => 'persons_search', 'arguments' => ['query' => 'Firma']],
		]), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$result = $resp->getPayload()['result'];
		$this->assertFalse($result['isError']);
		$sc = $result['structuredContent'];
		$this->assertCount(20, $sc['items']);
		$this->assertTrue($sc['pagination']['has_more']);
		$this->assertSame(['type' => 'person', 'id' => 1], $sc['items'][0]['ref']);
		$this->assertSame('company', $sc['items'][0]['person_type']);
		$this->assertSame('CZ10000001', $sc['items'][0]['vat_id']);
		// content text vždy přítomen
		$this->assertNotEmpty($result['content'][0]['text']);
	}

	// 5. tools/call neznámý nástroj → -32602
	public function testToolsCallUnknownToolIsInvalidParams(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
			'params' => ['name' => 'does_not_exist', 'arguments' => []],
		]), $db);

		$this->assertSame(JsonRpcError::INVALID_PARAMS, $resp->getPayload()['error']['code']);
	}

	// 6. tools/call persons_search bez query → -32602
	public function testToolsCallMissingQueryIsInvalidParams(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
			'params' => ['name' => 'persons_search', 'arguments' => []],
		]), $db);

		$this->assertSame(JsonRpcError::INVALID_PARAMS, $resp->getPayload()['error']['code']);
	}

	// 7. vykonávací chyba → result.isError (ne JSON-RPC error)
	public function testToolExecutionErrorReturnsIsError(): void
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willThrowException(new \RuntimeException('boom'));

		[$ctrl] = $this->controller($db);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
			'params' => ['name' => 'persons_search', 'arguments' => ['query' => 'x']],
		]), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$p = $resp->getPayload();
		$this->assertArrayNotHasKey('error', $p);
		$this->assertTrue($p['result']['isError']);
	}

	// 8. bez tokenu → HTTP 401
	public function testUnauthenticatedReturns401(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list',
		]), $db, auth: false);

		$this->assertSame(401, $this->getStatus($resp));
	}

	// 9. tělo není JSON → -32700
	public function testInvalidJsonBodyIsParseError(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->rawBodyRequest('{ not json'), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame(JsonRpcError::PARSE_ERROR, $resp->getPayload()['error']['code']);
	}

	public function testUnknownMethodIsMethodNotFound(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list',
		]), $db);

		$this->assertSame(JsonRpcError::METHOD_NOT_FOUND, $resp->getPayload()['error']['code']);
	}

	// --- Fáze 2: další čtecí nástroje -------------------------------------

	/** Vyvolá tools/call a vrátí celý `result` (content + structuredContent). */
	private function callTool(DataSourceConnection $db, string $name, array $args): array
	{
		[$ctrl] = $this->controller($db);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/call',
			'params' => ['name' => $name, 'arguments' => $args],
		]), $db);
		$this->assertSame(200, $this->getStatus($resp));
		return $resp->getPayload()['result'];
	}

	// tools/list → všechny čtyři nástroje s inputSchema
	public function testToolsListContainsAllReadTools(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/list',
		]), $db);

		$tools = $resp->getPayload()['result']['tools'];
		$names = array_column($tools, 'name');
		foreach (['persons_search', 'persons_get', 'documents_search', 'mail_list_pending', 'registry_search'] as $expected) {
			$this->assertContains($expected, $names);
		}
		foreach ($tools as $t) {
			$this->assertSame('object', $t['inputSchema']['type']);
			$this->assertNotEmpty($t['description']);
		}
	}

	// persons_get: existující ID → karta s adresami/bankou/kontakty + count
	public function testPersonsGetReturnsCard(): void
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchRow')->willReturnCallback(static fn(string $sql, ...$a) =>
			str_contains($sql, 'base_persons_persons') ? [
				'id' => 7, 'full_name' => 'ACME s.r.o.', 'person_id' => 'P7',
				'person_type' => 2, 'company_id' => '12345678', 'tax_id' => null,
				'vat_id' => 'CZ12345678', 'email' => 'info@acme.cz', 'birth_date' => null,
			] : null);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$a): array {
			if (str_contains($sql, 'base_persons_addresses')) {
				return [['address_type' => 1, 'display_line' => 'Hlavní 1, Praha', 'street' => 'Hlavní', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZ']];
			}
			if (str_contains($sql, 'base_persons_bank_accounts')) {
				return [['name' => 'Provozní', 'account_number' => '123/0800', 'iban' => 'CZ00', 'bic' => 'GIBACZPX', 'currency' => 'CZK']];
			}
			if (str_contains($sql, 'base_persons_contacts')) {
				return [['name' => 'Jan Novák', 'role' => 'jednatel', 'email' => 'jan@acme.cz', 'phone' => '+420']];
			}
			return [];
		});
		$db->method('fetchSingle')->willReturn(3);

		$result = $this->callTool($db, 'persons_get', ['id' => 7]);
		$this->assertFalse($result['isError']);
		$sc = $result['structuredContent'];
		$this->assertNull($sc['pagination']);
		$this->assertCount(1, $sc['items']);
		$card = $sc['items'][0];
		$this->assertSame(['type' => 'person', 'id' => 7], $card['ref']);
		$this->assertSame('company', $card['person_type']);
		$this->assertCount(1, $card['addresses']);
		$this->assertSame('Provozní', $card['bank_accounts'][0]['name']);
		$this->assertSame('Jan Novák', $card['contacts'][0]['name']);
		$this->assertSame(3, $card['documents_count']);
	}

	// persons_get: neexistující ID → prázdné items, ne chyba
	public function testPersonsGetNotFoundReturnsEmptyItems(): void
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchRow')->willReturn(null);

		$result = $this->callTool($db, 'persons_get', ['id' => 999]);
		$this->assertFalse($result['isError']);
		$this->assertSame([], $result['structuredContent']['items']);
		$this->assertStringContainsString('999', $result['structuredContent']['summary']);
	}

	// documents_search: partner + state=done → správné WHERE fragmenty a parametry
	public function testDocumentsSearchPartnerAndStateFilters(): void
	{
		$capturedSql = '';
		$capturedParams = [];
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$params) use (&$capturedSql, &$capturedParams): array {
			$capturedSql = $sql;
			$capturedParams = $params;
			return [];
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$this->callTool($db, 'documents_search', ['partner' => 42, 'state' => 'done']);

		$this->assertStringContainsString('`h`.`docState` = 40', $capturedSql);
		$this->assertStringContainsString('`h`.`partner` = %i', $capturedSql);
		$this->assertStringNotContainsString('docState` != 90', $capturedSql);
		// params: [partner, limit+1, offset]
		$this->assertSame([42, 21, 0], $capturedParams);
	}

	// documents_search: overdue → WHERE vylučuje storno (30) i smazané (90),
	// a per-item overdue bool se dopočítá z due_date < dnes
	public function testDocumentsSearchOverdue(): void
	{
		$capturedSql = '';
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$p) use (&$capturedSql): array {
			$capturedSql = $sql;
			return [
				['id' => 1, 'doc_type' => 'invno', 'doc_number' => 'FV1', 'partner' => 5, 'partner_name' => 'ACME', 'accounting_date' => '2026-01-01', 'due_date' => '2026-05-01', 'total_amount' => '100', 'doc_currency' => 'CZK', 'docState' => 40],
				['id' => 2, 'doc_type' => 'invno', 'doc_number' => 'FV2', 'partner' => 5, 'partner_name' => 'ACME', 'accounting_date' => '2026-01-02', 'due_date' => '2026-05-01', 'total_amount' => '200', 'doc_currency' => 'CZK', 'docState' => 30],
			];
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$result = $this->callTool($db, 'documents_search', ['overdue' => true]);
		$this->assertStringContainsString('`h`.`due_date` < CURDATE() AND `h`.`docState` NOT IN (30, 90)', $capturedSql);

		$items = $result['structuredContent']['items'];
		// po splatnosti: doklad 1 (state 40) ano, doklad 2 (storno 30) ne
		$this->assertTrue($items[0]['overdue']);
		$this->assertFalse($items[1]['overdue']);
		$this->assertStringContainsString('1 po splatnosti', $result['structuredContent']['summary']);
	}

	// documents_search: query přes doc_number a stránkování has_more
	public function testDocumentsSearchQueryAndPagination(): void
	{
		$rows = [];
		for ($i = 1; $i <= 21; $i++) {
			$rows[] = ['id' => $i, 'doc_type' => 'invno', 'doc_number' => "FV{$i}", 'partner' => null, 'partner_name' => null, 'accounting_date' => '2026-01-01', 'due_date' => null, 'total_amount' => '1', 'doc_currency' => 'CZK', 'docState' => 10];
		}
		$capturedSql = '';
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$p) use (&$capturedSql, $rows): array {
			$capturedSql = $sql;
			return $rows;
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$result = $this->callTool($db, 'documents_search', ['query' => 'FV']);
		$this->assertStringContainsString('doc_number` LIKE %s OR `h`.`partner_doc_number` LIKE %s', $capturedSql);
		$sc = $result['structuredContent'];
		$this->assertCount(20, $sc['items']);
		$this->assertTrue($sc['pagination']['has_more']);
		$this->assertNull($sc['items'][0]['partner']);
	}

	// registry_search: default state=filed + druh → správné WHERE a parametry
	public function testRegistrySearchDefaultStateAndKindFilter(): void
	{
		$capturedSql = '';
		$capturedParams = [];
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$params) use (&$capturedSql, &$capturedParams): array {
			$capturedSql = $sql;
			$capturedParams = $params;
			return [];
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$result = $this->callTool($db, 'registry_search', ['doc_kind' => 'contract']);

		$this->assertStringContainsString('`d`.`docState` = 40', $capturedSql);
		$this->assertStringContainsString('`d`.`doc_kind` = %s', $capturedSql);
		$this->assertSame(['contract', 21, 0], $capturedParams);
		$this->assertStringContainsString('žádné dokumenty', $result['structuredContent']['summary']);
	}

	// registry_search: fulltext jde přes oba indexy (ft_head + ft_text)
	public function testRegistrySearchFulltextUsesBothIndexes(): void
	{
		$capturedSql = '';
		$capturedParams = [];
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$params) use (&$capturedSql, &$capturedParams): array {
			$capturedSql = $sql;
			$capturedParams = $params;
			return [];
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$this->callTool($db, 'registry_search', ['query' => 'výpověď', 'state' => 'active']);

		$this->assertStringContainsString('MATCH (`d`.`title`, `d`.`ref_number`, `d`.`ai_summary`) AGAINST (%s)', $capturedSql);
		$this->assertStringContainsString('MATCH (`d`.`extracted_text`) AGAINST (%s)', $capturedSql);
		$this->assertStringContainsString('`d`.`docState` != 90', $capturedSql);
		$this->assertSame(['výpověď', 'výpověď', 21, 0], $capturedParams);
	}

	// registry_search: nenalezený šanon → prázdný výsledek se summary, bez SQL na dokumenty
	public function testRegistrySearchBinderNotFound(): void
	{
		$documentQueryRan = false;
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$p) use (&$documentQueryRan): array {
			if (str_contains($sql, 'base_registry_documents')) {
				$documentQueryRan = true;
			}
			return []; // binder lookup nic nenajde
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$result = $this->callTool($db, 'registry_search', ['binder_name' => 'Neexistující šanon']);

		$sc = $result['structuredContent'];
		$this->assertSame([], $sc['items']);
		$this->assertStringContainsString("Šanon 'Neexistující šanon' nebyl nalezen", $sc['summary']);
		$this->assertFalse($sc['pagination']['has_more']);
		$this->assertFalse($documentQueryRan);
	}

	// registry_search: binder filtr + expiring_within_days + mapování expired/labels
	public function testRegistrySearchBinderAndExpiring(): void
	{
		$capturedSql = '';
		$capturedParams = [];
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$params) use (&$capturedSql, &$capturedParams): array {
			if (str_starts_with($sql, 'SELECT `id` FROM `base_registry_binders`')) {
				return [['id' => 3], ['id' => 4]];
			}
			$capturedSql = $sql;
			$capturedParams = $params;
			return [
				['id' => 1, 'title' => 'Nájemní smlouva', 'doc_kind' => 'contract', 'ref_number' => 'S-1', 'partner' => 5, 'partner_name' => 'ACME', 'valid_from' => '2025-01-01', 'valid_to' => '2026-05-01', 'ai_summary' => str_repeat('x', 250), 'docState' => 40, 'binder_name' => 'Smlouvy'],
				['id' => 2, 'title' => 'Pojistka auta', 'doc_kind' => 'insurance', 'ref_number' => null, 'partner' => null, 'partner_name' => null, 'valid_from' => null, 'valid_to' => '2026-07-01', 'ai_summary' => null, 'docState' => 40, 'binder_name' => 'Smlouvy'],
			];
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$result = $this->callTool($db, 'registry_search', ['binder_name' => 'Smlouvy', 'expiring_within_days' => 30]);

		$this->assertStringContainsString('`d`.`binder` IN %in', $capturedSql);
		$this->assertStringContainsString('`d`.`valid_to` IS NOT NULL AND `d`.`valid_to` <= %s', $capturedSql);
		// [horizont dnes+30, binder ids, limit+1, offset]
		$this->assertSame(['2026-07-06', [3, 4], 21, 0], $capturedParams);

		$items = $result['structuredContent']['items'];
		$this->assertSame(['type' => 'registry_document', 'id' => 1], $items[0]['ref']);
		$this->assertSame('Nájemní smlouva — ACME', $items[0]['full_name']);
		$this->assertSame(['id' => 5, 'full_name' => 'ACME'], $items[0]['partner']);
		$this->assertSame('Smlouvy', $items[0]['binder']);
		$this->assertTrue($items[0]['expired']);
		$this->assertSame(201, mb_strlen($items[0]['ai_summary'])); // zkráceno na 200 + …
		$this->assertFalse($items[1]['expired']);
		$this->assertNull($items[1]['partner']);
		$this->assertStringContainsString('2 dokumentů, z toho 1 s prošlou platností', $result['structuredContent']['summary']);
	}

	// registry_search: limit se stropuje na 50
	public function testRegistrySearchLimitCap(): void
	{
		$capturedParams = [];
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$params) use (&$capturedParams): array {
			$capturedParams = $params;
			return [];
		});
		$db->method('fetchSingle')->willReturn('2026-06-06');

		$this->callTool($db, 'registry_search', ['limit' => 500]);

		$this->assertSame([51, 0], $capturedParams);
	}

	// mail_list_pending: mapování analysis_status, pending count, summary
	public function testMailListPendingMapsStatusAndCounts(): void
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturn([
			['id' => 1, 'subject' => 'Faktura', 'sender_name' => 'Dodavatel', 'sender_email' => 'd@x.cz', 'sender_person' => 9, 'received_at' => '2026-06-01 10:00:00', 'mailbox' => 1, 'mailbox_name' => 'Hlavní', 'docState' => 10, 'analysis_status_raw' => 2, 'pending_extracted_count' => 2],
			['id' => 2, 'subject' => 'Spam', 'sender_name' => null, 'sender_email' => 's@x.cz', 'sender_person' => null, 'received_at' => '2026-06-01 09:00:00', 'mailbox' => 1, 'mailbox_name' => 'Hlavní', 'docState' => 20, 'analysis_status_raw' => null, 'pending_extracted_count' => 0],
		]);

		$result = $this->callTool($db, 'mail_list_pending', []);
		$items = $result['structuredContent']['items'];
		$this->assertSame(['type' => 'mail_message', 'id' => 1], $items[0]['ref']);
		$this->assertSame('success', $items[0]['analysis_status']);
		$this->assertSame(2, $items[0]['pending_extracted_count']);
		$this->assertSame(['id' => 9], $items[0]['sender']['person']);
		$this->assertSame('none', $items[1]['analysis_status']);
		$this->assertNull($items[1]['sender']['person']);
		$this->assertStringContainsString('1 s akčními doklady', $result['structuredContent']['summary']);
	}

	// mail_list_pending: only_actionable → SQL filtruje pending_extracted_count > 0
	public function testMailListPendingOnlyActionableFiltersInSql(): void
	{
		$capturedSql = '';
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$p) use (&$capturedSql): array {
			$capturedSql = $sql;
			return [];
		});

		$this->callTool($db, 'mail_list_pending', ['only_actionable' => true]);
		$this->assertStringContainsString('`m`.`docState` != 40', $capturedSql);
		$this->assertStringContainsString('`t`.`pending_extracted_count` > 0', $capturedSql);
	}

	// --- Fáze 3: write-tier mail_draft_document --------------------------

	private function happyCanonical(): array
	{
		return json_decode(
			(string) file_get_contents(__DIR__ . '/../../../Fixtures/Exchange/invoiceReceived_happy.json'),
			true,
		);
	}

	/** Postaví controller s registrovaným draft nástrojem a vyvolá tools/call. */
	private function callDraftTool(DataSourceConnection $db, ?DocumentApplier $applier, array $args): array
	{
		$registry = new McpToolRegistry();
		$service = $applier !== null ? new ExtractedDocumentApplier($db, $applier) : null;
		$registry->register(new MailDraftDocumentTool($service));
		$ctrl = new McpController($registry);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 200, 'method' => 'tools/call',
			'params' => ['name' => 'mail_draft_document', 'arguments' => $args],
		]), $db);
		$this->assertSame(200, $this->getStatus($resp));
		return $resp->getPayload()['result'];
	}

	public function testDraftToolInToolsList(): void
	{
		$registry = new McpToolRegistry();
		$registry->register(new MailDraftDocumentTool(null));
		$ctrl = new McpController($registry);
		$db = $this->createMock(DataSourceConnection::class);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 201, 'method' => 'tools/list',
		]), $db);

		$tools = $resp->getPayload()['result']['tools'];
		$names = array_column($tools, 'name');
		$this->assertContains('mail_draft_document', $names);
		$tool = $tools[array_search('mail_draft_document', $names, true)];
		$this->assertContains('message_id', $tool['inputSchema']['required']);
	}

	public function testDraftToolGracefulWithoutApplier(): void
	{
		$db = $this->createMock(DataSourceConnection::class);
		$result = $this->callDraftTool($db, null, ['message_id' => 1]);
		$this->assertFalse($result['isError']);
		$sc = $result['structuredContent'];
		$this->assertSame([], $sc['items']);
		$this->assertStringContainsString('není', $sc['summary']);
	}

	public function testDraftToolDraftsCleanDocument(): void
	{
		$canonical = $this->happyCanonical();
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturn([
			['id' => 5, 'doc_type' => 'invni', 'status' => 20],
		]);
		$db->method('fetchRow')->willReturn([
			'id' => 5, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
			'extracted_json' => json_encode($canonical),
		]);
		$db->method('getDibiConnection')->willReturn($this->createMock(\Dibi\Connection::class));

		$applier = $this->createMock(DocumentApplier::class);
		$applier->expects($this->once())->method('apply')
			->willReturn(ApplyResult::ok($canonical, savedId: 777));

		$result = $this->callDraftTool($db, $applier, ['message_id' => 100]);
		$sc = $result['structuredContent'];
		$this->assertCount(1, $sc['items']);
		$this->assertTrue($sc['items'][0]['drafted']);
		$this->assertSame(['type' => 'document', 'id' => 777], $sc['items'][0]['document_ref']);
		$this->assertSame(['type' => 'extracted_document', 'id' => 5], $sc['items'][0]['extracted_ref']);
		$this->assertStringContainsString('založeno 1 konceptů', $sc['summary']);
	}

	public function testDraftToolReportsNeedsResolution(): void
	{
		$canonical = $this->happyCanonical();
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturn([
			['id' => 5, 'doc_type' => 'invni', 'status' => 20],
		]);
		$db->method('fetchRow')->willReturn([
			'id' => 5, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
			'extracted_json' => json_encode($canonical),
		]);

		$applier = $this->createMock(DocumentApplier::class);
		$applier->method('apply')->willReturn(ApplyResult::error(
			'unresolved_required', 'Doplň userAction',
			['_resolve' => ['issues' => [
				['severity' => 'error', 'path' => 'supplier', 'message' => 'Nelze automaticky propojit'],
				['severity' => 'warning', 'path' => 'rows.0.unit', 'message' => 'jen varování'],
			]]],
			422,
		));

		$result = $this->callDraftTool($db, $applier, ['message_id' => 100]);
		$item = $result['structuredContent']['items'][0];
		$this->assertFalse($item['drafted']);
		$this->assertTrue($item['needs_resolution']);
		$this->assertCount(1, $item['unresolved']); // jen severity=error
		$this->assertSame('supplier', $item['unresolved'][0]['path']);
		$this->assertStringContainsString('čeká na ruční dořešení', $result['structuredContent']['summary']);
	}

	public function testDraftToolNarrowsToExtractedIdAndSkipsAiFailed(): void
	{
		$capturedSql = '';
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturnCallback(static function (string $sql, ...$p) use (&$capturedSql): array {
			$capturedSql = $sql;
			return [['id' => 9, 'doc_type' => 'invni', 'status' => 70]]; // ai_failed
		});

		$applier = $this->createMock(DocumentApplier::class);
		$applier->expects($this->never())->method('apply');

		$result = $this->callDraftTool($db, $applier, ['message_id' => 100, 'extracted_document_id' => 9]);
		$this->assertStringContainsString('`id` = %i AND `message` = %i', $capturedSql);
		$item = $result['structuredContent']['items'][0];
		$this->assertTrue($item['skipped']);
		$this->assertStringContainsString('reanalýzu', $item['reason']);
	}

	public function testDraftToolUsesSafeModeAndDraftState(): void
	{
		$canonical = $this->happyCanonical();
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturn([['id' => 5, 'doc_type' => 'invni', 'status' => 20]]);
		$db->method('fetchRow')->willReturn([
			'id' => 5, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
			'extracted_json' => json_encode($canonical),
		]);

		$captured = null;
		$applier = $this->createMock(DocumentApplier::class);
		$applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
			$captured = $passed;
			return ApplyResult::error('unresolved_required', 'X', [], 422);
		});

		$this->callDraftTool($db, $applier, ['message_id' => 100]);
		$this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
		$this->assertSame(10, $captured['applyOptions']['targetDocState']);
		$this->assertSame(5, $captured['source']['extractedDoc']);
		$this->assertSame('aiExtraction', $captured['source']['kind']);
	}
}
