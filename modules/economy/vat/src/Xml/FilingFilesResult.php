<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Výsledek generování souborů podání (issue #55, X6/X7).
 *
 * XML je povinné — bez něj se hodí výjimka. PDF je pohodlí: když render
 * selže, výsledek nese `warnings` a soubory, které vznikly.
 */
final readonly class FilingFilesResult
{
    /**
     * @param list<FilingFile> $files
     * @param list<int>        $attachmentIds id uložených příloh (prázdné u `build()`)
     * @param list<string>     $warnings      co se nepovedlo, ale podání to nebrání
     */
    public function __construct(
        public array $files,
        public array $attachmentIds = [],
        public array $warnings = [],
    ) {}

    public function xml(): FilingFile
    {
        foreach ($this->files as $file) {
            if ($file->kind === FilingFilesService::KIND_XML) {
                return $file;
            }
        }
        throw new \LogicException('Výsledek generování nemá XML — to nemá nastat');
    }

    public function withAttachments(array $attachmentIds): self
    {
        return new self($this->files, $attachmentIds, $this->warnings);
    }

    /** @param list<string> $warnings */
    public function withWarnings(array $warnings): self
    {
        return new self($this->files, $this->attachmentIds, [...$this->warnings, ...$warnings]);
    }
}
