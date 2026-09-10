<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Generátor XML souhrnného hlášení (DPHSHV) ze snapshotu podání
 * (issue #55, X1/X4).
 *
 * Věta R per (kód plnění, DIČ pořizovatele): kód státu a číslo registrace
 * zvlášť (X13), počet plnění a hodnota v celých Kč zaokrouhlená nahoru —
 * to už udělal `FilingRounding` při sestavení, writer jen vypisuje.
 *
 * Následné hlášení se podává jako **plný obsah znovu** (X14): storno
 * řádky (`k_storno`, věta S) Shipard negeneruje. Atributy věty D
 * `poc_radku`, `poc_stran`, `pln_poc_celk` a `suma_pln` popis struktury
 * označuje za nevyplňované, takže tu nejsou.
 */
final class ShvXmlWriter extends EpoXmlWriter
{
    protected function appendBody(\DOMElement $document, FilingXmlInput $input): void
    {
        $row = $this->mapping->row();
        if ($row === null) {
            return;
        }

        foreach ($input->recapRows as $line) {
            [$country, $number] = EpoXmlFormat::euVatId($line['partner_vat_id'] ?? null);

            $this->appendSentence($document, (string) $row['veta'], [
                (string) $row['vatId']['countryAttr'] => $country,
                (string) $row['vatId']['attr']        => $number,
                (string) $row['code']                 => (string) (int) ($line['kod'] ?? 0),
                (string) $row['count']                => EpoXmlFormat::count((int) ($line['count'] ?? 0)),
                (string) $row['value']                => $this->money((float) ($line['value_filed'] ?? 0.0)),
            ]);
        }
    }
}
