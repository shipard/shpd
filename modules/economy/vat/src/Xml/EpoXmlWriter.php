<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Version;

/**
 * Společný základ generátorů XML pro EPO (issue #55, X1): obálka
 * `Pisemnost` → element písemnosti, hlavička (věta D + P) a formátování
 * hodnot podle popisu struktury Finanční správy.
 *
 * Formáty: datum `DD.MM.RRRR`, čísla bez oddělovače tisíců s desetinnou
 * tečkou a s počtem míst dle písemnosti (přiznání celé Kč, hlášení haléře),
 * boolean jako `A` / `N`. **Prázdný a nulový atribut se nevypisuje** —
 * jediná výjimka jsou součtové řádky (`alwaysEmit` v configu), které úřad
 * čeká i nulové; tak to dělal i starý Shipard (`addVItem`).
 *
 * Pořadí vět je dané `xs:sequence` ve schématu, proto se zapisují v pořadí
 * volání `appendBody()` — subclassa je musí dodržet, jinak XSD validace
 * spadne.
 */
abstract class EpoXmlWriter
{
    /** Hlavička `Pisemnost` — identifikace software, ne verze písemnosti. */
    public const SOFTWARE_NAME = 'Shipard';

    public function __construct(protected readonly VatXmlMapping $mapping) {}

    public function write(FilingXmlInput $input): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        // Verze bez git hashe — soubor musí být při opakovaném generování
        // bajtově stejný (kritérium determinismu).
        $pisemnost->setAttribute('nazevSW', self::SOFTWARE_NAME);
        $pisemnost->setAttribute('verzeSW', Version::VERSION);
        $dom->appendChild($pisemnost);

        $document = $dom->createElement($this->mapping->element());
        $document->setAttribute('verzePis', $this->mapping->verzePis());
        $pisemnost->appendChild($document);

        $header = new FilingHeaderResolver($this->mapping);
        $this->appendSentence($document, $this->mapping->header()['vetaD'], $header->vetaD($input));
        $this->appendSentence($document, $this->mapping->header()['vetaP'], $header->vetaP($input));

        $this->appendBody($document, $input);

        return (string) $dom->saveXML();
    }

    /** Věty s obsahem podání — v pořadí, které žádá schéma. */
    abstract protected function appendBody(\DOMElement $document, FilingXmlInput $input): void;

    /**
     * Přidá větu s atributy; prázdné hodnoty vynechá a **prázdnou větu
     * nevytvoří vůbec** (řádek bez hodnot do podání nepatří).
     *
     * @param array<string, string> $attributes už naformátované hodnoty
     */
    protected function appendSentence(\DOMElement $parent, string $name, array $attributes): ?\DOMElement
    {
        $attributes = array_filter($attributes, static fn (mixed $v): bool => $v !== null && $v !== '');
        if ($attributes === []) {
            return null;
        }

        $element = $parent->ownerDocument->createElement($name);
        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, (string) $value);
        }
        $parent->appendChild($element);
        return $element;
    }

    /** Peněžní hodnota v jednotkách písemnosti; `null` = atribut vynechat. */
    protected function money(float $value, bool $keepZero = false): ?string
    {
        return EpoXmlFormat::money($value, $this->mapping->valueScale(), $keepZero);
    }
}
