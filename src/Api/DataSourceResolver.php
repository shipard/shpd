<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Api\Exception\UnknownDataSourceException;
use Shipard\Api\Exception\UnknownHostException;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

class DataSourceResolver
{
	public function __construct(
		private string $domainsFile = '/etc/shipard/domains.json',
		private string $dataSourcesDir = '/opt/shipard/data-sources',
	) {}

	public function resolve(string $host, string $path): ResolvedDataSource
	{
		if ($this->isIpAddress($host)) {
			return $this->resolveDevMode($path);
		}
		return $this->resolveProductionMode($host, $path);
	}

	private function isIpAddress(string $host): bool
	{
		return (bool) preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host);
	}

	private function resolveProductionMode(string $host, string $path): ResolvedDataSource
	{
		$map = $this->loadDomainsFile();

		if (!isset($map[$host])) {
			throw new UnknownHostException($host);
		}

		$dsId = $map[$host];
		$config = $this->createDataSourceConfig($dsId);
		$connection = $this->createConnection($config);

		return new ResolvedDataSource($config, $connection, $path, false);
	}

	private function resolveDevMode(string $path): ResolvedDataSource
	{
		// Extract first path segment: /abcd-efgh-ijkl-mnop/api/v1/users → abcd-efgh-ijkl-mnop
		$trimmed = ltrim($path, '/');
		$slashPos = strpos($trimmed, '/');
		$dsId = $slashPos !== false ? substr($trimmed, 0, $slashPos) : $trimmed;

		if (!preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $dsId)) {
			throw new UnknownDataSourceException($dsId);
		}

		$configFile = $this->dataSourcesDir . '/' . $dsId . '/config/main.json';
		if (!file_exists($configFile)) {
			throw new UnknownDataSourceException($dsId);
		}

		$config = $this->createDataSourceConfig($dsId);
		$connection = $this->createConnection($config);

		// normalizedPath = everything after /{dsId}, defaulting to /
		$rest = $slashPos !== false ? substr($trimmed, $slashPos) : '/';
		$normalizedPath = $rest !== '' ? $rest : '/';

		return new ResolvedDataSource($config, $connection, $normalizedPath, true);
	}

	protected function loadDomainsFile(): array
	{
		// Chybějící domains.json = zatím žádná mapování → prázdná mapa, aby
		// nenamapovaný host skončil čistým UnknownHostException (404 Unknown host),
		// ne generickým 500.
		if (!file_exists($this->domainsFile)) {
			return [];
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
