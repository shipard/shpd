<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Validace request parametrů proti deklaraci reportu. Období se dle
 * `periodSource` deklarace překládá buď na `FiscalRange` (fiscalYear +
 * monthFrom/monthTo), nebo na `VatPeriodRange` (`period` = id instance
 * daňového tvrzení, jejíž typ musí odpovídat `vatReportType` reportu);
 * ostatní parametry se normalizují dle schématu
 * (neznámý = chyba, chybějící = default).
 *
 * Chyba → `InvalidArgumentException` s lidskou zprávou; controller ji mapuje
 * na HTTP 400.
 */
final class ReportParamValidator
{
    private const PERIOD_TYPE_REGULAR = 1;
    private const PERIOD_TYPE_CLOSING = 2;

    public function __construct(
        private readonly FiscalPeriodProvider $periods,
        private readonly ?ReportPeriodProvider $vatPeriods = null,
    ) {}

    /**
     * @param array<string, mixed> $rawParams
     * @return array{params: array<string, mixed>, range: ?FiscalRange, vatRange: ?VatPeriodRange}
     * @throws \InvalidArgumentException
     */
    public function validate(ReportDefinition $definition, array $rawParams): array
    {
        if ($definition->periodSource === 'vatPeriod') {
            $range    = null;
            $vatRange = $this->resolveVatRange($definition, $rawParams);
            $params   = ['period' => $vatRange->toParamsArray()];
            $reserved = ['period'];
        } else {
            $range    = $this->resolveRange($definition, $rawParams);
            $vatRange = null;
            $params   = ['period' => $range->toParamsArray()];
            $reserved = ['fiscalYear', 'monthFrom', 'monthTo'];
        }

        $schema = [];
        foreach ($definition->params as $param) {
            $schema[$param['id']] = $param;
        }

        foreach ($rawParams as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }
            if (!isset($schema[$key])) {
                throw new \InvalidArgumentException("Unknown parameter '{$key}'");
            }
            $params[$key] = $this->normalizeValue($schema[$key], $value);
        }

        foreach ($schema as $id => $param) {
            if (!array_key_exists($id, $params)) {
                $params[$id] = $param['default'];
            }
        }

