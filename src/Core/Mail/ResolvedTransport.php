<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Symfony\Component\Mailer\Transport\TransportInterface;

/** Výsledek TransportResolver::resolve() — transport + label do outbox logu. */
final readonly class ResolvedTransport
{
    public function __construct(
        public TransportInterface $transport,
        /** `sender:{id}` (custom SMTP) nebo `{host}:{port}` (relay) */
        public string $label,
    ) {
    }
}
