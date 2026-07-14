<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Isdoc;

/**
 * Chyba konverze ISDOC → canonical. `reason` rozlišuje třídy chyb, aby
 * volající (IsdocImportService) mohl u XML příloh bez ISDOC přípony odlišit
 * "tohle vůbec není ISDOC" (cizí root, nevalidní XML → příloha se tiše
 * přeskočí) od "je to ISDOC, ale neumíme ho zpracovat" (neznámý DocumentType,
 * chybějící povinný element → celá ISDOC větev se vzdá a zpráva jde do AI).
 */
final class IsdocParseException extends \RuntimeException
{
    public const REASON_INVALID_XML = 'invalid_xml';
    public const REASON_FOREIGN_ROOT = 'foreign_root';
    public const REASON_UNSUPPORTED_DOC_TYPE = 'unsupported_doc_type';
    public const REASON_MISSING_ELEMENT = 'missing_element';
    public const REASON_INVALID_ZIP = 'invalid_zip';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidXml(string $detail): self
    {
        return new self(self::REASON_INVALID_XML, "Invalid XML: {$detail}");
    }

    public static function foreignRoot(string $rootInfo): self
    {
        return new self(self::REASON_FOREIGN_ROOT, "Root element is not an ISDOC Invoice: {$rootInfo}");
    }

    public static function unsupportedDocumentType(string $value): self
    {
        return new self(
            self::REASON_UNSUPPORTED_DOC_TYPE,
            "Unsupported ISDOC DocumentType '{$value}' (only 1 = invoice, 2 = credit note are supported)",
        );
    }

    public static function missingElement(string $name): self
    {
        return new self(self::REASON_MISSING_ELEMENT, "Required ISDOC element '{$name}' is missing or empty");
    }

    public static function invalidZip(string $detail): self
    {
        return new self(self::REASON_INVALID_ZIP, "Invalid .isdocx archive: {$detail}");
    }
}
