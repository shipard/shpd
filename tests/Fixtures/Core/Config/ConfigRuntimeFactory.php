<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Config;

use Shipard\Core\Config\ConfigRuntime;

/**
 * ConfigRuntime má privátní konstruktor a čte compiled JSON z disku.
 * Pro unit testy, které potřebují jen pár cfgItems, ho naplníme reflexí —
 * bez temp adresáře a zápisu souboru (alternativa k pattern
 * `file_put_contents(compiled.cs.json)` ve starších testech).
 */
final class ConfigRuntimeFactory
{
    /** @param array<string, mixed> $items cfgItem id → data */
    public static function fromItems(array $items): ConfigRuntime
    {
        $ref = new \ReflectionClass(ConfigRuntime::class);
        $cfg = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('items')->setValue($cfg, $items);
        return $cfg;
    }
}
