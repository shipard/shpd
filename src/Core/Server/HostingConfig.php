<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Napojení DS serveru na hosting — volitelná sekce `hosting` v
 * `/etc/shipard/server.json` (D3, docs/hosting.md §5.1). Používá ji jen
 * agent `shpd-server hosting-sync`; chybějící sekce = server není
 * spravovaný hostingem a nic se nemění.
 *
 * Klíč je v konfiguračním souboru plaintext — soubor má práva 0600 a leží
 * vedle DB hesel se stejným threat modelem (vzor mail.relay).
 */
final readonly class HostingConfig
{
    public function __construct(
        public string $url,
        public int $serverId,
        #[\SensitiveParameter]
        public string $apiKey,
    ) {
        if ($this->url === '' || !preg_match('#^https?://#', $this->url)) {
            throw new \RuntimeException("Hosting config: 'url' must start with http:// or https://");
        }
        if ($this->serverId < 1) {
            throw new \RuntimeException("Hosting config: 'serverId' must be a positive integer");
        }
        if (!str_starts_with($this->apiKey, 'shpd_hk_')) {
            throw new \RuntimeException("Hosting config: 'apiKey' must start with shpd_hk_");
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: rtrim((string) ($data['url'] ?? ''), '/'),
            serverId: (int) ($data['serverId'] ?? 0),
            apiKey: (string) ($data['apiKey'] ?? ''),
        );
    }
}
