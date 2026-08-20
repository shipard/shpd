<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMap;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMatch;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalysis;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalyzer;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFile;

/**
 * Jeden průchod souborem: kvalita + seed + clustery, a odvozené statistiky
 * nad nimi (pokrytí, konzistence, mrtvé štítky, rozptyl účtů).
 */
class BookingHistoryAnalyzerTest extends TestCase
{
    private function map(): AccountTagMap
    {
        return new AccountTagMap([
            '518202' => ['it.internet'],
            '501100' => ['office.supplies'],
            '518300' => ['services.postage'],
        ]);
    }

    private function analyze(): BookingHistoryAnalysis
    {
        $file = BookingHistoryFile::open(
            dirname(__DIR__, 5) . '/Fixtures/Exchange/bookingHistory/valid.jsonl',
        );
        return (new BookingHistoryAnalyzer())->analyze($file, $this->map());
    }

    public function testSinglePassFillsAllConsumers(): void
    {
        $analysis = $this->analyze();

        $this->assertSame(4, $analysis->recordCount);
        $this->assertSame(4, $analysis->header->recordCount, 'hlavička souhlasí se skutečností');
        $this->assertSame(4, $analysis->quality->records());

        // Clustery jen z obsahonosných textů — degenerované se nesbírají.
        $this->assertSame(1, $analysis->distinctTextCount());
        $cluster = $analysis->clusters()['měsíční paušál za internet 100/10'];
        $this->assertSame('Měsíční  paušál za internet 100/10', $cluster->text);
        $this->assertSame(96, $cluster->rows);
        $this->assertSame('it.internet', $cluster->dominantReverseTag());

        // Druhy reverzní shody se počítají po řádcích a nad **všemi**
        // záznamy — i degenerovaný text má účet, takže do reverzu vstupuje
        // (96 na 518202 + 9 na 501100 + 3 na 518300).
        $this->assertSame(108, $analysis->matchKindRows[AccountTagMatch::KIND_EXACT]);
        $this->assertSame(2, $analysis->matchKindRows[AccountTagMatch::KIND_NO_ACCOUNT]);
    }

    public function testAccountsWithoutReverseTag(): void
    {
        $analysis = $this->analyze();
        $missing = $analysis->accountsWithoutReverseTag();

        // 501100 i 518300 jsou v mapě; 518300 padne na exact, takže tady
        // zůstane jen to, co mapa nezná — v tomto fixture nic.
        $this->assertSame([], $missing);
    }

    public function testDerivedStatsOverLlmTags(): void
    {
        // Umělá analýza: dva clustery, jeden shodný s reverzem, jeden ne.
        $file = BookingHistoryFile::open($this->tempFile(<<<'JSONL'
            {"format":"shpd.economy.booking-history","version":1,"chartVariant":"default"}
            {"companyId":"26378191","account":"518202","rowText":"Paušál internet","docCount":10,"rowCount":80,"totalAmount":8000}
            {"companyId":"11223344","account":"501100","rowText":"Toner do tiskárny","docCount":4,"rowCount":20,"totalAmount":2000}
            {"companyId":"55667788","account":"999999","rowText":"Nezařaditelná služba","docCount":3,"rowCount":10,"totalAmount":50000}
            JSONL));
        $analysis = (new BookingHistoryAnalyzer())->analyze($file, $this->map());

        $analysis->applyLlmTags([
            'paušál internet'      => 'it.internet',      // shoda s reverzem
            'toner do tiskárny'    => 'it.hardware',      // neshoda (reverz: office.supplies)
            'nezařaditelná služba' => null,               // model štítek nenašel
        ]);

        $this->assertSame(3, $analysis->classifiedClusterCount());

        $usage = $analysis->tagUsage();
        $this->assertSame(80, $usage['it.internet']['llmRows']);
        $this->assertSame(80, $usage['it.internet']['reverseRows']);
        $this->assertSame(20, $usage['it.hardware']['llmRows']);
        $this->assertSame(0, $usage['it.hardware']['reverseRows']);
        $this->assertSame(20, $usage['office.supplies']['reverseRows']);

        $consistency = $analysis->consistency();
        $this->assertSame(100, $consistency['rows'], 'cluster bez LLM štítku do matice nevstupuje');
        $this->assertSame(80, $consistency['agree']);
        $this->assertSame(20, $consistency['matrix']['it.hardware']['office.supplies']);
        $this->assertEqualsWithDelta(0.0, $consistency['perTag']['it.hardware']['share'], 0.0001);

        $disagreements = $analysis->disagreements();
        $this->assertCount(1, $disagreements);
        $this->assertSame('it.hardware', $disagreements[0]['tag']);
        $this->assertSame(['office.supplies' => 20], $disagreements[0]['topReverse']);

        // Nezařaditelné: podíl řádků a objemné clustery.
        $unclassified = $analysis->unclassifiedClusters();
        $this->assertCount(1, $unclassified);
        $this->assertSame('Nezařaditelná služba', $unclassified[0]->text);
        $this->assertEqualsWithDelta(10 / 110, $analysis->unclassifiedRowShare(), 0.0001);

        // Mrtvé štítky: co taxonomie zná a soubor nikde nepoužil.
        $dead = $analysis->deadTags(['it.internet', 'it.hardware', 'office.supplies', 'premises.rent']);
        $this->assertSame(['premises.rent'], $dead);

        // Objemný účet bez reverzního štítku se vypíchne (50 000 na 999999).
        $missing = $analysis->accountsWithoutReverseTag();
        $this->assertSame('999999', $missing[0]['account']);
        $this->assertSame(AccountTagMatch::KIND_UNMAPPED, $missing[0]['kind']);

        $spread = $analysis->accountSpread();
        $this->assertSame('it.internet', $spread[0]['tag']);
        $this->assertSame(1, $spread[0]['accounts']);
    }

    public function testClassificationOrderPrefersVolume(): void
    {
        $file = BookingHistoryFile::open($this->tempFile(<<<'JSONL'
            {"format":"shpd.economy.booking-history","version":1}
            {"account":"518202","rowText":"malý","docCount":1,"rowCount":1}
            {"account":"518202","rowText":"velký","docCount":9,"rowCount":90}
            JSONL));
        $analysis = (new BookingHistoryAnalyzer())->analyze($file, $this->map());

        $order = array_map(
            static fn ($cluster): string => $cluster->text,
            $analysis->clustersForClassification(),
        );
        $this->assertSame(['velký', 'malý'], $order);
    }

    public function testEmptyFileYieldsEmptyAnalysis(): void
    {
        $file = BookingHistoryFile::open(
            dirname(__DIR__, 5) . '/Fixtures/Exchange/bookingHistory/headerOnly.jsonl',
        );
        $analysis = (new BookingHistoryAnalyzer())->analyze($file, $this->map());

        $this->assertSame(0, $analysis->recordCount);
        $this->assertSame(0, $analysis->distinctTextCount());
        $this->assertSame([], $analysis->seed->candidates());
        $this->assertSame(0.0, $analysis->unclassifiedRowShare());
        $this->assertSame(['matrix' => [], 'perTag' => [], 'rows' => 0, 'agree' => 0], $analysis->consistency());
    }

    /** @var list<string> */
    private array $tempFiles = [];

    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/bh-analyzer-' . uniqid() . '.jsonl';
        // Heredoc v testu je odsazený — JSONL odsazení nesnese.
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
