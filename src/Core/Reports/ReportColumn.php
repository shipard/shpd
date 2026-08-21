<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

final class ReportColumn
{
    /** Jediný podporovaný typ sloupce ve v1. */
    public const TYPE_MONEY = 'money';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
    ) {
        if ($this->type !== self::TYPE_MONEY) {
            throw new \InvalidArgumentException("ReportColumn '{$id}': unsupported type '{$type}'");
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'label' => $this->label];
    }
}
