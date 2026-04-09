<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class RecalculateResult
{
    public function __construct(
        public readonly FormDefinition $formDefinition,
        public readonly array $data,
    ) {}
}
