<?php

declare(strict_types=1);

namespace Shipard\Core\Render;

/**
 * Výsledek renderu / konverze / post-processingu. Provozní selhání
 * (služba nedostupná, timeout, chyba enginu) nikdy nejde ven výjimkou —
 * volající přeskočí s poznámkou a pokračuje (vzor pdfdetach degradace).
 */
final readonly class RenderResult
{
    private function __construct(
        public bool $ok,
        public ?string $pdfContent,
        public ?RenderErrorKind $errorKind,
        public ?string $note,
    ) {
    }

    public static function success(string $pdfContent): self
    {
        return new self(true, $pdfContent, null, null);
    }

    public static function failure(RenderErrorKind $errorKind, string $note = ''): self
    {
        return new self(false, null, $errorKind, $note === '' ? null : $note);
    }
}
