<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Výsledek {@see ExtractedTargetApplier::unapply()}. `trashedId` = id
 * cílového záznamu přesunutého do Koše (soft-delete, vratné).
 */
final class TargetUnapplyResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $trashedId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $statusCode,
    ) {}

    public static function ok(int $trashedId): self
    {
        return new self(true, $trashedId, null, null, 200);
    }

    public static function error(string $errorCode, string $errorMessage, int $statusCode): self
    {
        return new self(false, null, $errorCode, $errorMessage, $statusCode);
    }
}
