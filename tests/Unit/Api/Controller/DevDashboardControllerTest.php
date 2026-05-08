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

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/shpd-dev-test-' . uniqid();
		mkdir($this->tmpDir, 0700, true);
		$this->ctrl = new DevDashboardController($this->tmpDir);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpDir);
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
}
