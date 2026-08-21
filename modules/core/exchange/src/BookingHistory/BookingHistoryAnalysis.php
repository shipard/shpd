<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Výsledek jednoho průchodu souborem účetní historie plus statistiky, které
 * se z něj dají odvodit (pokrytí taxonomie, konzistence LLM × reverz, mrtvé
 * štítky, rozptyl účtů).
 *
 * Odvozené statistiky žijí **tady, ne v reportu** — kolektivní analýza
 * napříč DS (samostatný pozdější task) je potřebuje bez markdownu.
 * {@see BookingHistoryReport} je jen formátovač.
 *
 * LLM štítky nejsou součástí průchodu — dotečou přes
 * {@see applyLlmTags()} po klasifikaci distinct textů, aby report i seed
 * šly udělat i bez LLM (`--no-llm`).
 */
final class BookingHistoryAnalysis
{
    /** Práh neshody, nad kterým report štítek vypíchne jako podezřelý. */
    public const DISAGREEMENT_ALERT = 0.3;

    /**
     * @param array<string, TextCluster> $clusters klíčované rowTextNorm
     * @param array<string, int> $degenerateAccountRows účet → řádky degenerovaných textů
     */
    public function __construct(
        public readonly BookingHistoryHeader $header,
        public readonly BookingHistoryQuality $quality,
        public readonly BookingHistorySeedBuilder $seed,
        public readonly AccountTagMap $accountTags,
        private array $clusters,
        public readonly int $recordCount,
        /** @var array<string, int> druh reverzní shody → řádky */
        public readonly array $matchKindRows,
        /**
         * Přesné shody zamítnuté kontrolou názvu (D36).
         *
         * @var array{records: int, rows: int, byAccount: array<string, int>}
         */
        public readonly array $degradedExact = ['records' => 0, 'rows' => 0, 'byAccount' => []],
        /**
         * Kód položky ve zdroji → řádky, přes **všechny** záznamy souboru.
         *
         * @var array<string, int>
         */
        public readonly array $fileItemCodes = [],
    ) {}

    /**
     * Výsledky klasifikace agregované per kód položky — vstup otagování
     * z užití (D38).
     *
     * Sčítají se jen **obsahonosné** texty (degenerované se do clusterů
     * nedostanou už při průchodu, D33) — text shodný s názvem položky by
     * jinak kruhově potvrzoval sám sebe. `null` (model štítek nenašel) je
     * v tally **plnohodnotný soupeř**: u catch-all a leasingových položek
     * má vyhrát a znamenat „bez návrhu".
     *
     * @return array<string, array{tags: array<string, int>, rows: int, dominant: ?string, dominantRows: int, share: float, dominantIsNull: bool, tie: bool}>
     *         `tags` má prázdný string jako značku výsledku `null`;
     *         `dominant` je štítek jen když vyhrál štítek — u `null`
     *         výsledku a remízy je `null` a rozliší je příznaky
     */
    public function usageByItemCode(): array
    {
        $usage = [];
        foreach ($this->clusters as $cluster) {
            if (!$cluster->llmClassified) {
                continue;
            }
            $key = $cluster->llmTag ?? '';
            foreach ($cluster->itemCodes as $code => $rows) {
                $entry = $usage[$code] ?? ['tags' => [], 'rows' => 0];
                $entry['tags'][$key] = ($entry['tags'][$key] ?? 0) + $rows;
                $entry['rows'] += $rows;
                $usage[$code] = $entry;
            }
        }

        foreach ($usage as $code => $entry) {
            $winner = null;
            $winnerRows = 0;
            $tie = false;
            foreach ($entry['tags'] as $tag => $rows) {
                if ($rows > $winnerRows) {
                    $winnerRows = $rows;
                    $winner = (string) $tag;
                    $tie = false;
                } elseif ($rows === $winnerRows) {
                    $tie = true;
                }
            }
            $usage[$code]['dominantIsNull'] = $winner === '';
            $usage[$code]['tie'] = $tie;
            $usage[$code]['dominant'] = ($tie || $winner === '' || $winner === null) ? null : $winner;
            $usage[$code]['dominantRows'] = $winnerRows;
            $usage[$code]['share'] = $entry['rows'] > 0 ? $winnerRows / $entry['rows'] : 0.0;
        }
        return $usage;
    }

    /** Zamítla kontrola názvů aspoň jednu přesnou shodu? */
    public function hasDegradedExact(): bool
    {
        return ($this->degradedExact['records'] ?? 0) > 0;
    }

