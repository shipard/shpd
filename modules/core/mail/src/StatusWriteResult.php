<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Výsledek {@see ExtractedDocumentApplier::writeStatusTransition()} — sdílené
 * primitivy, která zapíše nový status extrahovaného dokladu přes Document
 * hooky (validate / beforeSave / afterPersist, tj. i auto-transition zprávy
 * 30→40) v jedné transakci.
 *
 * Sdílí ji apply (status→applied), reject (status→rejected) i no-applier
 * fallback; každý si nad ní postaví vlastní prezentaci. Response-free, aby
 * fungovala i bez `DocumentApplier`/`ConfigRuntime`.
 */
final class StatusWriteResult
{
    /**
     * @param array<int, array{field: string, message: string, code: string}>|null $validationErrors
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $messageNdx,
        public readonly ?int $newStatus,
        public readonly bool $notFound,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $statusCode,
        public readonly ?array $validationErrors = null,
    ) {}

    public static function ok(int $messageNdx, int $newStatus): self
    {
        return new self(
            ok: true,
            messageNdx: $messageNdx,
            newStatus: $newStatus,
            notFound: false,
            errorCode: null,
            errorMessage: null,
            statusCode: 200,
        );
    }

    public static function notFound(): self
    {
        return new self(
            ok: false,
            messageNdx: 0,
            newStatus: null,
            notFound: true,
            errorCode: 'NOT_FOUND',
            errorMessage: null,
            statusCode: 404,
        );
    }

    public static function invalidState(int $messageNdx): self
    {
        return new self(
            ok: false,
            messageNdx: $messageNdx,
            newStatus: null,
            notFound: false,
            errorCode: 'INVALID_STATE',
            errorMessage: 'Document is not in a pending state (10/20/30)',
            statusCode: 409,
        );
    }

    /**
     * @param array<int, array{field: string, message: string, code: string}> $errors
     */
    public static function validationFailed(array $errors, int $messageNdx): self
    {
        return new self(
            ok: false,
            messageNdx: $messageNdx,
            newStatus: null,
            notFound: false,
            errorCode: 'VALIDATION_ERROR',
            errorMessage: 'Validation failed',
            statusCode: 422,
            validationErrors: $errors,
        );
    }

    public static function internalError(int $messageNdx): self
    {
        return new self(
            ok: false,
            messageNdx: $messageNdx,
            newStatus: null,
            notFound: false,
            errorCode: 'INTERNAL_ERROR',
            errorMessage: 'Internal server error',
            statusCode: 500,
        );
    }
}
