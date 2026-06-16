<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Bankovní transakce ve stavu 40 (Zaúčtováno) s chybou účtování
 * (accounting_state = 2). Jeden alert per transakce, finding_key = id —
 * reconciler auto-resolvuje, jakmile transakce podmínce nevyhovuje
 * (přeúčtováno OK přes /_bank/reaccount, nebo opustila stav 40).
 *
 * Bankovní obdoba AccountingErrorsCheck (doklady); subject tableId 414.
 * Výjimky se záměrně nechytají — reconciler při chybě běhu checku
 * existující alerty neresolvuje.
 */
final class BankAccountingErrorsCheck extends AlertCheck
{
    private const TX_STATE_DONE = 40;
    private const ACCOUNTING_STATE_ERROR = 2;

    /** Stable tableId of economy_bank_transactions — viz tables/economy_bank_transactions.jsonc. */
    private const SUBJECT_TABLE_ID = 414;

    public function run(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [date_transaction], [counterparty_name], [symbol1], [accounting_messages]
             FROM [economy_bank_transactions]
             WHERE [docState] = %i AND [accounting_state] = %i
             ORDER BY [id]',
            self::TX_STATE_DONE,
            self::ACCOUNTING_STATE_ERROR,
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
        $id           = (int) $row['id'];
        $label        = $this->displayLabel($id, $row);
        $firstMessage = $this->firstMessage((string) ($row['accounting_messages'] ?? ''));

        return new AlertFinding(
            findingKey: (string) $id,
            title: $this->buildTitle($label),
            message: $this->buildMessage($label, $firstMessage),
            severity: 'warning',
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $id,
            actions: [
                [
                    'id'      => 'open_transaction',
                    'label'   => $this->language === 'cs' ? 'Otevřít transakci' : 'Open transaction',
                    'kind'    => 'open_form',
                    'variant' => 'primary',
                    'primary' => true,
                    'target'  => [
                        'table' => 'economy_bank_transactions',
                        'mode'  => 'edit',
                        'id'    => $id,
                    ],
                ],
            ],
            context: [
                'counterparty_name' => (string) ($row['counterparty_name'] ?? ''),
                'first_message'     => $firstMessage,
            ],
        );
    }

    private function buildTitle(string $label): string
    {
        return $this->language === 'cs'
            ? sprintf('Transakce %s má chybu účtování', $label)
            : sprintf('Transaction %s has an accounting error', $label);
    }

    private function buildMessage(string $label, string $firstMessage): string
    {
        $detail = $firstMessage !== '' ? ' ' . $firstMessage . '.' : '';
        if ($this->language === 'cs') {
            return sprintf(
                'Transakce %s je ve stavu „Zaúčtováno", ale nepodařilo se ji zaúčtovat.%s '
                . 'Oprav účtový rozvrh nebo pohyb a spusť přeúčtování.',
                $label,
                $detail,
            );
        }
        return sprintf(
            'Transaction %s is in "Accounted" state but failed to post to the journal.%s '
            . 'Fix the chart of accounts or the operation and re-run accounting.',
            $label,
            $detail,
        );
    }

    /** První message z accounting_messages JSON (text už je lokalizovaný enginem). */
    private function firstMessage(string $json): string
    {
        if ($json === '') {
            return '';
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded[0]['message'])) {
            return '';
        }
        return rtrim((string) $decoded[0]['message'], '.');
    }

    /** @param array<string, mixed> $row */
    private function displayLabel(int $id, array $row): string
    {
        $counterparty = trim((string) ($row['counterparty_name'] ?? ''));
        $date = $row['date_transaction'] ?? null;
        $dateStr = $date instanceof \DateTimeInterface ? $date->format('j. n. Y') : (string) $date;

        if ($counterparty !== '' && $dateStr !== '') {
            return "{$counterparty} ({$dateStr})";
        }
        if ($counterparty !== '') {
            return $counterparty;
        }
        if ($dateStr !== '') {
            return $dateStr;
        }
        return '#' . $id;
    }
}
