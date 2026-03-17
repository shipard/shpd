<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class ValidationError
{
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
