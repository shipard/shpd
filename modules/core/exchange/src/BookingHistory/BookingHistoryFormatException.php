<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Chyba formátu souboru účetní historie (`docs/booking-history-format.md`).
 * Nese číslo řádku ve zdrojovém souboru — ten má tisíce záznamů, bez čísla
 * je hlášení nepoužitelné.
 *
 * Pozor: pole se jmenuje `fileLine`, ne `line` — `Exception::$line`
 * (číslo řádku v PHP kódu) je zabrané a nelze ho překrýt.
 */
final class BookingHistoryFormatException extends \RuntimeException
{
    public function __construct(
        public readonly int $fileLine,
        string $problem,
    ) {
        parent::__construct("řádek {$fileLine}: {$problem}");
    }
}
