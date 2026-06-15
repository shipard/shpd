<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\StatementFormatDetector;

class StatementFormatDetectorTest extends TestCase
{
    private function detector(): StatementFormatDetector
    {
        return new StatementFormatDetector([
            'cz.cba-xml'  => ['name' => 'ČBA / CAMT XML', 'checkRegExp' => '/<BkToCstmrStmt>/'],
            'cz.gpc'      => ['name' => 'GPC', 'srcCharset' => 'CP1250', 'checkRegExp' => '/^074(.+)\s075(.+)/', 'checkRegExp2' => '/^074(.+)\s/'],
            'cz.fio-json' => ['name' => 'FIO / JSON', 'checkRegExp' => '/"accountStatement"\s*:\s*\{/'],
        ]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Bank/' . $name);
    }

    public function testDetectCamt(): void
    {
        $r = $this->detector()->detect($this->fixture('camt053.xml'));
        $this->assertSame('cz.cba-xml', $r['formatId']);
        $this->assertNull($r['srcCharset']);
    }

    public function testDetectGpc(): void
    {
        $r = $this->detector()->detect($this->fixture('statement.gpc'));
        $this->assertSame('cz.gpc', $r['formatId']);
        $this->assertSame('CP1250', $r['srcCharset']);
    }

    public function testDetectFio(): void
    {
        $r = $this->detector()->detect($this->fixture('fio.json'));
        $this->assertSame('cz.fio-json', $r['formatId']);
        $this->assertNull($r['srcCharset']);
    }

    public function testUnknownFormatThrows(): void
    {
        $this->expectException(ImportException::class);
        $this->detector()->detect('cosi neznámého');
    }

    public function testDecodeCp1250Roundtrip(): void
    {
        $utf8 = 'Příliš žluťoučký kůň úpěl ďábelské ódy';
        $cp1250 = iconv('UTF-8', 'CP1250', $utf8);
        $this->assertNotFalse($cp1250);

        $decoded = $this->detector()->decode($cp1250, 'CP1250');
        $this->assertSame($utf8, $decoded);
    }

    public function testDecodeNullCharsetPassthrough(): void
    {
        $this->assertSame('raw', $this->detector()->decode('raw', null));
    }
}
