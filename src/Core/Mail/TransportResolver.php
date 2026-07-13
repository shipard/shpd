<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\Exception\MailTransportConfigException;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Rozhoduje, kudy odchozí zpráva půjde (D26): lookup from adresy
 * v core_mail_senders (hit → custom SMTP per sender, heslo přes
 * DsSecretCipher), miss → relay z konfigurace (DS override ?? server
 * default, merge dělá MailServiceFactory). Žádný relay → výjimka,
 * zpráva jde ve službě do fail větve s jasnou hláškou.
 */
class TransportResolver
{
    /**
     * @param ?\Closure $transportFactory (string $host, int $port, string $security,
     *   ?string $username, ?string $password): TransportInterface — testovací šev,
     *   default staví EsmtpTransport
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DataSourceConfig $dsConfig,
        private readonly ?MailRelayConfig $relay,
        private ?DsSecretCipher $cipher = null,
        private readonly ?\Closure $transportFactory = null,
    ) {
    }

    public function resolve(string $from): ResolvedTransport
    {
        $from = trim($from);

        $sender = $this->db->fetchRow(
            'SELECT * FROM core_mail_senders WHERE LOWER(email_from) = LOWER(%s) AND is_active = 1',
            $from,
        );

        if ($sender !== null) {
            $password = null;
            $encrypted = (string) ($sender['password_enc'] ?? '');
            if ($encrypted !== '') {
                $cipher   = $this->cipher ??= DsSecretCipher::forConfig($this->dsConfig);
                $password = $cipher->decrypt($encrypted);
            }

            $transport = $this->buildTransport(
                (string) $sender['smtp_host'],
                (int) $sender['smtp_port'],
                (string) $sender['smtp_security'],
                isset($sender['smtp_username']) ? (string) $sender['smtp_username'] : null,
                $password,
            );

            return new ResolvedTransport($transport, 'sender:' . $sender['id']);
        }

        if ($this->relay === null) {
            throw new MailTransportConfigException(
                "No SMTP sender matches '{$from}' and no mail relay is configured"
                . " (key 'mail.relay' in server.json or DS main.json)",
            );
        }

        $transport = $this->buildTransport(
            $this->relay->host,
            $this->relay->port,
            $this->relay->security,
            $this->relay->username,
            $this->relay->password,
        );

        return new ResolvedTransport($transport, "{$this->relay->host}:{$this->relay->port}");
    }

    private function buildTransport(
        string $host,
        int $port,
        string $security,
        ?string $username,
        #[\SensitiveParameter]
        ?string $password,
    ): TransportInterface {
        if ($this->transportFactory !== null) {
            return ($this->transportFactory)($host, $port, $security, $username, $password);
        }

        // Přímá konstrukce místo Transport::fromDsn() — hesla se
        // speciálními znaky bez URL-escapingu, žádný factory chain.
        // 'tls' = implicitní TLS (465); 'starttls' = default autoTls
        // (STARTTLS když ho server nabídne, 587); 'none' vypíná i
        // oportunistický STARTTLS (localhost:25).
        $transport = new EsmtpTransport($host, $port, tls: $security === 'tls');
        if ($security === 'none') {
            $transport->setAutoTls(false);
        }
        if ($username !== null && $username !== '') {
            $transport->setUsername($username);
            $transport->setPassword($password ?? '');
        }

        return $transport;
    }
}
