<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Bankovní výpisy, u nichž zůstatkový můstek nesedí
 * (reconciliation_state = 2). Jeden alert per výpis, finding_key = id —
 * reconciler auto-resolvuje, jakmile výpis podmínce nevyhovuje (doplnění
 * chybějící transakce → můstek sedne, stav se přepne na 1).
 */
final class StatementReconciliationCheck extends AlertCheck
{
    private const RECONCILIATION_MISMATCH = 2;
    private const DOC_STATE_DELETED = 90;

    /** Stable tableId of economy_bank_statements — viz tables/economy_bank_statements.jsonc. */
    private const SUBJECT_TABLE_ID = 415;

    public function run(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [statement_number], [period_start], [period_end]
             FROM [economy_bank_statements]
             WHERE [reconciliation_state] = %i AND [docState] != %i
             ORDER BY [id]',
            self::RECONCILIATION_MISMATCH,
            self::DOC_STATE_DELETED,
        );

        $findings = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $findings[] = $this->buildFinding($arr);
        }
        return $findings;
    }

    /** @param array<string, mixed> $row */
    private function buildFinding(array $row): AlertFinding
    {
        $id = (int) $row['id'];
        $label = $this->displayLabel($id, (string) ($row['statement_number'] ?? ''), $row);

        return new AlertFinding(
            findingKey: (string) $id,
            title: $this->buildTitle($label),
            message: $this->buildMessage($label),
            severity: 'warning',
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $id,
            actions: [
                [
                    'id'      => 'open_statement',
                    'label'   => $this->language === 'cs' ? 'Otevřít výpis' : 'Open statement',
                    'kind'    => 'open_form',
                    'variant' => 'primary',
                    'primary' => true,
                    'target'  => [
                        'table' => 'economy_bank_statements',
                        'mode'  => 'edit',
                        'id'    => $id,
                    ],
                ],
            ],
            context: [
                'statement_number' => (string) ($row['statement_number'] ?? ''),
            ],
        );
    }

    private function buildTitle(string $label): string
    {
        return $this->language === 'cs'
            ? sprintf('Výpis %s — zůstatky nesedí', $label)
            : sprintf('Statement %s — balances do not reconcile', $label);
    }

    private function buildMessage(string $label): string
    {
        return $this->language === 'cs'
            ? sprintf(
                'U výpisu %s neodpovídá počáteční zůstatek + obraty koncovému zůstatku. '
                . 'Chybí nebo přebývá transakce — zkontroluj a doplň.',
                $label,
            )
            : sprintf(
                'Statement %s: opening balance plus movements does not match the closing balance. '
                . 'A transaction is missing or extra — review and complete.',
                $label,
            );
    }

    /** @param array<string, mixed> $row */
    private function displayLabel(int $id, string $number, array $row): string
    {
        if ($number !== '') {
            return $number;
        }
        $start = (string) ($row['period_start'] ?? '');
        $end = (string) ($row['period_end'] ?? '');
        if ($start !== '' && $end !== '') {
            return $start . '–' . $end;
        }
        return '#' . $id;
    }
}
