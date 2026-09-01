<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Taxes;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Společný výběr dokladů pro všechny tři DPH kalkulátory (D5): doklady
 * ve stavu „V pořádku" (docState 40), jejichž **období DPH spadá do
 * intervalu** — join přes date containment na `economy_codebooks_vat_periods`,
 * nikdy přes DUZP přímo (doklad s ručně přiřazeným obdobím musí skončit
 * ve stejném KH jako ve svém přiznání). K hlavičkám načte řádky
 * `docs_core_vat_recap` (domácí měna) a rozliší DIČ partnera ze snapshotů
 * (`vat_id`, fallback `tax_id`).
 */
final class VatDocumentSelection
{
    private const DOC_STATE_OK = 40;

    public function __construct(private readonly DataSourceConnection $db) {}

    /**
     * @return list<array<string, mixed>> Doklady s klíči: id, doc_type,
     *         doc_number, partner_doc_number, total_amount_dom, vat_duzp,
     *         vat_dppd (ISO string|null), customer_vat_id, supplier_vat_id,
     *         recap (list řádků: vat_code, vat_pct, base_dom, tax_dom,
     *         is_reverse_pair).
     */
    public function load(int $vatRegistrationId, string $dateFrom, string $dateTo): array
    {
        $heads = $this->db->fetchAll(
            'SELECT [h].[id], [h].[doc_type], [h].[doc_number], [h].[partner_doc_number],'
            . ' [h].[total_amount_dom], [h].[vat_duzp], [h].[vat_dppd],'
            . ' [h].[customer_snapshot], [h].[supplier_snapshot]'
            . ' FROM [docs_core_heads] [h]'
            . ' JOIN [economy_codebooks_vat_periods] [vp] ON [vp].[id] = [h].[vat_period]'
            . ' WHERE [h].[vat_registration] = %i AND [h].[docState] = %i'
            . ' AND [vp].[date_begin] >= %d AND [vp].[date_end] <= %d'
            . ' ORDER BY [h].[doc_number], [h].[id]',
            $vatRegistrationId, self::DOC_STATE_OK, $dateFrom, $dateTo,
        );
        if ($heads === []) {
            return [];
        }

        $recapByHead = [];
        $recapRows = $this->db->fetchAll(
            'SELECT [doc_head], [vat_code], [vat_pct], [base_dom], [tax_dom], [is_reverse_pair]'
            . ' FROM [docs_core_vat_recap]'
            . ' WHERE [doc_head] IN %in'
            . ' ORDER BY [doc_head], [order_pos]',
            array_map(static fn (array $h): int => (int) $h['id'], $heads),
        );
        foreach ($recapRows as $row) {
            $recapByHead[(int) $row['doc_head']][] = [
                'vat_code'        => (string) $row['vat_code'],
                'vat_pct'         => (float) $row['vat_pct'],
                'base_dom'        => (float) $row['base_dom'],
                'tax_dom'         => (float) $row['tax_dom'],
                'is_reverse_pair' => (bool) $row['is_reverse_pair'],
            ];
        }

        $docs = [];
        foreach ($heads as $head) {
            $docs[] = [
                'id'                 => (int) $head['id'],
                'doc_type'           => (string) $head['doc_type'],
                'doc_number'         => (string) ($head['doc_number'] ?? ''),
                'partner_doc_number' => (string) ($head['partner_doc_number'] ?? ''),
                'total_amount_dom'   => (float) ($head['total_amount_dom'] ?? 0.0),
                'vat_duzp'           => $this->isoDate($head['vat_duzp']),
                'vat_dppd'           => $this->isoDate($head['vat_dppd']),
                'customer_vat_id'    => $this->vatIdFromSnapshot($head['customer_snapshot']),
                'supplier_vat_id'    => $this->vatIdFromSnapshot($head['supplier_snapshot']),
                'recap'              => $recapByHead[(int) $head['id']] ?? [],
            ];
        }
        return $docs;
    }

    /** DIČ ze snapshotu partnera: `vat_id` (DIČ pro DPH), fallback `tax_id`. */
    private function vatIdFromSnapshot(mixed $snapshot): string
    {
        if (!is_string($snapshot) || $snapshot === '') {
            return '';
        }
        $decoded = json_decode($snapshot, true);
        if (!is_array($decoded)) {
            return '';
        }
        $vatId = trim((string) ($decoded['vat_id'] ?? ''));
        return $vatId !== '' ? $vatId : trim((string) ($decoded['tax_id'] ?? ''));
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = (string) ($value ?? '');
        return $string !== '' ? $string : null;
    }
}
