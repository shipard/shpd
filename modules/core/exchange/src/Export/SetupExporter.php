<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Export;

use Dibi\Connection;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * Sekce `setup/` sady — číselníky, které `ds-reset` + provisionery
 * neobnoví, ale sada na nich stojí (R1 v tasks/dataset-phase1.md):
 * vlastní bankovní účty, registrace DPH, šanony spisovny, mailboxy.
 *
 * Jeden soubor per tabulka (`shpd.dataset.setup.v1`), řádky s přirozeným
 * klíčem, bez interních id a auditních sloupců. Hodnoty se typují podle
 * `TableDefinition`, aby JSON nesl int/float/bool místo řetězců z MySQL.
 * Tabulky modulů, které na DS nejsou aktivní, se přeskočí.
 */
final class SetupExporter
{
    public const FORMAT = 'shpd.dataset.setup.v1';

    /** @var array<string, array{table: string, key: list<string>}> */
    public const TABLES = [
        'bank_accounts'     => ['table' => 'economy_codebooks_bank_accounts',     'key' => ['code']],
        'vat_registrations' => ['table' => 'economy_codebooks_vat_registrations', 'key' => ['country', 'name']],
        'binders'           => ['table' => 'base_registry_binders',               'key' => ['name']],
        'mailboxes'         => ['table' => 'core_mail_mailboxes',                 'key' => ['mailbox_id']],
    ];

    private const SKIP_COLUMNS = ['id', 'docStateMain', 'created', 'modified', 'created_by'];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS (TableLoader::load)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $tables,
    ) {}

    /**
     * @return list<ExportedRecord> jeden záznam per tabulka (id = 0, slug = klíč sekce)
     */
    public function exportAll(): array
    {
        $out = [];
        foreach (self::TABLES as $key => $spec) {
            $def = $this->tables[$spec['table']] ?? null;
            if ($def === null) {
                continue;
            }
            $out[] = new ExportedRecord(0, $key, [
                'format' => self::FORMAT,
                'table'  => $key,
                'rows'   => $this->exportTable($spec['table'], $def, $spec['key']),
            ]);
        }
        return $out;
    }

    /**
     * @param list<string> $keyColumns
     * @return list<array<string, mixed>>
     */
    private function exportTable(string $tableName, TableDefinition $def, array $keyColumns): array
    {
        /** @var array<string, ColumnDefinition> $columns */
        $columns = [];
        foreach ($def->columns as $col) {
            $columns[$col->id] = $col;
        }
        $hasDocState = isset($columns['docState']);

        $order = implode(', ', array_map(static fn(string $c): string => "[{$c}]", [...$keyColumns, 'id']));
        $where = $hasDocState ? ' WHERE [docState] <> 90' : '';
        $rows = $this->db->fetchAll("SELECT * FROM [{$tableName}]{$where} ORDER BY {$order}");

        $out = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $record = [];
            foreach ($columns as $colId => $col) {
                if (in_array($colId, self::SKIP_COLUMNS, true) || !array_key_exists($colId, $row)) {
                    continue;
                }
                $record[$colId] = self::typedValue($col, $row[$colId]);
            }
            $out[] = $record;
        }
        return $out;
    }

    public static function typedValue(ColumnDefinition $col, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($col->type) {
            'tinyint', 'smallint', 'int', 'bigint', 'enumInt' => V::int($value),
            'numeric', 'float' => V::float($value),
            'boolean' => V::bool($value),
            'date' => V::date($value),
            'datetime' => V::dateTime($value),
            'json' => V::json($value),
            default => is_scalar($value) || $value instanceof \DateTimeInterface ? V::str($value) : null,
        };
    }
}
