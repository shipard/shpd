<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class FormTab
{
    /**
     * @param FormElement[] $elements
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $elements,
    ) {}

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'label'    => $this->label,
            'elements' => array_map(
                fn(FormElement $el) => $el->toArray(),
                $this->elements,
            ),
        ];
    }
}
