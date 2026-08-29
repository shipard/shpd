<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess\Http;

/**
 * Odpověď jednoho HTTP requestu (bez následování redirectů).
 *
 * `status` 0 = transportní chyba (viz `error`). `headers` mají lowercase
 * klíče, poslední výskyt vyhrává. `truncated` = tělo překročilo size cap
 * a přenos byl přerušen — `body` je pak nepoužitelné.
 */
final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
        public ?string $error = null,
        public bool $truncated = false,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
