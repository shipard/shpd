<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class ValidationResult
{
    /** @var ValidationError[] */
    private array $errors = [];

    /** @var ValidationError[] */
    private array $warnings = [];

    public function addError(string $column, string $message, string $code = ''): self
    {
        $this->errors[] = new ValidationError($column, $message, $code);
        return $this;
    }

    /**
     * Warning neblokuje uložení — `isValid()` počítá jen errory. Kanál pro
     * doporučení (např. chybějící bankovní spojení dodavatele): dokument se
     * uloží a warningy putují v success response jako pole `warnings`.
     */
    public function addWarning(string $column, string $message, string $code = ''): self
    {
        $this->warnings[] = new ValidationError($column, $message, $code);
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

    /** @return ValidationError[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function toArray(): array
    {
        return array_map(fn(ValidationError $e) => $e->toArray(), $this->errors);
    }

    public function warningsToArray(): array
    {
        return array_map(fn(ValidationError $e) => $e->toArray(), $this->warnings);
    }
}