        return ['params' => $params, 'range' => $range, 'vatRange' => $vatRange];
    }

    /** @param array<string, mixed> $rawParams */
    private function resolveVatRange(ReportDefinition $definition, array $rawParams): VatPeriodRange
    {
        if ($this->vatPeriods === null) {
            // Chyba zapojení (runner provider vždy dodává), ne uživatelského vstupu.
            throw new \RuntimeException(
                "Report '{$definition->id}': periodSource 'vatPeriod' requires a ReportPeriodProvider",
            );
        }

        $periodId = $rawParams['period'] ?? null;
        if ($periodId === null || $periodId === '' || !is_numeric($periodId) || (int) $periodId != $periodId || (int) $periodId < 1) {
            throw new \InvalidArgumentException("Missing or invalid required parameter 'period'");
        }
        $period = $this->vatPeriods->findPeriod((int) $periodId);
        if ($period === null) {
            throw new \InvalidArgumentException("VAT report period '" . (int) $periodId . "' does not exist");
        }
        if ($period['type'] !== $definition->vatReportType) {
            throw new \InvalidArgumentException(
                "VAT report period '{$period['name']}' is of type '{$period['type']}',"
                . " report '{$definition->id}' requires '{$definition->vatReportType}'",
            );
        }

        return new VatPeriodRange(
            periodId: $period['id'],
            reportType: $period['type'],
            periodName: $period['name'],
            registrationId: $period['registrationId'],
            registrationName: $period['registrationName'],
            dateBegin: $period['dateBegin'],
            dateEnd: $period['dateEnd'],
            locked: $period['locked'],
        );
    }

    /** @param array<string, mixed> $rawParams */
    private function resolveRange(ReportDefinition $definition, array $rawParams): FiscalRange
    {
        $yearName = $rawParams['fiscalYear'] ?? null;
        if (!is_string($yearName) || $yearName === '') {
            throw new \InvalidArgumentException("Missing required parameter 'fiscalYear'");
        }

        $year = $this->periods->findYearByName($yearName);
        if ($year === null) {
            throw new \InvalidArgumentException("Fiscal year '{$yearName}' does not exist");
        }

        $months = $this->periods->monthsOfYear($year['id']);
        // Běžné měsíce v pořadí date_begin = pozice 1..N; otevírací období
        // (a cokoli před prvním běžným měsícem intervalu) spadá do "before".
        $regular = array_values(array_filter(
            $months,
            static fn (array $m): bool => $m['periodType'] === self::PERIOD_TYPE_REGULAR,
        ));
        $count = count($regular);
        if ($count === 0) {
            throw new \InvalidArgumentException("Fiscal year '{$yearName}' has no regular fiscal months");
        }

        $from = $this->parseMonth($rawParams, 'monthFrom', $yearName, $count);
        $to   = $this->parseMonth($rawParams, 'monthTo', $yearName, $count);
        if ($from > $to) {
            throw new \InvalidArgumentException("'monthFrom' must not be greater than 'monthTo'");
        }

        $this->checkGranularity($definition, $from, $to, $count);

        $firstInRangeId = $regular[$from - 1]['id'];
        $idsBefore = [];
        foreach ($months as $month) {
            if ($month['id'] === $firstInRangeId) {
                break;
            }
            // Uzavírací období roku se řadí na konec dle date_begin; defenzivně
            // ho z "before" vylučujeme i kdyby datované bylo jinak.
            if ($month['periodType'] === self::PERIOD_TYPE_CLOSING) {
                continue;
            }
            $idsBefore[] = $month['id'];
        }

        $idsInRange = [];
        for ($i = $from - 1; $i <= $to - 1; $i++) {
            $idsInRange[] = $regular[$i]['id'];
        }

        return new FiscalRange(
            fiscalYearId: $year['id'],
            fiscalYear: $year['name'],
            monthFrom: $from,
            monthTo: $to,
            monthIdsBefore: $idsBefore,
            monthIdsInRange: $idsInRange,
        );
    }

    /** @param array<string, mixed> $rawParams */
    private function parseMonth(array $rawParams, string $key, string $yearName, int $count): int
    {
        $value = $rawParams[$key] ?? null;
        if ($value === null || $value === '' || !is_numeric($value) || (int) $value != $value) {
            throw new \InvalidArgumentException("Missing or invalid required parameter '{$key}'");
        }
        $month = (int) $value;
        if ($month < 1 || $month > $count) {
            throw new \InvalidArgumentException(
                "Fiscal month {$month} does not exist in fiscal year '{$yearName}' (1–{$count})",
            );
        }
        return $month;
    }

    /**
     * Interval musí odpovídat některé z granularit deklarovaných reportem —
     * picker nenabízí nic jiného (D8), API drží stejnou hranici.
     */
    private function checkGranularity(ReportDefinition $definition, int $from, int $to, int $count): void
    {
        $length  = $to - $from + 1;
        $matched = [];
        if ($length === 1) {
            $matched[] = 'month';
        }
        if ($length === 3 && ($from - 1) % 3 === 0) {
            $matched[] = 'quarter';
        }
        if ($length === 6 && ($from - 1) % 6 === 0) {
            $matched[] = 'halfYear';
        }
        if ($from === 1 && $to === $count) {
            $matched[] = 'year';
        }

        if (array_intersect($matched, $definition->periodGranularities) === []) {
            throw new \InvalidArgumentException(
                "Period {$from}–{$to} does not match any allowed granularity ("
                . implode('|', $definition->periodGranularities) . ')',
            );
        }
    }

    /** @param array{id: string, type: string, options: list<string>} $param */
    private function normalizeValue(array $param, mixed $value): mixed
    {
        if ($param['type'] === 'bool') {
            if (is_bool($value)) {
                return $value;
            }
            $map = ['true' => true, '1' => true, 'false' => false, '0' => false];
            if (is_string($value) && isset($map[strtolower($value)])) {
                return $map[strtolower($value)];
            }
            throw new \InvalidArgumentException("Parameter '{$param['id']}' must be a boolean");
        }

        // enum
        if (!is_string($value) || !in_array($value, $param['options'], true)) {
            throw new \InvalidArgumentException(
                "Parameter '{$param['id']}' must be one of " . implode('|', $param['options']),
            );
        }
        return $value;
    }
}
