<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Zdroj instancí daňových tvrzení (`economy_vat_report_periods`) pro
 * `ReportParamValidator` a katalog reportů — oddělený od DB kvůli unit
 * testům (in-memory fake). Produkční implementace: `DbReportPeriodProvider`.
 *
 * Tvar instance: `{id, registrationId, registrationName, type
 * (return|cs|rs), name, dateBegin, dateEnd (ISO), locked, docState}`.
 */
interface ReportPeriodProvider
{
    /**
     * Živá instance (docState != 90) dle id.
     *
     * @return ?array{id: int, registrationId: int, registrationName: string, type: string,
     *     name: string, dateBegin: string, dateEnd: string, locked: bool, docState: int}
     */
    public function findPeriod(int $id): ?array;

    /**
     * Registrace s instancemi všech typů — data pro picker období v katalogu
     * reportů. Registrace řazené dle `name`, instance dle `dateBegin`.
     *
     * @return list<array{id: int, name: string, vatId: ?string,
     *     periods: list<array{id: int, type: string, name: string, dateBegin: string,
     *     dateEnd: string, locked: bool, docState: int}>}>
     */
    public function registrationsWithPeriods(): array;
}
