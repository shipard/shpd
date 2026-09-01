<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Taxes;

/**
 * Rozpad dokladů do sekcí kontrolního hlášení DPHKH1 (tasks/taxes-phase01.md,
 * referenční logika old_shipard VatCSEngine):
 *
 * - config nese jen skupinu (`A1`/`A2`/`A4A5`/`B1`/`B2B3`); rozpad A4/A5
 *   a B2/B3 řeší tato třída per doklad — limit strict > 10 000,00 Kč na
 *   abs() celkové částky dokladu vč. daně v domácí měně; A4 navíc vyžaduje
 *   CZ DIČ odběratele, B2 DIČ netestuje (chybějící je jen měkká chyba),
 * - sazbová pásma (sloupce 1/2/3) z kategorie kódu: standard → 1,
 *   reduced + reduced1 → 2, reduced2 → 3; zero/exempt se neakumulují,
 * - ev. číslo: A1/A4 vlastní číslo dokladu, A2/B1/B2 číslo dodavatele
 *   (`partner_doc_number`), bez jakékoli normalizace,
 * - DPPD = `vat_dppd`, fallback `vat_duzp`,
 * - doklad se dvěma PDP kódy (kodPredPl 4 i 5) = dva řádky A1/B1,
 * - A5/B3 = jeden agregátní součtový řádek (pásma ve sloupcích).
 *
 * Čistá třída bez DB — vstup připravuje VatDocumentSelection, měkké chyby
 * vrací jako data (builder z nich dělá lokalizované ReportMessage).
 */
final class ControlStatementCalculator
{
    /** Limit rozpadu A4/A5 a B2/B3 — strict `>`, vč. daně, domácí měna. */
    public const LIMIT = 10000.0;

    public const SECTIONS = ['A1', 'A2', 'A4', 'A5', 'B1', 'B2', 'B3'];

    /**
     * @param array<string, array<string, mixed>> $vatCodes Definice kódů
     *        z world.vat (klíč = kód; používá se pole `category`).
     */
    public function __construct(
        private readonly VatOutputsMapping $mapping,
        private readonly array $vatCodes,
    ) {}

    /**
     * @param list<array<string, mixed>> $docs Doklady z VatDocumentSelection:
     *        id, doc_number, partner_doc_number, total_amount_dom, vat_duzp,
     *        vat_dppd, customer_vat_id, supplier_vat_id, recap[] (vat_code,
     *        base_dom, tax_dom).
     * @return array{
     *     sections: array<string, list<array<string, mixed>>>,
     *     errors: list<array{code: string, docId: int, docNumber: string, section: string}>,
     * }
     */
    public function calculate(array $docs): array
    {
        $sections   = array_fill_keys(self::SECTIONS, []);
        $aggregates = ['A5' => null, 'B3' => null];
        $errors     = [];

        foreach ($docs as $doc) {
            foreach ($this->groupRecap($doc) as $group) {
                $section = $this->resolveSection($group['group'], $doc);
                if ($section === 'A5' || $section === 'B3') {
                    $aggregates[$section] = $this->addBands(
                        $aggregates[$section] ?? $this->emptyBands(),
                        $group['bands'],
                    );
                    continue;
                }
                $sections[$section][] = $this->detailRow($section, $group, $doc, $errors);
            }
        }

        foreach (['A5', 'B3'] as $aggregate) {
            if ($aggregates[$aggregate] !== null) {
                $sections[$aggregate][] = [
                    'docId'      => null,
                    'evidNumber' => null,
                    'vatId'      => null,
                    'kodPredPl'  => null,
                    'dppd'       => null,
                ] + $this->roundBands($aggregates[$aggregate]);
            }
        }

        foreach (['A1', 'A2', 'A4', 'B1', 'B2'] as $detail) {
            usort(
                $sections[$detail],
                static fn (array $a, array $b): int =>
                    strcmp((string) $a['evidNumber'], (string) $b['evidNumber'])
                        ?: (($a['docId'] ?? 0) <=> ($b['docId'] ?? 0)),
            );
        }

        return ['sections' => $sections, 'errors' => $errors];
    }

