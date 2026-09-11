<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\VatPeriodAssigner;

/**
 * Alert `economy.vat.filed_unlocked_periods` (#55 D25): instance přiznání
 * s podaným řádným podáním starším než GRACE_DAYS dní, která není
 * uzamčená. „Podáno" samo instanci nezamyká (D18) — zámek je vědomý krok,
 * alert ho připomene, až uživatel dodělá práci kolem podání.
 */
class FiledUnlockedPeriodsCheck extends AlertCheck
{
    private const TABLE_ID = 441;

    /** Dny od podání, po které alert nehlásí — ať neruší uprostřed práce. */
    public const GRACE_DAYS = 3;

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->rows() as $row) {
            $id = (int) $row['id'];
            $name = (string) $row['name'];
            $filed = (string) ($row['date_filed'] ?? '');

            $findings[] = new AlertFinding(
                findingKey: 'period:' . $id,
                title: $isCs
                    ? "Podané přiznání {$name} není uzamčené"
                    : "Filed return {$name} is not locked",
                message: $isCs
                    ? "Řádné podání bylo odesláno {$filed}. Uzamkněte tvrzení, aby se obsah podaného období"
                        . ' už neměnil (Daňová tvrzení → Uzamknout).'
                    : "The regular filing was submitted on {$filed}. Lock the period so its filed content"
                        . ' can no longer change (Report periods → Lock).',
                severity: 'warning',
                subjectTableId: self::TABLE_ID,
                subjectRowId: $id,
                actions: [[
                    'id'       => 'open_period',
                    'label'    => $isCs ? 'Otevřít tvrzení' : 'Open period',
                    'kind'     => 'open_viewer',
                    'viewerId' => 'economy.vat.reportPeriods',
                    'recordId' => $id,
                    'primary'  => true,
                ]],
            );
        }
        return $findings;
    }

    /**
     * Neuzamčené instance `return` s podaným řádným podáním starším než
     * GRACE_DAYS. Seam pro testy.
     *
     * @return list<array{id: int, name: string, date_filed: string}>
     */
    protected function rows(): array
    {
        $cutoff = (new \DateTimeImmutable($this->today()))->modify('-' . self::GRACE_DAYS . ' days')->format('Y-m-d');
        $rows = $this->db->fetchAll(
            'SELECT [p].[id], [p].[name], MAX([f].[date_filed]) AS [date_filed]'
            . ' FROM [economy_vat_report_periods] [p]'
            . ' JOIN [economy_vat_filings] [f] ON [f].[report_period] = [p].[id]'
            . '   AND [f].[docState] = %i AND [f].[filing_kind] = %s AND [f].[date_filed] <= %d'
            . ' WHERE [p].[report_type] = %s AND [p].[docState] != 90 AND [p].[locked] = 0'
            . ' GROUP BY [p].[id], [p].[name]'
            . ' ORDER BY [date_filed] DESC, [p].[id] DESC',
            FilingDocument::DOC_STATE_FILED, 'regular', $cutoff, VatPeriodAssigner::TYPE_RETURN,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'date_filed' => (string) VatPeriodAssigner::isoDate($row['date_filed']),
            ];
        }
        return $out;
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }
}
