<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Generates fixed set of test mailboxes for core.mail seeding.
 *
 * Identifikace: `mailbox_id` začíná prefixem `TEST-` — podle toho je
 * `seed-mail-clear` pozná a smaže. Doména je placeholder (`shipard.test`),
 * v produkci by DS měla reálnou doménu z konfigurace.
 */
class FakeMailboxGenerator
{
    public const PREFIX = 'TEST-';
    private const DOMAIN = 'shipard.test';

    /** @return list<array<string, mixed>> Řazeno tak, aby `invoices` byla první (default ve formuláři). */
    public function generate(): array
    {
        $now = date('Y-m-d H:i:s');

        // docState = 40 (V pořádku), docStateMain = 3 — schránka je okamžitě provozní
        $baseStatus = [
            'docState'     => 40,
            'docStateMain' => 3,
            'created'      => $now,
            'created_by'   => null,
            'modified'     => $now,
        ];

        return [
            array_merge([
                'mailbox_id'           => self::PREFIX . 'invoices',
                'name'                 => 'Faktury (test)',
                'email_address'        => 'invoices@' . self::DOMAIN,
                'description'          => 'Testovací schránka pro přijaté faktury.',
                'default_primary_type' => 'invoiceReceived',
            ], $baseStatus),

            array_merge([
                'mailbox_id'           => self::PREFIX . 'info',
                'name'                 => 'Obecné (test)',
                'email_address'        => 'info@' . self::DOMAIN,
                'description'          => 'Testovací schránka pro obecnou komunikaci.',
                'default_primary_type' => 'other',
            ], $baseStatus),

            array_merge([
                'mailbox_id'           => self::PREFIX . 'support',
                'name'                 => 'Podpora (test)',
                'email_address'        => 'support@' . self::DOMAIN,
                'description'          => 'Testovací schránka pro uživatelskou podporu.',
                'default_primary_type' => 'other',
            ], $baseStatus),
        ];
    }
}
