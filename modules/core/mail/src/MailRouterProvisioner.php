<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní provisioning systémového uživatele `_mail_router` + výchozí
 * schránky pro příjem pošty z externího mail-routeru.
 *
 * Používá se z:
 *   - `ds-upgrade` (auto-hook na konci, aby každý DS měl po upgrade vše nachystané)
 *   - `mail-router-bootstrap` (manuální spuštění)
 *   - `mail-router-setup` (musí zajistit, že uživatel existuje před generováním API klíče)
 */
class MailRouterProvisioner
{
    public const ROUTER_LOGIN = '_mail_router';
    public const DEFAULT_MAILBOX_ID = 'default';
    private const DEFAULT_DOMAIN = 'shipard.email';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * Zajistí existenci uživatele + schránky. Vrací pole s informací o tom,
     * co bylo vytvořeno (pro CLI output).
     *
     * @return array{
     *     user: array{id: int, created: bool},
     *     mailbox: array{id: int, created: bool, skipped_reason?: string}
     * }
     */
    public function provision(string $dsId): array
    {
        $user = $this->ensureRouterUser();
        $mailbox = $this->ensureDefaultMailbox($dsId);

        return [
            'user' => $user,
            'mailbox' => $mailbox,
        ];
    }

    /**
     * @return array{id: int, created: bool}
     */
    public function ensureRouterUser(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_system_users WHERE login = %s',
            self::ROUTER_LOGIN,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $randomPassword = bin2hex(random_bytes(32));
        $id = $this->db->insertRow('core_system_users', [
            'login' => self::ROUTER_LOGIN,
            'password_hash' => password_hash($randomPassword, PASSWORD_DEFAULT),
            'full_name' => 'Mail Router (system)',
            'email' => null,
            'is_active' => 1,
            'is_system' => 1,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /**
     * Vytvoří (pokud chybí) výchozí schránku `default`. Respektuje invariant
     * "max jedna is_default schránka per DS" — pokud už jiná schránka má
     * `is_default = 1`, nic neměníme a vrátíme `skipped_reason`.
     *
     * @return array{id: int, created: bool, skipped_reason?: string}
     */
    public function ensureDefaultMailbox(string $dsId): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_mail_mailboxes WHERE mailbox_id = %s',
            self::DEFAULT_MAILBOX_ID,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $existingDefault = $this->db->fetchRow(
            'SELECT id, mailbox_id FROM core_mail_mailboxes WHERE is_default = %i',
            1,
        );

        if ($existingDefault !== null) {
            return [
                'id' => (int) $existingDefault['id'],
                'created' => false,
                'skipped_reason' => "Another mailbox is already marked as default: {$existingDefault['mailbox_id']}",
            ];
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->db->insertRow('core_mail_mailboxes', [
            'mailbox_id' => self::DEFAULT_MAILBOX_ID,
            'name' => 'Hlavní schránka',
            'email_address' => $dsId . '@' . self::DEFAULT_DOMAIN,
            'description' => 'Výchozí schránka DS pro příjem došlé pošty (auto-provisioned).',
            'default_primary_type' => 'other',
            'is_default' => 1,
            'docState' => 40,
            'docStateMain' => 3,
            'created' => $now,
            'modified' => $now,
        ]);

        return ['id' => $id, 'created' => true];
    }
}
