<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Xml;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderProfile;
use Shipard\Core\Render\RenderResult;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Module\Economy\Vat\Xml\FilingPdfService;
use Shipard\Module\Economy\Vat\Xml\FilingPeriod;
use Shipard\Module\Economy\Vat\Xml\FilingXmlInput;

/** Engine bez HTTP — vrací připravený výsledek a pamatuje si HTML. */
final class CapturingRenderEngine implements RenderEngineInterface
{
    /** @var list<string> */
    public array $html = [];

    public function __construct(private readonly RenderResult $result) {}

    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult
    {
        $this->html[] = $html;
        return $this->result;
    }

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult
    {
        return $this->result;
    }

    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult
    {
        return $this->result;
    }

    public function health(): bool
    {
        return true;
    }
}

/**
 * Opis a obsah podání v PDF (#55 X7). Testuje se **model a HTML**, které
 * jde do render služby — samotný převod na PDF je věc Gotenbergu
 * (integrační test `FilingFilesServiceTest`).
 */
class FilingPdfServiceTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../../../../modules/economy/vat/config';

    public function testBothFilesAreProducedAndNamedAfterTheXml(): void
    {
        [$service, $engine] = $this->service();
        $files = $service->render(1, $this->input(), 'DPHDP3-12345678-2026-04');

        $this->assertSame(
            [FilingFilesService::KIND_PREVIEW, FilingFilesService::KIND_CONTENT],
            array_map(static fn ($file): string => $file->kind, $files),
        );
        $this->assertSame('DPHDP3-12345678-2026-04-opis.pdf', $files[0]->name);
        $this->assertSame('DPHDP3-12345678-2026-04-obsah.pdf', $files[1]->name);
        $this->assertSame('application/pdf', $files[0]->mimeType);
        $this->assertSame([], $service->warnings());
        $this->assertCount(2, $engine->html);
    }

    public function testPreviewShowsHeaderAndOnlyRowsWithValues(): void
    {
        [$service, $engine] = $this->service();
        $service->render(1, $this->input(), 'X');
        $preview = $engine->html[0];

        $this->assertStringContainsString('Přiznání k dani z přidané hodnoty', $preview);
        $this->assertStringContainsString('04/2026', $preview);
        $this->assertStringContainsString('Ukázka s.r.o.', $preview);
        $this->assertStringContainsString('CZ12345678', $preview);
        $this->assertStringContainsString('I. Zdanitelná plnění', $preview);
        $this->assertStringContainsString('VI. Výpočet daňové povinnosti', $preview);
        $this->assertStringNotContainsString('II. Ostatní plnění', $preview, 'prázdný oddíl se netiskne');
    }

    /**
     * Opis nesmí ukázat hodnotu, kterou podání nenese: ř. 62 má v XML jen
     * daň, přestože snapshot u součtu drží i základ.
     */
    public function testPreviewHidesColumnsTheFilingDoesNotCarry(): void
    {
        [$service, $engine] = $this->service();
        $service->render(1, $this->input(), 'X');

        // Základ 22 200 nese jen ř. 1; kdyby se tiskl i u součtového
        // ř. 62 (snapshot ho tam má), byl by v opisu dvakrát.
        $this->assertSame(1, substr_count($engine->html[0], self::money('22 200,00')));
        $this->assertStringContainsString(self::money('4 662,00'), $engine->html[0]);
    }

    public function testContentListsDocumentsPerRowWithTotals(): void
    {
        [$service, $engine] = $this->service();
        $service->render(1, $this->input(), 'X');
        $content = $engine->html[1];

        $this->assertStringContainsString('Obsah podání', $content);
        $this->assertStringContainsString('Řádek 1', $content);
        $this->assertStringContainsString('FV-9001', $content);
        $this->assertStringContainsString('FV-9002', $content);
        $this->assertStringContainsString('Celkem (2)', $content);
        $this->assertStringContainsString(self::money('22 200,00'), $content, 'součet skupiny');
    }

    public function testHtmlIsEscaped(): void
    {
        [$service, $engine] = $this->service();
        $header = ['typ_ds' => 'P', 'dic' => '12345678', 'zkrobchjm' => 'Ukázka <s.r.o.> & spol.'];
        $service->render(1, $this->input(['header' => $header]), 'X');

        $this->assertStringContainsString('Ukázka &lt;s.r.o.&gt; &amp; spol.', $engine->html[0]);
        $this->assertStringNotContainsString('<s.r.o.>', $engine->html[0]);
    }

    // ── Degradace ───────────────────────────────────────────────────────────

    public function testUnconfiguredServiceProducesNothingButWarns(): void
    {
        $service = new FilingPdfService($this->db(), new RenderClient(null), $this->config());

        $this->assertSame([], $service->render(1, $this->input(), 'X'));
        $this->assertCount(1, $service->warnings());
        $this->assertStringContainsString('není nakonfigurovaná', $service->warnings()[0]);
    }

    public function testFailedRenderIsAWarningNotAnException(): void
    {
        [$service] = $this->service(RenderResult::failure(RenderErrorKind::Timeout, 'too slow'));

        $this->assertSame([], $service->render(1, $this->input(), 'X'));
        $this->assertCount(2, $service->warnings(), 'opis i obsah');
        $this->assertStringContainsString('timeout', $service->warnings()[0]);
    }

    public function testWarningsResetBetweenRuns(): void
    {
        [$service] = $this->service();
        $service->render(1, $this->input(), 'X');
        $service->render(1, $this->input(), 'X');

        $this->assertSame([], $service->warnings());
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** Částky se sázejí s pevnou mezerou, ať se číslo nezalomí. */
    private static function money(string $formatted): string
    {
        return str_replace(' ', "\u{00A0}", $formatted);
    }

    /** @return array{0: FilingPdfService, 1: CapturingRenderEngine} */
    private function service(?RenderResult $result = null): array
    {
        $engine = new CapturingRenderEngine($result ?? RenderResult::success('%PDF-1.4 fake'));
        $client = new RenderClient(new RenderConfig('http://render.invalid', 30), $engine);

        return [new FilingPdfService($this->db(), $client, $this->config()), $engine];
    }

    /** Dokladová úroveň snapshotu — jediné, co si renderer čte z DB. */
    private function db(): \Dibi\Connection
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturn([
            new \Dibi\Row([
                'doc_number' => 'FV-9001', 'partner_vat_id' => 'CZ12345678',
                'vat_duzp' => '2026-04-10', 'vat_dppd' => '2026-04-10', 'vat_code' => 'cz-120',
                'base_dom' => 18000.0, 'tax_dom' => 3780.0,
                'dp3_row' => 1, 'kh_section' => 'A4', 'sh_kod' => null,
            ]),
            new \Dibi\Row([
                'doc_number' => 'FV-9002', 'partner_vat_id' => 'CZ87654321',
                'vat_duzp' => '2026-04-12', 'vat_dppd' => '2026-04-12', 'vat_code' => 'cz-120',
                'base_dom' => 4200.0, 'tax_dom' => 882.0,
                'dp3_row' => 1, 'kh_section' => 'A5', 'sh_kod' => null,
            ]),
        ]);
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $reports = JsoncParser::parseFile(self::CONFIG . '/vat-reports-cz.jsonc');
        $xml     = JsoncParser::parseFile(self::CONFIG . '/vat-xml-cz.jsonc');
        $kinds   = JsoncParser::parseFile(self::CONFIG . '/filingKinds.jsonc');

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'economy.vat.reports.cz'  => $reports,
                'economy.vat.xml.cz'      => $xml,
                'economy.vat.filingKinds' => $kinds,
                default                   => null,
            },
        );
        return $config;
    }

    /** @param array<string, mixed> $override */
    private function input(array $override = []): FilingXmlInput
    {
        return new FilingXmlInput(
            reportType: 'return',
            filingKind: 'regular',
            previousKind: null,
            header: $override['header'] ?? [
                'typ_ds' => 'P', 'dic' => '12345678', 'zkrobchjm' => 'Ukázka s.r.o.',
                'ulice' => 'Dlouhá', 'c_pop' => '12', 'psc' => '76001', 'naz_obce' => 'Ukázkov',
            ],
            period: FilingPeriod::fromRange('2026-04-01', '2026-04-30'),
            dateIssue: '2026-05-02',
            dateFiled: '2026-05-04',
            returnRows: [
                1  => ['base' => 22200.0, 'full' => 4662.0, 'reduced' => 0.0],
                46 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
                62 => ['base' => 22200.0, 'full' => 4662.0, 'reduced' => 0.0],
                63 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
                64 => ['base' => 0.0, 'full' => 4662.0, 'reduced' => 0.0],
            ],
            coefficients: ['coefficient' => 1.0],
        );
    }
}
