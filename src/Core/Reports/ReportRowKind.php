<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * `Computed` = dopočtený řádek, který v deníku neexistuje (výsledek
 * hospodaření v rozvaze, D13) — ve Fázi 1 ho nikdo neemituje.
 */
enum ReportRowKind: string
{
    case Detail = 'detail';
    case Subtotal = 'subtotal';
    case Total = 'total';
    case Computed = 'computed';
}
