<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

/**
 * Výsledek jednoho běhu `AlertReconciler::runCheck()`.
 *
 * Status:
 *  - `ok`      — check proběhl bez chyby a vrátil 0 nálezů
 *  - `found`   — check proběhl bez chyby a vrátil 1..N nálezů
 *  - `error`   — check hodil výjimku, existující alerty se NEresolvovaly
 *  - `skipped` — check byl `enabled: false` v registry, NEBO právě běží
 *                v jiném procesu (lock); reconciler ho přeskočil
 */
final readonly class AlertRunResult
{
    public const STATUS_OK      = 'ok';
    public const STATUS_FOUND   = 'found';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public string $checkId,
        public string $status,
        public int $findingsCount = 0,
        public int $newCount = 0,
        public int $updatedCount = 0,
        public int $resolvedCount = 0,
        public int $durationMs = 0,
        public ?string $errorMessage = null,
        public ?string $skippedReason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'checkId'        => $this->checkId,
            'status'         => $this->status,
            'findingsCount'  => $this->findingsCount,
            'newCount'       => $this->newCount,
            'updatedCount'   => $this->updatedCount,
            'resolvedCount'  => $this->resolvedCount,
            'durationMs'     => $this->durationMs,
            'errorMessage'   => $this->errorMessage,
            'skippedReason'  => $this->skippedReason,
        ];
    }
}
