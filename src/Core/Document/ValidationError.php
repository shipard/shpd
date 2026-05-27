<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class ValidationError
{
    /**
     * Conventional `column` value for errors that don't belong to a specific
     * field. The frontend renders these in the form's top-level banner rather
     * than next to an input. New form-level validations should use this
     * constant; see docs/edit-forms.md section 8 for the full `field` contract.
     */
    public const FIELD_FORM = '_form';

    public function __construct(
        public readonly string $column,
        public readonly string $message,
        public readonly string $code = '',
    ) {}

    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
