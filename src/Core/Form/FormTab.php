<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class FormTab
{
    /**
     * @param FormElement[] $elements
     * @param string $type  Tab type: 'fields' (default) or 'attachments'
     * @param int|null $tableId  Numeric tableId (for attachments tab)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $elements,
        public readonly string $type = 'fields',
        public readonly ?int $tableId = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'id'       => $this->id,
            'label'    => $this->label,
        ];

        if ($this->type !== 'fields') {
            $result['type'] = $this->type;
        }

        if ($this->tableId !== null) {
            $result['table_id'] = $this->tableId;
        }

        $result['elements'] = array_map(
            fn(FormElement $el) => $el->toArray(),
            $this->elements,
        );

        return $result;
    }
}
