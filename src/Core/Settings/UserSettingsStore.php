<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Per-uživatelská varianta {@see SettingsStore} nad tabulkou
 * `core_system_user_settings`. Každý dotaz je scoped na `user_id`, unikátní
 * je dvojice `(user_id, key)`. Hodnoty se ukládají jako JSON (sloupec
 * `value`), klíče používají tečkové namespacy (`account.theme`,
 * `account.language`, …).
 *
 * Request-level cache: instance je per-user, takže stačí klíčovat jen
 * názvem klíče (user_id je fixní v konstruktoru).
 */
class UserSettingsStore implements KeyValueStore
{
    private const string TABLE = 'core_system_user_settings';

    /** @var array<string, mixed> cache klíč → dekódovaná hodnota (vč. null pro "neexistuje") */
    private array $cache = [];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly int $userId,
    ) {
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $raw = $this->db->fetchSingle(
            'SELECT `value` FROM `' . self::TABLE . '` WHERE `user_id` = %i AND `key` = %s',
            $this->userId,
            $key,
        );

        $value = is_string($raw) ? json_decode($raw, true) : null;
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array
    {
        $result  = [];
        $missing = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->cache)) {
                $result[$key] = $this->cache[$key];
            } else {
                $missing[]    = $key;
                $result[$key] = null;
            }
        }

        if ($missing !== []) {
            $rows = $this->db->fetchAll(
                'SELECT `key`, `value` FROM `' . self::TABLE . '` WHERE `user_id` = %i AND `key` IN %in',
                $this->userId,
                $missing,
            );
            foreach ($rows as $row) {
                $value = is_string($row['value']) ? json_decode($row['value'], true) : null;
                $result[$row['key']]      = $value;
                $this->cache[$row['key']] = $value;
            }
            foreach ($missing as $key) {
                if (!array_key_exists($key, $this->cache)) {
                    $this->cache[$key] = null;
                }
            }
        }

        return $result;
    }

    /**
     * Upsert hodnoty. `set($key, null)` klíč smaže — null a "neexistuje"
     * jsou pro čtenáře nerozlišitelné. Unikát `(user_id, key)` zajistí, že
     * ON DUPLICATE KEY UPDATE trefí správný řádek per uživatel.
     */
    public function set(string $key, mixed $value): void
    {
        if ($value === null) {
            $this->delete($key);
            return;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->execute(
            'INSERT INTO `' . self::TABLE . '` (`user_id`, `key`, `value`, `modified`) VALUES (%i, %s, %s, NOW())'
            . ' ON DUPLICATE KEY UPDATE `value` = %s, `modified` = NOW()',
            $this->userId,
            $key,
            $json,
            $json,
        );

        $this->cache[$key] = $value;
    }

    public function delete(string $key): void
    {
        $this->db->deleteWhere(self::TABLE, '`user_id` = %i AND `key` = %s', $this->userId, $key);
        $this->cache[$key] = null;
    }
}
