<?php
declare(strict_types=1);

namespace Shipard\Api\Exception;

/**
 * DS existuje, ale jeho stav (config/state.json) HTTP provoz blokuje —
 * suspended / pending_deletion / aktivní maintenance. index.php mapuje na
 * 503 + Retry-After (issue #56 D3): nikdy 404, mail-router by zprávy
 * zahodil, 503 frontuje.
 */
class DataSourceUnavailableException extends \RuntimeException
{
	public function __construct(
		public readonly string $dsId,
		public readonly string $effectiveState,
		public readonly ?string $maintenanceReason = null,
	) {
		parent::__construct("Data source {$dsId} unavailable: {$effectiveState}"
			. ($maintenanceReason !== null ? " (maintenance: {$maintenanceReason})" : ''));
	}
}
