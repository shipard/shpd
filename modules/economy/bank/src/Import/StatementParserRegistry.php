<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

use Shipard\Module\Economy\Bank\Import\Parsers\CbaXmlParser;
use Shipard\Module\Economy\Bank\Import\Parsers\FioJsonParser;
use Shipard\Module\Economy\Bank\Import\Parsers\GpcParser;

/**
 * Mapuje formatId (z StatementFormatDetector) na instanci parseru.
 * Fáze 2: CAMT / GPC / FIO; ostatní formáty se doplní později.
 */
final class StatementParserRegistry
{
    /** @throws ImportException pro formát bez parseru */
    public function parserFor(string $formatId): StatementParser
    {
        return match ($formatId) {
            'cz.cba-xml'  => new CbaXmlParser(),
            'cz.gpc'      => new GpcParser(),
            'cz.fio-json' => new FioJsonParser(),
            default       => throw new ImportException(
                "Pro formát '{$formatId}' zatím není parser (Fáze 2 podporuje CAMT, GPC, FIO).",
            ),
        };
    }
}
