<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Outcome of an Applier call (validate / preview / apply). Designed to
 * map 1:1 onto the REST envelope produced by ExchangeController.
 *
 * On success: `canonical` is the enriched payload (with `_resolve`
 * populated by preview, plus `savedDocId` set by apply).
 * On failure: `canonical` carries whatever state was reached before
 * the failure — typically already enriched with `_resolve` so the
 * client can decide what to do (set `userAction` and retry).
 */
class ApplyResult
{
    /**
     * @param array<string, mixed> $canonical
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $canonical,
        public readonly ?int $savedDocId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $statusCode,
    ) {}

    /**
     * @param array<string, mixed> $canonical
     */
    public static function ok(array $canonical, ?int $savedDocId = null, int $statusCode = 200): self
    {
        return new self(
            success: true,
            canonical: $canonical,
            savedDocId: $savedDocId,
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
            savedDocId: null,
            errorCode: $code,
            errorMessage: $message,
            statusCode: $statusCode,
        );
    }
}
