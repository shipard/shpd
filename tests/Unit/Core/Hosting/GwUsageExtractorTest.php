<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Hosting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Hosting\GwUsageExtractor;

class GwUsageExtractorTest extends TestCase
{
    /** Realistický SSE stream vč. cache polí v message_start.usage. */
    private function sampleSseStream(): string
    {
        return implode('', [
            "event: message_start\n",
            'data: {"type":"message_start","message":{"id":"msg_01","model":"claude-sonnet-4-5",'
            . '"usage":{"input_tokens":42,"cache_creation_input_tokens":100,"cache_read_input_tokens":200}}}' . "\n",
            "\n",
            "event: content_block_start\n",
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n",
            "\n",
            "event: ping\n",
            'data: {"type":"ping"}' . "\n",
            "\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}' . "\n",
            "\n",
            "event: content_block_stop\n",
            'data: {"type":"content_block_stop","index":0}' . "\n",
            "\n",
            "event: message_delta\n",
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":7}}' . "\n",
            "\n",
            "event: message_stop\n",
            'data: {"type":"message_stop"}' . "\n",
            "\n",
        ]);
    }

    public function testSseHappyPath(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->begin('text/event-stream; charset=utf-8');
        $extractor->feed($this->sampleSseStream());

        $usage = $extractor->finish();

        $this->assertTrue($usage['stream']);
        $this->assertSame('claude-sonnet-4-5', $usage['model']);
        $this->assertSame(42, $usage['input_tokens']);
        $this->assertSame(7, $usage['output_tokens']);
        $this->assertSame(100, $usage['cache_creation_tokens']);
        $this->assertSame(200, $usage['cache_read_tokens']);
    }

    public function testSseChunkedMidLineAndMidJson(): void
    {
        $stream = $this->sampleSseStream();
        $extractor = new GwUsageExtractor();
        $extractor->begin('text/event-stream');

        // Krmit po 7 bajtech — chunky lámou řádky i JSON uprostřed.
        foreach (str_split($stream, 7) as $chunk) {
            $extractor->feed($chunk);
        }

        $usage = $extractor->finish();
        $this->assertSame(42, $usage['input_tokens']);
        $this->assertSame(7, $usage['output_tokens']);
        $this->assertSame('claude-sonnet-4-5', $usage['model']);
    }

    public function testSseTruncatedAfterMessageStart(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->begin('text/event-stream');
        $extractor->feed(
            'data: {"type":"message_start","message":{"model":"claude-sonnet-4-5",'
            . '"usage":{"input_tokens":42}}}' . "\n",
        );

        $usage = $extractor->finish();

        $this->assertTrue($usage['stream']);
        $this->assertSame(42, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
        $this->assertSame(0, $usage['cache_creation_tokens']);
    }

    public function testSseErrorEventStreamYieldsZeros(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->begin('text/event-stream');
        $extractor->feed(
            "event: error\n"
            . 'data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}' . "\n\n",
        );

        $usage = $extractor->finish();

        $this->assertTrue($usage['stream']);
        $this->assertNull($usage['model']);
        $this->assertSame(0, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
    }

    public function testNonSseJsonBody(): void
    {
        $body = json_encode([
            'id' => 'msg_01',
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'Hello']],
            'usage' => [
                'input_tokens' => 42,
                'output_tokens' => 7,
                'cache_creation_input_tokens' => 100,
                'cache_read_input_tokens' => 200,
            ],
        ]);

        $extractor = new GwUsageExtractor();
        $extractor->begin('application/json');
        // Ve třech chunkách.
        foreach (str_split($body, (int) ceil(strlen($body) / 3)) as $chunk) {
            $extractor->feed($chunk);
        }

        $usage = $extractor->finish();

        $this->assertFalse($usage['stream']);
        $this->assertSame('claude-sonnet-4-5', $usage['model']);
        $this->assertSame(42, $usage['input_tokens']);
        $this->assertSame(7, $usage['output_tokens']);
        $this->assertSame(100, $usage['cache_creation_tokens']);
        $this->assertSame(200, $usage['cache_read_tokens']);
    }

    public function testNonSseErrorBodyYieldsZeros(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->begin('application/json');
        $extractor->feed('{"type":"error","error":{"type":"rate_limit_error","message":"Rate limited"}}');

        $usage = $extractor->finish();

        $this->assertFalse($usage['stream']);
        $this->assertNull($usage['model']);
        $this->assertSame(0, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
    }

    public function testNonSseBodyOverLimitYieldsZeros(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->begin('application/json');

        // Přes 10 MB — buffer se zahodí (truncated), žádný memory blowup.
        $chunk = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 11; $i++) {
            $extractor->feed($chunk);
        }

        $usage = $extractor->finish();
        $this->assertSame(0, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
    }

    public function testFinishWithoutBeginYieldsZeros(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->feed('garbage before begin');

        $usage = $extractor->finish();

        $this->assertFalse($usage['stream']);
        $this->assertNull($usage['model']);
        $this->assertSame(0, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
    }

    public function testGarbageInputDoesNotThrow(): void
    {
        $extractor = new GwUsageExtractor();
        $extractor->begin('text/event-stream');
        $extractor->feed("data: not-json\n\x00\xff\ndata: {\"type\":42}\n");

        $usage = $extractor->finish();
        $this->assertSame(0, $usage['input_tokens']);
    }
}
