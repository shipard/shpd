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
    ) {}

    public static function ok(array $data): self
    {
        return new self(true, $data);
    }

    public static function validationFailed(ValidationResult $validation): self
    {
        return new self(false, null, $validation);
    }

    public static function error(string $message): self
    {
        return new self(false, null, null, $message);
    }

    public function isSuccess(): bool { return $this->success; }
    public function getData(): ?array { return $this->data; }
    public function getValidation(): ?ValidationResult { return $this->validation; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
}
