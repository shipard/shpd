<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Api\Exception\UnknownHostException;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

class DataSourceResolver
{
	public function __construct(
		private string $domainsFile = '/etc/shipard/domains.json',
		private string $dataSourcesDir = '/opt/shipard/data-sources',
	) {}

	public function resolve(string $host): ResolvedDataSource
	{
		$map = $this->loadDomainsFile();

		if (!isset($map[$host])) {
			throw new UnknownHostException($host);
		}

		$dsId = $map[$host];
		$config = $this->createDataSourceConfig($dsId);
		$connection = $this->createConnection($config);

		return new ResolvedDataSource($config, $connection);
	}

	protected function loadDomainsFile(): array
	{
		if (!file_exists($this->domainsFile)) {
			throw new \RuntimeException("Domains file not found: {$this->domainsFile}");
		}

		$content = file_get_contents($this->domainsFile);
		$data = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException("Invalid JSON in domains file: " . json_last_error_msg());
		}

		if (!is_array($data)) {
			throw new \RuntimeException("Domains file must contain a JSON object");
		}

		return $data;
	}

	protected function createDataSourceConfig(string $dsId): DataSourceConfig
	{
		$dsDir = $this->dataSourcesDir . '/' . $dsId;
		return new DataSourceConfig($dsDir);
	}

	protected function createConnection(DataSourceConfig $config): DataSourceConnection
	{
		return new DataSourceConnection($config);
	}
}
