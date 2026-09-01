<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

final class ReportColumn
{
    /** Peněžní sloupec — buňka `{md, d, balance}`. */
    public const TYPE_MONEY = 'money';
    /** Textový sloupec — buňka je prostý string (např. ev. číslo, DIČ). */
    public const TYPE_TEXT = 'text';
    /** Datumový sloupec — buňka je ISO string `YYYY-MM-DD`. */
    public const TYPE_DATE = 'date';

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
        if (!in_array($this->type, [self::TYPE_MONEY, self::TYPE_TEXT, self::TYPE_DATE], true)) {
            throw new \InvalidArgumentException("ReportColumn '{$id}': unsupported type '{$type}'");
        }
        if (!in_array($this->display, [self::DISPLAY_BALANCE, self::DISPLAY_SIDES], true)) {
            throw new \InvalidArgumentException("ReportColumn '{$id}': unsupported display '{$display}'");
        }
        if ($this->display === self::DISPLAY_SIDES && $this->type !== self::TYPE_MONEY) {
            throw new \InvalidArgumentException("ReportColumn '{$id}': display 'sides' requires type 'money'");
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'label' => $this->label, 'display' => $this->display];
    }
}
