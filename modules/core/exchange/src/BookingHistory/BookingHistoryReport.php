<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Markdown report o souboru účetní historie (D35). Čistý formátovač —
 * všechna vyhodnocení jsou v {@see BookingHistoryAnalysis}, tady se jen
 * píše text. Díky tomu má kolektivní analýza napříč DS (pozdější task)
 * z čeho vyrobit jiný výstup nad stejnými čísly.
 *
 * Sekce dle D35: kvalita zdroje, pokrytí taxonomie, konzistence
 * (LLM × reverz), mrtvé štítky, náhled seedu.
 */
final class BookingHistoryReport
{
    private const TOP_ACCOUNTS = 15;
    private const TOP_CLUSTERS = 20;
    private const TOP_SEED = 40;

    /** @var list<string> */
    private array $lines = [];

    /**
     * @param array<string, mixed> $taxonomy cfgItem core.exchange.contentTags
     * @param array<string, array{action: string, existingTag?: string, existingOrigin?: string}> $seedPlan
     *        plán zápisu per IČO (z SeedApplier::plan()); prázdné = bez plánu
     */
    public function __construct(
        private readonly BookingHistoryAnalysis $analysis,
        private readonly array $taxonomy = [],
        private readonly array $seedPlan = [],
        private readonly ?string $inputPath = null,
        private readonly ?string $generatedAt = null,
    ) {}

    public function render(): string
    {
        $this->lines = [];
        $this->source();
        $this->quality();
        $this->coverage();
        $this->consistency();
        $this->deadTags();
        $this->seedPreview();
        return implode("\n", $this->lines) . "\n";
    }

    /** Krátký souhrn na stdout — stejná čísla, jen bez tabulek. */
    public function summaryLines(): array
    {
        $quality = $this->analysis->quality;
        $seed = $this->analysis->seed;
        $out = [
            sprintf(
                'Záznamů: %d, řádků: %d, distinct textů: %d',
                $this->analysis->recordCount,
                $quality->rows(),
                $this->analysis->distinctTextCount(),
            ),
            sprintf(
                'Degenerovaných textů: %s řádků (%s)',
                $this->num($quality->degenerateRows()),
                $this->pct($quality->degenerateShare()),
            ),
            sprintf(
                'Reverz účet→štítek: %s řádků se štítkem, %s bez',
                $this->num($this->matchKindRows(['exact', 'synthetic'])),
                $this->num($this->matchKindRows(['ambiguous', 'unmapped', 'noAccount'])),
            ),
        ];
        if ($this->analysis->classifiedClusterCount() > 0) {
            $consistency = $this->analysis->consistency();
            $out[] = sprintf(
                'LLM klasifikace: %d distinct textů, bez štítku %s řádků; shoda s reverzem %s',
                $this->analysis->classifiedClusterCount(),
                $this->pct($this->analysis->unclassifiedRowShare()),
                $consistency['rows'] > 0 ? $this->pct($consistency['agree'] / $consistency['rows']) : '—',
            );
        }
        $out[] = sprintf(
            'Seed kandidátů: %d (z %d IČO)',
            count($seed->candidates()),
            $seed->skipped()['companies'],
        );
        return $out;
    }

    // ── Sekce ───────────────────────────────────────────────────────────────

    private function source(): void
    {
        $header = $this->analysis->header;
        $this->h(1, 'Report účetní historie');
        $this->line();
        $this->line('| Položka | Hodnota |');
        $this->line('|---|---|');
        $this->row('Zdroj', $header->sourceLabel());
        if ($this->inputPath !== null) {
            $this->row('Soubor', '`' . basename($this->inputPath) . '`');
        }
        $this->row('Varianta osnovy', $header->chartVariant
            . ($header->chartVariantIsGuessed() ? ' → reverz běžel nad **podnikatelskou** nabídkou' : ''));
        $this->row('Měna', $header->currency);
        $this->row('Období', ($header->period['from'] ?? '?') . ' — ' . ($header->period['to'] ?? '?'));
        $this->row('Typy dokladů', $header->docTypes !== [] ? implode(', ', $header->docTypes) : '—');
        $this->row('Exportováno', $header->exportedAt ?? '—');
        $this->row('Záznamů', $this->num($this->analysis->recordCount));
        if ($header->recordCount !== null && $header->recordCount !== $this->analysis->recordCount) {
            $this->row(
                'Nesoulad hlavičky',
                sprintf(
                    '⚠ hlavička hlásí %s, soubor má %s',
                    $this->num($header->recordCount),
                    $this->num($this->analysis->recordCount),
                ),
            );
        }
        if ($this->generatedAt !== null) {
            $this->row('Report vytvořen', $this->generatedAt);
        }
        if ($header->chartVariantIsGuessed()) {
            $this->line();
            $this->line('> Zdroj neuvedl variantu osnovy. Čísla účtů se mezi podnikatelskou'
                . ' a neziskovou osnovou překrývají, takže reverzní štítky ber jako odhad.');
        }
    }

