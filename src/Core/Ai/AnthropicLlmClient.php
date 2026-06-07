<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;

/**
 * Anthropic Messages API client, streaming, no tool-use (Phase 2a).
 *
 * Raw HTTP (curl) on purpose — the project has no PHP Anthropic SDK dependency
 * (the analyzer side is Python), and the task specifies a thin streamed path.
 *
 * SSE parsing is split from the network transport: `sendStreamingRequest()` is
 * the only method that touches curl and is `protected` so tests can override it
 * and feed fixture chunks (including chunks split mid-line). Everything else is
 * pure and unit-tested without a network.
 */
class AnthropicLlmClient implements LlmClient
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_BASE_URL  = 'https://api.anthropic.com';

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        if ($params->provider !== 'anthropic') {
            throw new LlmUnsupportedProviderException($params->provider);
        }

        $body = [
            'model'      => $params->model,
            'max_tokens' => $params->maxTokens,
            'stream'     => true,
            'messages'   => $params->messages,
        ];
        if ($params->system !== null && $params->system !== '') {
            $body['system'] = $params->system;
        }
        // Omitted when null: Opus 4.7/4.8 reject `temperature` with HTTP 400.
        if ($params->temperature !== null) {
            $body['temperature'] = $params->temperature;
        }
        if ($params->tools !== null && $params->tools !== []) {
            $body['tools'] = $params->tools;
        }

        // Accumulator threaded through the SSE parser. `buf` holds the partial
        // line carried across chunk boundaries; `blocks` collects content blocks
        // keyed by their stream index (text + tool_use, finalized below);
        // `error` records an inline SSE error event (thrown after the stream
        // drains, not from the callback).
        $acc = [
            'buf'    => '',
            'text'   => '',
            'in'     => null,
            'out'    => null,
            'stop'   => null,
            'model'  => null,
            'error'  => null,
            'blocks' => [],
        ];

        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->sendStreamingRequest($params, $json, function (string $chunk) use (&$acc, $onTextDelta): void {
            $this->feedSse($chunk, $acc, $onTextDelta);
        });

        if ($acc['error'] !== null) {
            throw new LlmApiException(200, $acc['error']['type'], $acc['error']['message']);
        }

        [$contentBlocks, $toolUses] = $this->finalizeBlocks($acc['blocks']);

        return new LlmChatResult(
            $acc['text'],
            $acc['in'],
            $acc['out'],
            $acc['stop'],
            $acc['model'],
            $toolUses,
            $contentBlocks,
        );
    }

    /**
     * Turns the index-keyed accumulator into ordered Anthropic content blocks
     * and the tool_use subset.
     *
     * @param array<int, array<string, mixed>> $rawBlocks
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{id: string, name: string, input: array}>}
     */
    private function finalizeBlocks(array $rawBlocks): array
    {
        ksort($rawBlocks);
        $contentBlocks = [];
        $toolUses = [];

        foreach ($rawBlocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $contentBlocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $decoded = ($block['json'] ?? '') !== '' ? json_decode((string) $block['json'], true) : [];
                $input = is_array($decoded) ? $decoded : [];
                $contentBlocks[] = [
                    'type'  => 'tool_use',
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => $input,
                ];
                $toolUses[] = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => $input,
                ];
            }
        }

        return [$contentBlocks, $toolUses];
    }

    /**
     * Performs the streaming POST and invokes $onChunk for every received body
     * chunk (status < 400). On an error response the body is buffered and turned
     * into an LlmApiException once complete. Overridable for tests.
     *
     * @param callable(string $chunk): void $onChunk
     */
    protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
    {
        $baseUrl = rtrim($params->baseUrl !== '' ? $params->baseUrl : self::DEFAULT_BASE_URL, '/');
        $url = $baseUrl . '/v1/messages';

        $headers = [
            'content-type: application/json',
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];
        if ($params->apiKey !== null && $params->apiKey !== '') {
            $headers[] = 'x-api-key: ' . $params->apiKey;
        }

        $status = 0;
        $errorBody = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$status, &$errorBody, $onChunk): int {
                if ($status >= 400) {
                    $errorBody .= $chunk;       // error response body, not SSE
                } else {
                    $onChunk($chunk);
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($status >= 400) {
            $decoded = json_decode($errorBody, true);
            $type = is_array($decoded) ? ($decoded['error']['type'] ?? 'api_error') : 'api_error';
            $msg  = is_array($decoded) ? ($decoded['error']['message'] ?? "HTTP {$status}") : "HTTP {$status}";
            throw new LlmApiException($status, (string) $type, (string) $msg);
        }
        if ($ok === false || $errno !== 0) {
            throw new LlmApiException(0, 'transport_error', $errmsg !== '' ? $errmsg : 'curl transport error');
        }
    }

    /**
     * Feeds a raw chunk into the SSE line buffer, dispatching every complete
     * `data:` line. Robust to chunks that split a line mid-way.
     *
     * @param array<string, mixed>          $acc
     * @param callable(string $text): void  $onTextDelta
     */
    private function feedSse(string $chunk, array &$acc, callable $onTextDelta): void
    {
        $acc['buf'] .= $chunk;
        while (($nl = strpos($acc['buf'], "\n")) !== false) {
            $line = rtrim(substr($acc['buf'], 0, $nl), "\r");
            $acc['buf'] = substr($acc['buf'], $nl + 1);

            if (!str_starts_with($line, 'data:')) {
                continue; // `event:` lines, blank separators, comments — ignored
            }
            $payload = trim(substr($line, 5));
            if ($payload === '') {
                continue;
            }
            $data = json_decode($payload, true);
            if (is_array($data)) {
                $this->dispatchEvent($data, $acc, $onTextDelta);
            }
        }
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<string, mixed>          $acc
     * @param callable(string $text): void  $onTextDelta
     */
    private function dispatchEvent(array $data, array &$acc, callable $onTextDelta): void
    {
        switch ($data['type'] ?? '') {
            case 'message_start':
                if (isset($data['message']['usage']['input_tokens'])) {
                    $acc['in'] = (int) $data['message']['usage']['input_tokens'];
                }
                if (isset($data['message']['model'])) {
                    $acc['model'] = (string) $data['message']['model'];
                }
                break;

            case 'content_block_start':
                $index = (int) ($data['index'] ?? 0);
                $cb = $data['content_block'] ?? [];
                if (($cb['type'] ?? '') === 'text') {
                    $acc['blocks'][$index] = ['type' => 'text', 'text' => (string) ($cb['text'] ?? '')];
                } elseif (($cb['type'] ?? '') === 'tool_use') {
                    $acc['blocks'][$index] = [
                        'type' => 'tool_use',
                        'id'   => (string) ($cb['id'] ?? ''),
                        'name' => (string) ($cb['name'] ?? ''),
                        'json' => '',
                    ];
                }
                break;

            case 'content_block_delta':
                $index = (int) ($data['index'] ?? 0);
                $deltaType = $data['delta']['type'] ?? '';
                if ($deltaType === 'text_delta') {
                    $text = (string) ($data['delta']['text'] ?? '');
                    if ($text !== '') {
                        $acc['text'] .= $text;
                        if (isset($acc['blocks'][$index]) && $acc['blocks'][$index]['type'] === 'text') {
                            $acc['blocks'][$index]['text'] .= $text;
                        }
                        $onTextDelta($text);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    if (isset($acc['blocks'][$index]) && $acc['blocks'][$index]['type'] === 'tool_use') {
                        $acc['blocks'][$index]['json'] .= (string) ($data['delta']['partial_json'] ?? '');
                    }
                }
                break;

            case 'message_delta':
                if (isset($data['usage']['output_tokens'])) {
                    $acc['out'] = (int) $data['usage']['output_tokens'];
                }
                if (isset($data['delta']['stop_reason'])) {
                    $acc['stop'] = (string) $data['delta']['stop_reason'];
                }
                break;

            case 'error':
                $acc['error'] = [
                    'type'    => (string) ($data['error']['type'] ?? 'api_error'),
                    'message' => (string) ($data['error']['message'] ?? 'stream error'),
                ];
                break;

            // message_stop, content_block_stop, ping → ignored (blocks finalized post-stream)
        }
    }
}
