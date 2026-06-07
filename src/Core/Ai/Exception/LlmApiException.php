<?php

declare(strict_types=1);

namespace Shipard\Core\Ai\Exception;

/**
 * Raised on transport failure or an API error response (HTTP 4xx/5xx, or an
 * inline SSE `error` event). Carries the HTTP status (0 for transport-level
 * failures) and the provider's error type string.
 */
class LlmApiException extends LlmException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorType,
        string $message,
    ) {
        parent::__construct($message);
    }
}
