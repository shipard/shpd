<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Document\ValidationError;

/**
 * Validace vygenerovaného XML proti oficiálnímu schématu z repozitáře
 * (`modules/economy/vat/xsd/`, issue #55, X5). Selhání je **chyba**:
 * soubor, který neprojde schématem, se nesmí uložit jako příloha podání,
 * natož odeslat.
 *
 * Schéma hlídá strukturu (jména vět a atributů, povinnost, počty, délky
 * a číselné rozsahy), ne obsah — hodnoty jednoznakových kódů jsou v XSD
 * jen `maxLength=1`. Doménovou správnost proto řeší `FilingXmlValidator`
 * ještě před generováním.
 */
final class EpoXsdValidator
{
    /** Typ tvrzení (`economy.vat.reportTypes`) → soubor schématu. */
    public const XSD_BY_REPORT_TYPE = [
        'return' => 'dphdp3_epo2.xsd',
        'cs'     => 'dphkh1_epo2.xsd',
        'rs'     => 'dphshv_epo2.xsd',
    ];

    private readonly string $xsdDir;

    public function __construct(?string $xsdDir = null)
    {
        $this->xsdDir = $xsdDir ?? dirname(__DIR__, 2) . '/xsd';
    }

    public function schemaFile(string $reportType): string
    {
        $file = self::XSD_BY_REPORT_TYPE[$reportType] ?? null;
        if ($file === null) {
            throw new \DomainException("Pro typ tvrzení '{$reportType}' není schéma XSD");
        }
        return $this->xsdDir . '/' . $file;
    }

    /**
     * @return list<string> hlášky schématu; prázdné pole = XML je validní
     */
    public function validate(string $xml, string $reportType): array
    {
        $schema = $this->schemaFile($reportType);
        if (!is_file($schema)) {
            throw new \RuntimeException("Chybí schéma XSD '{$schema}'");
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($xml)) {
                return self::collectErrors();
            }
            if ($dom->schemaValidate($schema)) {
                return [];
            }
            return self::collectErrors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Validace s výjimkou — chyby dostane volající jako `ValidationError`
     * na formuláři, protože typicky jde o nedovyplněnou hlavičku.
     */
    public function assertValid(string $xml, string $reportType): void
    {
        $messages = $this->validate($xml, $reportType);
        if ($messages === []) {
            return;
        }
        throw new FilingXmlValidationException(array_map(
            static fn (string $message): ValidationError => new ValidationError(
                ValidationError::FIELD_FORM,
                'Soubor neodpovídá schématu Finanční správy: ' . $message,
                'xsd_invalid',
            ),
            $messages,
        ));
    }

    /** @return list<string> */
    private static function collectErrors(): array
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $text = trim($error->message);
            // Hlášky libxml nesou celý XPath s namespacem — pro uživatele
            // stačí jméno věty a atributu, které v hlášce zůstává.
            $messages[] = $error->line > 0 ? "řádek {$error->line}: {$text}" : $text;
        }
        return $messages;
    }
}
