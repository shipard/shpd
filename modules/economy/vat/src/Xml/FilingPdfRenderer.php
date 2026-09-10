<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Tiskové výstupy podání (issue #55, X7): opis přiznání a obsah podání
 * po dokladech.
 *
 * PDF je **pohodlí, ne povinnost** — když render selže, implementace
 * vrátí míň souborů (klidně žádný) a důvod v `warnings()`; XML se uloží
 * i tak a přechod do stavu Podáno projde. Proto rozhraní nehází výjimky.
 */
interface FilingPdfRenderer
{
    /**
     * @param string $baseName jméno souborů bez přípony (shodné s XML)
     * @return list<FilingFile>
     */
    public function render(FilingXmlInput $input, string $baseName): array;

    /**
     * Co se při posledním renderu nepovedlo.
     *
     * @return list<string>
     */
    public function warnings(): array;
}
