<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Render\Engine;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Render\Engine\GotenbergEngine;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderErrorKind;

/**
 * HTTP transport nahrazuje subclass přes seam performPost/performGet —
 * testy ověřují sestavení multipart těla a mapování chyb bez reálného
 * HTTP (vzor PersonsRegistryClient / AnthropicLlmClient).
 */
class CapturingGotenbergEngine extends GotenbergEngine
{
    /** @var list<array{url: string, contentType: string, body: string, timeoutSec: int}> */
    public array $posts = [];

    /** @var list<array{url: string, timeoutSec: int}> */
    public array $gets = [];

    /** @var array{statusCode: int, body: string, errno: int, error: ?string} */
    public array $response = ['statusCode' => 200, 'body' => '%PDF-1.7 fake', 'errno' => 0, 'error' => null];

    protected function performPost(string $url, string $contentType, string $body, int $timeoutSec): array
    {
        $this->posts[] = ['url' => $url, 'contentType' => $contentType, 'body' => $body, 'timeoutSec' => $timeoutSec];
        return $this->response;
    }

    protected function performGet(string $url, int $timeoutSec): array
    {
        $this->gets[] = ['url' => $url, 'timeoutSec' => $timeoutSec];
        return $this->response;
    }
}

class GotenbergEngineTest extends TestCase
{
    private function engine(): CapturingGotenbergEngine
    {
        return new CapturingGotenbergEngine('http://127.0.0.1:3000');
    }

    public function testRenderHtmlBuildsMultipartBody(): void
    {
        $engine = $this->engine();

        $result = $engine->renderHtml(
            '<p>ahoj</p>',
            ['logo.png' => 'png-bytes'],
            new PdfOptions(marginTop: '1cm', marginBottom: '1cm', marginLeft: '1cm', marginRight: '1cm'),
            30,
        );

        $this->assertTrue($result->ok);
        $this->assertSame('%PDF-1.7 fake', $result->pdfContent);

        $post = $engine->posts[0];
        $this->assertSame('http://127.0.0.1:3000/forms/chromium/convert/html', $post['url']);
        $this->assertSame(30, $post['timeoutSec']);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $post['contentType']);

