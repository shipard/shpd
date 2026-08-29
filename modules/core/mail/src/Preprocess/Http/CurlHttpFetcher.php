<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess\Http;

/**
 * curl implementace HttpFetcher: jen http/https, žádné redirecty,
 * CURLOPT_RESOLVE pinuje ověřenou IP, write callback přeruší přenos nad
 * size cap (návrat jiné délky než chunk = CURLE_WRITE_ERROR).
 */
final class CurlHttpFetcher implements HttpFetcher
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const USER_AGENT = 'Shipard-Preprocess/1.0 (+https://shipard.cz)';

    public function get(string $url, string $pinnedIp, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $pin = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;

        $headers = [];
        $body = '';
        $truncated = false;

        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, error: 'curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"],
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $truncated = true;
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false && !$truncated ? (curl_error($ch) ?: 'unknown curl error') : null;
        curl_close($ch);

        if ($error !== null && $status === 0) {
            return new HttpResponse(0, $headers, '', $error);
        }

        return new HttpResponse($status, $headers, $truncated ? '' : $body, $error, $truncated);
    }
}
