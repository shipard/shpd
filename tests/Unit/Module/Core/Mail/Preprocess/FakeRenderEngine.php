<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderResult;

/**
 * In-memory engine pro testy akcí předzpracování: vrací předem daný
 * výsledek a zaznamenává volání (HTML, assety, options, timeout).
 * Sdílí RenderBodyToPdfActionTest a FetchLinkedDocumentActionTest.
 */
final class FakeRenderEngine implements RenderEngineInterface
{
    /** @var list<array{html: string, assets: array<string, string>, options: PdfOptions, timeoutSec: int}> */
    public array $renders = [];

    public function __construct(private readonly RenderResult $result)
    {
    }

    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult
    {
        $this->renders[] = ['html' => $html, 'assets' => $assets, 'options' => $options, 'timeoutSec' => $timeoutSec];
        return $this->result;
    }

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult
    {
        throw new \LogicException('convertOffice is not expected in preprocess tests');
    }

    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult
    {
        throw new \LogicException('embedFiles is not expected in preprocess tests');
    }

    public function health(): bool
    {
        return true;
    }
}