        $body = $post['body'];
        $this->assertStringContainsString('name="files"; filename="index.html"', $body);
        $this->assertStringContainsString('<p>ahoj</p>', $body);
        $this->assertStringContainsString('name="files"; filename="logo.png"', $body);
        $this->assertStringContainsString('png-bytes', $body);
        // A4 → rozměry, ne jméno formátu (Gotenberg jmenované formáty nezná)
        $this->assertStringContainsString("name=\"paperWidth\"\r\n\r\n21cm", $body);
        $this->assertStringContainsString("name=\"paperHeight\"\r\n\r\n29.7cm", $body);
        $this->assertStringContainsString("name=\"landscape\"\r\n\r\nfalse", $body);
        $this->assertStringContainsString("name=\"printBackground\"\r\n\r\nfalse", $body);
        $this->assertStringContainsString("name=\"marginTop\"\r\n\r\n1cm", $body);
        $this->assertStringNotContainsString('header.html', $body);
        $this->assertStringNotContainsString('footer.html', $body);
    }

    public function testRenderHtmlWithHeaderFooterAndLandscape(): void
    {
        $engine = $this->engine();

        $engine->renderHtml(
            '<p>x</p>',
            [],
            new PdfOptions(
                orientation: 'landscape',
                headerTemplate: '<html><body>H</body></html>',
                footerTemplate: '<html><body>F</body></html>',
                printBackground: true,
            ),
            30,
        );

        $body = $engine->posts[0]['body'];
        $this->assertStringContainsString('name="files"; filename="header.html"', $body);
        $this->assertStringContainsString('name="files"; filename="footer.html"', $body);
        $this->assertStringContainsString("name=\"landscape\"\r\n\r\ntrue", $body);
        $this->assertStringContainsString("name=\"printBackground\"\r\n\r\ntrue", $body);
    }

    public function testAssetFilenameIsSanitized(): void
    {
        $engine = $this->engine();

        $engine->renderHtml('<p>x</p>', ['../dir/ev"il.png' => 'data'], new PdfOptions(), 30);

        $body = $engine->posts[0]['body'];
        $this->assertStringContainsString('filename="evil.png"', $body);
        $this->assertStringNotContainsString('../', $body);
    }

    public function testConvertOfficeRoute(): void
    {
        $engine = $this->engine();

        $result = $engine->convertOffice('smlouva.docx', 'docx-bytes', 45);

        $this->assertTrue($result->ok);
        $post = $engine->posts[0];
        $this->assertSame('http://127.0.0.1:3000/forms/libreoffice/convert', $post['url']);
        $this->assertSame(45, $post['timeoutSec']);
        $this->assertStringContainsString('name="files"; filename="smlouva.docx"', $post['body']);
    }

    public function testEmbedFilesRoute(): void
    {
        $engine = $this->engine();

        $result = $engine->embedFiles(
            '%PDF-main',
            [['fileName' => 'invoice.isdoc', 'content' => '<isdoc/>']],
            30,
        );

        $this->assertTrue($result->ok);
        $post = $engine->posts[0];
        $this->assertSame('http://127.0.0.1:3000/forms/pdfengines/embed', $post['url']);
        $this->assertStringContainsString('name="files"; filename="document.pdf"', $post['body']);
        $this->assertStringContainsString('name="embeds"; filename="invoice.isdoc"', $post['body']);
        $this->assertStringContainsString('<isdoc/>', $post['body']);
    }

    public function testTimeoutMapsToTimeout(): void
    {
        $engine = $this->engine();
        $engine->response = ['statusCode' => 0, 'body' => '', 'errno' => CURLE_OPERATION_TIMEDOUT, 'error' => 'timed out'];

        $result = $engine->renderHtml('<p>x</p>', [], new PdfOptions(), 5);

        $this->assertSame(RenderErrorKind::Timeout, $result->errorKind);
    }

    public function testConnectionErrorMapsToUnreachable(): void
    {
        $engine = $this->engine();
        $engine->response = ['statusCode' => 0, 'body' => '', 'errno' => CURLE_COULDNT_CONNECT, 'error' => 'refused'];

        $result = $engine->renderHtml('<p>x</p>', [], new PdfOptions(), 5);

        $this->assertSame(RenderErrorKind::Unreachable, $result->errorKind);
        $this->assertStringContainsString('refused', (string) $result->note);
    }

    public function testHttpErrorMapsToEngineError(): void
    {
        $engine = $this->engine();
        $engine->response = ['statusCode' => 400, 'body' => 'Invalid form field', 'errno' => 0, 'error' => null];

        $result = $engine->renderHtml('<p>x</p>', [], new PdfOptions(), 5);

        $this->assertSame(RenderErrorKind::EngineError, $result->errorKind);
        $this->assertStringContainsString('HTTP 400', (string) $result->note);
        $this->assertStringContainsString('Invalid form field', (string) $result->note);
    }

    public function testNonPdfBodyMapsToEngineError(): void
    {
        $engine = $this->engine();
        $engine->response = ['statusCode' => 200, 'body' => '<html>oops</html>', 'errno' => 0, 'error' => null];

        $result = $engine->renderHtml('<p>x</p>', [], new PdfOptions(), 5);

        $this->assertSame(RenderErrorKind::EngineError, $result->errorKind);
    }

    public function testHealthOkAndFail(): void
    {
        $engine = $this->engine();
        $this->assertTrue($engine->health());
        $this->assertSame('http://127.0.0.1:3000/health', $engine->gets[0]['url']);

        $engine->response = ['statusCode' => 0, 'body' => '', 'errno' => CURLE_COULDNT_CONNECT, 'error' => 'refused'];
        $this->assertFalse($engine->health());
    }
}
