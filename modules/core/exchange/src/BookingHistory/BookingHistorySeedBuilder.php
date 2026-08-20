<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Kandidáti seed pravidel `IČO → obsahový štítek` (D32) — streamovaný
 * akumulátor nad reverzními štítky záznamů.
 *
 * Prahy: dominantní štítek musí mít `share >= 0.8` (podíl **řádků** mezi
 * řádky s rozřešeným štítkem) a `docCount >= 3`. `docCount` se bere
 * z dominantního štítku, ne z celého IČO — práh tak stojí na důkazech pro
 * ten konkrétní štítek. Je to horní odhad počtu dokladů (jeden doklad
 * spadá pod víc agregačních klíčů), takže práh 3 je spodní mez, kterou
 * odhad může jen nadstřelit.
 *
 * Remíza dominance → bez kandidáta: dodavatel s pestrým sortimentem
 * pravidlo nedostane (stejná logika jako mazání kolizních `learned`
 * pravidel v ContentTagRuleCaptureHandler).
 *
 * Záznamy bez IČO ({@see BookingHistoryRecord::$companyId} null) se
 * nepočítají do ničeho než do statistiky přeskočených.
 */
final class BookingHistorySeedBuilder
{
    public const DEFAULT_MIN_SHARE = 0.8;
    public const DEFAULT_MIN_DOC_COUNT = 3;

    /** @var array<string, array{totalRows: int, tags: array<string, array{rows: int, docs: int}>}> */
    private array $companies = [];

    private int $recordsWithoutCompanyId = 0;

    public function __construct(
        private readonly float $minShare = self::DEFAULT_MIN_SHARE,
        private readonly int $minDocCount = self::DEFAULT_MIN_DOC_COUNT,
    ) {}

    public function add(BookingHistoryRecord $record, AccountTagMatch $match): void
    {
        if ($record->companyId === null) {
            $this->recordsWithoutCompanyId++;
            return;
        }

        $company = $this->companies[$record->companyId] ?? ['totalRows' => 0, 'tags' => []];
        $company['totalRows'] += $record->rowCount;

        if ($match->tag !== null) {
            $tag = $company['tags'][$match->tag] ?? ['rows' => 0, 'docs' => 0];
            $tag['rows'] += $record->rowCount;
            $tag['docs'] += $record->docCount;
            $company['tags'][$match->tag] = $tag;
        }

        $this->companies[$record->companyId] = $company;
    }

    /**
     * Kandidáti splňující prahy, nejsilnější podpora první.
     *
     * @return list<SeedCandidate>
     */
    public function candidates(): array
    {
        $out = [];
        foreach ($this->companies as $companyId => $company) {
            $candidate = $this->evaluate((string) $companyId, $company);
            if ($candidate instanceof SeedCandidate) {
                $out[] = $candidate;
            }
        }
        usort($out, static function (SeedCandidate $a, SeedCandidate $b): int {
            return [$b->rows, $b->docs, $a->companyId] <=> [$a->rows, $a->docs, $b->companyId];
        });
        return $out;
    }

    /**
     * Proč IČO kandidátem není — vstup do reportu, aby prahy nebyly
     * neprůhledné.
     *
     * @return array{noCompanyIdRecords: int, companies: int, noResolvedTag: int, tie: int, belowShare: int, belowDocCount: int}
     */
    public function skipped(): array
    {
        $counters = [
            'noCompanyIdRecords' => $this->recordsWithoutCompanyId,
            'companies'          => count($this->companies),
            'noResolvedTag'      => 0,
            'tie'                => 0,
            'belowShare'         => 0,
            'belowDocCount'      => 0,
        ];
        foreach ($this->companies as $companyId => $company) {
            $reason = $this->evaluate((string) $companyId, $company);
            if (is_string($reason)) {
                $counters[$reason]++;
            }
        }
        return $counters;
    }

    /**
     * @param array{totalRows: int, tags: array<string, array{rows: int, docs: int}>} $company
     * @return SeedCandidate|string kandidát, nebo důvod zamítnutí
     */
    private function evaluate(string $companyId, array $company): SeedCandidate|string
    {
        if ($company['tags'] === []) {
            return 'noResolvedTag';
        }

        $resolvedRows = 0;
        $bestTag = null;
        $bestRows = -1;
        $tieAtBest = false;
        foreach ($company['tags'] as $tag => $stats) {
            $resolvedRows += $stats['rows'];
            if ($stats['rows'] > $bestRows) {
                $bestRows = $stats['rows'];
                $bestTag = (string) $tag;
                $tieAtBest = false;
            } elseif ($stats['rows'] === $bestRows) {
                $tieAtBest = true;
            }
        }
        if ($resolvedRows <= 0 || $bestTag === null) {
            return 'noResolvedTag';
        }
        if ($tieAtBest) {
            return 'tie';
        }

        $share = $bestRows / $resolvedRows;
        if ($share < $this->minShare) {
            return 'belowShare';
        }
        $docs = $company['tags'][$bestTag]['docs'];
        if ($docs < $this->minDocCount) {
            return 'belowDocCount';
        }

        return new SeedCandidate(
            companyId: $companyId,
            tag: $bestTag,
            rows: $bestRows,
            docs: $docs,
            resolvedRows: $resolvedRows,
            totalRows: $company['totalRows'],
            share: $share,
            coverage: $company['totalRows'] > 0 ? $resolvedRows / $company['totalRows'] : 0.0,
        );
    }
}
