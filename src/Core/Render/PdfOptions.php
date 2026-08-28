<?php

declare(strict_types=1);

namespace Shipard\Core\Render;

/**
 * Parametry PDF výstupu. Sémantika polí převzata ze starého
 * PdfCreator/pdfRenderer (paperFormat, orientace, okraje per strana,
 * header/footer šablony) kvůli budoucí migraci šablon.
 *
 * Okraje jsou CSS délky (`'1.6cm'`, `'0.5in'`); null = výchozí okraj
 * profilu (RenderProfile::defaultMargin, aplikuje RenderClient).
 * Header/footer šablona = kompletní HTML dokument; přítomnost šablony
 * sama zapíná tisk hlavičky/patičky.
 *
 * Nevalidní hodnoty = programátorská chyba → InvalidArgumentException
 * (provozní stavy vrací RenderClient jako RenderResult).
 */
final readonly class PdfOptions
{
    public const PAPER_FORMATS = ['A3', 'A4', 'A5', 'Letter', 'Legal'];
    public const ORIENTATIONS = ['portrait', 'landscape'];

    public function __construct(
        public string $paperFormat = 'A4',
        public string $orientation = 'portrait',
        public ?string $marginTop = null,
        public ?string $marginBottom = null,
        public ?string $marginLeft = null,
        public ?string $marginRight = null,
        public ?string $headerTemplate = null,
        public ?string $footerTemplate = null,
        public bool $printBackground = false,
    ) {
        if (!in_array($this->paperFormat, self::PAPER_FORMATS, true)) {
            throw new \InvalidArgumentException(
                "PdfOptions: paperFormat must be one of: " . implode(', ', self::PAPER_FORMATS),
            );
        }
        if (!in_array($this->orientation, self::ORIENTATIONS, true)) {
            throw new \InvalidArgumentException(
                "PdfOptions: orientation must be one of: " . implode(', ', self::ORIENTATIONS),
            );
        }
    }

    /** Doplní chybějící okraje výchozí hodnotou profilu. */
    public function withDefaults(RenderProfile $profile): self
    {
        $margin = $profile->defaultMargin();
        return new self(
            paperFormat: $this->paperFormat,
            orientation: $this->orientation,
            marginTop: $this->marginTop ?? $margin,
            marginBottom: $this->marginBottom ?? $margin,
            marginLeft: $this->marginLeft ?? $margin,
            marginRight: $this->marginRight ?? $margin,
            headerTemplate: $this->headerTemplate,
            footerTemplate: $this->footerTemplate,
            printBackground: $this->printBackground,
        );
    }
}