    private function quality(): void
    {
        $quality = $this->analysis->quality;
        $this->h(2, 'Kvalita zdroje');
        $this->line();
        $this->line('| Metrika | Záznamy | Řádky | Podíl řádků |');
        $this->line('|---|--:|--:|--:|');
        $this->line(sprintf(
            '| celkem | %s | %s | 100 %% |',
            $this->num($quality->records()),
            $this->num($quality->rows()),
        ));
        $this->line(sprintf(
            '| degenerovaný text | %s | %s | %s |',
            $this->num($quality->degenerateRecords()),
            $this->num($quality->degenerateRows()),
            $this->pct($quality->degenerateShare()),
        ));
        foreach ($this->degeneracyOrder() as $kind => $label) {
            $stats = $quality->degenerateByKind()[$kind] ?? ['records' => 0, 'rows' => 0];
            $this->line(sprintf(
                '| — %s | %s | %s | %s |',
                $label,
                $this->num($stats['records']),
                $this->num($stats['rows']),
                $this->pct($quality->rows() > 0 ? $stats['rows'] / $quality->rows() : 0.0),
            ));
        }
        foreach ([
            'bez IČO'  => $quality->missingCompanyId(),
            'bez účtu' => $quality->missingAccount(),
        ] as $label => $stats) {
            $this->line(sprintf(
                '| %s | %s | %s | %s |',
                $label,
                $this->num($stats['records']),
                $this->num($stats['rows']),
                $this->pct($quality->rows() > 0 ? $stats['rows'] / $quality->rows() : 0.0),
            ));
        }

        $this->line();
        $this->line(sprintf(
            'Objem: **%s %s** (záznamů bez částky: %s). Dokladů: ~%s — součet `docCount`'
            . ' je horní odhad, jeden doklad spadá pod víc agregačních klíčů.',
            $this->amount($quality->amount()),
            $this->analysis->header->currency,
            $this->num($quality->recordsWithoutAmount()),
            $this->num($quality->docs()),
        ));

        $this->line();
        $this->h(3, 'Objemné účty');
        $this->line();
        $this->line('| Účet | Řádky | Objem | Degenerované řádky | Reverzní štítek |');
        $this->line('|---|--:|--:|--:|---|');
        foreach ($quality->topAccounts(self::TOP_ACCOUNTS) as $stats) {
            $match = $this->analysis->accountTags->resolve($stats['account']);
            $this->line(sprintf(
                '| `%s` | %s | %s | %s | %s |',
                $stats['account'],
                $this->num($stats['rows']),
                $this->amount($stats['amount']),
                $this->pct($stats['rows'] > 0 ? $stats['degenerateRows'] / $stats['rows'] : 0.0),
                $this->matchLabel($match),
            ));
        }
    }

