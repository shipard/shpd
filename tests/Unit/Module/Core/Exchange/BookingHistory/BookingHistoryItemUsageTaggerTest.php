<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMap;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalyzer;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFile;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryItemUsageTagger;

/**
 * Otagování položek z užití (D38): míra shody kódů, dominance klasifikace
 * per položka, prahy a důvody zamítnutí.
 */
class BookingHistoryItemUsageTaggerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * @param list<array{id: int, code: string, name: string, tags: list<string>}> $catalog
     * @param array<string, array<string, int>> $usageTags kód → (štítek|'' pro null) → řádky
     * @param array<string, int>|null $fileCodes
     */
    private function tagger(
        array $catalog,
        array $usageTags,
        ?array $fileCodes = null,
        float $minShare = BookingHistoryItemUsageTagger::DEFAULT_MIN_SHARE,
        int $minRows = BookingHistoryItemUsageTagger::DEFAULT_MIN_ROWS,
    ): BookingHistoryItemUsageTagger {
        $usage = [];
        foreach ($usageTags as $code => $tags) {
            $rows = array_sum($tags);
            $winner = null;
            $winnerRows = 0;
            $tie = false;
            foreach ($tags as $tag => $tagRows) {
                if ($tagRows > $winnerRows) {
                    $winnerRows = $tagRows;
                    $winner = (string) $tag;
                    $tie = false;
                } elseif ($tagRows === $winnerRows) {
                    $tie = true;
                }
            }
            $usage[(string) $code] = [
                'tags'           => $tags,
                'rows'           => $rows,
                'dominant'       => ($tie || $winner === '' || $winner === null) ? null : $winner,
                'dominantRows'   => $winnerRows,
                'share'          => $rows > 0 ? $winnerRows / $rows : 0.0,
                'dominantIsNull' => $winner === '',
                'tie'            => $tie,
            ];
        }
        $fileCodes ??= array_fill_keys(array_keys($usageTags), 10);

        return new BookingHistoryItemUsageTagger($catalog, $usage, $fileCodes, $minShare, $minRows);
    }

    /** @return list<array{id: int, code: string, name: string, tags: list<string>}> */
    private function catalog(): array
    {
        return [
            ['id' => 1, 'code' => '11', 'name' => 'Ubytování', 'tags' => []],
            ['id' => 2, 'code' => '100', 'name' => 'Parkovné', 'tags' => []],
            ['id' => 3, 'code' => '103', 'name' => 'Ostatní provozní náklady', 'tags' => []],
            ['id' => 4, 'code' => '200', 'name' => 'Nafta', 'tags' => ['vehicle.fuel']],
        ];
    }

    public function testMatchRateCountsFileCodesPresentInCatalog(): void
    {
        $tagger = $this->tagger($this->catalog(), [], ['11' => 5, '100' => 5, '999' => 5, '888' => 5]);

        $this->assertSame(4, $tagger->fileCodeCount());
        $this->assertSame(2, $tagger->matchedCodeCount());
        $this->assertEqualsWithDelta(0.5, $tagger->matchRate(), 0.0001);
        $this->assertFalse($tagger->isAutoEligible());
    }

    public function testAutoEligibleAtThreshold(): void
    {
        $codes = ['11' => 5, '100' => 5, '103' => 5, '200' => 5, '999' => 5];
        $tagger = $this->tagger($this->catalog(), [], $codes);

        $this->assertEqualsWithDelta(0.8, $tagger->matchRate(), 0.0001);
        $this->assertTrue($tagger->isAutoEligible(), 'práh 0.8 je včetně');
    }

    public function testEmptyFileCodesMeanZeroMatchRate(): void
    {
        $tagger = $this->tagger($this->catalog(), [], []);
        $this->assertSame(0.0, $tagger->matchRate());
        $this->assertFalse($tagger->isAutoEligible());
    }

    public function testCodeMatchingIgnoresCaseAndWhitespace(): void
    {
        $tagger = $this->tagger(
            [['id' => 9, 'code' => 'ab-12', 'name' => 'Položka', 'tags' => []]],
            ['  AB-12 ' => ['it.software' => 9]],
        );
        $plan = $tagger->plan();
        $this->assertCount(1, $plan);
        $this->assertSame(9, $plan[0]['id']);
    }

    public function testDominantTagAboveThresholdsBecomesPlan(): void
    {
        $tagger = $this->tagger($this->catalog(), [
            '11'  => ['travel.accommodation' => 18, 'people.catering' => 2],
            '100' => ['vehicle.parking' => 9],
        ]);

        $plan = $tagger->plan();
        $this->assertCount(2, $plan);
        // Řazení podle podpory: 18 řádků před 9.
        $this->assertSame('11', $plan[0]['code']);
        $this->assertSame('travel.accommodation', $plan[0]['tag']);
        $this->assertSame(18, $plan[0]['dominantRows']);
        $this->assertSame(20, $plan[0]['rows']);
        $this->assertEqualsWithDelta(0.9, $plan[0]['share'], 0.0001);
        $this->assertSame('vehicle.parking', $plan[1]['tag']);
        $this->assertSame(2, $tagger->skipped()['candidates']);
    }

    public function testDominantNullMeansNoProposal(): void
    {
        // Catch-all položka: model u většiny textů štítek nenašel.
        $tagger = $this->tagger($this->catalog(), [
            '103' => ['' => 30, 'office.supplies' => 4],
        ]);

        $this->assertSame([], $tagger->plan());
        $this->assertSame(1, $tagger->skipped()['dominantNull']);
    }

    public function testTieMeansNoProposal(): void
    {
        $tagger = $this->tagger($this->catalog(), [
            '11' => ['travel.accommodation' => 10, 'people.catering' => 10],
        ]);

        $this->assertSame([], $tagger->plan());
        $this->assertSame(1, $tagger->skipped()['tie']);
    }

    public function testBelowShareAndBelowRowsAreRejectedSeparately(): void
    {
        $belowShare = $this->tagger($this->catalog(), [
            '11' => ['travel.accommodation' => 6, 'people.catering' => 4],
        ]);
        $this->assertSame([], $belowShare->plan());
        $this->assertSame(1, $belowShare->skipped()['belowShare']);

        $belowRows = $this->tagger($this->catalog(), [
            '100' => ['vehicle.parking' => 4],
        ]);
        $this->assertSame([], $belowRows->plan());
        $this->assertSame(1, $belowRows->skipped()['belowRows']);
    }

    public function testThresholdsAreConfigurable(): void
    {
        $usage = ['11' => ['travel.accommodation' => 3, 'people.catering' => 2]];

        $this->assertSame([], $this->tagger($this->catalog(), $usage)->plan());
        $this->assertCount(1, $this->tagger($this->catalog(), $usage, minShare: 0.6, minRows: 3)->plan());
    }

    public function testAlreadyTaggedItemIsSkipped(): void
    {
        $tagger = $this->tagger($this->catalog(), [
            '200' => ['vehicle.service' => 40],   // položka už má vehicle.fuel
        ]);

        $this->assertSame([], $tagger->plan());
        $this->assertSame(1, $tagger->skipped()['alreadyTagged']);
    }

    public function testCodeOutsideCatalogIsSkipped(): void
    {
        $tagger = $this->tagger($this->catalog(), [
            '999999' => ['premises.rent' => 50],
        ]);

        $this->assertSame([], $tagger->plan());
        $this->assertSame(1, $tagger->skipped()['notInCatalog']);
    }

    /**
     * Degenerované texty se do agregace nedostanou — cluster nad nimi
     * nevzniká (D33), takže „text == název položky" nemůže kruhově potvrdit
     * sám sebe.
     */
    public function testDegenerateTextsStayOutOfUsageAggregation(): void
    {
        $path = $this->tempFile(<<<'JSONL'
            {"format":"shpd.economy.booking-history","version":1}
            {"companyId":"11111111","account":"518001","itemCode":"11","itemName":"Ubytování","rowText":"Ubytování","docCount":9,"rowCount":30}
            {"companyId":"11111111","account":"518001","itemCode":"11","itemName":"Ubytování","rowText":"Hotel Kramolín 3 noci","docCount":3,"rowCount":6}
            JSONL);
        $analysis = (new BookingHistoryAnalyzer())->analyze(
            BookingHistoryFile::open($path),
            new AccountTagMap([]),
        );
        $analysis->applyLlmTags(['hotel kramolín 3 noci' => 'travel.accommodation']);

        $usage = $analysis->usageByItemCode();
        // Jen obsahonosný cluster: 6 řádků, ne 36.
        $this->assertSame(6, $usage['11']['rows']);
        $this->assertSame('travel.accommodation', $usage['11']['dominant']);

        $tagger = new BookingHistoryItemUsageTagger(
            $this->catalog(),
            $usage,
            $analysis->fileItemCodes,
        );
        $plan = $tagger->plan();
        $this->assertCount(1, $plan);
        $this->assertSame(6, $plan[0]['rows']);
        // Míra shody bere všechny kódy souboru, i ty degenerované.
        $this->assertSame(1, $tagger->fileCodeCount());
        $this->assertEqualsWithDelta(1.0, $tagger->matchRate(), 0.0001);
    }

    public function testUnclassifiedClustersDoNotCount(): void
    {
        $path = $this->tempFile(<<<'JSONL'
            {"format":"shpd.economy.booking-history","version":1}
            {"companyId":"11111111","itemCode":"100","rowText":"Parkoviště P1 letiště","docCount":9,"rowCount":20}
            {"companyId":"11111111","itemCode":"100","rowText":"Neklasifikovaný text","docCount":9,"rowCount":40}
            JSONL);
        $analysis = (new BookingHistoryAnalyzer())->analyze(
            BookingHistoryFile::open($path),
            new AccountTagMap([]),
        );
        // Druhý text klasifikací neprošel (padlá dávka) → do tally nepatří.
        $analysis->applyLlmTags(['parkoviště p1 letiště' => 'vehicle.parking']);

        $usage = $analysis->usageByItemCode();
        $this->assertSame(20, $usage['100']['rows']);
        $this->assertEqualsWithDelta(1.0, $usage['100']['share'], 0.0001);
    }

    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/bh-usage-' . uniqid() . '.jsonl';
        $lines = array_map('trim', explode("\n", trim($contents)));
        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->tempFiles[] = $path;
        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }
}
