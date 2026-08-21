<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Zpráva výsledku reportu (D15): `code` je strojově čitelný
 * (např. `journal.accountNotFound`), `text` lidský a lokalizovaný,
 * `rowRef` volitelná vazba na řádek výsledku.
 */
final class ReportMessage
{
    public function __construct(
        public readonly ReportMessageSeverity $severity,
        public readonly string $code,
        public readonly string $text,
        public readonly ?string $rowRef = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'severity' => $this->severity->value,
            'code'     => $this->code,
            'text'     => $this->text,
        ];
        if ($this->rowRef !== null) {
            $out['rowRef'] = $this->rowRef;
        }
        return $out;
    }
}
