<?php

declare(strict_types=1);

namespace Shipard\Core\Security;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Sdílená logika pro správu API klíčů — generování, ukládání, vyhledávání,
 * deaktivace. Plaintext se zobrazí jen jednou; v DB žije pouze SHA-256 hash
 * a 12-znakový key_prefix pro lookup.
 */
final class ApiKeyService
{
    public const TOKEN_PREFIX = 'shpd_ak_';
    public const KEY_PREFIX_LENGTH = 12;
    private const TOKEN_RANDOM_BYTES = 16; // → 32 hex chars

    public function __construct(private readonly DataSourceConnection $db)
    {
    }

    /**
     * Vygeneruje a uloží nový API klíč pro daného uživatele. Plaintext se vrátí
     * pouze v návratové hodnotě — v DB se ukládá jen SHA-256 hash + key_prefix.
     *
     * @param int                     $userId     FK na core_system_users.id
     * @param string                  $name       lidsky čitelný popisek
     * @param string[]                $allowedIps prázdné pole = bez restrikce
     * @param \DateTimeImmutable|null $expiresAt  null = bez expirace
     *
     * @return array{plaintext: string, id: int, keyPrefix: string}
     */
    public function createKey(
        int $userId,
        string $name,
        array $allowedIps = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $plaintext = self::generateToken();
        $keyPart = substr($plaintext, strlen(self::TOKEN_PREFIX));
        $keyPrefix = substr($keyPart, 0, self::KEY_PREFIX_LENGTH);
        $keyHash = hash('sha256', $plaintext);

        $now = date('Y-m-d H:i:s');
        $id = $this->db->insertRow('core_system_api_keys', [
            'user_id'      => $userId,
            'name'         => $name,
            'key_hash'     => $keyHash,
            'key_prefix'   => $keyPrefix,
            'expires_at'   => $expiresAt?->format('Y-m-d H:i:s'),
            'allowed_ips'  => $allowedIps === [] ? null : json_encode(array_values($allowedIps)),
            'is_active'    => 1,
            'last_used_at' => null,
            'created'      => $now,
            'modified'     => $now,
        ]);

        return [
            'plaintext' => $plaintext,
            'id'        => $id,
            'keyPrefix' => $keyPrefix,
        ];
    }

    /**
     * Vyhledá API klíče s join na core_system_users (přidává sloupec `login`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listKeys(?int $userId = null, bool $includeInactive = false): array
    {
        $sql = 'SELECT k.*, u.login AS user_login
                FROM core_system_api_keys k
                LEFT JOIN core_system_users u ON u.id = k.user_id
                WHERE 1=1';
        $params = [];

        if (!$includeInactive) {
            $sql .= ' AND k.is_active = %i';
            $params[] = 1;
        }
        if ($userId !== null) {
            $sql .= ' AND k.user_id = %i';
            $params[] = $userId;
        }

        $sql .= ' ORDER BY k.id ASC';

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Resolve uživatele dle login / email / numeric ID. Vrací row z
     * core_system_users, nebo null pokud nenalezen / ambiguous (víc různých
     * shod přes různé cesty). Caller, který potřebuje rozlišit miss od
     * ambiguous (např. CLI error messaging), použije {@see findUserMatches()}.
     */
    public function findUser(string $loginOrEmail): ?array
    {
        $candidates = $this->findUserMatches($loginOrEmail);
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Vrací všechny rows z core_system_users, které matchnou daný řetězec
     * přes login / email / numeric ID. Deduplikuje podle ID (jeden user
     * se může strefit více cestami — vrátí se jako jeden záznam).
     *
     * @return array<int, array<string, mixed>> 0 = miss, 1 = unique, 2+ = ambiguous
     */
    public function findUserMatches(string $loginOrEmail): array
    {
        $candidates = [];

        if (ctype_digit($loginOrEmail)) {
            $byId = $this->db->fetchRow(
                'SELECT * FROM core_system_users WHERE id = %i',
                (int) $loginOrEmail,
            );
            if ($byId !== null) {
                $candidates[(int) $byId['id']] = $byId;
            }
        }

        $byLogin = $this->db->fetchRow(
            'SELECT * FROM core_system_users WHERE login = %s',
            $loginOrEmail,
        );
        if ($byLogin !== null) {
            $candidates[(int) $byLogin['id']] = $byLogin;
        }

        $byEmail = $this->db->fetchRow(
            'SELECT * FROM core_system_users WHERE email = %s',
            $loginOrEmail,
        );
        if ($byEmail !== null) {
            $candidates[(int) $byEmail['id']] = $byEmail;
        }

        return array_values($candidates);
    }

    /**
     * Deaktivuje klíč. Idempotentní — vrací true při skutečné změně,
     * false pokud klíč už byl neaktivní nebo neexistuje.
     */
    public function revokeKey(int $keyId): bool
    {
        $row = $this->db->fetchRow(
            'SELECT id, is_active FROM core_system_api_keys WHERE id = %i',
            $keyId,
        );
        if ($row === null || (int) $row['is_active'] === 0) {
            return false;
        }

        $this->db->updateWhere(
            'core_system_api_keys',
            ['is_active' => 0, 'modified' => date('Y-m-d H:i:s')],
            'id = %i',
            $keyId,
        );

        return true;
    }

    /**
     * Vyhledá klíč podle key_prefix joinovaný s login uživatele.
     * Pokud sdílí prefix víc klíčů (nepravděpodobné), vrací první podle id ASC —
     * caller (např. api-key-revoke) si ambiguity řeší explicitně přes COUNT.
     */
    public function findKeyByPrefix(string $keyPrefix): ?array
    {
        return $this->db->fetchRow(
            'SELECT k.*, u.login AS user_login
             FROM core_system_api_keys k
             LEFT JOIN core_system_users u ON u.id = k.user_id
             WHERE k.key_prefix = %s
             ORDER BY k.id ASC
             LIMIT 1',
            $keyPrefix,
        );
    }

    /**
     * Spočítá kolik klíčů sdílí daný prefix. Pomocník pro ambiguity check
     * v api-key-revoke --prefix.
     */
    public function countKeysByPrefix(string $keyPrefix): int
    {
        $value = $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_system_api_keys WHERE key_prefix = %s',
            $keyPrefix,
        );
        return (int) $value;
    }

    /**
     * Načte klíč podle ID s joinovaným login uživatele.
     */
    public function findKeyById(int $keyId): ?array
    {
        return $this->db->fetchRow(
            'SELECT k.*, u.login AS user_login
             FROM core_system_api_keys k
             LEFT JOIN core_system_users u ON u.id = k.user_id
             WHERE k.id = %i',
            $keyId,
        );
    }

    /**
     * Generuje plaintext token `shpd_ak_` + 32 hex chars. Formát musí
     * zůstat kompatibilní s AuthMiddleware::handleApiKey().
     */
    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_RANDOM_BYTES));
    }
}
