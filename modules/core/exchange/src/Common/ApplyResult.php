<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Common;

/**
 * Outcome of an Applier call (validate / preview / apply). Designed to
 * map 1:1 onto the REST envelope produced by exchange controllers.
 *
 * On success: `canonical` is the enriched payload (with `_resolve`
 * populated by preview, plus a saved record id reported via `savedId`).
 * On failure: `canonical` carries whatever state was reached before
 * the failure — typically already enriched with `_resolve` so the
 * client can decide what to do (set `userAction` and retry).
 *
 * `savedId` is generic across exchange flows — Document flow stores
 * the `docs_core_heads.id`, Person flow the `base_persons_persons.id`.
 * Controllers map it to flow-specific JSON keys (`savedDocId`,
 * `savedPersonId`) at the API boundary.
 */
class ApplyResult
{
    /**
     * @param array<string, mixed> $canonical
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $canonical,
        public readonly ?int $savedId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $statusCode,
    ) {}

    /**
     * @param array<string, mixed> $canonical
     */
    public static function ok(array $canonical, ?int $savedId = null, int $statusCode = 200): self
    {
        return new self(
            success: true,
            canonical: $canonical,
            savedId: $savedId,
            errorCode: null,
            errorMessage: null,
            statusCode: $statusCode,
        );
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public static function error(
        string $code,
        string $message,
        array $canonical = [],
        int $statusCode = 422,
    ): self {
        return new self(
            success: false,
            canonical: $canonical,
            savedId: null,
            errorCode: $code,
            errorMessage: $message,
            statusCode: $statusCode,
        );
    }
}
