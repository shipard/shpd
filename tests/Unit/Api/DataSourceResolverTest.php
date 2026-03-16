<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\DataSourceResolver;
use Shipard\Api\Exception\UnknownHostException;
use Shipard\Api\ResolvedDataSource;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Subclass that skips the real DB connection for unit tests.
 */
class TestableDataSourceResolver extends DataSourceResolver
{
	protected function createConnection(DataSourceConfig $config): DataSourceConnection
	{
		// Use reflection to bypass the real Dibi connection
		$mock = $this->createMockConnection($config);
		return $mock;
	}

	private function createMockConnection(DataSourceConfig $config): DataSourceConnection
	{
		// Build a stub without calling the constructor (which needs a real DB)
		$ref = new \ReflectionClass(DataSourceConnection::class);
		/** @var DataSourceConnection $stub */
		$stub = $ref->newInstanceWithoutConstructor();
		return $stub;
	}
}

class DataSourceResolverTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/shpd_resolver_test_' . uniqid();
		mkdir($this->tempDir, 0755, true);
	}

	protected function tearDown(): void
	{
		$this->removeDir($this->tempDir);
	}

	private function removeDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') as $entry) {
			is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
		}
		rmdir($dir);
	}

	private function writeDomains(array $map): string
	{
		$path = $this->tempDir . '/domains.json';
		file_put_contents($path, json_encode($map));
		return $path;
	}

	private function writeDsConfig(string $dsId, array $data = []): void
	{
		$dsDir = $this->tempDir . '/data-sources/' . $dsId . '/config';
		mkdir($dsDir, 0755, true);
		$defaults = [
			'id'                => $dsId,
			'name'              => 'Test DS',
			'database_name'     => str_replace('-', '_', $dsId),
			'database_user'     => 'shpd_test',
			'database_password' => 'secret',
			'created'           => '2026-03-16T10:00:00+01:00',
		];
		file_put_contents($dsDir . '/main.json', json_encode(array_merge($defaults, $data)));
	}

	private function makeResolver(string $domainsFile): TestableDataSourceResolver
	{
		return new TestableDataSourceResolver(
			$domainsFile,
			$this->tempDir . '/data-sources',
		);
	}

	public function testResolveKnownHost(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolver($domainsFile);
		$result = $resolver->resolve('demo.shipard.cz');

		$this->assertInstanceOf(ResolvedDataSource::class, $result);
		$this->assertSame($dsId, $result->config->getId());
	}

	public function testResolveUnknownHostThrows(): void
	{
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => 'a3f2-b8c1-d4e7-f9a0']);

		$resolver = $this->makeResolver($domainsFile);

		$this->expectException(UnknownHostException::class);
		$resolver->resolve('unknown.shipard.cz');
	}

	public function testResolveMultipleEntries(): void
	{
		$map = [
			'firma1.shipard.cz' => 'aaaa-bbbb-cccc-dddd',
			'firma2.shipard.cz' => 'eeee-ffff-0000-1111',
			'demo.shipard.cz'   => 'a3f2-b8c1-d4e7-f9a0',
		];
		$domainsFile = $this->writeDomains($map);
		foreach ($map as $host => $dsId) {
			$this->writeDsConfig($dsId);
		}

		$resolver = $this->makeResolver($domainsFile);

		foreach ($map as $host => $dsId) {
			$result = $resolver->resolve($host);
			$this->assertSame($dsId, $result->config->getId(), "Failed for host: {$host}");
		}
	}

	public function testMissingDomainsFileThrows(): void
	{
		$resolver = $this->makeResolver($this->tempDir . '/nonexistent.json');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/not found/i');

		$resolver->resolve('demo.shipard.cz');
	}

	public function testInvalidJsonInDomainsFileThrows(): void
	{
		$path = $this->tempDir . '/domains.json';
		file_put_contents($path, '{invalid json}');

		$resolver = $this->makeResolver($path);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/invalid json/i');

		$resolver->resolve('demo.shipard.cz');
	}
}
