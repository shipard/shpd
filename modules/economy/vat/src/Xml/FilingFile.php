<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/** Jeden vygenerovaný soubor podání (issue #55, X6). */
final readonly class FilingFile
{
    public function __construct(
        /** `epo-xml` | `epo-preview` | `epo-content` — jde do `metadata.kind` přílohy. */
        public string $kind,
        public string $name,
        public string $content,
        public string $mimeType,
    ) {}
}
