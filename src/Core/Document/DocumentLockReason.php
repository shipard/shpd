<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Jeden důvod, proč je záznam uzamčený (documentLockProviders, #55 D24).
 *
 * `title` je krátká věta pro uživatele („Kontrolní hlášení 01/2026 je
 * uzamčené"), `message` volitelné doplnění. `source` identifikuje typ zámku
 * (`vat_period`, `fiscal_month`, …) a spolu s `params` umožňuje klientovi
 * text lokalizovat; `subjectTableId`/`subjectRowId` míří na zamykající
 * entitu (instance tvrzení, fiskální měsíc). `unlockAction` je rezervované
 * pro průvodce odemknutím z banneru dokladu (navazující issue) — F4a ho jen
 * přenáší.
 */
final readonly class DocumentLockReason
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $unlockAction
     */
    public function __construct(
        public string $source,
        public string $title,
        public string $message = '',
        public ?int $subjectTableId = null,
        public ?int $subjectRowId = null,
        public array $params = [],
        public ?array $unlockAction = null,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('DocumentLockReason: source must not be empty');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('DocumentLockReason: title must not be empty');
        }
        if (($subjectTableId === null) xor ($subjectRowId === null)) {
            throw new \InvalidArgumentException(
                'DocumentLockReason: subjectTableId and subjectRowId must be both set or both null',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'source'  => $this->source,
            'title'   => $this->title,
            'message' => $this->message,
            'params'  => $this->params,
        ];
        if ($this->subjectTableId !== null) {
            $out['subjectTableId'] = $this->subjectTableId;
            $out['subjectRowId']   = $this->subjectRowId;
        }
        if ($this->unlockAction !== null) {
            $out['unlockAction'] = $this->unlockAction;
        }
        return $out;
    }
}
