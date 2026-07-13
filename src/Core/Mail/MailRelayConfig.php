<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Konfigurace SMTP relay pro odchozí poštu — klíč `mail.relay` v
 * `/etc/shipard/server.json` (server default) nebo v DS `config/main.json`
 * (override). Merge (DS ?? server) dělá MailServiceFactory, ne config
 * třídy — DataSourceConfig záměrně nevidí ServerConfig.
 *
 * Heslo je v konfiguračním souboru plaintext — oba soubory mají práva
 * 0600, spravuje je provozovatel a leží vedle DB hesel se stejným threat
 * modelem (šifrování DsSecretCipherem by ciphertext položilo vedle klíče).
 */
final readonly class MailRelayConfig
{
    public const SECURITY_MODES = ['starttls', 'tls', 'none'];

    public function __construct(
        public string $host,
        public int $port = 587,
        public string $security = 'starttls',
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
    ) {
        if ($this->host === '') {
            throw new \RuntimeException("Mail relay: 'host' must be a non-empty string");
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new \RuntimeException("Mail relay: 'port' must be between 1 and 65535");
        }
        if (!in_array($this->security, self::SECURITY_MODES, true)) {
            throw new \RuntimeException(
                "Mail relay: 'security' must be one of: " . implode(', ', self::SECURITY_MODES),
            );
        }
    }

    public static function fromArray(array $data): self
    {
        $host = $data['host'] ?? '';
        if (!is_string($host)) {
            throw new \RuntimeException("Mail relay: 'host' must be a string");
        }

        return new self(
            host: $host,
            port: (int) ($data['port'] ?? 587),
            security: (string) ($data['security'] ?? 'starttls'),
            username: isset($data['username']) ? (string) $data['username'] : null,
            password: isset($data['password']) ? (string) $data['password'] : null,
        );
    }
}
