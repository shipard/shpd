<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class ValidationResult
{
    /** @var ValidationError[] */
    private array $errors = [];

    public function addError(string $column, string $message, string $code = ''): self
    {
        $this->errors[] = new ValidationError($column, $message, $code);
        return $this;
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /** @return ValidationError[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function toArray(): array
    {
        return array_map(fn(ValidationError $e) => $e->toArray(), $this->errors);
    }
}
