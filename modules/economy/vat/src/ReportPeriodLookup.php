<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Zdroj instancí tvrzení pro VatPeriodAssigner — buď jen hledá
 * (dávkový přepočet), nebo chybějící instanci založí jako koncept
 * (ReportPeriodsProvisioner při uložení dokladu, D9).
 */
interface ReportPeriodLookup
{
    /**
     * Živá instance dané registrace a typu, jejíž rozsah obsahuje datum.
     *
     * @return ?array{id: int, date_begin: string, date_end: string}
     */
    public function covering(int $registrationId, string $type, string $date): ?array;
}
