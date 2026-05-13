<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

final class FormSection
{
    /**
     * @param FormColumn[] $columns Non-empty list of columns.
     */
    public function __construct(
        public readonly array $columns,
        public readonly ?string $title = null,
        public readonly bool $hidden = false,
    ) {
        if ($columns === []) {
            throw new \InvalidArgumentException('FormSection requires at least one column');
        }
        foreach ($columns as $i => $col) {
            if (!$col instanceof FormColumn) {
                throw new \InvalidArgumentException(
                    sprintf('FormSection columns[%d] must be FormColumn, got %s', $i, get_debug_type($col)),
                );
            }
        }
    }

    public function toArray(): array
    {
        $result = [
            'title'   => $this->title,
            'columns' => array_map(
                fn(FormColumn $col) => $col->toArray(),
                $this->columns,
            ),
        ];

        if ($this->hidden) {
            $result['hidden'] = true;
        }

        return $result;
    }
}
