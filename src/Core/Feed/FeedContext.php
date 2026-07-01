<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Kontext předaný feed zdroji při sběru karet. Nese vše, co zdroj potřebuje z
 * requestu/DS — DataSource je už resolvnutý (DS-scoped), `config` může být null
 * (compiled config nedoběhl → zdroj degraduje, ne crashne). `maxCards` je strop
 * feedu, aby zdroj mohl omezit vlastní dotaz (žádné neomezené SELECTy).
 *
 * Analog `McpInvocationContext` — bezstavové, readonly.
 */
final readonly class FeedContext
{
    public function __construct(
        public DataSourceConnection $db,
        public ?ConfigRuntime $config,
        public string $language,
        public int $maxCards,
    ) {}
}
