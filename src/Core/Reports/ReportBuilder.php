<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Výpočet jednoho reportu. Implementace žijí v modulech
 * (`modules/{skupina}/{modul}/src/Reports/`), deklarace na ně odkazuje
 * klíčem `builder`. Builder počítá vždy živě (D12) a mezisoučty emituje
 * sám — nikdy je nedopočítává prezentace (D4).
 */
interface ReportBuilder
{
    public function build(ReportRequest $request): ReportResult;
}
