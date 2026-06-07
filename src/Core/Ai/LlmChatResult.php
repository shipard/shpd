<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Aggregated result of a streaming chat turn: the full text plus usage
 * telemetry collected from the stream's message_start / message_delta events.
 *
 * `contentBlocks` is the assistant turn's full block list in order (text +
 * tool_use) — for faithful persistence and feeding the turn back to the model.
 * `toolUses` is the tool_use subset (`[{id, name, input}]`); empty means the
 * model produced a final answer.
 */
final readonly class LlmChatResult
{
    /**
     * @param array<int, array{id: string, name: string, input: array}> $toolUses
     * @param array<int, array<string, mixed>>                           $contentBlocks
     */
    public function __construct(
        public string $text,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?string $stopReason,
        public ?string $model,
        public array $toolUses = [],
        public array $contentBlocks = [],
    ) {}
}
