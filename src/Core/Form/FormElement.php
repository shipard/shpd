<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class FormElement
{
    public function __construct(
        public readonly string $type,
        public readonly int $cols = 1,
        public readonly ?string $column = null,
        public readonly ?string $label = null,
        public readonly ?string $placeholder = null,
        public readonly bool $required = false,
        public readonly bool $readOnly = false,
        public readonly bool $hidden = false,
        public readonly ?string $triggers = null,
        public readonly ?string $hint = null,
        public readonly ?array $options = null,
        public readonly ?array $elements = null,
        public readonly ?string $table = null,
        public readonly ?string $foreignKey = null,
        public readonly ?string $formId = null,
        public readonly ?string $content = null,
        public readonly ?string $inputType = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'type' => $this->type,
            'cols' => $this->cols,
        ];

        if ($this->column !== null) {
            $result['column'] = $this->column;
        }
        if ($this->label !== null) {
            $result['label'] = $this->label;
        }
        if ($this->placeholder !== null) {
            $result['placeholder'] = $this->placeholder;
        }
        if ($this->required) {
            $result['required'] = true;
        }
        if ($this->readOnly) {
            $result['read_only'] = true;
        }
        if ($this->hidden) {
            $result['hidden'] = true;
        }
        if ($this->triggers !== null) {
            $result['triggers'] = $this->triggers;
        }
        if ($this->hint !== null) {
            $result['hint'] = $this->hint;
        }
        if ($this->options !== null) {
            $result['options'] = $this->options;
        }
        if ($this->elements !== null) {
            $result['elements'] = array_map(
                fn(FormElement $el) => $el->toArray(),
                $this->elements,
            );
        }
        if ($this->table !== null) {
            $result['table'] = $this->table;
        }
        if ($this->foreignKey !== null) {
            $result['foreign_key'] = $this->foreignKey;
        }
        if ($this->formId !== null) {
            $result['form_id'] = $this->formId;
        }
        if ($this->content !== null) {
            $result['content'] = $this->content;
        }
        if ($this->inputType !== null) {
            $result['input_type'] = $this->inputType;
        }

        return $result;
    }
}
