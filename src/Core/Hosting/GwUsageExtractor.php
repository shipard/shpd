<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting;

/**
 * Metering tee AI gateway (D5) — čistá třída bez I/O. Controller jí
 * paralelně krmí chunky odpovědi Anthropicu, které zároveň přeposílá
 * klientovi; finish() vrátí vytěžené usage pro INSERT do
 * hosting_core_ai_usage.
 *
 * SSE mód (content-type text/event-stream): line-buffered parser (vzor
 * AnthropicLlmClient::feedSse) čte jen `message_start` (model, input +
 * cache pole) a `message_delta` (output). Non-SSE mód: buffer celého JSON
 * body s limitem 10 MB (nad limit → nuly).
 *
 * Kontrakt: nikdy nehodí výjimku na garbage vstup; finish() bez begin()
 * (transport failure před hlavičkami) vrací nuly. Chybové odpovědi →
 * nuly, http_status loguje controller.
 */
final class GwUsageExtractor
{
    private const int JSON_BUFFER_LIMIT = 10 * 1024 * 1024;

    private bool $begun = false;
    private bool $sse = false;
    private bool $truncated = false;

    private string $buf = '';
    private ?string $model = null;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheCreationTokens = 0;
    private int $cacheReadTokens = 0;

    /** Volá se jednou, jakmile jsou známé hlavičky upstream odpovědi. */
    public function begin(string $contentType): void
    {
        $this->begun = true;
        $this->sse = str_contains(strtolower($contentType), 'text/event-stream');
    }

    public function feed(string $chunk): void
    {
        if (!$this->begun || $chunk === '') {
            return;
        }

        if (!$this->sse) {
            if (strlen($this->buf) + strlen($chunk) > self::JSON_BUFFER_LIMIT) {
                $this->truncated = true;
                return;
            }
            $this->buf .= $chunk;
            return;
        }

        $this->buf .= $chunk;
        while (($nl = strpos($this->buf, "\n")) !== false) {
            $line = rtrim(substr($this->buf, 0, $nl), "\r");
            $this->buf = substr($this->buf, $nl + 1);

            if (!str_starts_with($line, 'data:')) {
                continue; // `event:` lines, blank separators, comments — ignored
            }
            $payload = trim(substr($line, 5));
            if ($payload === '') {
                continue;
            }
            $data = json_decode($payload, true);
            if (is_array($data)) {
                $this->dispatchEvent($data);
            }
        }
    }

    /**
     * @return array{stream: bool, model: ?string, input_tokens: int,
     *               output_tokens: int, cache_creation_tokens: int,
     *               cache_read_tokens: int}
     */
    public function finish(): array
    {
        if ($this->begun && !$this->sse && !$this->truncated && $this->buf !== '') {
            $data = json_decode($this->buf, true);
            if (is_array($data)) {
                $usage = $data['usage'] ?? null;
                if (is_array($usage)) {
                    $this->model = isset($data['model']) ? (string) $data['model'] : null;
                    $this->inputTokens = (int) ($usage['input_tokens'] ?? 0);
                    $this->outputTokens = (int) ($usage['output_tokens'] ?? 0);
                    $this->cacheCreationTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);
                    $this->cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);
                }
            }
        }

        return [
            'stream'                => $this->sse,
            'model'                 => $this->model,
            'input_tokens'          => $this->inputTokens,
            'output_tokens'         => $this->outputTokens,
            'cache_creation_tokens' => $this->cacheCreationTokens,
            'cache_read_tokens'     => $this->cacheReadTokens,
        ];
    }

    /** @param array<string, mixed> $data */
    private function dispatchEvent(array $data): void
    {
        switch ($data['type'] ?? '') {
            case 'message_start':
                $message = $data['message'] ?? null;
                if (!is_array($message)) {
                    break;
                }
                if (isset($message['model'])) {
                    $this->model = (string) $message['model'];
                }
                $usage = $message['usage'] ?? null;
                if (is_array($usage)) {
                    $this->inputTokens = (int) ($usage['input_tokens'] ?? 0);
                    $this->cacheCreationTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);
                    $this->cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);
                }
                break;

            case 'message_delta':
                if (isset($data['usage']['output_tokens'])) {
                    $this->outputTokens = (int) $data['usage']['output_tokens'];
                }
                break;
        }
    }
}
