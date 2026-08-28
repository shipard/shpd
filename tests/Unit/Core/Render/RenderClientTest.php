<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Render;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderProfile;
use Shipard\Core\Render\RenderResult;

/**
 * Engine nahrazuje in-memory implementace kontraktu — žádné reálné HTTP
 * (vzor AnthropicLlmClientTest).
 */
class RecordingRenderEngine implements RenderEngineInterface
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct(private readonly RenderResult $result)
    {
    }

    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult
    {
        $this->calls[] = ['renderHtml', $html, $assets, $options, $timeoutSec];
        return $this->result;
    }

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult
    {
        $this->calls[] = ['convertOffice', $fileName, $content, $timeoutSec];
        return $this->result;
    }

    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult
    {
        $this->calls[] = ['embedFiles', $pdfContent, $attachments, $timeoutSec];
        return $this->result;
    }

    public function health(): bool
    {
        $this->calls[] = ['health'];
        return true;
    }
}

class RenderClientTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        RenderClient::resetWarningForTesting();
        ErrorLogger::resetForTesting();
        $this->logPath = sys_get_temp_dir() . '/shpd-render-test-' . bin2hex(random_bytes(6)) . '.log';
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        RenderClient::resetWarningForTesting();
        @unlink($this->logPath);
    }

    private function config(int $timeoutSec = 30): RenderConfig
    {
        return new RenderConfig('http://127.0.0.1:3000', $timeoutSec);
    }

    public function testUnconfiguredReturnsUnconfiguredWithoutEngineCall(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient(null, $engine);

        $result = $client->renderHtml('<p>x</p>', [], RenderProfile::Untrusted);

        $this->assertFalse($result->ok);
        $this->assertSame(RenderErrorKind::Unconfigured, $result->errorKind);
        $this->assertSame([], $engine->calls);
        $this->assertFalse($client->isConfigured());
    }

    public function testRenderHtmlSuccess(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-content'));
        $client = new RenderClient($this->config(), $engine);

        $result = $client->renderHtml('<p>x</p>', ['logo.png' => 'png-bytes'], RenderProfile::Report);

        $this->assertTrue($result->ok);
        $this->assertSame('%PDF-content', $result->pdfContent);
        $this->assertTrue($client->isConfigured());
        $this->assertCount(1, $engine->calls);
        $this->assertSame(['logo.png' => 'png-bytes'], $engine->calls[0][2]);
    }

    public function testUntrustedRejectsHeaderFooter(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(), $engine);

        $result = $client->renderHtml(
            '<p>x</p>',
            [],
            RenderProfile::Untrusted,
            new PdfOptions(footerTemplate: '<html><body>f</body></html>'),
        );

        $this->assertFalse($result->ok);
        $this->assertSame(RenderErrorKind::InvalidInput, $result->errorKind);
        $this->assertSame([], $engine->calls);
    }

    public function testUntrustedRejectsPrintBackground(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(), $engine);

        $result = $client->renderHtml(
            '<p>x</p>',
            [],
            RenderProfile::Untrusted,
            new PdfOptions(printBackground: true),
        );

        $this->assertFalse($result->ok);
        $this->assertSame(RenderErrorKind::InvalidInput, $result->errorKind);
        $this->assertSame([], $engine->calls);
    }

    public function testProfileDefaultsAppliedBeforeEngineCall(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(60), $engine);

        $client->renderHtml('<p>x</p>', [], RenderProfile::Report);

        /** @var PdfOptions $options */
        $options = $engine->calls[0][3];
        $this->assertSame('1.6cm', $options->marginTop);
        $this->assertSame('1.6cm', $options->marginLeft);
        $this->assertSame(60, $engine->calls[0][4]);
    }

    public function testUntrustedTimeoutIsCapped(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(120), $engine);

        $client->renderHtml('<p>x</p>', [], RenderProfile::Untrusted);

        /** @var PdfOptions $options */
        $options = $engine->calls[0][3];
        $this->assertSame(30, $engine->calls[0][4]);
        $this->assertSame('1cm', $options->marginTop);
    }

    public function testExplicitMarginsSurviveDefaults(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(), $engine);

        $client->renderHtml('<p>x</p>', [], RenderProfile::Report, new PdfOptions(marginTop: '3cm'));

        /** @var PdfOptions $options */
        $options = $engine->calls[0][3];
        $this->assertSame('3cm', $options->marginTop);
        $this->assertSame('1.6cm', $options->marginBottom);
    }

    public function testConvertOfficeRejectsEmptyInput(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(), $engine);

        $result = $client->convertOffice('', 'obsah');

        $this->assertSame(RenderErrorKind::InvalidInput, $result->errorKind);
        $this->assertSame([], $engine->calls);
    }

    public function testConvertOfficeUnconfigured(): void
    {
        $client = new RenderClient(null);

        $result = $client->convertOffice('sample.docx', 'obsah');

        $this->assertSame(RenderErrorKind::Unconfigured, $result->errorKind);
    }

    public function testConvertOfficeSuccess(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(45), $engine);

        $result = $client->convertOffice('sample.docx', 'obsah');

        $this->assertTrue($result->ok);
        $this->assertSame(['convertOffice', 'sample.docx', 'obsah', 45], $engine->calls[0]);
    }

    public function testHealthUnconfiguredIsFalse(): void
    {
        $client = new RenderClient(null);

        $this->assertFalse($client->health());
    }

    public function testHealthDelegatesToEngine(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(), $engine);

        $this->assertTrue($client->health());
        $this->assertSame([['health']], $engine->calls);
    }

    public function testWarningLoggedOnceThenDebug(): void
    {
        $engine = new RecordingRenderEngine(
            RenderResult::failure(RenderErrorKind::Unreachable, 'connection refused'),
        );
        $client = new RenderClient($this->config(), $engine);

        $client->renderHtml('<p>x</p>', [], RenderProfile::Untrusted);
        $client->renderHtml('<p>x</p>', [], RenderProfile::Untrusted);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logPath))));
        $renderLines = array_values(array_filter(
            array_map(fn (string $line) => json_decode($line, true), $lines),
            fn (?array $entry) => $entry !== null && str_starts_with((string) $entry['msg'], 'render:'),
        ));

        $levels = array_column($renderLines, 'level');
        $this->assertSame(['warn', 'debug'], $levels);
    }

    public function testInvalidInputDoesNotLogWarning(): void
    {
        $engine = new RecordingRenderEngine(RenderResult::success('%PDF-x'));
        $client = new RenderClient($this->config(), $engine);

        $client->renderHtml('<p>x</p>', [], RenderProfile::Untrusted, new PdfOptions(printBackground: true));

        $this->assertFalse(file_exists($this->logPath) && str_contains((string) file_get_contents($this->logPath), '"level":"warn"'));
    }
}
