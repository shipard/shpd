<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Render\PostProcess;

use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\PostProcess\EmbedIsdocStep;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;

class ScriptedEmbedEngine implements RenderEngineInterface
{
    /** @var list<array{pdf: string, attachments: array}> */
    public array $embedCalls = [];

    public function __construct(private readonly RenderResult $embedResult)
    {
    }

    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult
    {
        throw new \LogicException('not expected');
    }

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult
    {
        throw new \LogicException('not expected');
    }

    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult
    {
        $this->embedCalls[] = ['pdf' => $pdfContent, 'attachments' => $attachments];
        return $this->embedResult;
    }

    public function health(): bool
    {
        return true;
    }
}

class MissingBinaryEmbedIsdocStep extends EmbedIsdocStep
{
    protected string $pdfattachBin = 'shpd-nonexistent-pdfattach';
}

class EmbedIsdocStepTest extends PostProcessTestCase
{
    public function testEnginePathIsPreferred(): void
    {
        $engine = new ScriptedEmbedEngine(RenderResult::success('%PDF-from-engine'));
        $step = new EmbedIsdocStep($engine);

        $result = $step->apply($this->createMinimalPdf(), ['fileName' => 'invoice.isdoc', 'content' => '<isdoc/>']);

        $this->assertSame('%PDF-from-engine', $result);
        $this->assertCount(1, $engine->embedCalls);
        $this->assertSame('invoice.isdoc', $engine->embedCalls[0]['attachments'][0]['fileName']);
    }

    public function testEngineFailureFallsBackToPdfattach(): void
    {
        if (!$this->binaryAvailable('pdfattach') || !$this->binaryAvailable('pdfdetach')) {
            $this->markTestSkipped('pdfattach/pdfdetach not available');
        }

        $engine = new ScriptedEmbedEngine(RenderResult::failure(RenderErrorKind::Unreachable, 'down'));
        $step = new EmbedIsdocStep($engine);

        $result = $step->apply($this->createMinimalPdf(), ['fileName' => 'invoice.isdoc', 'content' => '<isdoc/>']);

        $this->assertAttachmentListed($result, 'invoice.isdoc');
    }

    public function testWithoutEngineUsesPdfattach(): void
    {
        if (!$this->binaryAvailable('pdfattach') || !$this->binaryAvailable('pdfdetach')) {
            $this->markTestSkipped('pdfattach/pdfdetach not available');
        }

        $step = new EmbedIsdocStep(null);

        $result = $step->apply($this->createMinimalPdf(), ['fileName' => 'faktura.isdoc', 'content' => '<isdoc/>']);

        $this->assertAttachmentListed($result, 'faktura.isdoc');
    }

    public function testFileNameIsSanitizedToBasename(): void
    {
        $engine = new ScriptedEmbedEngine(RenderResult::success('%PDF-from-engine'));
        $step = new EmbedIsdocStep($engine);

        $step->apply($this->createMinimalPdf(), ['fileName' => '../tmp/ev"il.isdoc', 'content' => '<isdoc/>']);

        $this->assertSame('evil.isdoc', $engine->embedCalls[0]['attachments'][0]['fileName']);
    }

    public function testMissingParamsThrows(): void
    {
        $step = new EmbedIsdocStep(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fileName/');

        $step->apply($this->createMinimalPdf(), ['content' => '<isdoc/>']);
    }

    public function testMissingBinaryThrowsWithInstallHint(): void
    {
        $step = new MissingBinaryEmbedIsdocStep(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not installed/');

        $step->apply($this->createMinimalPdf(), ['fileName' => 'x.isdoc', 'content' => '<isdoc/>']);
    }

    /** Ověří attachment přes `pdfdetach -list` (kritérium z PRD). */
    private function assertAttachmentListed(string $pdf, string $expectedName): void
    {
        $this->assertStringStartsWith('%PDF', $pdf);

        $path = sys_get_temp_dir() . '/shpd-render-test-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, $pdf);
        try {
            exec('pdfdetach -list ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode);
            $listing = implode("\n", $output);
            $this->assertStringContainsString('1 embedded files', $listing);
            $this->assertStringContainsString($expectedName, $listing);
        } finally {
            @unlink($path);
        }
    }
}
