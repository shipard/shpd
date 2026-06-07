<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Aggregated result of a streaming chat turn: the full text plus usage
 * telemetry collected from the stream's message_start / message_delta events.
 */
final readonly class LlmChatResult
{
    public function __construct(
        public string $text,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?string $stopReason,
        public ?string $model,
    ) {}
}
