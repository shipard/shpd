<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Dávkový přepočet zařazení dokladů po změně rozsahu instance (issue #55,
 * D13 „editace rozsahu instance spouští přepočet dotčených dokladů").
 *
 * Dávka = doklady registrace, které na instanci míří, nebo jejichž DUZP či
 * efektivní datum (DPPD, fallback DUZP) spadá do nového rozsahu. Přepisují
 * se jen **nekonzistentní** ukazatele: NULL, nebo instance, která datum
 * dokladu (pro svůj typ) neobsahuje. Konzistentní ukazatel jinam se nechá —
 * tak přežije ruční přesun dokladu mezi instancemi, pokud datum dokladu
 * do cílové instance spadá. Chybějící instance se tu nezakládají (find-only);
 * doklad s NULL se dorovná při svém příštím uložení.
 */
final class VatPeriodRecalculator
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?VatOutputsMapping $mapping,
    ) {}

    /** @return int počet aktualizovaných dokladů */
    public function recomputeForInstance(int $instanceId): int
    {
        $instance = $this->db->fetchRow(
            'SELECT [id], [vat_registration], [report_type], [date_begin], [date_end]'
            . ' FROM [economy_vat_report_periods] WHERE [id] = %i',
            $instanceId,
        );
        if ($instance === null) {
            return 0;
        }
        $type = (string) $instance['report_type'];
        $column = DocsHeadsVatPeriodHandler::COLUMN_TYPES;
        $column = array_search($type, $column, true);
        if ($column === false) {
            return 0;
        }
        $regId = (int) $instance['vat_registration'];
        $begin = (string) VatPeriodAssigner::isoDate($instance['date_begin']);
        $end   = (string) VatPeriodAssigner::isoDate($instance['date_end']);

        $heads = $this->db->fetchAll(
            'SELECT [id], [vat_registration], [vat_duzp], [vat_dppd], [vat_period], [cs_period], [rs_period]'
            . ' FROM [docs_core_heads]'
            . ' WHERE [docState] != 90 AND [vat_registration] = %i AND ('
            . ' %n = %i'
            . ' OR ([vat_duzp] >= %d AND [vat_duzp] <= %d)'
            . ' OR (COALESCE([vat_dppd], [vat_duzp]) >= %d AND COALESCE([vat_dppd], [vat_duzp]) <= %d))',
            $regId, $column, $instanceId, $begin, $end, $begin, $end,
        );
        if ($heads === []) {
            return 0;
        }

        $headIds = array_map(static fn (array $h): int => (int) $h['id'], $heads);
        $recapByHead = [];
        foreach ($this->db->fetchAll(
            'SELECT [doc_head], [vat_code] FROM [docs_core_vat_recap] WHERE [doc_head] IN %in',
            $headIds,
        ) as $row) {
            $recapByHead[(int) $row['doc_head']][] = ['vat_code' => (string) $row['vat_code']];
        }

        $referenced = [];
        foreach ($heads as $head) {
            foreach (array_keys(DocsHeadsVatPeriodHandler::COLUMN_TYPES) as $col) {
                if (!empty($head[$col])) {
                    $referenced[(int) $head[$col]] = true;
                }
            }
        }
        $instances = [];
        if ($referenced !== []) {
            foreach ($this->db->fetchAll(
                'SELECT [id], [report_type], [date_begin], [date_end] FROM [economy_vat_report_periods]'
                . ' WHERE [id] IN %in AND [docState] != 90',
                array_keys($referenced),
            ) as $row) {
                $instances[(int) $row['id']] = [
                    'type'       => (string) $row['report_type'],
                    'date_begin' => (string) VatPeriodAssigner::isoDate($row['date_begin']),
                    'date_end'   => (string) VatPeriodAssigner::isoDate($row['date_end']),
                ];
            }
        }

        $lookup = new ReportPeriodsProvisioner($this->db); // find-only
        $assigner = new VatPeriodAssigner($lookup, $this->mapping);

        $updated = 0;
        foreach ($heads as $head) {
            $duzp = VatPeriodAssigner::isoDate($head['vat_duzp']);
            if ($duzp === null) {
                continue;
            }
            $computed = $assigner->compute($head, $recapByHead[(int) $head['id']] ?? []);

            $currentReturn = !empty($head['vat_period']) ? ($instances[(int) $head['vat_period']] ?? null) : null;
            $returnRange = $currentReturn !== null && $currentReturn['type'] === VatPeriodAssigner::TYPE_RETURN
                ? $currentReturn
                : ($computed['vat_period'] !== null ? $lookup->find($regId, VatPeriodAssigner::TYPE_RETURN, $duzp) : null);
            $effective = VatPeriodAssigner::effectiveDate(VatPeriodAssigner::isoDate($head['vat_dppd']), $duzp, $returnRange);

            $updates = [];
            foreach (DocsHeadsVatPeriodHandler::COLUMN_TYPES as $col => $colType) {
                $current = !empty($head[$col]) ? (int) $head[$col] : null;
                $date = $colType === VatPeriodAssigner::TYPE_RETURN ? $duzp : $effective;
                $consistent = $current !== null
                    && isset($instances[$current])
                    && $instances[$current]['type'] === $colType
                    && $instances[$current]['date_begin'] <= $date
                    && $instances[$current]['date_end'] >= $date;
                if (!$consistent && $computed[$col] !== $current) {
                    $updates[$col] = $computed[$col];
                }
            }
            if ($updates !== []) {
                $this->db->updateWhere('docs_core_heads', $updates, '[id] = %i', (int) $head['id']);
                $updated++;
            }
        }
        return $updated;
    }
}
