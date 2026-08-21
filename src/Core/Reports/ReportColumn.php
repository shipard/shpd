<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

final class ReportColumn
{
    /** Jediný podporovaný typ sloupce ve v1. */
    public const TYPE_MONEY = 'money';

    /** Zobrazovací hint: jedna hodnota (Zůstatek). */
    public const DISPLAY_BALANCE = 'balance';
    /** Zobrazovací hint: trojice MD / D / Zůstatek. */
    public const DISPLAY_SIDES = 'sides';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly string $display = self::DISPLAY_BALANCE,
    ) {
        if ($this->type !== self::TYPE_MONEY) {
            throw new \InvalidArgumentException("ReportColumn '{$id}': unsupported type '{$type}'");
        }
        if (!in_array($this->display, [self::DISPLAY_BALANCE, self::DISPLAY_SIDES], true)) {
            throw new \InvalidArgumentException("ReportColumn '{$id}': unsupported display '{$display}'");
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'label' => $this->label, 'display' => $this->display];
    }
}
