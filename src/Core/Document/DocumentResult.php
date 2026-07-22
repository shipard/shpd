<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class DocumentResult
{
    public function __construct(
        private bool $success,
        private ?array $data = null,
        private ?ValidationResult $validation = null,
        private ?string $errorMessage = null,
        private bool $domainError = false,
        private ?string $domainErrorCode = null,
    ) {}

    /**
     * Volitelný $validation nese neblokující warningy z Document::validate()
     * do success response (errory by uložení zastavily už ve validationFailed).
     */
    public static function ok(array $data, ?ValidationResult $validation = null): self
    {
        return new self(true, $data, $validation);
    }

    public static function validationFailed(ValidationResult $validation): self
    {
        return new self(false, null, $validation);
    }

    public static function error(string $message): self
    {
        return new self(false, null, null, $message);
    }

    /**
     * Domain error — business rule violation (e.g. "doklad není poslední v řadě").
     * Maps to HTTP 422 INVALID_STATE_TRANSITION (or supplied code) at controller.
     */
    public static function domainError(string $message, ?string $code = null): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            domainError: true,
            domainErrorCode: $code,
        );
    }

    public function isSuccess(): bool { return $this->success; }
    public function getData(): ?array { return $this->data; }
    public function getValidation(): ?ValidationResult { return $this->validation; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function isDomainError(): bool { return $this->domainError; }
    public function getDomainErrorCode(): ?string { return $this->domainErrorCode; }
}