    /**
     * Účty, na kterých kontrola názvů zamítla přesnou shodu — nejvíc
     * zasažené první. Přímý seznam k prohlédnutí: buď cizí analytika
     * znamená něco jiného (správná degradace), nebo je název položky
     * v exportu k ničemu.
     *
     * @return list<array{account: string, rows: int, offerTags: list<string>}>
     */
    public function degradedExactAccounts(int $limit = 10): array
    {
        $accounts = $this->degradedExact['byAccount'] ?? [];
        arsort($accounts);
        $out = [];
        foreach ($accounts as $account => $rows) {
            $out[] = [
                'account'   => (string) $account,
                'rows'      => $rows,
                'offerTags' => $this->accountTags->tagsForAccount((string) $account),
            ];
        }
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /** @return array<string, TextCluster> */
    public function clusters(): array
    {
        return $this->clusters;
    }

    public function distinctTextCount(): int
    {
        return count($this->clusters);
    }

    /**
     * Clustery ke klasifikaci, nejobjemnější první — když se běh omezí
     * (rozpočet, přerušení), zaplatí se nejdřív za to, co nese nejvíc řádků.
     *
     * @return list<TextCluster>
     */
    public function clustersForClassification(): array
    {
        $clusters = array_values($this->clusters);
        usort($clusters, static function (TextCluster $a, TextCluster $b): int {
            return [$b->rows, $b->amount, $a->norm] <=> [$a->rows, $a->amount, $b->norm];
        });
        return $clusters;
    }

    /**
     * Zapíše výsledky klasifikace. Klíč = rowTextNorm, hodnota = štítek nebo
     * null („model štítek nenašel"). Norm, který v mapě není, zůstává
     * neklasifikovaný — to není totéž jako null výsledek.
     *
     * @param array<string, string|null> $tagByNorm
     */
    public function applyLlmTags(array $tagByNorm): void
    {
        foreach ($tagByNorm as $norm => $tag) {
            $cluster = $this->clusters[$norm] ?? null;
            if ($cluster === null) {
                continue;
            }
            $cluster->llmTag = is_string($tag) && $tag !== '' ? $tag : null;
            $cluster->llmClassified = true;
        }
    }

    public function classifiedClusterCount(): int
    {
        $count = 0;
        foreach ($this->clusters as $cluster) {
            if ($cluster->llmClassified) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Clustery, které klasifikace prošla, ale štítek nedostaly — objemné
     * první. To je vstup pro rozhodnutí, jestli taxonomii chybí štítek
     * (D2 břitva), nebo jsou ty texty prostě šum.
     *
     * @return list<TextCluster>
     */
    public function unclassifiedClusters(int $limit = 0): array
    {
        $out = [];
        foreach ($this->clustersForClassification() as $cluster) {
            if ($cluster->llmClassified && $cluster->llmTag === null) {
                $out[] = $cluster;
            }
        }
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /** Podíl řádků, kterým klasifikace štítek nedala (z klasifikovaných). */
    public function unclassifiedRowShare(): float
    {
        $classifiedRows = 0;
        $withoutTag = 0;
        foreach ($this->clusters as $cluster) {
            if (!$cluster->llmClassified) {
                continue;
            }
            $classifiedRows += $cluster->rows;
            if ($cluster->llmTag === null) {
                $withoutTag += $cluster->rows;
            }
        }
        return $classifiedRows > 0 ? $withoutTag / $classifiedRows : 0.0;
    }

    /**
     * Pokrytí taxonomie — per štítek řádky z LLM a z reverzu.
     *
     * @return array<string, array{llmRows: int, reverseRows: int, clusters: int}>
     */
    public function tagUsage(): array
    {
        $usage = [];
        foreach ($this->clusters as $cluster) {
            if ($cluster->llmTag !== null) {
                $entry = $usage[$cluster->llmTag] ?? ['llmRows' => 0, 'reverseRows' => 0, 'clusters' => 0];
                $entry['llmRows'] += $cluster->rows;
                $entry['clusters']++;
                $usage[$cluster->llmTag] = $entry;
            }
            foreach ($cluster->reverseTags as $tag => $rows) {
                $entry = $usage[$tag] ?? ['llmRows' => 0, 'reverseRows' => 0, 'clusters' => 0];
                $entry['reverseRows'] += $rows;
                $usage[$tag] = $entry;
            }
        }
        return $usage;
    }

    /**
     * Štítky taxonomie, které se nikde neobjevily (ani LLM, ani reverz).
     * Mrtvý štítek je kandidát na škrt — nebo důkaz, že zdroj takový obsah
     * nemá; report to nerozhoduje, jen ukáže.
     *
     * @param list<string> $taxonomyKeys
     * @return list<string>
     */
    public function deadTags(array $taxonomyKeys): array
    {
        $usage = $this->tagUsage();
        $dead = [];
        foreach ($taxonomyKeys as $tag) {
            $entry = $usage[$tag] ?? null;
            if ($entry === null || ($entry['llmRows'] === 0 && $entry['reverseRows'] === 0)) {
                $dead[] = $tag;
            }
        }
        return $dead;
    }

    /**
     * Matice shody LLM × reverz vážená řádky — jen clustery, kde jsou oba
     * signály. `matrix[llmTag][reverseTag] = řádky`.
     *
     * @return array{matrix: array<string, array<string, int>>, perTag: array<string, array{rows: int, agree: int, disagree: int, share: float}>, rows: int, agree: int}
     */
    public function consistency(): array
    {
        $matrix = [];
        $perTag = [];
        $rows = 0;
        $agree = 0;

        foreach ($this->clusters as $cluster) {
            $llmTag = $cluster->llmTag;
            $reverseTag = $cluster->dominantReverseTag();
            if ($llmTag === null || $reverseTag === null) {
                continue;
            }
            $matrix[$llmTag][$reverseTag] = ($matrix[$llmTag][$reverseTag] ?? 0) + $cluster->rows;

            $entry = $perTag[$llmTag] ?? ['rows' => 0, 'agree' => 0, 'disagree' => 0, 'share' => 0.0];
            $entry['rows'] += $cluster->rows;
            if ($llmTag === $reverseTag) {
                $entry['agree'] += $cluster->rows;
                $agree += $cluster->rows;
            } else {
                $entry['disagree'] += $cluster->rows;
            }
            $entry['share'] = $entry['rows'] > 0 ? $entry['agree'] / $entry['rows'] : 0.0;
            $perTag[$llmTag] = $entry;
            $rows += $cluster->rows;
        }

        ksort($matrix);
        ksort($perTag);
        return ['matrix' => $matrix, 'perTag' => $perTag, 'rows' => $rows, 'agree' => $agree];
    }

    /**
     * Štítky, u kterých LLM a reverz nesouhlasí nad prahem — buď špatné
     * mapování štítek↔účet v nabídce, nebo štítek s rozmazaným významem.
     *
     * @return list<array{tag: string, rows: int, agreeShare: float, topReverse: array<string, int>}>
     */
    public function disagreements(float $threshold = self::DISAGREEMENT_ALERT): array
    {
        $consistency = $this->consistency();
        $out = [];
        foreach ($consistency['perTag'] as $tag => $entry) {
            if ($entry['rows'] <= 0 || (1.0 - $entry['share']) <= $threshold) {
                continue;
            }
            $reverse = $consistency['matrix'][$tag] ?? [];
            arsort($reverse);
            $out[] = [
                'tag'        => (string) $tag,
                'rows'       => $entry['rows'],
                'agreeShare' => $entry['share'],
                'topReverse' => array_slice($reverse, 0, 3, true),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['rows'] <=> $a['rows']);
        return $out;
    }

    /**
     * Rozptyl účtů per LLM štítek — štítek roztažený přes mnoho účtů buď
     * míchá různé obsahy, nebo zdroj účtuje nekonzistentně.
     *
     * @return list<array{tag: string, accounts: int, rows: int, top: array<string, int>}>
     */
    public function accountSpread(): array
    {
        $spread = [];
        foreach ($this->clusters as $cluster) {
            if ($cluster->llmTag === null) {
                continue;
            }
            $entry = $spread[$cluster->llmTag] ?? ['rows' => 0, 'accounts' => []];
            $entry['rows'] += $cluster->rows;
            foreach ($cluster->accounts as $account => $rows) {
                $entry['accounts'][$account] = ($entry['accounts'][$account] ?? 0) + $rows;
            }
            $spread[$cluster->llmTag] = $entry;
        }

        $out = [];
        foreach ($spread as $tag => $entry) {
            $accounts = $entry['accounts'];
            arsort($accounts);
            $out[] = [
                'tag'      => (string) $tag,
                'accounts' => count($accounts),
                'rows'     => $entry['rows'],
                'top'      => array_slice($accounts, 0, 5, true),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['accounts'], $b['rows']] <=> [$a['accounts'], $a['rows']]);
        return $out;
    }

    /**
     * Objemné účty, kterým reverz štítek nedal — přímý seznam k doplnění
     * `contentTags` v nabídce položek.
     *
     * @return list<array{account: string, rows: int, docs: int, amount: float, kind: string, candidates: list<string>}>
     */
    public function accountsWithoutReverseTag(int $limit = 20): array
    {
        $out = [];
        foreach ($this->quality->topAccounts(0) as $stats) {
            $match = $this->accountTags->resolve($stats['account']);
            if ($match->isHit()) {
                continue;
            }
            $out[] = [
                'account'    => $stats['account'],
                'rows'       => $stats['rows'],
                'docs'       => $stats['docs'],
                'amount'     => $stats['amount'],
                'kind'       => $match->kind,
                'candidates' => $match->candidates,
            ];
        }
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }
}
