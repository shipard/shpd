<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Zdroj fiskálních období pro `ReportParamValidator` — oddělený od DB kvůli
 * unit testům (in-memory fake). Produkční implementace:
 * `DbFiscalPeriodProvider`.
 */
interface FiscalPeriodProvider
{
    /** @return array{id: int, name: string}|null */
    public function findYearByName(string $name): ?array;

    /**
     * Všechny fiskální měsíce roku seřazené dle `date_begin`
     * (otevírací období první, uzavírací poslední).
     *
     * @return list<array{id: int, periodType: int}>
     */
    public function monthsOfYear(int $fiscalYearId): array;

    /**
     * Fiskální roky s počtem běžných měsíců (`period_type` 1) — data pro
     * picker období v katalogu reportů. Řazené dle `name`.
     *
     * @return list<array{name: string, months: int}>
     */
    public function regularYears(): array;
}
