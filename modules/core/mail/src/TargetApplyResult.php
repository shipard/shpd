<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Výsledek {@see ProposalTargetApplier::apply()}. Tvar drží mapování
 * 1:1 na {@see ProposalApplyOutcome} factory (`ok`/`error`).
 */
final class TargetApplyResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $savedId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $statusCode,
    ) {}

    public static function ok(int $savedId): self
    {
        return new self(true, $savedId, null, null, 200);
    }

    public static function error(string $errorCode, string $errorMessage, int $statusCode): self
    {
        return new self(false, null, $errorCode, $errorMessage, $statusCode);
    }
}
