<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Věta D a věta P podání (issue #55, X3): co je uložené v hlavičce
 * snapshotu, se opíše, a co je odvoditelné, se dopočítá až tady.
 *
 * Odvozená pole věty D — konstanty `dokument` / `k_uladis`, kód formy
 * z druhu podání, datum zjištění důvodů, datum podání a zdaňovací období
 * z instance — se **needitují**: jsou to fakta o podání, ne údaje
 * podatele, a jejich zdrojem je záznam podání.
 *
 * Které pole hlavičky patří do které věty, říká config
 * (`header.vetaDFields`), ne tato třída — sady se mezi písemnostmi liší
 * (kontrolní a souhrnné hlášení nemají ve větě D co editovat).
 */
final class FilingHeaderResolver
{
    public function __construct(private readonly VatXmlMapping $mapping) {}

    /** @return array<string, ?string> atributy věty D, už naformátované */
    public function vetaD(FilingXmlInput $input): array
    {
        $header     = $this->mapping->header();
        $attributes = $this->mapping->constants();
        $attributes[$this->mapping->formaAttribute()] = $this->mapping->forma(
            $input->filingKind,
            $input->previousKind,
        );

        foreach ($header['derived'] ?? [] as $attribute) {
            $value = $this->derive((string) $attribute, $input);
            if ($value !== null) {
                $attributes[(string) $attribute] = $value;
            }
        }

        foreach ($header['vetaDFields'] ?? [] as $field) {
            $value = $this->headerValue((string) $field, $input);
            if ($value !== null) {
                $attributes[(string) $field] = $value;
            }
        }
        return $attributes;
    }

    /** @return array<string, ?string> atributy věty P, už naformátované */
    public function vetaP(FilingXmlInput $input): array
    {
        $vetaDFields = $this->mapping->header()['vetaDFields'] ?? [];
        $attributes  = [];

        // Pořadí atributů = pořadí polí v uložené hodnotě, tedy kanonické
        // pořadí schématu — generování je proto deterministické.
        //
        // Klíč, který schéma zrušilo novější verzí, zůstává v uložené
        // hodnotě a vypíše se; když ho mezitím zrušil i formulář úřadu,
        // podání neprojde XSD validací. Je to správný výsledek: takové
        // podání patří do staré struktury, ne do nové.
        foreach (array_keys($input->header) as $field) {
            if (str_starts_with((string) $field, '_') || in_array($field, $vetaDFields, true)) {
                continue;
            }
            $formatted = $this->headerValue((string) $field, $input);
            if ($formatted !== null) {
                $attributes[(string) $field] = $formatted;
            }
        }
        return $attributes;
    }

    /**
     * Odvozený atribut věty D. Neznámé jméno je chyba configu — tiše
     * vynechaný atribut by se projevil až odmítnutím podání na portálu.
     */
    private function derive(string $attribute, FilingXmlInput $input): ?string
    {
        return match ($attribute) {
            'd_zjist'   => EpoXmlFormat::date($input->dateFound),
            'd_poddp'   => EpoXmlFormat::date($input->filingDate()),
            'rok'       => (string) $input->period->year,
            'mesic'     => $input->period->month !== null ? (string) $input->period->month : null,
            'ctvrt'     => $input->period->quarter !== null ? (string) $input->period->quarter : null,
            'zdobd_od'  => EpoXmlFormat::date($input->period->from),
            'zdobd_do'  => EpoXmlFormat::date($input->period->to),
            default     => throw new \DomainException(
                "XML mapování: neznámý odvozený atribut věty D '{$attribute}'",
            ),
        };
    }

    /**
     * Hodnota pole hlavičky ve tvaru pro XML.
     *
     * Typ se nebere ze schématu (writer ho nezná), ale z hodnoty: boolean
     * pole vyjmenovává config (`yesNoFields`), datum se pozná podle tvaru
     * `RRRR-MM-DD` — jiné pole věty P takový tvar nabýt nemůže. DIČ
     * subjektu i zástupce mají ve schématu vzor `[0-9]{1,10}`, takže se
     * z nich vypisují jen číslice.
     *
     * Stát (`countryNameFields`) drží hlavička jako ISO kód, formulář chce
     * **název z číselníku Země** daňového portálu (`naz_zeme_c25`, #55 F3-3).
     * Kód bez názvu je výjimka — validace podání ho ohlásí dřív jako chybu
     * pole, tady už se tiše vypsat nesmí nic.
     */
    private function headerValue(string $field, FilingXmlInput $input): ?string
    {
        $value = $input->header[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, $this->mapping->countryNameFields(), true)) {
            return $this->mapping->countryName($value) ?? throw new \DomainException(
                "Stát '{$value}' nemá název v číselníku zemí daňového portálu (world.cz.epoCountries)",
            );
        }
        if (in_array($field, $this->mapping->header()['yesNoFields'] ?? [], true)) {
            return EpoXmlFormat::yesNo($value);
        }
        if (in_array($field, ['dic', 'zast_ic'], true)) {
            return EpoXmlFormat::taxNumberDigits($value);
        }
        if (EpoXmlFormat::isIsoDate($value)) {
            return EpoXmlFormat::date($value);
        }
        if (is_bool($value)) {
            return EpoXmlFormat::yesNo($value);
        }
        return trim((string) $value);
    }
}
