<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderProfile;

/**
 * Integrační test proti živému Gotenbergu — mimo běžný CI běh, gated
 * env proměnnou (vzor SHIPARD_INTEGRATION_DS_PATH):
 *
 *   SHIPARD_INTEGRATION_GOTENBERG_URL=http://127.0.0.1:3000 \
 *     vendor/bin/phpunit --testsuite Integration --filter Gotenberg
 */
class GotenbergIntegrationTest extends TestCase
{
    private RenderClient $client;

    protected function setUp(): void
    {
        $url = getenv('SHIPARD_INTEGRATION_GOTENBERG_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set SHIPARD_INTEGRATION_GOTENBERG_URL to a running Gotenberg instance to run this test.',
            );
        }
        RenderClient::resetWarningForTesting();
        $this->client = new RenderClient(RenderConfig::fromArray(['url' => $url]));
    }

    public function testHealth(): void
    {
        $this->assertTrue($this->client->health());
    }

    public function testRenderHtmlUntrusted(): void
    {
        $result = $this->client->renderHtml(
            '<html><body><h1>Čeština: příliš žluťoučký kůň</h1></body></html>',
            [],
            RenderProfile::Untrusted,
        );

        $this->assertTrue($result->ok, (string) $result->note);
        $this->assertStringStartsWith('%PDF', (string) $result->pdfContent);
        $this->assertSame(1, $this->pageCount((string) $result->pdfContent));
    }

    public function testRenderHtmlReportWithHeaderFooterIsMultiPage(): void
    {
        $body = str_repeat('<p style="page-break-after: always;">strana</p>', 3);
        $result = $this->client->renderHtml(
            "<html><body>{$body}</body></html>",
            [],
            RenderProfile::Report,
            new PdfOptions(
                headerTemplate: '<html><body><div style="font-size:8px;">Hlavička</div></body></html>',
                footerTemplate: '<html><body><div style="font-size:8px;">'
                    . 'Strana <span class="pageNumber"></span>/<span class="totalPages"></span>'
                    . '</div></body></html>',
            ),
        );

        $this->assertTrue($result->ok, (string) $result->note);
        $this->assertGreaterThanOrEqual(3, $this->pageCount((string) $result->pdfContent));
    }

    public function testPostProcessEmbedAndAppend(): void
    {
        $base = $this->client->renderHtml('<html><body>doklad</body></html>', [], RenderProfile::Untrusted);
        $this->assertTrue($base->ok, (string) $base->note);
        $appendix = $this->client->renderHtml('<html><body>příloha</body></html>', [], RenderProfile::Untrusted);
        $this->assertTrue($appendix->ok, (string) $appendix->note);

        $result = $this->client->postProcess((string) $base->pdfContent, [
            ['step' => 'embedIsdoc', 'params' => ['fileName' => 'faktura.isdoc', 'content' => '<isdoc/>']],
            ['step' => 'appendPdfs', 'params' => ['pdfs' => [(string) $appendix->pdfContent]]],
        ]);

        $this->assertTrue($result->ok, (string) $result->note);
        $this->assertSame(2, $this->pageCount((string) $result->pdfContent));
        $this->assertStringContainsString('faktura.isdoc', $this->attachmentListing((string) $result->pdfContent));
    }

    private function pageCount(string $pdf): int
    {
        $output = $this->runPopplerTool('pdfinfo', $pdf);
        $this->assertMatchesRegularExpression('/Pages:\s+\d+/', $output);
        preg_match('/Pages:\s+(\d+)/', $output, $m);
        return (int) $m[1];
    }

    private function attachmentListing(string $pdf): string
    {
        return $this->runPopplerTool('pdfdetach -list', $pdf);
    }

    private function runPopplerTool(string $tool, string $pdf): string
    {
        $bin = explode(' ', $tool)[0];
        exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $probe, $probeCode);
        if ($probeCode !== 0) {
            $this->markTestSkipped("{$bin} not available");
        }

        $path = sys_get_temp_dir() . '/shpd-render-it-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, $pdf);
        try {
            exec($tool . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "{$tool} failed: " . implode("\n", $output));
            return implode("\n", $output);
        } finally {
            @unlink($path);
        }
    }
}
