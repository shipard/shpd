<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Zdroj registrací a období DPH pro `ReportParamValidator` a katalog
 * reportů — oddělený od DB kvůli unit testům (in-memory fake). Produkční
 * implementace: `DbVatPeriodProvider`.
 */
interface VatPeriodProvider
{
    /** @return array{id: int, name: string}|null */
    public function findRegistration(int $id): ?array;

    /**
     * Období registrace seřazená dle `date_begin`. Data ISO `YYYY-MM-DD`.
     *
     * @return list<array{id: int, name: string, dateBegin: string, dateEnd: string, locked: bool}>
     */
    public function periodsOfRegistration(int $registrationId): array;

    /**
     * Registrace s obdobími — data pro picker období v katalogu reportů.
     * Řazené dle `name`.
     *
     * @return list<array{id: int, name: string, vatId: ?string,
     *     taxPeriodKind: int, csPeriodKind: int, rsPeriodKind: int,
     *     periods: list<array{id: int, name: string, dateBegin: string, dateEnd: string, locked: bool}>}>
     */
    public function registrationsWithPeriods(): array;
}
