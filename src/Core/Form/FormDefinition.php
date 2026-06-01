<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class FormDefinition
{
    /**
     * @param FormTab[] $tabs
     */
    public function __construct(
        public readonly string $table,
        public readonly string $title,
        public readonly string $titleNew,
        public readonly array $tabs,
        public ?array $docStates = null,
        public ?FormHeaderInfo $headerInfo = null,
    ) {}

    public function withDocStates(array $docStatesInfo): static
    {
        $clone = clone $this;
        $clone->docStates = $docStatesInfo;
        return $clone;
    }

    public function withHeaderInfo(?FormHeaderInfo $headerInfo): static
    {
        $clone = clone $this;
        $clone->headerInfo = $headerInfo;
        return $clone;
    }

    public function toArray(): array
    {
        $result = [
            'table'       => $this->table,
            'title'       => $this->title,
            'title_new'   => $this->titleNew,
            'tabs'        => array_map(
                fn(FormTab $tab) => $tab->toArray(),
                $this->tabs,
            ),
            'header_info' => $this->headerInfo?->toArray(),
        ];

        if ($this->docStates !== null) {
            $result['doc_states'] = $this->docStates;
        }

        return $result;
    }
}
