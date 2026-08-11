<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * HTTP-agnostický výsledek {@see MessageProposalApplier}. Nese úspěch
 * i rozlišitelné chybové cesty, takže HTTP slupka i MCP nástroj nad ním
 * postaví vlastní prezentaci (Response / obálka).
 *
 * `statusCode` je HTTP-like hint pro controller mapper; `canonical` u chyby
 * typicky nese `_resolve.issues` (co dořešit), u úspěchu enriched payload.
 */
final class ProposalApplyOutcome
{
    /**
     * @param array<string, mixed>|null $canonical
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $messageNdx,
        public readonly ?int $analysisNdx,
        public readonly ?int $savedDocId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $statusCode,
        public readonly ?array $canonical,
        public readonly bool $idempotent = false,
        public readonly bool $recovered = false,
    ) {}

    /**
     * @param array<string, mixed>|null $canonical
     */
    public static function ok(
        int $messageNdx,
        ?int $analysisNdx,
        ?int $savedDocId,
        ?array $canonical,
        bool $idempotent = false,
        bool $recovered = false,
    ): self {
        return new self(
            ok: true,
            messageNdx: $messageNdx,
            analysisNdx: $analysisNdx,
            savedDocId: $savedDocId,
            errorCode: null,
            errorMessage: null,
            statusCode: 200,
            canonical: $canonical,
            idempotent: $idempotent,
            recovered: $recovered,
        );
    }

    /**
     * @param array<string, mixed>|null $canonical
     */
    public static function error(
        int $messageNdx,
        ?int $analysisNdx,
        string $errorCode,
        ?string $errorMessage,
        int $statusCode,
        ?array $canonical = null,
    ): self {
        return new self(
            ok: false,
            messageNdx: $messageNdx,
            analysisNdx: $analysisNdx,
            savedDocId: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            statusCode: $statusCode,
            canonical: $canonical,
        );
    }
}
