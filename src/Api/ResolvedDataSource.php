<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

readonly class ResolvedDataSource
{
	public function __construct(
		public DataSourceConfig $config,
		public DataSourceConnection $connection,
		public string $normalizedPath,
		private bool $devMode = false,
	) {}

	public function isDevMode(): bool
	{
		return $this->devMode;
	}
}
