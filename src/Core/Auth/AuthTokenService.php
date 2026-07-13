<?php

declare(strict_types=1);

namespace Shipard\Core\Auth;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Jednorázové autentizační tokeny (`core_system_auth_tokens`) pro pozvánky
 * a reset hesla. Plaintext `shpd_pt_...` se vrátí jen jednou (jde do mailu);
 * v DB žije pouze SHA-256 hash — vzor API klíče. Pozvánka je technicky reset
 * s delším TTL a jinou šablonou: obě purposes konzumuje stejná landing page.
 */
final class AuthTokenService
{
    public const TOKEN_PREFIX = 'shpd_pt_';
    private const TOKEN_RANDOM_BYTES = 32;

    public const PURPOSE_INVITE = 'invite';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const RESET_TTL_SECONDS = 3600;          // 1 hodina
    public const INVITE_TTL_SECONDS = 7 * 86400;    // 7 dní

    /** Expirované tokeny starší než tohle se mažou v miss větvi consume(). */
    private const PRUNE_AFTER_SECONDS = 30 * 86400;

    public function __construct(private readonly DataSourceConnection $db)
    {
    }

    /**
     * Vygeneruje a uloží nový token. Existující nepoužité tokeny stejného
     * purpose + user se smažou — platí vždy jen poslední odeslaný mail.
     */
    public function issue(int $userId, string $purpose, int $ttlSeconds): string
    {
        $plaintext = self::TOKEN_PREFIX . $this->urlSafeToken(self::TOKEN_RANDOM_BYTES);

        $this->db->deleteWhere(
            'core_system_auth_tokens',
            'user_id = %i AND purpose = %s AND used_at IS NULL',
            $userId,
            $purpose,
        );

        $this->db->insertRow('core_system_auth_tokens', [
            'purpose'    => $purpose,
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $plaintext),
            'created'    => date('Y-m-d H:i:s'),
            'expires'    => date('Y-m-d H:i:s', time() + $ttlSeconds),
            'used_at'    => null,
        ]);

        return $plaintext;
    }

    /**
     * Neburning kontrola — vrací user_id platného tokenu, token zůstává
     * použitelný. Umožňuje zvalidovat politiku hesla bez spálení tokenu.
     *
     * @param string[] $purposes
     */
    public function validate(string $token, array $purposes): ?int
    {
        $row = $this->db->fetchRow(
            'SELECT user_id FROM core_system_auth_tokens
             WHERE token_hash = %s AND purpose IN %in
               AND used_at IS NULL AND expires > %dt',
            hash('sha256', $token),
            $purposes,
            new \DateTimeImmutable(),
        );

        return $row === null ? null : (int) $row['user_id'];
    }

    /**
     * Atomicky označí token jako použitý a vrátí user_id. Single-use i při
     * souběhu — rozhoduje affected rows jediného UPDATE, druhé volání dostane
     * null. Miss větev uklidí dávno expirované tokeny (žádné CLI není třeba).
     *
     * @param string[] $purposes
     */
    public function consume(string $token, array $purposes): ?int
    {
        $tokenHash = hash('sha256', $token);

        $this->db->execute(
            'UPDATE core_system_auth_tokens SET used_at = %dt
             WHERE token_hash = %s AND purpose IN %in
               AND used_at IS NULL AND expires > %dt',
            new \DateTimeImmutable(),
            $tokenHash,
            $purposes,
            new \DateTimeImmutable(),
        );

        if ($this->db->getAffectedRows() !== 1) {
            $this->prune();
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT user_id FROM core_system_auth_tokens WHERE token_hash = %s',
            $tokenHash,
        );

        return $row === null ? null : (int) $row['user_id'];
    }

    /** Smaže tokeny expirované před více než PRUNE_AFTER_SECONDS. */
    public function prune(): void
    {
        $this->db->deleteWhere(
            'core_system_auth_tokens',
            'expires < %dt',
            new \DateTimeImmutable('-' . self::PRUNE_AFTER_SECONDS . ' seconds'),
        );
    }

    private function urlSafeToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
