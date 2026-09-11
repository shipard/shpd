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
 *
 * Ukazatel na instanci typu se zákonným počátkem (#58, `validFrom`), jehož
 * doklad má efektivní datum před tímto počátkem, je **nekonzistentní** i když
 * instance datum obsahuje → NULL. Bez toho by po nasazení anachronické
 * instance (KH 2013) dál držely doklady a guard by bránil jejich zrušení.
 *
 * Zámek (#55 D26): přepočet nesmí přepsat ukazatel dokladu **ze** zamčené
 * ani **do** zamčené instance — plán změn se nejdřív celý spočítá, pak se
 * ověří proti `locked` dotčených instancí, a teprve potom zapíše. Kolize =
 * DomainException s výčtem dokladů; volající (ReportPeriodDocument::afterPersist)
 * běží v save transakci, uložení sousední instance se odroluje.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class VatPeriodRecalculator
{
    /** Kolik čísel dokladů vyjmenovat v chybě zámku. */
    private const LOCK_MESSAGE_DOCS = 5;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?VatOutputsMapping $mapping,
    ) {}

    /**
     * @return int počet aktualizovaných dokladů
     * @throws \DomainException přepočet by změnil doklad zamčené instance
     */
    public function recomputeForInstance(int $instanceId): int
    {
        $instance = $this->loadInstance($instanceId);
        if ($instance === null) {
            return 0;
        }
        $type = (string) $instance['report_type'];
        $column = array_search($type, DocsHeadsVatPeriodHandler::COLUMN_TYPES, true);
        if ($column === false) {
            return 0;
        }
        $regId = (int) $instance['vat_registration'];
        $begin = (string) VatPeriodAssigner::isoDate($instance['date_begin']);
        $end   = (string) VatPeriodAssigner::isoDate($instance['date_end']);

        $heads = $this->loadHeads($regId, $column, $instanceId, $begin, $end);
        if ($heads === []) {
            return 0;
        }

        $headIds = array_map(static fn (array $h): int => (int) $h['id'], $heads);
        $recapByHead = $this->loadRecapCodes($headIds);

        $referenced = [];
        foreach ($heads as $head) {
            foreach (array_keys(DocsHeadsVatPeriodHandler::COLUMN_TYPES) as $col) {
                if (!empty($head[$col])) {
                    $referenced[(int) $head[$col]] = true;
                }
            }
        }
        $instances = $referenced !== [] ? $this->loadInstances(array_keys($referenced)) : [];

        $lookup = $this->lookup();
        $assigner = new VatPeriodAssigner($lookup, $this->mapping);

        // 1. plán: co by se u kterého dokladu změnilo
        $plan = [];
        foreach ($heads as $head) {
            $duzp = VatPeriodAssigner::isoDate($head['vat_duzp']);
            if ($duzp === null) {
                continue;
            }
            $computed = $assigner->compute($head, $recapByHead[(int) $head['id']] ?? []);

            $currentReturn = !empty($head['vat_period']) ? ($instances[(int) $head['vat_period']] ?? null) : null;
            $returnRange = $currentReturn !== null && $currentReturn['type'] === VatPeriodAssigner::TYPE_RETURN
                ? $currentReturn
                : ($computed['vat_period'] !== null ? $lookup->covering($regId, VatPeriodAssigner::TYPE_RETURN, $duzp) : null);
            $effective = VatPeriodAssigner::effectiveDate(VatPeriodAssigner::isoDate($head['vat_dppd']), $duzp, $returnRange);

            $updates = [];
            foreach (DocsHeadsVatPeriodHandler::COLUMN_TYPES as $col => $colType) {
                $current = !empty($head[$col]) ? (int) $head[$col] : null;
                $date = $colType === VatPeriodAssigner::TYPE_RETURN ? $duzp : $effective;
                $consistent = $current !== null
                    && isset($instances[$current])
                    && $instances[$current]['type'] === $colType
                    && $instances[$current]['date_begin'] <= $date
                    && $instances[$current]['date_end'] >= $date
                    && !$assigner->isBeforeValidFrom($colType, $date);
                if (!$consistent && $computed[$col] !== $current) {
                    $updates[$col] = $computed[$col];
                }
            }
            if ($updates !== []) {
                $plan[(int) $head['id']] = ['head' => $head, 'updates' => $updates];
            }
        }
        if ($plan === []) {
            return 0;
        }

        // 2. zámek: dotčené instance (odkud i kam) nesmí být zamčené
        $touched = [];
        foreach ($plan as $item) {
            foreach ($item['updates'] as $col => $new) {
                if (!empty($item['head'][$col])) {
                    $touched[(int) $item['head'][$col]] = true;
                }
                if ($new !== null) {
                    $touched[(int) $new] = true;
                }
            }
        }
        $locked = $this->lockedInstanceIds(array_keys($touched));
        if ($locked !== []) {
            $offenders = [];
            foreach ($plan as $headId => $item) {
                foreach ($item['updates'] as $col => $new) {
                    $from = !empty($item['head'][$col]) ? (int) $item['head'][$col] : null;
                    if (($from !== null && isset($locked[$from])) || ($new !== null && isset($locked[(int) $new]))) {
                        $offenders[$headId] = (string) ($item['head']['doc_number'] ?? ('#' . $headId));
                        break;
                    }
                }
            }
            if ($offenders !== []) {
                throw new \DomainException(self::lockMessage(array_values($offenders)));
            }
        }

        // 3. zápis
        foreach ($plan as $headId => $item) {
            $this->updateHead($headId, $item['updates']);
        }
        return count($plan);
    }

    /** @param list<string> $docNumbers */
    public static function lockMessage(array $docNumbers): string
    {
        $shown = array_slice($docNumbers, 0, self::LOCK_MESSAGE_DOCS);
        $rest = count($docNumbers) - count($shown);
        $list = implode(', ', $shown) . ($rest > 0 ? " a {$rest} dalších" : '');
        $count = count($docNumbers);
        return "Změna rozsahu by přepsala zařazení dokladů uzamčeného tvrzení ({$count}): {$list}."
            . ' Nejdřív tvrzení odemkněte.';
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return ?array<string, mixed> */
    protected function loadInstance(int $instanceId): ?array
    {
        return $this->db->fetchRow(
            'SELECT [id], [vat_registration], [report_type], [date_begin], [date_end]'
            . ' FROM [economy_vat_report_periods] WHERE [id] = %i',
            $instanceId,
        );
    }

    /** @return list<array<string, mixed>> */
    protected function loadHeads(int $regId, string $column, int $instanceId, string $begin, string $end): array
    {
        return $this->db->fetchAll(
            'SELECT [id], [doc_number], [vat_registration], [vat_duzp], [vat_dppd], [vat_period], [cs_period], [rs_period]'
            . ' FROM [docs_core_heads]'
            . ' WHERE [docState] != 90 AND [vat_registration] = %i AND ('
            . ' %n = %i'
            . ' OR ([vat_duzp] >= %d AND [vat_duzp] <= %d)'
            . ' OR (COALESCE([vat_dppd], [vat_duzp]) >= %d AND COALESCE([vat_dppd], [vat_duzp]) <= %d))',
            $regId, $column, $instanceId, $begin, $end, $begin, $end,
        );
    }

    /**
     * @param list<int> $headIds
     * @return array<int, list<array{vat_code: string}>>
     */
    protected function loadRecapCodes(array $headIds): array
    {
        $recapByHead = [];
        foreach ($this->db->fetchAll(
            'SELECT [doc_head], [vat_code] FROM [docs_core_vat_recap] WHERE [doc_head] IN %in',
            $headIds,
        ) as $row) {
            $recapByHead[(int) $row['doc_head']][] = ['vat_code' => (string) $row['vat_code']];
        }
        return $recapByHead;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{type: string, date_begin: string, date_end: string}>
     */
    protected function loadInstances(array $ids): array
    {
        $instances = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [report_type], [date_begin], [date_end] FROM [economy_vat_report_periods]'
            . ' WHERE [id] IN %in AND [docState] != 90',
            $ids,
        ) as $row) {
            $instances[(int) $row['id']] = [
                'type'       => (string) $row['report_type'],
                'date_begin' => (string) VatPeriodAssigner::isoDate($row['date_begin']),
                'date_end'   => (string) VatPeriodAssigner::isoDate($row['date_end']),
            ];
        }
        return $instances;
    }

    /**
     * @param list<int> $ids
     * @return array<int, true>
     */
    protected function lockedInstanceIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id] FROM [economy_vat_report_periods] WHERE [id] IN %in AND [locked] = 1',
            $ids,
        ) as $row) {
            $out[(int) $row['id']] = true;
        }
        return $out;
    }

    /** Find-only lookup — přepočet nezakládá instance. */
    protected function lookup(): ReportPeriodLookup
    {
        return new ReportPeriodsProvisioner($this->db, $this->mapping?->validFromByType() ?? []);
    }

    /** @param array<string, ?int> $updates */
    protected function updateHead(int $headId, array $updates): void
    {
        $this->db->updateWhere('docs_core_heads', $updates, '[id] = %i', $headId);
    }
}
