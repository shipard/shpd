<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Xml;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\Xml\EpoXmlDiff;

/**
 * Porovnávač souborů pro EPO (#55 X9). Musí ignorovat všechno, co o shodě
 * podání nic neříká (pořadí atributů i řádků, zápis čísla, odsazení),
 * a naopak spolehlivě chytit jinou hodnotu, chybějící i přebývající řádek.
 */
class EpoXmlDiffTest extends TestCase
{
    public function testIdenticalFilesHaveNoDifferences(): void
    {
        $xml = $this->dp3('<Veta1 obrat23="1000" dan23="210"/>');

        $this->assertSame([], EpoXmlDiff::compare($xml, $xml));
        $this->assertSame('Soubory se shodují.', EpoXmlDiff::format([]));
    }

    public function testAttributeOrderAndFormattingDoNotMatter(): void
    {
        $expected = $this->dp3('<Veta1 obrat23="1000" dan23="210"/>');
        $actual   = $this->dp3("<Veta1\n  dan23=\"210.00\"\n  obrat23=\"1000.0\"/>");

        $this->assertSame([], EpoXmlDiff::compare($expected, $actual));
    }

    public function testDateWrittenWithOrWithoutLeadingZeroIsTheSameDay(): void
    {
        $expected = $this->dp3('<VetaD d_zjist="4.5.2026"/>');
        $actual   = $this->dp3('<VetaD d_zjist="04.05.2026"/>');

        $this->assertSame([], EpoXmlDiff::compare($expected, $actual));
    }

    public function testChangedValueIsReportedWithBothSides(): void
    {
        $differences = EpoXmlDiff::compare(
            $this->dp3('<Veta1 obrat23="1000" dan23="210"/>'),
            $this->dp3('<Veta1 obrat23="1000" dan23="211"/>'),
        );

        $this->assertCount(1, $differences);
        $this->assertSame(['Veta1', 'value', 'dan23', '210', '211'], [
            $differences[0]['sentence'],
            $differences[0]['kind'],
            $differences[0]['attribute'],
            $differences[0]['expected'],
            $differences[0]['actual'],
        ]);
        $this->assertStringContainsString('Veta1/@dan23', EpoXmlDiff::format($differences));
    }

    public function testMissingAndExtraAttributesAreReported(): void
    {
        $differences = EpoXmlDiff::compare(
            $this->dp3('<Veta1 obrat23="1000" dan23="210"/>'),
            $this->dp3('<Veta1 obrat23="1000" obrat5="500"/>'),
        );

        $reported = array_column($differences, 'attribute');
        sort($reported);
        $this->assertSame(['dan23', 'obrat5'], $reported);
        $this->assertSame('—', $differences[array_search('dan23', $reported, true)]['actual']);
    }

    // ── Řádky hlášení ───────────────────────────────────────────────────────

    public function testRowOrderInASectionDoesNotMatter(): void
    {
        $expected = $this->kh1(
            '<VetaA4 dic_odb="1" zakl_dane1="100.00"/><VetaA4 dic_odb="2" zakl_dane1="200.00"/>',
        );
        $actual = $this->kh1(
            '<VetaA4 dic_odb="2" zakl_dane1="200.00"/><VetaA4 dic_odb="1" zakl_dane1="100.00"/>',
        );

        $this->assertSame([], EpoXmlDiff::compare($expected, $actual));
    }

    public function testMissingRowIsReported(): void
    {
        $differences = EpoXmlDiff::compare(
            $this->kh1('<VetaA4 dic_odb="1" zakl_dane1="100.00"/><VetaA4 dic_odb="2" zakl_dane1="200.00"/>'),
            $this->kh1('<VetaA4 dic_odb="1" zakl_dane1="100.00"/>'),
        );

        $this->assertCount(1, $differences);
        $this->assertSame('missing', $differences[0]['kind']);
        $this->assertStringContainsString('dic_odb="2"', $differences[0]['row']);
        $this->assertStringContainsString('chybí řádek', EpoXmlDiff::format($differences));
    }

    public function testExtraRowIsReported(): void
    {
        $differences = EpoXmlDiff::compare(
            $this->kh1('<VetaA4 dic_odb="1" zakl_dane1="100.00"/>'),
            $this->kh1('<VetaA4 dic_odb="1" zakl_dane1="100.00"/><VetaA4 dic_odb="9" zakl_dane1="900.00"/>'),
        );

        $this->assertCount(1, $differences);
        $this->assertSame('extra', $differences[0]['kind']);
        $this->assertStringContainsString('přebývá řádek', EpoXmlDiff::format($differences));
    }

    public function testWholeMissingSentenceIsReportedAsMissingRows(): void
    {
        $differences = EpoXmlDiff::compare(
            $this->kh1('<VetaA4 dic_odb="1" zakl_dane1="100.00"/><VetaB2 dic_dod="5" zakl_dane1="50.00"/>'),
            $this->kh1('<VetaA4 dic_odb="1" zakl_dane1="100.00"/>'),
        );

        $this->assertSame(['VetaB2'], array_column($differences, 'sentence'));
    }

    // ── Ignorované atributy ─────────────────────────────────────────────────

    public function testSoftwareAndContactDifferencesAreIgnoredByDefault(): void
    {
        $expected = $this->dp3('<VetaP dic="12345678" email="stary@example.com" c_telef="571000000"/>', 'StarySW');
        $actual   = $this->dp3('<VetaP dic="12345678" email="novy@example.com" c_telef="571999999"/>');

        $this->assertSame([], EpoXmlDiff::compare($expected, $actual));
    }

    public function testStrictComparisonSeesThemAll(): void
    {
        $expected = $this->dp3('<VetaP dic="12345678" email="stary@example.com"/>', 'StarySW');
        $actual   = $this->dp3('<VetaP dic="12345678" email="novy@example.com"/>');

        $differences = EpoXmlDiff::compare($expected, $actual, []);
        $attributes  = array_column($differences, 'attribute');
        sort($attributes);

        $this->assertSame(['email', 'nazevSW'], $attributes);
    }

    public function testBrokenXmlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EpoXmlDiff::compare('<Pisemnost>', $this->dp3(''));
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function dp3(string $body, string $software = 'Shipard'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Pisemnost nazevSW="' . $software . '" verzeSW="0.1.1">'
            . '<DPHDP3 verzePis="01.02">' . $body . '</DPHDP3></Pisemnost>';
    }

    private function kh1(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Pisemnost nazevSW="Shipard" verzeSW="0.1.1">'
            . '<DPHKH1 verzePis="01.02">' . $body . '</DPHKH1></Pisemnost>';
    }
}
