<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Minimal, provider-agnostic door to a streaming chat LLM.
 *
 * Phase 2a deliberately keeps this tiny: a single streaming call, no tool-use.
 * The stream format itself is not abstracted — only the text deltas and the
 * final usage/result are surfaced. Tool-use (Phase 2b) will extend this.
 */
interface LlmClient
{
    /**
     * Streams a model response. For each text delta, calls $onTextDelta with
     * the incremental text. Returns the aggregated result (full text + usage).
     *
     * @param callable(string $text): void $onTextDelta
     *
     * @throws Exception\LlmUnsupportedProviderException when the provider is not supported
     * @throws Exception\LlmApiException on transport / API errors
     */
    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult;
}
