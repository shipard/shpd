<?php

declare(strict_types=1);

namespace Shipard\Core\Ai\Exception;

/** Raised when a backend's provider has no client implementation (v1: anthropic only). */
class LlmUnsupportedProviderException extends LlmException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Unsupported LLM provider: {$provider}");
    }
}
