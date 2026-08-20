<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMap;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalysis;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalyzer;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFile;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryReport;

/** Markdown report dle D35 — sekce, čísla, degradace bez LLM. */
class BookingHistoryReportTest extends TestCase
{
    private const TAXONOMY = [
        'it.internet'     => ['name' => 'Internet connectivity'],
        'office.supplies' => ['name' => 'Office supplies'],
        'it.hardware'     => ['name' => 'IT hardware'],
        'premises.rent'   => ['name' => 'Rent'],
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    private function analysis(): BookingHistoryAnalysis
    {
        $path = $this->tempFile(<<<'JSONL'
            {"format":"shpd.economy.booking-history","version":1,"sourceSystem":{"name":"shipard-e10"},"sourceRef":"ds test","chartVariant":"default","currency":"CZK","period":{"from":"2020-01-01","to":"2026-06-30"},"docTypes":["invni"],"recordCount":9}
            {"companyId":"11111111","account":"518202","itemName":"Internet","rowText":"Paušál internet 100/10","docCount":40,"rowCount":80,"totalAmount":80000}
            {"companyId":"11111111","account":"518202","itemName":"Internet","rowText":"Zřízení přípojky","docCount":1,"rowCount":1,"totalAmount":2000}
            {"companyId":"22222222","account":"501100","itemName":"Papír","rowText":"Toner do tiskárny","docCount":10,"rowCount":20,"totalAmount":20000}
            {"companyId":"22222222","account":"501100","itemName":"Papír","rowText":"Papír","docCount":5,"rowCount":10,"totalAmount":5000}
            {"companyId":"33333333","account":"999999","rowText":"Nezařaditelná služba","docCount":6,"rowCount":12,"totalAmount":300000}
            {"companyId":"33333333","account":"999999","rowText":"999999","docCount":2,"rowCount":4,"totalAmount":1000}
            {"companyId":null,"account":null,"rowText":"","docCount":3,"rowCount":6,"totalAmount":null}
            {"companyId":"44444444","account":"518202","rowText":"Paušál internet 100/10","docCount":4,"rowCount":8,"totalAmount":8000}
            JSONL);

        $map = new AccountTagMap([
            '518202' => ['it.internet'],
            '501100' => ['office.supplies'],
        ]);
        return (new BookingHistoryAnalyzer())->analyze(BookingHistoryFile::open($path), $map);
    }

    public function testAllSectionsArePresentWithLlmTags(): void
    {
        $analysis = $this->analysis();
        $analysis->applyLlmTags([
            'paušál internet 100/10' => 'it.internet',
            'zřízení přípojky'       => 'it.internet',
            'toner do tiskárny'      => 'it.hardware',   // neshoda s reverzem
            'papír'                  => 'office.supplies',
            'nezařaditelná služba'   => null,
        ]);

        $markdown = (new BookingHistoryReport(
            $analysis,
            self::TAXONOMY,
            [
                '11111111' => ['action' => 'insert'],
                '22222222' => ['action' => 'skip', 'existingTag' => 'goods.stock', 'existingOrigin' => 'user'],
                '44444444' => ['action' => 'update', 'existingTag' => 'it.phone', 'existingOrigin' => 'seed'],
            ],
            '/tmp/history.jsonl',
            '2026-08-20 12:00',
        ))->render();

        foreach ([
            '# Report účetní historie',
            '## Kvalita zdroje',
            '## Pokrytí taxonomie',
            '## Konzistence LLM × reverz',
            '## Mrtvé štítky',
            '## Náhled seed pravidel',
            '### Objemné účty',
            '### Objemné účty bez reverzního štítku',
            '### Texty bez štítku (LLM)',
            '### Štítky s neshodou nad prahem',
            '### Rozptyl účtů per štítek',
        ] as $section) {
            $this->assertStringContainsString($section, $markdown, "chybí sekce {$section}");
        }

        // Zdroj a nesoulad hlavičky (hlásí 9, soubor má 8).
        $this->assertStringContainsString('shipard-e10 (ds test)', $markdown);
        $this->assertStringContainsString('`history.jsonl`', $markdown);
        $this->assertStringContainsString('⚠ hlavička hlásí 9, soubor má 8', $markdown);

        // Kvalita: degenerované texty (prázdný + == itemName + == account).
        $this->assertStringContainsString('| degenerovaný text |', $markdown);
        $this->assertStringContainsString('shodný s číslem účtu', $markdown);

        // Objemný účet bez reverzního štítku (300 000 na 999999).
        $this->assertStringContainsString('mimo nabídku', $markdown);
        $this->assertStringContainsString('`999999`', $markdown);

        // Konzistence: it.hardware vs office.supplies.
        $this->assertStringContainsString('`it.hardware`', $markdown);
        $this->assertStringContainsString('Nezařaditelná služba', $markdown);

        // Mrtvý štítek: premises.rent se v souboru neobjevil.
        $this->assertStringContainsString('`premises.rent` — Rent', $markdown);

        // Seed: plán zápisu per kandidát.
        $this->assertStringContainsString('nové pravidlo', $markdown);
        $this->assertStringContainsString('**přeskočeno** (má `goods.stock`, původ `user`)', $markdown);
        $this->assertStringContainsString('aktualizace seedu (má `it.phone`, původ `seed`)', $markdown);
    }

    public function testReportWithoutLlmDegradesToReverseOnly(): void
    {
        $markdown = (new BookingHistoryReport($this->analysis(), self::TAXONOMY))->render();

        $this->assertStringContainsString('LLM klasifikace neproběhla', $markdown);
        $this->assertStringNotContainsString('## Konzistence LLM × reverz', $markdown);
        // Kvalita a seed jdou i bez LLM.
        $this->assertStringContainsString('## Kvalita zdroje', $markdown);
        $this->assertStringContainsString('## Náhled seed pravidel', $markdown);
    }

    public function testMissingTaxonomyDoesNotBreakDeadTagsSection(): void
    {
        $markdown = (new BookingHistoryReport($this->analysis(), []))->render();
        $this->assertStringContainsString('## Mrtvé štítky', $markdown);
        $this->assertStringContainsString('Taxonomie není k dispozici', $markdown);
    }

    public function testSummaryLines(): void
    {
        $analysis = $this->analysis();
        $analysis->applyLlmTags(['paušál internet 100/10' => 'it.internet']);

        $summary = implode("\n", (new BookingHistoryReport($analysis, self::TAXONOMY))->summaryLines());

        $this->assertStringContainsString('Záznamů: 8', $summary);
        $this->assertStringContainsString('Degenerovaných textů:', $summary);
        $this->assertStringContainsString('Reverz účet→štítek:', $summary);
        $this->assertStringContainsString('LLM klasifikace: 1 distinct textů', $summary);
        $this->assertStringContainsString('Seed kandidátů:', $summary);
    }

    public function testEmptyFileRendersWithoutCrash(): void
    {
        $path = $this->tempFile('{"format":"shpd.economy.booking-history","version":1}');
        $analysis = (new BookingHistoryAnalyzer())->analyze(
            BookingHistoryFile::open($path),
            new AccountTagMap([]),
        );

        $markdown = (new BookingHistoryReport($analysis, self::TAXONOMY))->render();
        $this->assertStringContainsString('_Žádný kandidát — seed by nic nezapsal._', $markdown);
        $this->assertStringContainsString('## Kvalita zdroje', $markdown);
    }

    public function testPipeInTextDoesNotBreakTable(): void
    {
        $path = $this->tempFile(<<<'JSONL'
            {"format":"shpd.economy.booking-history","version":1}
            {"companyId":"11111111","account":"999999","rowText":"Služba A | Služba B","docCount":3,"rowCount":9,"totalAmount":1000}
            JSONL);
        $analysis = (new BookingHistoryAnalyzer())->analyze(
            BookingHistoryFile::open($path),
            new AccountTagMap(['518202' => ['it.internet']]),
        );
        $analysis->applyLlmTags(['služba a | služba b' => null]);

        $markdown = (new BookingHistoryReport($analysis, self::TAXONOMY))->render();
        $this->assertStringContainsString('Služba A \\| Služba B', $markdown);
    }

    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/bh-report-' . uniqid() . '.jsonl';
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
