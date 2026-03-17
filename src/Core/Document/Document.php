<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

abstract class Document
{
    protected array $data = [];
    protected array $originalData = [];

    public function validate(array &$data): ValidationResult
    {
        return new ValidationResult();
    }

    public function beforeSave(array &$data): void
    {
    }

    public function afterSave(array $data): void
    {
    }

    public function beforeDelete(array $data): void
    {
    }

    public function afterDelete(array $data): void
    {
    }

    public function onLoad(array &$data): void
    {
    }
}
