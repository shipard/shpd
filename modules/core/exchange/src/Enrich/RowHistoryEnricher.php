<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Obohacení řádků AI-extrahovaného canonical dokumentu z historie dokladů
 * téhož partnera (deterministická vrstva 0 „AI párování položek").
 *
 * Čistá funkce canonical → canonical: pro řádky bez `item.ourCode` dohledá
 * v řádcích dřívějších dokladů stejného partnera a doc_type shodný popis
 * a propíše z něj trojici `item.ourCode` + `vat.code` + `account` (D1).
 * Doplňuje jen prázdná pole — AI extrakce ani userActions se nepřepisují
 * (D3; userActions se mergují až po enrichmentu a reconcile fáze
 * DocumentApplieru má absolutní přednost).
 *
 * Matchování popisu (D5), první zásah vyhrává, historie nejnovější první:
 *
 *   0. exact match syrového textu               → historyExactRaw  / high
 *   1. exact match normalizovaného textu        → historyExactNorm / high
 *   2. Jaccard token-set ≥ FUZZY_THRESHOLD      → historyFuzzy     / medium
 *
 * Audit per řádek jde do `_resolve.rows[i].enrichment` (D6) — blok se
 * zapisuje vždy, i pro nenapárované a přeskočené řádky. Opakovaný běh je
 * autoritativní a idempotentní: vlastní dřívější návrhy (poznané podle
 * `enrichment.suggested` == aktuální hodnota) se nejdřív odvolají a matchuje
 * se znovu proti aktuální DB, takže persistnutý enrichment z `/result`
 * nezablokuje fresh běh při preview/apply (D2).
 *
 * Partner nedohledán, `selfParty` chybí nebo doklad nemá řádky → tichý skip,
 * canonical se vrátí beze změny (D4). Žádné side-efekty, žádné issues.
 */
final class RowHistoryEnricher
{
    /** Stavy dokladů považované za naučenou historii: 20 Potvrzeno, 40 V pořádku. */
    private const HISTORY_DOC_STATES = [20, 40];

    /** Stejná trojice jako v resolverech — navrhovaná položka musí být živá. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    private const HISTORY_LIMIT = 200;
    private const FUZZY_THRESHOLD = 0.6;

    public function __construct(
        private readonly Connection $db,
        private readonly PartyResolver $partyResolver,
    ) {}

    /** Production factory — stejné složení PartyResolveru jako DocumentApplier::create(). */
    public static function create(Connection $db): self
    {
        return new self($db, new PartyResolver($db, new OwnCompanyResolver($db)));
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function enrich(array $canonical): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        if ($rows === []) {
            return $canonical;
        }

        $partnerId = $this->resolveCounterparty($canonical);
        if ($partnerId === null) {
            return $canonical;
        }

        $canonical = $this->revertOwnSuggestions($canonical);

        $docType = DocumentApplier::mapDocTypeValue((string) ($canonical['docType'] ?? ''));
        $history = $this->loadHistory($partnerId, $docType);

        foreach ($canonical['rows'] as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $canonical = $this->enrichRow($canonical, (int) $idx, $row, $history);
        }

        return $canonical;
    }

    /**
     * Řádek, u kterého dává smysl navrhovat položku: běžný item řádek.
     * Kontační řádky (acc.record / accSide) položku nenesou — používá se
     * i pro strop statusu (D7) v AnalysisController.
     *
     * @param array<string, mixed> $row
     */
    public static function rowExpectsItem(array $row): bool
    {
        $rowKind = $row['rowKind'] ?? null;
        if ($rowKind !== null && $rowKind !== 'item') {
            return false;
        }
        if (($row['accSide'] ?? null) !== null) {
            return false;
        }
        return ($row['operation'] ?? null) !== 'acc.record';
    }

    // ── Per-row match + propsání ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function enrichRow(array $canonical, int $idx, array $row, array $history): array
    {
        $enrichment = [
            'matchedBy'   => null,
            'confidence'  => null,
            'sourceDocId' => null,
            'suggested'   => [],
        ];

        if (!self::rowExpectsItem($row)) {
            $enrichment['skipped'] = 'noItemRow';
            return $this->writeEnrichment($canonical, $idx, $enrichment);
        }
        if (trim((string) ($row['item']['ourCode'] ?? '')) !== '') {
            $enrichment['skipped'] = 'hasOurCode';
            return $this->writeEnrichment($canonical, $idx, $enrichment);
        }

        $text = $this->rowText($row);
        $match = $text !== null ? $this->findMatch($text, $history) : null;
        if ($match === null) {
            return $this->writeEnrichment($canonical, $idx, $enrichment);
        }

        [$hist, $matchedBy, $confidence] = $match;

        $suggested = [];
        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $item['ourCode'] = (string) $hist['item_code'];
        $row['item'] = $item;
        $suggested['ourCode'] = (string) $hist['item_code'];

        $histVat = trim((string) ($hist['vat_code'] ?? ''));
        if ($histVat !== '' && trim((string) ($row['vat']['code'] ?? '')) === '') {
            $vat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
            $vat['code'] = $histVat;
            $row['vat'] = $vat;
            $suggested['vatCode'] = $histVat;
        }

        $histAccount = trim((string) ($hist['account_number'] ?? ''));
        if ($histAccount !== '' && trim((string) ($row['account'] ?? '')) === '') {
            $row['account'] = $histAccount;
            $suggested['account'] = $histAccount;
        }

        $canonical['rows'][$idx] = $row;
        $enrichment['matchedBy'] = $matchedBy;
        $enrichment['confidence'] = $confidence;
        $enrichment['sourceDocId'] = (int) $hist['doc_head'];
        $enrichment['suggested'] = $suggested;

        return $this->writeEnrichment($canonical, $idx, $enrichment);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array{0: array<string, mixed>, 1: string, 2: string}|null
     */
    private function findMatch(string $text, array $history): ?array
    {
        foreach ($history as $hist) {
            if (trim((string) ($hist['description'] ?? '')) === $text) {
                return [$hist, 'historyExactRaw', 'high'];
            }
        }

        $norm = $this->normalizeText($text);
        if ($norm === '') {
            return null;
        }
        foreach ($history as $hist) {
            if ($this->normalizeText((string) ($hist['description'] ?? '')) === $norm) {
                return [$hist, 'historyExactNorm', 'high'];
            }
        }

        $tokens = $this->tokenize($norm);
        foreach ($history as $hist) {
            $histTokens = $this->tokenize($this->normalizeText((string) ($hist['description'] ?? '')));
            if ($this->jaccard($tokens, $histTokens) >= self::FUZZY_THRESHOLD) {
                return [$hist, 'historyFuzzy', 'medium'];
            }
        }

        return null;
    }

    /**
     * Text řádku pro matchování — stejný fallback řetěz jako
     * DocumentApplier::transformRows().
     *
     * @param array<string, mixed> $row
     */
    private function rowText(array $row): ?string
    {
        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $text = $row['description'] ?? $item['description'] ?? $item['name'] ?? null;
        if (!is_string($text)) {
            return null;
        }
        $text = trim($text);
        return $text === '' ? null : $text;
    }

    /** Lowercase + odstranění číslic/datumů/částek/interpunkce + collapse whitespace (D5). */
    private function normalizeText(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = (string) preg_replace('/[^\p{L}\s]+/u', ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** @return list<string> Unikátní tokeny. */
    private function tokenize(string $norm): array
    {
        return $norm === '' ? [] : array_values(array_unique(explode(' ', $norm)));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union === 0 ? 0.0 : $intersection / $union;
    }

    // ── Counterparty + historie ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    private function resolveCounterparty(array $canonical): ?int
    {
        $counterKey = match ($canonical['selfParty'] ?? null) {
            'customer' => 'supplier',
            'supplier' => 'customer',
            default    => null,
        };
        if ($counterKey === null) {
            return null;
        }
        $party = is_array($canonical[$counterKey] ?? null) ? $canonical[$counterKey] : [];
        if ($party === []) {
            return null;
        }
        $result = $this->partyResolver->resolve($party);
        return $result->status === ResolveStatus::Matched ? $result->matchedId : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadHistory(int $partnerId, string $docType): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [r.description], [r.vat_code], [h.id] AS [doc_head],
                    [i.code] AS [item_code], [a.number] AS [account_number]
             FROM [docs_core_rows] AS [r]
             JOIN [docs_core_heads] AS [h] ON [h.id] = [r.doc_head]
             JOIN [economy_items] AS [i]
                  ON [i.id] = [r.item] AND [i.docState] IN (%i, %i, %i)
             LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [r.account]
             WHERE [h.partner] = %i AND [h.doc_type] = %s
               AND [h.docState] IN (%i, %i)
             ORDER BY [h.id] DESC
             LIMIT %i',
            self::ITEM_ACTIVE_STATES[0], self::ITEM_ACTIVE_STATES[1], self::ITEM_ACTIVE_STATES[2],
            $partnerId, $docType,
            self::HISTORY_DOC_STATES[0], self::HISTORY_DOC_STATES[1],
            self::HISTORY_LIMIT,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
        }
        return $out;
    }

    // ── Idempotence + audit blok ────────────────────────────────────────────

    /**
     * Odvolá návrhy z předchozího běhu: hodnota, kterou tehdy doplnil
     * enrichment (`enrichment.suggested`) a která od té doby nebyla změněna,
     * se vyprázdní a řádek jde do matchování znovu.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function revertOwnSuggestions(array $canonical): array
    {
        $resolveRows = $canonical['_resolve']['rows'] ?? null;
        if (!is_array($resolveRows)) {
            return $canonical;
        }
        foreach ($resolveRows as $entry) {
            if (!is_array($entry) || !is_array($entry['enrichment'] ?? null)) {
                continue;
            }
            $idx = $entry['index'] ?? null;
            if (!is_int($idx) || !is_array($canonical['rows'][$idx] ?? null)) {
                continue;
            }
            $suggested = is_array($entry['enrichment']['suggested'] ?? null)
                ? $entry['enrichment']['suggested']
                : [];
            $row = $canonical['rows'][$idx];
            if (isset($suggested['ourCode']) && ($row['item']['ourCode'] ?? null) === $suggested['ourCode']) {
                $row['item']['ourCode'] = null;
            }
            if (isset($suggested['vatCode']) && ($row['vat']['code'] ?? null) === $suggested['vatCode']) {
                $row['vat']['code'] = null;
            }
            if (isset($suggested['account']) && ($row['account'] ?? null) === $suggested['account']) {
                $row['account'] = null;
            }
            $canonical['rows'][$idx] = $row;
        }
        return $canonical;
    }

    /**
     * Zapíše audit blok do `_resolve.rows[i].enrichment` — merge do existující
     * entry podle `index` (konvence resolveAll), jinak novou entry přidá.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $enrichment
     * @return array<string, mixed>
     */
    private function writeEnrichment(array $canonical, int $idx, array $enrichment): array
    {
        $resolveRows = is_array($canonical['_resolve']['rows'] ?? null)
            ? $canonical['_resolve']['rows']
            : [];
        foreach ($resolveRows as $pos => $entry) {
            if (is_array($entry) && ($entry['index'] ?? null) === $idx) {
                $resolveRows[$pos]['enrichment'] = $enrichment;
                $canonical['_resolve']['rows'] = $resolveRows;
                return $canonical;
            }
        }
        $resolveRows[] = ['index' => $idx, 'enrichment' => $enrichment];
        $canonical['_resolve']['rows'] = $resolveRows;
        return $canonical;
    }
}
