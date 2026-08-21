<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Otagování položek **z užití** (D38, `tasks/booking-history-followups.md`).
 *
 * Reverz přes nabídku (D34) předpokládá, že účty položek DS odpovídají naší
 * osnově. U datasetu migrovaného z téhož systému, který dodal účetní
 * historii, je k dispozici řádově silnější signál: **kódy položek souboru
 * se kryjí s katalogem DS**, takže se dá agregovat, co model o textech té
 * konkrétní položky napsal. Pilotní DS měl 46 z 53 kódů souboru v katalogu
 * (0,87), zatímco reverz přes nabídku tam nezasáhl skoro nic — jeho
 * analytiky jsou catch-all účty s desítkami štítků.
 *
 * Návrh dostane položka, jejíž **dominantní** výsledek klasifikace je
 * štítek (ne `null`, ne remíza) s podílem `>= minShare` na klasifikovaných
 * řádcích a aspoň `minRows` řádky. Dominantní `null` je správná odpověď
 * u catch-all a leasingových položek — ty návrh dostat nemají.
 *
 * Multi-tag se v v1 nenavrhuje: jeden dominantní štítek, nebo nic.
 *
 * Čistá služba bez DB — katalog i statistiky užití dostane hotové, takže
 * je testovatelná bez datasetu. Zápis dělá
 * {@see \Shipard\Module\Economy\Items\ContentTagBackfill::apply()}, tedy
 * tatáž cesta jako u offer režimu (merge štítků, izolace chyb per položka).
 */
final class BookingHistoryItemUsageTagger
{
    public const DEFAULT_MIN_SHARE = 0.7;
    public const DEFAULT_MIN_ROWS = 5;

    /** Míra shody kódů, od které auto režim volí usage místo offer. */
    public const AUTO_MATCH_RATE = 0.8;

    /** @var array<string, array{id: int, code: string, name: string, tags: list<string>}> */
    private array $catalog = [];

    /**
     * @param list<array{id: int, code: string, name: string, tags: list<string>}> $catalog
     *        živé položky DS (kód, název, existující štítky)
     * @param array<string, array{rows: int, dominant: ?string, dominantRows: int, share: float, dominantIsNull: bool, tie: bool}> $usage
     *        {@see BookingHistoryAnalysis::usageByItemCode()}
     * @param array<string, int> $fileItemCodes všechny kódy souboru → řádky
     */
    public function __construct(
        array $catalog,
        private readonly array $usage,
        private readonly array $fileItemCodes,
        private readonly float $minShare = self::DEFAULT_MIN_SHARE,
        private readonly int $minRows = self::DEFAULT_MIN_ROWS,
    ) {
        foreach ($catalog as $item) {
            // Kódy jsou identifikátory — porovnávají se po trimu a bez
            // ohledu na velikost písmen (číselné kódy tím nic neutrpí).
            $this->catalog[self::normalizeCode((string) $item['code'])] = $item;
        }
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Podíl kódů souboru, které jsou v katalogu DS. Rozhoduje auto volbu
     * režimu — nízká míra znamená „tenhle soubor je z jiného světa".
     */
    public function matchRate(): float
    {
        $fileCodes = $this->fileCodeCount();
        return $fileCodes > 0 ? $this->matchedCodeCount() / $fileCodes : 0.0;
    }

    public function fileCodeCount(): int
    {
        return count($this->fileItemCodes);
    }

    public function matchedCodeCount(): int
    {
        $matched = 0;
        foreach (array_keys($this->fileItemCodes) as $code) {
            if (isset($this->catalog[self::normalizeCode((string) $code)])) {
                $matched++;
            }
        }
        return $matched;
    }

    /** Je signál dost silný na to, aby auto režim vybral usage? */
    public function isAutoEligible(): bool
    {
        return $this->matchRate() >= self::AUTO_MATCH_RATE;
    }

    /**
     * Plán otagování, nejsilnější podpora první.
     *
     * @return list<array{id: int, code: string, name: string, tag: string, rows: int, dominantRows: int, share: float}>
     */
    public function plan(): array
    {
        $plan = [];
        foreach ($this->usage as $code => $entry) {
            $item = $this->catalog[self::normalizeCode((string) $code)] ?? null;
            if ($item === null || $item['tags'] !== []) {
                continue;
            }
            if ($entry['dominant'] === null
                || $entry['dominantRows'] < $this->minRows
                || $entry['share'] < $this->minShare
            ) {
                continue;
            }
            $plan[] = [
                'id'           => $item['id'],
                'code'         => $item['code'],
                'name'         => $item['name'],
                'tag'          => $entry['dominant'],
                'rows'         => $entry['rows'],
                'dominantRows' => $entry['dominantRows'],
                'share'        => $entry['share'],
            ];
        }
        usort($plan, static function (array $a, array $b): int {
            return [$b['dominantRows'], $b['share'], $a['code']] <=> [$a['dominantRows'], $a['share'], $b['code']];
        });
        return $plan;
    }

    /**
     * Proč se kód do plánu nedostal — vstup pro výpis příkazu, aby „nic se
     * neotagovalo" nebylo neprůhledné.
     *
     * @return array{candidates: int, notInCatalog: int, alreadyTagged: int, dominantNull: int, tie: int, belowShare: int, belowRows: int}
     */
    public function skipped(): array
    {
        $counters = [
            'candidates'    => 0,
            'notInCatalog'  => 0,
            'alreadyTagged' => 0,
            'dominantNull'  => 0,
            'tie'           => 0,
            'belowShare'    => 0,
            'belowRows'     => 0,
        ];
        foreach ($this->usage as $code => $entry) {
            $item = $this->catalog[self::normalizeCode((string) $code)] ?? null;
            if ($item === null) {
                $counters['notInCatalog']++;
            } elseif ($item['tags'] !== []) {
                $counters['alreadyTagged']++;
            } elseif ($entry['tie']) {
                $counters['tie']++;
            } elseif ($entry['dominantIsNull'] || $entry['dominant'] === null) {
                $counters['dominantNull']++;
            } elseif ($entry['share'] < $this->minShare) {
                $counters['belowShare']++;
            } elseif ($entry['dominantRows'] < $this->minRows) {
                $counters['belowRows']++;
            } else {
                $counters['candidates']++;
            }
        }
        return $counters;
    }
}
