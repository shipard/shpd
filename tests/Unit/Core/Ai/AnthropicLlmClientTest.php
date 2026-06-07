<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Ai\LlmChatParams;

/**
 * Parsing tests for AnthropicLlmClient. The network transport is replaced by a
 * fixture fed through the protected sendStreamingRequest() seam — no real HTTP.
 */
class AnthropicLlmClientTest extends TestCase
{
    private function params(string $provider = 'anthropic'): LlmChatParams
    {
        return new LlmChatParams(
            provider: $provider,
            model: 'claude-opus-4-8',
            apiKey: 'sk-test',
            baseUrl: '',
            system: 'You are a test.',
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]],
            maxTokens: 1024,
            temperature: null,
        );
    }

    /** @param string[] $chunks */
    private function client(array $chunks): AnthropicLlmClient
    {
        return new class($chunks) extends AnthropicLlmClient {
            /** @param string[] $chunks */
            public function __construct(private array $chunks) {}

            protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
            {
                foreach ($this->chunks as $chunk) {
                    $onChunk($chunk);
                }
            }
        };
    }

    private function sampleStream(): string
    {
        return implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_1","type":"message","role":"assistant","model":"claude-opus-4-8","content":[],"usage":{"input_tokens":42,"output_tokens":1}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: ping',
            'data: {"type":"ping"}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Ahoj"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":", světe"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":7}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ]);
    }

    public function testParsesTextDeltasUsageAndStopReason(): void
    {
        // Split into tiny chunks to force mid-line splits across the buffer.
        $chunks = str_split($this->sampleStream(), 17);

        $deltas = [];
        $result = $this->client($chunks)->streamChat(
            $this->params(),
            function (string $t) use (&$deltas): void { $deltas[] = $t; },
        );

        $this->assertSame(['Ahoj', ', světe'], $deltas);
        $this->assertSame('Ahoj, světe', $result->text);
        $this->assertSame(42, $result->inputTokens);
        $this->assertSame(7, $result->outputTokens);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertSame('claude-opus-4-8', $result->model);
    }

    public function testUnsupportedProviderThrows(): void
    {
        $this->expectException(LlmUnsupportedProviderException::class);
        $this->client([])->streamChat($this->params('openai'), function (): void {});
    }

    public function testInlineErrorEventThrows(): void
    {
        $stream = "event: error\n"
            . 'data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}' . "\n\n";

        try {
            $this->client([$stream])->streamChat($this->params(), function (): void {});
            $this->fail('Expected LlmApiException');
        } catch (LlmApiException $e) {
            $this->assertSame('overloaded_error', $e->errorType);
            $this->assertStringContainsString('Overloaded', $e->getMessage());
        }
    }

    public function testParsesToolUseBlock(): void
    {
        $stream = implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_2","model":"claude-opus-4-8","usage":{"input_tokens":50,"output_tokens":1}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_1","name":"persons_search","input":{}}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"query\":"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"\"Acme\"}"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":12}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ]);

        $result = $this->client(str_split($stream, 19))->streamChat($this->params(), function (): void {});

        $this->assertSame('tool_use', $result->stopReason);
        $this->assertCount(1, $result->toolUses);
        $this->assertSame('toolu_1', $result->toolUses[0]['id']);
        $this->assertSame('persons_search', $result->toolUses[0]['name']);
        $this->assertSame(['query' => 'Acme'], $result->toolUses[0]['input']);
        // contentBlocks carries the tool_use block for faithful replay
        $this->assertSame('tool_use', $result->contentBlocks[0]['type']);
        $this->assertSame(['query' => 'Acme'], $result->contentBlocks[0]['input']);
    }

    public function testKeepsTextAndToolUseOrdering(): void
    {
        $stream = implode("\n", [
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hledám…"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_9","name":"documents_search","input":{}}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{}"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":1}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":5}}',
            '',
        ]);

        $result = $this->client([$stream])->streamChat($this->params(), function (): void {});

        $this->assertSame('Hledám…', $result->text);
        $this->assertCount(2, $result->contentBlocks);
        $this->assertSame('text', $result->contentBlocks[0]['type']);
        $this->assertSame('Hledám…', $result->contentBlocks[0]['text']);
        $this->assertSame('tool_use', $result->contentBlocks[1]['type']);
        $this->assertSame('documents_search', $result->contentBlocks[1]['name']);
        $this->assertCount(1, $result->toolUses);
    }
}
