<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Database\DataSourceConnection;

readonly class ResolvedDataSource
{
	/**
	 * Stav DS z config/state.json, načtený resolverem (#56). Sem dojde jen
	 * DS, který HTTP neblokuje — efektivní stav je `active` nebo `read_only`.
	 */
	public DataSourceState $state;

	public function __construct(
		public DataSourceConfig $config,
		public DataSourceConnection $connection,
		public string $normalizedPath,
		private bool $devMode = false,
		?DataSourceState $state = null,
	) {
		$this->state = $state ?? DataSourceState::active();
	}

	public function isDevMode(): bool
	{
		return $this->devMode;
	}

	/** Efektivní stav `read_only` — pipeline vynucuje ReadOnlyPolicy (fáze 2). */
	public function isReadOnly(): bool
	{
		return $this->state->getEffectiveState() === DataSourceState::READ_ONLY;
	}
}
