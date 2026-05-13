<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

final class FormColumn
{
    /**
     * @param FormElement[] $elements
     */
    public function __construct(
        public readonly array $elements,
    ) {}

    public function toArray(): array
    {
        return [
            'elements' => array_map(
                fn(FormElement $el) => $el->toArray(),
                $this->elements,
            ),
        ];
    }
}
