<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Živé souhrnné hlášení (DPHSHV): agregace per (kód plnění, DIČ odběratele)
 * — počet plnění (= počet recap řádků, shodně se starým VatRS) a hodnota
 * v domácí měně. Plná přesnost — zaokrouhlení nahoru na celé Kč je věc XML
 * (Fáze 3). Chybějící DIČ odběratele je měkká chyba (builder z ní dělá
 * lokalizované ReportMessage), řádek se agreguje pod prázdné DIČ.
 */
final class RecapitulativeStatementCalculator
{
    public function __construct(private readonly VatOutputsMapping $mapping) {}

    /**
     * @param list<array<string, mixed>> $docs Doklady z VatDocumentSelection.
     * @return array{
     *     rows: list<array{kod: int, vatId: string, count: int, value: float}>,
     *     errors: list<array{code: string, docId: int, docNumber: string}>,
     * } `rows` řazené dle (DIČ, kód).
     */
    public function calculate(array $docs): array
    {
        $groups = [];
        $errors = [];

        foreach ($docs as $doc) {
            foreach ($doc['recap'] ?? [] as $row) {
                $sh = $this->mapping->sh((string) $row['vat_code']);
                if ($sh === null) {
                    continue;
                }
                $vatId = (string) ($doc['customer_vat_id'] ?? '');
                if ($vatId === '') {
                    $errors[] = [
                        'code'      => 'missingVatId',
                        'docId'     => (int) $doc['id'],
                        'docNumber' => (string) $doc['doc_number'],
                    ];
                }
                $kod = (int) $sh['kod'];
                $key = "{$kod}|{$vatId}";
                $groups[$key] ??= ['kod' => $kod, 'vatId' => $vatId, 'count' => 0, 'value' => 0.0];
                $groups[$key]['count']++;
                $groups[$key]['value'] += (float) $row['base_dom'];
            }
        }

        $rows = array_values($groups);
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp($a['vatId'], $b['vatId']) ?: ($a['kod'] <=> $b['kod']),
        );
        foreach ($rows as &$row) {
            $row['value'] = round($row['value'], 2);
        }
        unset($row);

        return ['rows' => $rows, 'errors' => $errors];
    }
}
