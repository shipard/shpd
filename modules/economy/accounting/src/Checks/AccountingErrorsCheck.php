<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Doklady ve stavu 40 (V pořádku) s chybou účtování
 * (accounting_state = 2). Jeden alert per doklad, finding_key = id —
 * reconciler auto-resolvuje, jakmile doklad podmínce nevyhovuje
 * (přeúčtováno OK přes /_accounting/reaccount, nebo opustil 40).
 *
 * Výjimky se záměrně nechytají — reconciler při chybě běhu checku
 * existující alerty neresolvuje.
 */
final class AccountingErrorsCheck extends AlertCheck
{
    private const DOC_STATE_OK = 40;
    private const ACCOUNTING_STATE_ERROR = 2;

    /** Stable tableId of docs_core_heads — see tables/docs_core_heads.jsonc. */
    private const SUBJECT_TABLE_ID = 401;

    public function run(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_number], [doc_text], [accounting_messages]
             FROM [docs_core_heads]
             WHERE [docState] = %i AND [accounting_state] = %i
             ORDER BY [id]',
            self::DOC_STATE_OK,
            self::ACCOUNTING_STATE_ERROR,
        );

        $findings = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $findings[] = $this->buildFinding($arr);
        }
        return $findings;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildFinding(array $row): AlertFinding
    {
        $id           = (int) $row['id'];
        $docLabel     = $this->displayLabel($id, (string) ($row['doc_number'] ?? ''));
        $firstMessage = $this->firstMessage((string) ($row['accounting_messages'] ?? ''));

        return new AlertFinding(
            findingKey: (string) $id,
            title: $this->buildTitle($docLabel),
            message: $this->buildMessage($docLabel, $firstMessage),
            severity: 'warning',
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $id,
            actions: [
                [
                    'id'      => 'open_doc',
                    'label'   => $this->language === 'cs' ? 'Otevřít doklad' : 'Open document',
                    'kind'    => 'open_form',
                    'variant' => 'primary',
                    'primary' => true,
                    'target'  => [
                        'table' => 'docs_core_heads',
                        'mode'  => 'edit',
                        'id'    => $id,
                    ],
                ],
            ],
            context: [
                'doc_number'    => (string) ($row['doc_number'] ?? ''),
                'first_message' => $firstMessage,
            ],
        );
    }

    private function buildTitle(string $label): string
    {
        return $this->language === 'cs'
            ? sprintf('Doklad %s má chybu účtování', $label)
            : sprintf('Document %s has an accounting error', $label);
    }

    private function buildMessage(string $label, string $firstMessage): string
    {
        $detail = $firstMessage !== '' ? ' ' . $firstMessage . '.' : '';
        if ($this->language === 'cs') {
            return sprintf(
                'Doklad %s je ve stavu „V pořádku", ale nepodařilo se ho zaúčtovat.%s '
                . 'Oprav účtový rozvrh nebo položku a spusť přeúčtování.',
                $label,
                $detail,
            );
        }
        return sprintf(
            'Document %s is in "OK" state but failed to post to the journal.%s '
            . 'Fix the chart of accounts or the item and re-run accounting.',
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

    private function displayLabel(int $id, string $docNumber): string
    {
        if ($docNumber === '' || str_starts_with($docNumber, '!')) {
            return '#' . $id;
        }
        return $docNumber;
    }
}
