<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Pravidla pohybu řádku (`operation`, cfgItem docs.core.rowOperations) —
 * tvrdá validace sdílená dvěma místy:
 *
 *   1. DocRowsDocument::validate — uložení řádku přes sub-form
 *   2. DocDocument::validate — přechod dokladu do stavu 40 (záchytná síť
 *      pro řádky vzniklé před zavedením pohybů nebo importem)
 *
 * Měkké (účetní) kontroly — položka acc.entry je typ 2 a má účet — patří
 * do AccountingEngine (Fáze 2), ne sem.
 */
final class DocRowOperationRules
{
    /** Pohyb účtovaný přímo na účet z položky — vyžaduje vyplněný item. */
    public const OPERATION_ACC_ENTRY = 'acc.entry';

    /**
     * Samovyvažující pohyb (`selfBalancing: 1`, FX čtveřice): kroky předpisu
     * pokrývají obě strany, řádek stranu nenese. Kontrola vyrovnanosti ho
     * počítá do MD i DAL a `acc_side` ignoruje (migrace ho může poslat).
     *
     * @param array<string, mixed> $cfgOperations cfgItem docs.core.rowOperations
     */
    public static function isSelfBalancing(string $operation, array $cfgOperations): bool
    {
        return !empty($cfgOperations[$operation]['selfBalancing']);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $cfgOperations cfgItem docs.core.rowOperations
     * @return list<array{column: string, message: string, code: string}>
     */
    public static function validateRow(array $row, string $docType, array $cfgOperations): array
    {
        $rowKind = (int) ($row['row_kind'] ?? 1);
        $operation = trim((string) ($row['operation'] ?? ''));

        if ($rowKind !== 1) {
            if ($operation !== '') {
                return [[
                    'column'  => 'operation',
                    'message' => 'Textový řádek nesmí mít pohyb',
                    'code'    => 'operation_on_text_row',
                ]];
            }
            return [];
        }

        if ($operation === '') {
            return [[
                'column'  => 'operation',
                'message' => 'Pohyb je povinný',
                'code'    => 'required',
            ]];
        }

        $entry = $cfgOperations[$operation] ?? null;
        if (!is_array($entry)) {
            return [[
                'column'  => 'operation',
                'message' => "Neznámý pohyb '{$operation}'",
                'code'    => 'operation_unknown',
            ]];
        }
        if (!isset($entry['docTypes'][$docType])) {
            return [[
                'column'  => 'operation',
                'message' => 'Pohyb není povolen pro tento typ dokladu',
                'code'    => 'operation_not_allowed',
            ]];
        }

        if ($operation === self::OPERATION_ACC_ENTRY && empty($row['item'])) {
            return [[
                'column'  => 'item',
                'message' => 'Pohyb Účetní položka vyžaduje vybranou položku',
                'code'    => 'item_required_for_acc_entry',
            ]];
        }

        return [];
    }
}
