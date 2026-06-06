<?php
declare(strict_types=1);

namespace Shipard\Api\Mcp;

use Shipard\Api\AuthContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Kontext předaný nástroji při vykonání. Nese vše, co nástroj potřebuje z
 * requestu/DS — DataSource je už resolvnutý (DS-scoped), `userId` je v `$auth`
 * k dispozici pro audit a budoucí per-user omezení.
 */
final readonly class McpInvocationContext
{
	public function __construct(
		public AuthContext $auth,
		public DataSourceConnection $db,
		public array $tables,
		public ?ConfigRuntime $config,
	) {}
}
