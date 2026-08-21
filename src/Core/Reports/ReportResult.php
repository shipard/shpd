<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Jediný výstup report enginu (D4): samonosný strukturovaný výsledek —
 * metadata + definice sloupců + plochý seznam řádků. Všechny prezentace
 * (REST, budoucí viewer, MCP, diff) jsou renderery nad tímto tvarem.
 *
 * `status` se nezadává — odvozuje se z nejvyšší severity zpráv (D15).
 * Tvar `toArray()`: docs/reports.md §3.1.
 */
final class ReportResult
{
    public readonly ReportStatus $status;
    public readonly \DateTimeImmutable $generatedAt;

    /**
     * @param array<string, mixed> $params Normalizované parametry běhu
     *                                     (vč. klíče `period`).
     * @param string $dataSource Id zdroje dat (`xxxx-xxxx-xxxx-xxxx`).
     * @param ReportMessage[] $messages
     * @param ReportColumn[] $columns
     * @param ReportRow[] $rows
     */
    public function __construct(
        public readonly string $reportId,
        public readonly array $params,
        public readonly string $dataSource,
        public readonly array $messages,
        public readonly array $columns,
        public readonly array $rows,
        ?\DateTimeImmutable $generatedAt = null,
    ) {
        $this->status      = ReportStatus::fromMessages($messages);
        $this->generatedAt = $generatedAt ?? new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reportId'    => $this->reportId,
            'params'      => $this->params,
            'generatedAt' => $this->generatedAt->format(\DateTimeInterface::ATOM),
            'dataSource'  => $this->dataSource,
            'status'      => $this->status->value,
            'messages'    => array_map(static fn (ReportMessage $m): array => $m->toArray(), $this->messages),
            'columns'     => array_map(static fn (ReportColumn $c): array => $c->toArray(), $this->columns),
            'rows'        => array_map(static fn (ReportRow $r): array => $r->toArray(), $this->rows),
        ];
    }
}
