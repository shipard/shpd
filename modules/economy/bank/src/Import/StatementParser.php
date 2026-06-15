<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

/**
 * Parser bankovního výpisu. Čistá transformace již dekódovaného UTF-8 textu
 * na pole výpisů — žádný přístup k DB, účtům ani osobám (to je import service).
 * Charset řeší registr (StatementFormatDetector) před voláním parseru.
 */
interface StatementParser
{
    /**
     * @return ParsedStatement[] Jeden soubor = N výpisů (CAMT/GPC mají více).
     */
    public function parse(string $text): array;
}
