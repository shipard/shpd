<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

/**
 * Výsledek jedné akce předzpracování. Selhání je provozní stav (expirovaný
 * odkaz, cizí doména, timeout) — zapisuje se do `preprocess_log.results`,
 * nikdy nevyletí jako výjimka (D6).
 */
final readonly class ActionResult
{
    /**
     * @param list<int> $attachmentIds Id vygenerovaných příloh (obsahové
     *        přílohy zprávy s provenance metadaty).
     */
    public function __construct(
        public bool $ok,
        public string $note = '',
        public array $attachmentIds = [],
    ) {
    }

    /** @param list<int> $attachmentIds */
    public static function success(string $note = '', array $attachmentIds = []): self
    {
        return new self(true, $note, $attachmentIds);
    }

    public static function failure(string $note): self
    {
        return new self(false, $note);
    }
}
