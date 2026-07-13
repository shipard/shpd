<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Odchozí zpráva — vstup pro MailOutboxService::enqueue(). Kdo posílá
 * rozhoduje volající: explicitní $from, jinak DS default ze settings
 * (`mail.defaultFrom`). Kudy zpráva půjde (custom sender vs. relay)
 * rozhoduje až TransportResolver při pokusu o odeslání.
 */
final readonly class OutboundMessage
{
    /** @param int[] $attachments id příloh z core_attachments_files */
    public function __construct(
        public string $to,
        public string $subject,
        public string $sourceModule,
        public ?string $from = null,
        public ?string $bodyText = null,
        public ?string $bodyHtml = null,
        public array $attachments = [],
        public ?int $recipientPersonId = null,
        public ?string $sourceRef = null,
        public int $priority = 0,
        public ?int $createdBy = null,
    ) {
    }
}
