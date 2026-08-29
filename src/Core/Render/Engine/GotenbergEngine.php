<?php

declare(strict_types=1);

namespace Shipard\Core\Render\Engine;

use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;

/**
 * Adaptér na Gotenberg (D2) — jediná třída v kódu, která zná jeho API.
 *
 * Multipart POST na `forms/chromium/convert/html` (index.html + assety
 * + volitelné header.html/footer.html), `forms/libreoffice/convert`
 * a `forms/pdfengines/embed`. Tělo se staví ručně (opakovaná form pole
 * `files`/`embeds` s CURLOPT_POSTFIELDS polem nejdou), HTTP přes curl
 * v protected seamu performPost/performGet — testy subclassují bez
 * reálného HTTP (vzor PersonsRegistryClient::performHttpGet).
 *
 * Gotenberg nezná pojmenované formáty papíru — paperFormat se mapuje
 * na paperWidth/paperHeight (portrait rozměry + flag landscape).
 */
class GotenbergEngine implements RenderEngineInterface
{
    /** paperFormat => [paperWidth, paperHeight] (portrait). */
    private const PAPER_DIMENSIONS = [
        'A3'     => ['29.7cm', '42cm'],
        'A4'     => ['21cm', '29.7cm'],
        'A5'     => ['14.8cm', '21cm'],
        'Letter' => ['8.5in', '11in'],
        'Legal'  => ['8.5in', '14in'],
    ];

    private const HEALTH_TIMEOUT_SEC = 3;
    private const NOTE_BODY_LIMIT = 300;
    private const USER_AGENT = 'Shipard/render-client';

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult
    {
        $parts = [
            $this->filePart('files', 'index.html', $html, 'text/html'),
        ];
        foreach ($assets as $filename => $content) {
            $parts[] = $this->filePart('files', (string) $filename, $content, 'application/octet-stream');
        }
        if ($options->headerTemplate !== null) {
            $parts[] = $this->filePart('files', 'header.html', $options->headerTemplate, 'text/html');
        }
        if ($options->footerTemplate !== null) {
            $parts[] = $this->filePart('files', 'footer.html', $options->footerTemplate, 'text/html');
        }

        [$paperWidth, $paperHeight] = self::PAPER_DIMENSIONS[$options->paperFormat];
        $fields = [
            'paperWidth'      => $paperWidth,
            'paperHeight'     => $paperHeight,
            'landscape'       => $options->orientation === 'landscape' ? 'true' : 'false',
            'printBackground' => $options->printBackground ? 'true' : 'false',
        ];
        foreach (
            [
                'marginTop'    => $options->marginTop,
                'marginBottom' => $options->marginBottom,
                'marginLeft'   => $options->marginLeft,
                'marginRight'  => $options->marginRight,
            ] as $field => $value
        ) {
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }
        foreach ($fields as $name => $value) {
            $parts[] = $this->fieldPart($name, $value);
        }

        return $this->postMultipart('/forms/chromium/convert/html', $parts, $timeoutSec);
    }

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult
    {
        $parts = [
            $this->filePart('files', $fileName, $content, 'application/octet-stream'),
        ];

        return $this->postMultipart('/forms/libreoffice/convert', $parts, $timeoutSec);
    }

    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult
    {
        $parts = [
            $this->filePart('files', 'document.pdf', $pdfContent, 'application/pdf'),
        ];
        foreach ($attachments as $attachment) {
            $parts[] = $this->filePart(
                'embeds',
                $attachment['fileName'],
                $attachment['content'],
                'application/octet-stream',
            );
        }

        return $this->postMultipart('/forms/pdfengines/embed', $parts, $timeoutSec);
    }

    public function health(): bool
    {
        $response = $this->performGet($this->baseUrl . '/health', self::HEALTH_TIMEOUT_SEC);

        return $response['errno'] === 0 && $response['statusCode'] === 200;
    }

    // ── sestavení multipart těla ─────────────────────────────────────────

    /** @return array{headers: string, content: string} */
    private function filePart(string $field, string $filename, string $content, string $contentType): array
    {
        $safe = $this->safeFilename($filename);
        return [
            'headers' => "Content-Disposition: form-data; name=\"{$field}\"; filename=\"{$safe}\"\r\n"
                . "Content-Type: {$contentType}",
            'content' => $content,
        ];
    }

    /** @return array{headers: string, content: string} */
    private function fieldPart(string $name, string $value): array
    {
        return [
            'headers' => "Content-Disposition: form-data; name=\"{$name}\"",
            'content' => $value,
        ];
    }

    /**
     * Assety se referencují relativně z index.html — Gotenberg je ukládá
     * do jednoho plochého adresáře, cesty a CR/LF/uvozovky v názvu proto
     * nemají co dělat v Content-Disposition.
     */
    private function safeFilename(string $filename): string
    {
        return str_replace(["\r", "\n", '"', '\\'], '', basename($filename));
    }

    /** @param list<array{headers: string, content: string}> $parts */
    private function postMultipart(string $route, array $parts, int $timeoutSec): RenderResult
    {
        $boundary = '----shpd-render-' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n" . $part['headers'] . "\r\n\r\n" . $part['content'] . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $response = $this->performPost(
            $this->baseUrl . $route,
            "multipart/form-data; boundary={$boundary}",
            $body,
            $timeoutSec,
        );

        return $this->mapResponse($response, $route);
    }

    /** @param array{statusCode: int, body: string, errno: int, error: ?string} $response */
    private function mapResponse(array $response, string $route): RenderResult
    {
        if ($response['errno'] === CURLE_OPERATION_TIMEDOUT) {
            return RenderResult::failure(RenderErrorKind::Timeout, "render timed out ({$route})");
        }
        if ($response['errno'] !== 0 || $response['statusCode'] === 0) {
            $detail = $response['error'] ?? 'no HTTP response';
            return RenderResult::failure(RenderErrorKind::Unreachable, "render service unreachable: {$detail}");
        }
        if ($response['statusCode'] >= 400) {
            $note = trim(substr($response['body'], 0, self::NOTE_BODY_LIMIT));
            return RenderResult::failure(
                RenderErrorKind::EngineError,
                "HTTP {$response['statusCode']} ({$route}): {$note}",
            );
        }
        if (!str_starts_with($response['body'], '%PDF')) {
            return RenderResult::failure(
                RenderErrorKind::EngineError,
                "unexpected non-PDF response ({$route})",
            );
        }

        return RenderResult::success($response['body']);
    }

    // ── HTTP transport (seam pro testy) ──────────────────────────────────

    /** @return array{statusCode: int, body: string, errno: int, error: ?string} */
    protected function performPost(string $url, string $contentType, string $body, int $timeoutSec): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSec, 5),
            CURLOPT_HTTPHEADER     => ['Content-Type: ' . $contentType],
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'statusCode' => $statusCode,
            'body'       => $responseBody === false ? '' : (string) $responseBody,
            'errno'      => $errno,
            'error'      => $error,
        ];
    }

    /** @return array{statusCode: int, body: string, errno: int, error: ?string} */
    protected function performGet(string $url, int $timeoutSec): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'statusCode' => $statusCode,
            'body'       => $responseBody === false ? '' : (string) $responseBody,
            'errno'      => $errno,
            'error'      => $error,
        ];
    }
}
