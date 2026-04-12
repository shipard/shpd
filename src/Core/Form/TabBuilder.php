<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class TabBuilder
{
    /** @var FormElement[][] Stack of element arrays (for group nesting) */
    private array $elementStack;

    /** @var array{label: string, cols: int}[] Metadata for each open group */
    private array $groupMeta = [];

    public function __construct(
        private readonly string $id,
        private readonly string $label,
    ) {
        $this->elementStack = [[]];
    }

    public function addInput(
        string $column,
        int $cols = 1,
        ?string $label = null,
        bool $required = false,
        ?string $triggers = null,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $placeholder = null,
        ?string $hint = null,
        ?string $inputType = null,
    ): static {
        $this->push(new FormElement(
            type: 'input',
            cols: $cols,
            column: $column,
            label: $label,
            placeholder: $placeholder,
            required: $required,
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            hint: $hint,
            inputType: $inputType,
        ));
        return $this;
    }

    public function addSelect(
        string $column,
        int $cols = 1,
        ?string $label = null,
        ?array $options = null,
        ?string $triggers = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
    ): static {
        $this->push(new FormElement(
            type: 'select',
            cols: $cols,
            column: $column,
            label: $label,
            required: $required,
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            options: $options,
        ));
        return $this;
    }

    public function addSeparator(?string $label = null, bool $hidden = false): static
    {
        $this->push(new FormElement(
            type: 'separator',
            cols: 4,
            label: $label,
            hidden: $hidden,
        ));
        return $this;
    }

    public function openGroup(string $label, int $cols = 4): static
    {
        $this->groupMeta[] = ['label' => $label, 'cols' => $cols];
        $this->elementStack[] = [];
        return $this;
    }

    public function closeGroup(): static
    {
        if ($this->groupMeta === []) {
            throw new \LogicException('closeGroup() called without matching openGroup()');
        }

        $elements = array_pop($this->elementStack);
        $meta = array_pop($this->groupMeta);

        $this->push(new FormElement(
            type: 'group',
            cols: $meta['cols'],
            label: $meta['label'],
            elements: $elements,
        ));
        return $this;
    }

    public function addSubtable(
        string $table,
        string $foreignKey,
        ?string $formId = null,
        ?string $label = null,
        int $cols = 4,
    ): static {
        $this->push(new FormElement(
            type: 'subtable',
            cols: $cols,
            label: $label,
            table: $table,
            foreignKey: $foreignKey,
            formId: $formId,
        ));
        return $this;
    }

    public function addHtml(string $content, int $cols = 4): static
    {
        $this->push(new FormElement(
            type: 'html',
            cols: $cols,
            content: $content,
        ));
        return $this;
    }

    public function build(): FormTab
    {
        if ($this->groupMeta !== []) {
            throw new \LogicException('Unclosed group in TabBuilder');
        }

        return new FormTab($this->id, $this->label, $this->autoHideSeparators($this->elementStack[0]));
    }

    /**
     * Automatically hides a separator if all elements following it
     * (until the next separator or end of list) are also hidden.
     *
     * @param FormElement[] $elements
     * @return FormElement[]
     */
    private function autoHideSeparators(array $elements): array
    {
        $result = $elements;
        $count  = count($result);

        for ($i = 0; $i < $count; $i++) {
            if ($result[$i]->type !== 'separator') {
                continue;
            }
            // Collect elements until next separator or end
            $allHidden = true;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($result[$j]->type === 'separator') {
                    break;
                }
                if (!$result[$j]->hidden) {
                    $allHidden = false;
                    break;
                }
            }
            if ($allHidden) {
                // Replace with a hidden copy
                $sep = $result[$i];
                $result[$i] = new FormElement(
                    type: $sep->type,
                    cols: $sep->cols,
                    label: $sep->label,
                    hidden: true,
                );
            }
        }

        return $result;
    }

    private function push(FormElement $element): void
    {
        $this->elementStack[count($this->elementStack) - 1][] = $element;
    }
}