    /**
     * Recap řádky dokladu seskupené per KH cíl — skupina + PDP kód; doklad
     * se dvěma PDP kódy tak vyrobí dva řádky.
     *
     * @param array<string, mixed> $doc
     * @return list<array{group: string, kodPredPl: ?int, bands: array<string, float>}>
     */
    private function groupRecap(array $doc): array
    {
        $groups = [];
        foreach ($doc['recap'] ?? [] as $row) {
            $code = (string) $row['vat_code'];
            $kh   = $this->mapping->kh($code);
            if ($kh === null) {
                continue;
            }
            $band = $this->rateBand($code);
            if ($band === null) {
                continue; // zero/exempt se do pásem neakumulují
            }
            $kodPredPl = isset($kh['kodPredPl']) ? (int) $kh['kodPredPl'] : null;
            $key       = $kh['group'] . ($kodPredPl !== null ? ".{$kodPredPl}" : '');
            $groups[$key] ??= ['group' => $kh['group'], 'kodPredPl' => $kodPredPl, 'bands' => $this->emptyBands()];
            $groups[$key]['bands']["base{$band}"] += (float) $row['base_dom'];
            $groups[$key]['bands']["tax{$band}"]  += (float) $row['tax_dom'];
        }
        return array_values($groups);
    }

    /** Sazbové pásmo KH (sloupce 1/2/3) z kategorie kódu. */
    private function rateBand(string $code): ?int
    {
        return match ((string) ($this->vatCodes[$code]['category'] ?? '')) {
            'standard'            => 1,
            'reduced', 'reduced1' => 2,
            'reduced2'            => 3,
            default               => null,
        };
    }

    /** @param array<string, mixed> $doc */
    private function resolveSection(string $group, array $doc): string
    {
        return match ($group) {
            'A4A5'  => $this->overLimit($doc) && $this->hasCzVatId((string) ($doc['customer_vat_id'] ?? ''))
                ? 'A4' : 'A5',
            'B2B3'  => $this->overLimit($doc) ? 'B2' : 'B3',
            default => $group,
        };
    }

    /** @param array<string, mixed> $doc */
    private function overLimit(array $doc): bool
    {
        return abs((float) ($doc['total_amount_dom'] ?? 0.0)) > self::LIMIT;
    }

    /** CZ DIČ dle vzoru starého Shipardu: neprázdné s prefixem 'CZ'. */
    private function hasCzVatId(string $vatId): bool
    {
        return $vatId !== '' && str_starts_with($vatId, 'CZ');
    }

    /**
     * @param array{group: string, kodPredPl: ?int, bands: array<string, float>} $group
     * @param array<string, mixed> $doc
     * @param list<array{code: string, docId: int, docNumber: string, section: string}> $errors
     * @return array<string, mixed>
     */
    private function detailRow(string $section, array $group, array $doc, array &$errors): array
    {
        // A-sekce = naše prodeje (DIČ odběratele), A2/B-sekce = přijatá
        // plnění (DIČ dodavatele + jeho číslo dokladu).
        $received   = in_array($section, ['A2', 'B1', 'B2'], true);
        $vatId      = $received
            ? (string) ($doc['supplier_vat_id'] ?? '')
            : (string) ($doc['customer_vat_id'] ?? '');
        $evidNumber = $received
            ? (string) ($doc['partner_doc_number'] ?? '')
            : (string) ($doc['doc_number'] ?? '');

        if ($section === 'B2' && $vatId === '') {
            $errors[] = [
                'code'      => 'missingVatId',
                'docId'     => (int) $doc['id'],
                'docNumber' => (string) $doc['doc_number'],
                'section'   => $section,
            ];
        }
        if (($section === 'B1' || $section === 'B2') && $evidNumber === '') {
            $errors[] = [
                'code'      => 'missingPartnerDocNumber',
                'docId'     => (int) $doc['id'],
                'docNumber' => (string) $doc['doc_number'],
                'section'   => $section,
            ];
        }

        $dppd = (string) ($doc['vat_dppd'] ?? '');
        if ($dppd === '') {
            $dppd = (string) ($doc['vat_duzp'] ?? '');
        }

        return [
            'docId'      => (int) $doc['id'],
            'evidNumber' => $evidNumber,
            'vatId'      => $vatId,
            'kodPredPl'  => $group['kodPredPl'],
            'dppd'       => $dppd !== '' ? $dppd : null,
        ] + $this->roundBands($group['bands']);
    }

    /** @return array<string, float> */
    private function emptyBands(): array
    {
        return ['base1' => 0.0, 'tax1' => 0.0, 'base2' => 0.0, 'tax2' => 0.0, 'base3' => 0.0, 'tax3' => 0.0];
    }

    /**
     * @param array<string, float> $target
     * @param array<string, float> $add
     * @return array<string, float>
     */
    private function addBands(array $target, array $add): array
    {
        foreach ($add as $key => $value) {
            $target[$key] += $value;
        }
        return $target;
    }

    /**
     * @param array<string, float> $bands
     * @return array<string, float>
     */
    private function roundBands(array $bands): array
    {
        return array_map(static fn (float $value): float => round($value, 2), $bands);
    }
}
