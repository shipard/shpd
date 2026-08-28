<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

/**
 * Konfigurace PDF rendering služby (Gotenberg) — volitelný klíč `render`
 * v `/etc/shipard/server.json`. Služba je per fyzický stroj (D1), proto
 * server config, ne DS config. Chybějící klíč = služba nekonfigurována,
 * vše degraduje (RenderClient vrací errorKind=unconfigured).
 *
 * Viz docs/render.md a tasks/pdf-rendering-service.md (#34).
 */
final readonly class RenderConfig
{
    public function __construct(
        public string $url,
        public int $timeoutSec = 30,
    ) {
        if ($this->url === '' || !preg_match('#^https?://#', $this->url)) {
            throw new \RuntimeException(
                "Render: 'url' must start with http:// or https://",
            );
        }
        if ($this->timeoutSec < 1) {
            throw new \RuntimeException("Render: 'timeoutSec' must be a positive integer");
        }
    }

    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? '';
        if (!is_string($url)) {
            throw new \RuntimeException("Render: 'url' must be a string");
        }

        return new self(
            url: rtrim($url, '/'),
            timeoutSec: (int) ($data['timeoutSec'] ?? 30),
        );
    }
}
