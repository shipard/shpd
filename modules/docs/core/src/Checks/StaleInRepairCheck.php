<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Detekuje doklady, které visí ve stavu „V opravě" (docState = 80) déle než
 * `STALE_HOURS` hodin. Jeden alert per stale doklad.
 *
 * `finding_key` = ID dokladu jako string → reconciler dedupuje napříč běhy
 * a auto-resolvuje, jakmile doklad ze stavu 80 odejde (zpět do 40 V pořádku,
 * 30 Storno, nebo 90 Smazáno).
 */
final class StaleInRepairCheck extends AlertCheck
{
    private const STALE_HOURS = 24;

    private const DOC_STATE_IN_REPAIR = 80;

    /** Stable tableId of docs_core_heads — see tables/docs_core_heads.jsonc. */
    private const SUBJECT_TABLE_ID = 401;

    public function run(): array
    {
        $threshold = (new \DateTimeImmutable('-' . self::STALE_HOURS . ' hours'))
            ->format('Y-m-d H:i:s');

        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_number], [doc_text], [doc_state_changed_at]
             FROM [docs_core_heads]
             WHERE [docState] = %i
               AND [doc_state_changed_at] IS NOT NULL
               AND [doc_state_changed_at] < %s
             ORDER BY [doc_state_changed_at] ASC',
            self::DOC_STATE_IN_REPAIR,
            $threshold,
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
        $id        = (int) $row['id'];
        $docNumber = (string) ($row['doc_number'] ?? '');
        $docText   = (string) ($row['doc_text'] ?? '');
        $changedAt = $this->normalizeDateTime($row['doc_state_changed_at'] ?? '');

        $days     = $this->daysSince($changedAt);
        $docLabel = $this->displayLabel($id, $docNumber);

        return new AlertFinding(
            findingKey: (string) $id,
            title: $this->buildTitle($docLabel, $days),
            message: $this->buildMessage($docLabel, $docText, $changedAt),
            severity: 'warning',
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $id,
            actions: [
                [
                    'id'      => 'open_doc',
                    'label'   => $this->actionLabelOpenDoc(),
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
                'doc_number'           => $docNumber,
                'doc_state_changed_at' => $changedAt,
                'days_stale'           => $days,
            ],
        );
    }

    private function buildTitle(string $label, int $days): string
    {
        if ($this->language === 'cs') {
            return sprintf('Doklad %s je v opravě %s', $label, $this->czechDays($days));
        }
        return sprintf(
            'Document %s has been in repair for %d day%s',
            $label,
            $days,
            $days === 1 ? '' : 's',
        );
    }

    private function buildMessage(string $label, string $docText, string $changedAt): string
    {
        $textPart = $docText !== '' ? sprintf(' (%s)', $docText) : '';
        if ($this->language === 'cs') {
            return sprintf(
                'Doklad %s%s je ve stavu „V opravě" už od %s. Stojí to za pozornost — '
                . 'buď ho dokončit („V pořádku"), nebo vrátit do Konceptu.',
                $label,
                $textPart,
                $this->formatDate($changedAt),
            );
        }
        return sprintf(
            'Document %s%s has been in "Being edited" state since %s. '
            . 'Either complete it (mark as Done) or revert to Draft.',
            $label,
            $textPart,
            $this->formatDate($changedAt),
        );
    }

    private function actionLabelOpenDoc(): string
    {
        return $this->language === 'cs' ? 'Otevřít doklad' : 'Open document';
    }

    /**
     * Doklad obvykle má reálné `doc_number` (přechod do 80 vede přes 40,
     * tedy číslo už je přiděleno), ale defenzivně padáme na "#{id}", pokud
     * by `doc_number` byl prázdný nebo placeholder (`!0000000...`).
     */
    private function displayLabel(int $id, string $docNumber): string
    {
        if ($docNumber === '' || str_starts_with($docNumber, '!')) {
            return '#' . $id;
        }
        return $docNumber;
    }

    private function daysSince(string $datetime): int
    {
        if ($datetime === '') {
            return 0;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return 0;
        }
        $diff = time() - $ts;
        return max(0, (int) floor($diff / 86400));
    }

    /** "1 den" / "2 dny" / "5 dnů" */
    private function czechDays(int $n): string
    {
        if ($n === 1) {
            return '1 den';
        }
        if ($n >= 2 && $n <= 4) {
            return $n . ' dny';
        }
        return $n . ' dnů';
    }

    private function formatDate(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        if ($this->language === 'cs') {
            return date('j. n. Y H:i', $ts);
        }
        return date('Y-m-d H:i', $ts);
    }

    /**
     * Normalize a datetime value from Dibi — `datetime` columns come back as
     * `\Dibi\DateTime` objects, not strings. Cast to canonical SQL format
     * so downstream string-based helpers work the same as for direct strings.
     */
    private function normalizeDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return (string) $value;
    }
}
