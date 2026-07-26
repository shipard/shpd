<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní generátor období DPH pro aktivní registrace.
 *
 * Pro každou registraci s `docState IN (10, 40, 80)` doplní chybějící
 * období v aktuálním a následujícím kalendářním roce, omezené
 * `valid_from`/`valid_to`.
 *
 * Lookup před insertem je **překryvový** (`date_begin <= kandidát.date_end
 * AND date_end >= kandidát.date_begin`), ne rovnostní na `date_begin`:
 * v tabulce mohou být období importovaná ze starého Shipardu s jinou
 * frekvencí, než má registrace v `tax_period_kind` — rovnostní lookup by je
 * nenašel a založil období překrývající se s existujícím.
 *
 * Lookup **ignoruje docState** — smazaná období (`docState=90`) se proto
 * nikdy nevracejí. S překryvovou semantikou platí tento invariant šířeji:
 * smazané období blokuje generování všeho, co se s ním překrývá (smazané
 * Q1 u čtvrtletní registrace přepnuté na měsíční zablokuje leden, únor
 * i březen).
 */
class VatPeriodsProvisioner
{
    private const KIND_MONTHLY = 1;
    private const KIND_QUARTERLY = 2;

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return array{vatPeriods: array{created: int, existing: int}}
     */
    public function provision(?\DateTimeImmutable $referenceDate = null): array
    {
        $referenceDate ??= new \DateTimeImmutable('today');
        $currentYear = (int) $referenceDate->format('Y');
        $years = [$currentYear, $currentYear + 1];

        $registrations = $this->db->fetchAll(
            'SELECT `id`, `tax_period_kind`, `valid_from`, `valid_to`'
            . ' FROM `economy_codebooks_vat_registrations`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `id`',
        );

        $created = 0;
        $existing = 0;

        foreach ($registrations as $reg) {
            $regId = (int) $reg['id'];
            $kind = (int) $reg['tax_period_kind'];
            $validFrom = new \DateTimeImmutable((string) $reg['valid_from']);
            $validTo = !empty($reg['valid_to'])
                ? new \DateTimeImmutable((string) $reg['valid_to'])
                : null;

            $this->db->begin();
            try {
                foreach ($years as $year) {
                    $genResult = $this->generatePeriodsForYear(
                        $regId,
                        $kind,
                        $year,
                        $validFrom,
                        $validTo,
                    );
                    $created += $genResult['created'];
                    $existing += $genResult['existing'];
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        }

        return ['vatPeriods' => ['created' => $created, 'existing' => $existing]];
    }

    /**
     * @return array{created: int, existing: int}
     */
    private function generatePeriodsForYear(
        int $regId,
        int $kind,
        int $year,
        \DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
    ): array {
        $candidates = $this->buildCandidates($kind, $year);

        $created = 0;
        $existing = 0;

        foreach ($candidates as $candidate) {
            if ($candidate['date_end'] < $validFrom) {
                continue;
            }
            if ($validTo !== null && $candidate['date_begin'] > $validTo) {
                continue;
            }

            // Idempotence přes PŘEKRYV, ne rovnost date_begin: v tabulce mohou být
            // období importovaná ze starého Shipardu s jinou frekvencí, než jakou má
            // registrace v `tax_period_kind` (např. čtvrtletní historie + měsíční
            // registrace). Rovnostní lookup by je nenašel a vygeneroval by období
            // překrývající se s existujícím → nedeterministické dohledání v
            // DocDocument::resolveVatPeriodId() (LIMIT 1 bez ORDER BY).
            $row = $this->db->fetchRow(
                'SELECT `id` FROM `economy_codebooks_vat_periods`'
                . ' WHERE `vat_registration` = %i'
                . ' AND `date_begin` <= %d AND `date_end` >= %d',
                $regId,
                $candidate['date_end']->format('Y-m-d'),
                $candidate['date_begin']->format('Y-m-d'),
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $this->db->insertRow('economy_codebooks_vat_periods', [
                'vat_registration' => $regId,
                'name'             => $candidate['name'],
                'date_begin'       => $candidate['date_begin']->format('Y-m-d'),
                'date_end'         => $candidate['date_end']->format('Y-m-d'),
                'locked'           => 0,
                'docState'         => 40,
                'docStateMain'     => 3,
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * @return list<array{date_begin: \DateTimeImmutable, date_end: \DateTimeImmutable, name: string}>
     */
    private function buildCandidates(int $kind, int $year): array
    {
        $candidates = [];

        if ($kind === self::KIND_MONTHLY) {
            for ($m = 1; $m <= 12; $m++) {
                $dateBegin = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
                $dateEnd = $dateBegin->modify('+1 month -1 day');
                $candidates[] = [
                    'date_begin' => $dateBegin,
                    'date_end'   => $dateEnd,
                    'name'       => sprintf('%02d/%04d', $m, $year),
                ];
            }
            return $candidates;
        }

        if ($kind === self::KIND_QUARTERLY) {
            for ($q = 1; $q <= 4; $q++) {
                $startMonth = ($q - 1) * 3 + 1;
                $dateBegin = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
                $dateEnd = $dateBegin->modify('+3 months -1 day');
                $candidates[] = [
                    'date_begin' => $dateBegin,
                    'date_end'   => $dateEnd,
                    'name'       => sprintf('Q%d/%04d', $q, $year),
                ];
            }
            return $candidates;
        }

        return [];
    }
}