    private function coverage(): void
    {
        $this->h(2, 'Pokrytí taxonomie');
        $this->line();

        $withTag = $this->matchKindRows(['exact', 'synthetic']);
        $rows = $this->analysis->quality->rows();
        $this->line(sprintf(
            'Reverz účet→štítek zasáhl **%s** z %s řádků (%s): přesná shoda %s,'
            . ' syntetika %s. Bez štítku: kolizní účet %s, účet mimo nabídku %s, bez účtu %s.',
            $this->num($withTag),
            $this->num($rows),
            $this->pct($rows > 0 ? $withTag / $rows : 0.0),
            $this->num($this->matchKindRows([AccountTagMatch::KIND_EXACT])),
            $this->num($this->matchKindRows([AccountTagMatch::KIND_SYNTHETIC])),
            $this->num($this->matchKindRows([AccountTagMatch::KIND_AMBIGUOUS])),
            $this->num($this->matchKindRows([AccountTagMatch::KIND_UNMAPPED])),
            $this->num($this->matchKindRows([AccountTagMatch::KIND_NO_ACCOUNT])),
        ));

        if ($this->analysis->hasDegradedExact()) {
            $degraded = $this->analysis->degradedExact;
            $this->line();
            $this->line(sprintf(
                'Kontrola názvů zamítla **%s** přesných shod (%s řádků): číslo účtu v nabídce'
                . ' sedělo, ale název položky ve zdroji znamená něco jiného. Takové záznamy'
                . ' spadly na hrubší syntetickou úroveň (D36).',
                $this->num($degraded['records']),
                $this->num($degraded['rows']),
            ));
            $this->line();
            $this->line('| Účet | Zamítnuté řádky | Štítek z nabídky |');
            $this->line('|---|--:|---|');
            foreach ($this->analysis->degradedExactAccounts() as $entry) {
                $this->line(sprintf(
                    '| `%s` | %s | %s |',
                    $entry['account'],
                    $this->num($entry['rows']),
                    $entry['offerTags'] !== [] ? '`' . implode('`, `', $entry['offerTags']) . '`' : '—',
                ));
            }
        }

        $missing = $this->analysis->accountsWithoutReverseTag(self::TOP_ACCOUNTS);
        if ($missing !== []) {
            $this->line();
            $this->h(3, 'Objemné účty bez reverzního štítku');
            $this->line();
            $this->line('| Účet | Řádky | Objem | Důvod | Kandidáti |');
            $this->line('|---|--:|--:|---|---|');
            foreach ($missing as $entry) {
                $this->line(sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    $entry['account'],
                    $this->num($entry['rows']),
                    $this->amount($entry['amount']),
                    $this->kindLabel($entry['kind']),
                    $entry['candidates'] !== [] ? '`' . implode('`, `', $entry['candidates']) . '`' : '—',
                ));
            }
        }

