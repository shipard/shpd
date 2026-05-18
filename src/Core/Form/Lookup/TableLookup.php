<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * Abstraktní bázová třída pro lookup endpoint na konkrétní tabulce.
 *
 * Konkrétní implementace (např. `PersonsLookup`) sahá do své tabulky,
 * sestaví SQL hledání podle `$q`, případně aplikuje whitelistované
 * filtery (`getAllowedFilterKeys`) a vrátí `LookupItem[]` s display popisem.
 *
 * Registrace v `module.jsonc` → `lookups: [{table, class}]`. Načítá
 * `LookupLoader` (analogie `FormLoader`).
 */
abstract class TableLookup
{
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;

    final public function setDb(DataSourceConnection $db): void
    {
        $this->db = $db;
    }

    final public function setConfig(?ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    final public function setTableDef(TableDefinition $def): void
    {
        $this->tableDef = $def;
    }

    /**
     * Hledá záznamy podle volného textu.
     *
     * @param string                $q      Volně psaný term; prázdný = první stránka záznamů
     * @param array<string, scalar> $filter Whitelistované filter páry (sloupec → hodnota)
     * @param int                   $limit  1..50; controller už hodnotu sřízl
     * @return list<LookupItem>
     */
    abstract public function search(string $q, array $filter, int $limit): array;

    /**
     * Vrátí display popisy pro seznam ID.
     *
     * Pořadí výstupu nemusí odpovídat vstupu. Neexistující ID se prostě
     * v poli nevyskytnou — žádná chyba.
     *
     * @param list<int|string> $ids
     * @return list<LookupItem>
     */
    abstract public function resolve(array $ids): array;

    /**
     * Whitelist filter klíčů, které smí klient v `?filter[…]` poslat.
     * Default: žádné. Subclassy s cascade overridují.
     *
     * @return list<string>
     */
    public function getAllowedFilterKeys(): array
    {
        return [];
    }
}
