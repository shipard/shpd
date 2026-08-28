<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Render\PostProcess;

use Shipard\Core\Render\PostProcess\AppendPdfsStep;

class MissingBinaryAppendPdfsStep extends AppendPdfsStep
{
    protected string $pdfuniteBin = 'shpd-nonexistent-pdfunite';
}

class AppendPdfsStepTest extends PostProcessTestCase
{
    public function testAppendsPdfs(): void
    {
        if (!$this->binaryAvailable('pdfunite') || !$this->binaryAvailable('pdfinfo')) {
            $this->markTestSkipped('pdfunite/pdfinfo not available');
        }

        $step = new AppendPdfsStep();
        $base = $this->createMinimalPdf();

        $result = $step->apply($base, ['pdfs' => [$this->createMinimalPdf(), $this->createMinimalPdf()]]);

        $this->assertStringStartsWith('%PDF', $result);
        $this->assertSame(3, $this->pageCount($result));
    }

    public function testMissingParamsThrows(): void
    {
        $step = new AppendPdfsStep();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pdfs/');

        $step->apply($this->createMinimalPdf(), []);
    }

    public function testNonPdfEntryThrows(): void
    {
        $step = new AppendPdfsStep();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pdfs\[0\]/');

        $step->apply($this->createMinimalPdf(), ['pdfs' => ['not a pdf']]);
    }

    public function testMissingBinaryThrowsWithInstallHint(): void
    {
        $step = new MissingBinaryAppendPdfsStep();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not installed/');

        $step->apply($this->createMinimalPdf(), ['pdfs' => [$this->createMinimalPdf()]]);
    }
}