        if ($this->analysis->classifiedClusterCount() === 0) {
            $this->line();
            $this->line('> LLM klasifikace neproběhla — sekce pokrytí štítků a konzistence'
                . ' jsou jen z reverzu. (Spusť bez `--no-llm`, nebo nastav AI backend.)');
        } else {
            $this->line();
            $this->h(3, 'Štítky podle zdroje signálu');
            $this->line();
            $this->line('| Štítek | LLM řádky | Reverzní řádky | Clustery |');
            $this->line('|---|--:|--:|--:|');
            $usage = $this->analysis->tagUsage();
            uasort($usage, static fn (array $a, array $b): int => ($b['llmRows'] + $b['reverseRows']) <=> ($a['llmRows'] + $a['reverseRows']));
            foreach ($usage as $tag => $entry) {
                $this->line(sprintf(
                    '| %s | %s | %s | %s |',
                    $this->tagLabel((string) $tag),
                    $this->num($entry['llmRows']),
                    $this->num($entry['reverseRows']),
                    $this->num($entry['clusters']),
                ));
            }

            $unclassified = $this->analysis->unclassifiedClusters(self::TOP_CLUSTERS);
            $this->line();
            $this->h(3, 'Texty bez štítku (LLM)');
            $this->line();
            $this->line(sprintf(
                'Podíl řádků bez štítku: **%s** z klasifikovaných. Nejobjemnější clustery:',
                $this->pct($this->analysis->unclassifiedRowShare()),
            ));
            if ($unclassified === []) {
                $this->line();
                $this->line('_Žádné — model zařadil všechno._');
            } else {
                $this->line();
                $this->line('| Text | Řádky | Objem | Účty |');
                $this->line('|---|--:|--:|---|');
                foreach ($unclassified as $cluster) {
                    $accounts = $cluster->accounts;
                    arsort($accounts);
                    $this->line(sprintf(
                        '| %s | %s | %s | %s |',
                        $this->cell($cluster->text),
                        $this->num($cluster->rows),
                        $this->amount($cluster->amount),
                        implode(', ', array_map(
                            static fn ($a): string => '`' . $a . '`',
                            array_slice(array_keys($accounts), 0, 3),
                        )) ?: '—',
                    ));
                }
            }
        }
    }

    private function consistency(): void
    {
        if ($this->analysis->classifiedClusterCount() === 0) {
            return;
        }

        $consistency = $this->analysis->consistency();
        $this->h(2, 'Konzistence LLM × reverz');
        $this->line();
        if ($consistency['rows'] === 0) {
            $this->line('_Žádný cluster nemá oba signály — matici není z čeho postavit._');
            return;
        }
        $this->line(sprintf(
            'Clustery s oběma signály: **%s** řádků, shoda **%s**.',
            $this->num($consistency['rows']),
            $this->pct($consistency['agree'] / $consistency['rows']),
        ));

        $this->line();
        $this->line('| LLM štítek | Řádky | Shoda | Nejčastější reverzní štítky |');
        $this->line('|---|--:|--:|---|');
        $perTag = $consistency['perTag'];
        uasort($perTag, static fn (array $a, array $b): int => $b['rows'] <=> $a['rows']);
        foreach ($perTag as $tag => $entry) {
            $reverse = $consistency['matrix'][$tag] ?? [];
            arsort($reverse);
            $top = [];
            foreach (array_slice($reverse, 0, 3, true) as $reverseTag => $reverseRows) {
                $top[] = sprintf('`%s` %s', $reverseTag, $this->num($reverseRows));
            }
            $this->line(sprintf(
                '| `%s` | %s | %s | %s |',
                $tag,
                $this->num($entry['rows']),
                $this->pct($entry['share']),
                implode(', ', $top),
            ));
        }

        $disagreements = $this->analysis->disagreements();
        if ($disagreements !== []) {
            $this->line();
            $this->h(3, 'Štítky s neshodou nad prahem');
            $this->line();
            $this->line(sprintf(
                'Neshoda nad **%s** — buď chybí `contentTags` u účtu v nabídce položek,'
                . ' nebo má štítek rozmazaný význam:',
                $this->pct(BookingHistoryAnalysis::DISAGREEMENT_ALERT),
            ));
            $this->line();
            foreach ($disagreements as $entry) {
                $reverse = [];
                foreach ($entry['topReverse'] as $tag => $rows) {
                    $reverse[] = sprintf('`%s` (%s)', $tag, $this->num($rows));
                }
                $this->line(sprintf(
                    '- `%s` — shoda %s na %s řádcích; reverz říká %s',
                    $entry['tag'],
                    $this->pct($entry['agreeShare']),
                    $this->num($entry['rows']),
                    implode(', ', $reverse),
                ));
            }
        }

        $spread = $this->analysis->accountSpread();
        if ($spread !== []) {
            $this->line();
            $this->h(3, 'Rozptyl účtů per štítek');
            $this->line();
            $this->line('| LLM štítek | Účtů | Řádky | Nejčastější účty |');
            $this->line('|---|--:|--:|---|');
            foreach (array_slice($spread, 0, self::TOP_CLUSTERS) as $entry) {
                $top = [];
                foreach ($entry['top'] as $account => $rows) {
                    $top[] = sprintf('`%s` %s', $account, $this->num($rows));
                }
                $this->line(sprintf(
                    '| `%s` | %s | %s | %s |',
                    $entry['tag'],
                    $this->num($entry['accounts']),
                    $this->num($entry['rows']),
                    implode(', ', $top),
                ));
            }
        }
    }

    private function deadTags(): void
    {
        $this->h(2, 'Mrtvé štítky');
        $this->line();
        if ($this->taxonomy === []) {
            $this->line('_Taxonomie není k dispozici (chybí compiled config) — nelze vyhodnotit._');
            return;
        }
        $dead = $this->analysis->deadTags(array_map('strval', array_keys($this->taxonomy)));
        if ($dead === []) {
            $this->line('_Žádné — každý štítek taxonomie se v souboru objevil._');
            return;
        }
        $this->line(sprintf(
            'Štítky, které se v souboru neobjevily ani z LLM, ani z reverzu (%d z %d):',
            count($dead),
            count($this->taxonomy),
        ));
        $this->line();
        foreach ($dead as $tag) {
            $this->line('- ' . $this->tagLabel($tag));
        }
        $this->line();
        $this->line('> Mrtvý štítek neznamená „ke škrtu" — může jít o obsah, který tenhle'
            . ' zdroj prostě nemá. Ke škrtu je až štítek mrtvý napříč zdroji.');
    }

    private function seedPreview(): void
    {
        $seed = $this->analysis->seed;
        $candidates = $seed->candidates();
        $skipped = $seed->skipped();

        $this->h(2, 'Náhled seed pravidel');
        $this->line();
        $this->line(sprintf(
            'Kandidátů: **%d** z %d IČO (prahy: podíl řádků ≥ %s, dokladů ≥ %d).',
            count($candidates),
            $skipped['companies'],
            $this->pct(BookingHistorySeedBuilder::DEFAULT_MIN_SHARE),
            BookingHistorySeedBuilder::DEFAULT_MIN_DOC_COUNT,
        ));
        $this->line();
        $this->line(sprintf(
            'Zamítnuto: bez reverzního štítku %s, remíza dominance %s, pod prahem podílu %s,'
            . ' pod prahem dokladů %s. Záznamů bez IČO: %s.',
            $this->num($skipped['noResolvedTag']),
            $this->num($skipped['tie']),
            $this->num($skipped['belowShare']),
            $this->num($skipped['belowDocCount']),
            $this->num($skipped['noCompanyIdRecords']),
        ));

        if ($candidates === []) {
            $this->line();
            $this->line('_Žádný kandidát — seed by nic nezapsal._');
            return;
        }

        $this->line();
        $this->line('| IČO | Štítek | Podíl | Pokrytí | Řádky | Doklady | Plán zápisu |');
        $this->line('|---|---|--:|--:|--:|--:|---|');
        foreach (array_slice($candidates, 0, self::TOP_SEED) as $candidate) {
            $this->line(sprintf(
                '| `%s` | `%s` | %s | %s | %s | %s | %s |',
                $candidate->companyId,
                $candidate->tag,
                $this->pct($candidate->share),
                $this->pct($candidate->coverage),
                $this->num($candidate->rows),
                $this->num($candidate->docs),
                $this->planLabel($candidate->companyId),
            ));
        }
        if (count($candidates) > self::TOP_SEED) {
            $this->line();
            $this->line(sprintf('_… a další %d kandidáti._', count($candidates) - self::TOP_SEED));
        }
        $this->line();
        $this->line('> **Pokrytí** je podíl řádků dodavatele, kterým reverz vůbec dal štítek.'
            . ' Vysoký podíl při nízkém pokrytí = pravidlo postavené na malém výseku historie.');
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function degeneracyOrder(): array
    {
        return [
            BookingHistoryRecord::DEGENERACY_EMPTY     => 'prázdný',
            BookingHistoryRecord::DEGENERACY_ITEM_NAME => 'shodný s názvem položky',
            BookingHistoryRecord::DEGENERACY_ACCOUNT   => 'shodný s číslem účtu',
        ];
    }

    /** @param list<string> $kinds */
    private function matchKindRows(array $kinds): int
    {
        $sum = 0;
        foreach ($kinds as $kind) {
            $sum += $this->analysis->matchKindRows[$kind] ?? 0;
        }
        return $sum;
    }

    private function matchLabel(AccountTagMatch $match): string
    {
        if ($match->tag !== null) {
            return sprintf(
                '`%s`%s',
                $match->tag,
                $match->kind === AccountTagMatch::KIND_SYNTHETIC ? ' _(syntetika)_' : '',
            );
        }
        return $this->kindLabel($match->kind);
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            AccountTagMatch::KIND_AMBIGUOUS  => 'kolizní účet',
            AccountTagMatch::KIND_UNMAPPED   => 'mimo nabídku',
            AccountTagMatch::KIND_NO_ACCOUNT => 'bez účtu',
            default                          => $kind,
        };
    }

    private function planLabel(string $companyId): string
    {
        $plan = $this->seedPlan[$companyId] ?? null;
        if ($plan === null) {
            return '—';
        }
        $existing = isset($plan['existingTag'])
            ? sprintf(' (má `%s`, původ `%s`)', $plan['existingTag'], $plan['existingOrigin'] ?? '?')
            : '';
        return match ($plan['action']) {
            'insert' => 'nové pravidlo',
            'update' => 'aktualizace seedu' . $existing,
            'skip'   => '**přeskočeno**' . $existing,
            'same'   => 'bez změny' . $existing,
            default  => $plan['action'],
        };
    }

    private function tagLabel(string $tag): string
    {
        $entry = $this->taxonomy[$tag] ?? null;
        $name = is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '';
        return $name !== '' ? sprintf('`%s` — %s', $tag, $name) : sprintf('`%s`', $tag);
    }

    /** Text do tabulky — pipe by rozbil řádek, dlouhý text ořezat. */
    private function cell(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));
        $text = str_replace('|', '\\|', $text);
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 77) . '…' : $text;
    }

    private function num(int|float $value): string
    {
        return number_format((float) $value, 0, ',', ' ');
    }

    private function amount(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private function pct(float $share): string
    {
        return number_format($share * 100, 1, ',', ' ') . ' %';
    }

    private function h(int $level, string $text): void
    {
        if ($this->lines !== [] && end($this->lines) !== '') {
            $this->line();
        }
        $this->line(str_repeat('#', $level) . ' ' . $text);
    }

    private function row(string $label, string $value): void
    {
        $this->line("| {$label} | {$value} |");
    }

    private function line(string $text = ''): void
    {
        $this->lines[] = $text;
    }
}
