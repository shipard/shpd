<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevDashboardController;
use Shipard\Api\Request;
use Shipard\Api\Response;

class DevDashboardControllerTest extends TestCase
{
	private string $tmpDir;
	private DevDashboardController $ctrl;
	/** @var list<string> */
	private array $tmpFiles = [];

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/shpd-dev-test-' . uniqid();
		mkdir($this->tmpDir, 0700, true);
		$this->ctrl = new DevDashboardController($this->tmpDir);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpDir);
		foreach ($this->tmpFiles as $f) {
			if (file_exists($f)) unlink($f);
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$entries = scandir($dir) ?: [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	private function createDs(string $id, string $name, ?string $created = null): void
	{
		$dir = $this->tmpDir . '/' . $id . '/config';
		mkdir($dir, 0700, true);
		$cfg = [
			'id'            => $id,
			'name'          => $name,
			'database_name' => str_replace('-', '_', $id),
		];
		if ($created !== null) {
			$cfg['created'] = $created;
		}
		file_put_contents($dir . '/main.json', json_encode($cfg));
	}

	private function makeRequest(string $method, string $uri): Request
	{
		return Request::fromArray($method, $uri, [], '', []);
	}

	private function getStatus(Response $r): int
	{
		$prop = (new \ReflectionClass($r))->getProperty('status');
		return (int) $prop->getValue($r);
	}

	private function getHeaders(Response $r): array
	{
		$prop = (new \ReflectionClass($r))->getProperty('headers');
		return (array) $prop->getValue($r);
	}

	private function getPayloadRaw(Response $r): mixed
	{
		$prop = (new \ReflectionClass($r))->getProperty('payload');
		return $prop->getValue($r);
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function testRootPathRedirects(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/'));
		$this->assertSame(302, $this->getStatus($resp));
		$this->assertSame('/_dev/', $this->getHeaders($resp)['Location'] ?? null);
	}

	public function testDashboardPageReturnsHtml(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/'));
		$this->assertSame(200, $this->getStatus($resp));
		$body = (string) $this->getPayloadRaw($resp);
		$this->assertStringContainsString('DEVELOPMENT MODE', $body);
		$this->assertStringContainsString('Shipard Dev Dashboard', $body);
		$this->assertStringContainsString((string) gethostname(), $body);
	}

	public function testDashboardPageWithoutTrailingSlash(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev'));
		$this->assertSame(200, $this->getStatus($resp));
		$body = (string) $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Shipard Dev Dashboard', $body);
	}

	public function testListDataSourcesReturnsSorted(): void
	{
		$this->createDs('cccc-cccc-cccc-cccc', 'Charlie');
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha');
		$this->createDs('bbbb-bbbb-bbbb-bbbb', 'Bravo');

		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$names = array_column($payload['data'], 'name');
		$this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names);
	}

	public function testListDataSourcesSkipsDirsWithoutMainJson(): void
	{
		$this->createDs('aaaa-aaaa-aaaa-aaaa', 'Alpha');
		mkdir($this->tmpDir . '/lost+found', 0700, true);

		$resp    = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertCount(1, $payload['data']);
		$this->assertSame('Alpha', $payload['data'][0]['name']);
	}

	public function testListDataSourcesSkipsCorruptJson(): void
	{
		$dir = $this->tmpDir . '/corrupt-ds-id/config';
		mkdir($dir, 0700, true);
		file_put_contents($dir . '/main.json', '{ this is not json');

		$resp    = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame([], $payload['data']);
	}

	public function testListDataSourcesEmpty(): void
	{
		$resp    = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/data-sources'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame([], $payload['data']);
	}

	public function testUnknownPathReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/random'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	public function testApiDataSourcesPostReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('POST', '/_dev/api/data-sources'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// Logs page / API
	// -------------------------------------------------------------------------

	private function makeLogFile(array $lines): string
	{
		$path = tempnam(sys_get_temp_dir(), 'shpd-log-');
		if ($path === false) {
			$this->fail('Could not create temp log file');
		}
		$this->tmpFiles[] = $path;
		file_put_contents($path, implode("\n", $lines) . "\n");
		return $path;
	}

	private function ctrlWithLog(string $logPath): DevDashboardController
	{
		return new DevDashboardController($this->tmpDir, $logPath);
	}

	private function jsonLine(array $data): string
	{
		return (string) json_encode($data);
	}

	public function testLogsPageReturnsHtml(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/logs/'));
		$this->assertSame(200, $this->getStatus($resp));
		$body = (string) $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Shipard Logs', $body);
		$this->assertStringContainsString((string) gethostname(), $body);
	}

	public function testLogsPageWithoutTrailingSlash(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/logs'));
		$this->assertSame(200, $this->getStatus($resp));
		$body = (string) $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Shipard Logs', $body);
	}

	public function testApiLogsWithoutLogPathReturns503(): void
	{
		// $this->ctrl was constructed without log path
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$this->assertSame(503, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertFalse($payload['success']);
		$this->assertSame('LOG_NOT_CONFIGURED', $payload['error']['code']);
	}

	public function testApiLogsReturnsParsedEntries(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'one']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:01+00:00', 'level' => 'warn', 'msg' => 'two']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:02+00:00', 'level' => 'error', 'msg' => 'three']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$this->assertCount(3, $payload['data']['entries']);
		$this->assertTrue($payload['data']['available']);
		$this->assertSame('one', $payload['data']['entries'][0]['msg']);
		$this->assertSame('three', $payload['data']['entries'][2]['msg']);
	}

	public function testApiLogsSkipsInvalidJsonLines(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'good']),
			'this is not { valid json',
			$this->jsonLine(['ts' => '2026-05-07T12:00:02+00:00', 'level' => 'error', 'msg' => 'good2']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$payload = $this->getPayloadRaw($resp);
		$this->assertCount(2, $payload['data']['entries']);
		$msgs = array_column($payload['data']['entries'], 'msg');
		$this->assertSame(['good', 'good2'], $msgs);
	}

	public function testApiLogsSkipsLinesMissingRequiredFields(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'ok']),
			$this->jsonLine(['level' => 'info', 'msg' => 'no-ts']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:02+00:00', 'msg' => 'no-level']),
			$this->jsonLine(['ts' => '2026-05-07T12:00:03+00:00', 'level' => 'warn', 'msg' => 'ok2']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$payload = $this->getPayloadRaw($resp);
		$msgs = array_column($payload['data']['entries'], 'msg');
		$this->assertSame(['ok', 'ok2'], $msgs);
	}

	public function testApiLogsRespectsLimit(): void
	{
		$lines = [];
		for ($i = 1; $i <= 50; $i++) {
			$lines[] = $this->jsonLine([
				'ts'    => sprintf('2026-05-07T12:00:%02d+00:00', $i % 60),
				'level' => 'info',
				'msg'   => 'm' . $i,
			]);
		}
		$logPath = $this->makeLogFile($lines);
		$ctrl    = $this->ctrlWithLog($logPath);

		$resp    = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/logs?limit=10', ['limit' => '10'], '', []));
		$payload = $this->getPayloadRaw($resp);
		$this->assertCount(10, $payload['data']['entries']);
		$this->assertSame(10, $payload['data']['limit']);
		// Last 10 of 50 should be m41..m50
		$this->assertSame('m41', $payload['data']['entries'][0]['msg']);
		$this->assertSame('m50', $payload['data']['entries'][9]['msg']);
	}

	public function testApiLogsCapsLimitAt2000(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'x']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/logs?limit=99999', ['limit' => '99999'], '', []));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(2000, $payload['data']['limit']);
	}

	public function testApiLogsClampsLimitAtMin(): void
	{
		$logPath = $this->makeLogFile([
			$this->jsonLine(['ts' => '2026-05-07T12:00:00+00:00', 'level' => 'info', 'msg' => 'x']),
		]);
		$ctrl = $this->ctrlWithLog($logPath);
		$resp = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/logs?limit=0', ['limit' => '0'], '', []));
		$payload = $this->getPayloadRaw($resp);
		$this->assertSame(1, $payload['data']['limit']);
	}

	public function testApiLogsHandlesMissingLogFile(): void
	{
		$ctrl = $this->ctrlWithLog('/tmp/shpd-nonexistent-log-' . uniqid());
		$resp = $ctrl->dispatch($this->makeRequest('GET', '/_dev/api/logs'));
		$this->assertSame(200, $this->getStatus($resp));

		$payload = $this->getPayloadRaw($resp);
		$this->assertTrue($payload['success']);
		$this->assertFalse($payload['data']['available']);
		$this->assertSame([], $payload['data']['entries']);
	}

	// -------------------------------------------------------------------------
	// ds-create page / validation
	// -------------------------------------------------------------------------

	private function makeJsonRequest(string $method, string $uri, array $body): Request
	{
		$rawBody = json_encode($body) ?: '';
		return Request::fromArray($method, $uri, [], $rawBody, []);
	}

	public function testDsCreatePageReturnsHtml(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/ds-create/'));
		$this->assertSame(200, $this->getStatus($resp));
		$body = (string) $this->getPayloadRaw($resp);
		$this->assertStringContainsString('Create new Data Source', $body);
	}

	public function testDsCreatePageWithoutTrailingSlash(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/ds-create'));
		$this->assertSame(200, $this->getStatus($resp));
	}

	public function testDsCreateValidationEmptyName(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => '',
			'login'    => 'admin',
			'password' => 'admin',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertFalse($payload['success']);
		$this->assertSame('VALIDATION', $payload['error']['code']);
		$this->assertStringContainsString('Name is required', $payload['error']['message']);
	}

	public function testDsCreateValidationInvalidLogin(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'bad-login!',
			'password' => 'admin',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('letters, digits, and underscores', $payload['error']['message']);
	}

	public function testDsCreateValidationShortPassword(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test DS',
			'login'    => 'admin',
			'password' => 'ab',
		]));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$this->assertStringContainsString('at least 4 characters', $payload['error']['message']);
	}

	public function testDsCreateValidationMultipleErrors(): void
	{
		$resp = $this->ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', []));
		$this->assertSame(400, $this->getStatus($resp));
		$payload = $this->getPayloadRaw($resp);
		$msg = $payload['error']['message'];
		$this->assertStringContainsString('Name is required', $msg);
		$this->assertStringContainsString('Admin login is required', $msg);
		$this->assertStringContainsString('Admin password is required', $msg);
	}

	public function testDsCreateGetReturns404(): void
	{
		$resp = $this->ctrl->dispatch($this->makeRequest('GET', '/_dev/api/ds-create'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// ds-create pipeline (subprocess mocks via subclassing)
	// -------------------------------------------------------------------------

	private function streamToString(Response $response): string
	{
		$prop = (new \ReflectionClass($response))->getProperty('streamProducer');
		$producer = $prop->getValue($response);
		ob_start();
		$producer();
		return (string) ob_get_clean();
	}

	private function makeTestableCtrl(): TestableDevDashboardController
	{
		return new TestableDevDashboardController($this->tmpDir);
	}

	public function testDsCreatePipelineHappyPath(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "Some output\n  Directory:     /tmp/test/abc123\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'seed'     => false,
		]));

		$this->assertSame(200, $this->getStatus($resp));
		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[STEP] Creating data source...', $out);
		$this->assertStringContainsString('[STEP] Running ds-upgrade...', $out);
		$this->assertStringContainsString('[STEP] Creating admin user', $out);
		$this->assertStringContainsString('[DONE] {"id":"abc123"', $out);
		$this->assertCount(3, $ctrl->commandsRun);
	}

	public function testDsCreatePipelineCreateFails(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[1, "boom\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] ds-create failed', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
		$this->assertCount(1, $ctrl->commandsRun);
	}

	public function testDsCreatePipelineUpgradeFails(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/test/abc123\n"],
			[1, "upgrade boom\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('ds-upgrade failed', $out);
		$this->assertStringContainsString('abc123', $out);
		$this->assertStringNotContainsString('[DONE]', $out);
		$this->assertCount(2, $ctrl->commandsRun);
	}

	public function testDsCreatePipelineParsesIdFromDirectoryLine(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /opt/shipard/data-sources/zzzz-aaaa-bbbb-cccc\n"],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[DONE] {"id":"zzzz-aaaa-bbbb-cccc"', $out);
		$this->assertStringContainsString('"url":"/zzzz-aaaa-bbbb-cccc/app/"', $out);
	}

	public function testDsCreatePipelineFailsOnMissingDirectoryLine(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "Some output without the magic line\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('[ERROR] Could not parse', $out);
		$this->assertCount(1, $ctrl->commandsRun);
	}

	public function testDsCreatePipelineWithSeed(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/x/abc123\n"],
			[0, ""],
			[0, ""],
			[0, ""],
			[0, ""],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'admin',
			'seed'     => true,
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('Seeding test persons', $out);
		$this->assertStringContainsString('Seeding test mail', $out);
		$this->assertStringContainsString('[DONE]', $out);
		$this->assertCount(5, $ctrl->commandsRun);
	}

	public function testDsCreatePasswordRedactedInOutput(): void
	{
		$ctrl = $this->makeTestableCtrl();
		$ctrl->commandResults = [
			[0, "  Directory:     /tmp/x/abc123\n"],
			[0, ""],
			[0, "running with --password=secret123 in args\n"],
		];

		$resp = $ctrl->dispatch($this->makeJsonRequest('POST', '/_dev/api/ds-create', [
			'name'     => 'Test',
			'login'    => 'admin',
			'password' => 'secret123',
		]));

		$out = $this->streamToString($resp);
		$this->assertStringContainsString('--password=***', $out);
		$this->assertStringNotContainsString('--password=secret123', $out);
	}
}

class TestableDevDashboardController extends DevDashboardController
{
	/** @var list<array{0: int, 1: string}> */
	public array $commandResults = [];
	/** @var list<string> */
	public array $commandsRun = [];

	protected function streamCommand(string $cmd, bool $redactPassword = false): array
	{
		$this->commandsRun[] = $cmd;
		if (count($this->commandResults) === 0) {
			return [0, ''];
		}
		$result = array_shift($this->commandResults);

		$emitted = $redactPassword
			? (string) preg_replace('/--password=\S+/', '--password=***', $result[1])
			: $result[1];
		echo $emitted;

		return $result;
	}
}
