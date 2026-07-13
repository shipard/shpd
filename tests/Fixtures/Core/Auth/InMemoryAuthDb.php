<?php
declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Auth;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro auth/OIDC testy. Interpretuje
 * jen SQL, které IdentityMapper / OidcController / SessionService skutečně
 * posílají (match podle podřetězců). Instancuje se přes
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class InMemoryAuthDb extends DataSourceConnection
{
	/** @var array<int, array> */
	public array $users = [];
	/** @var array<int, array> */
	public array $identities = [];
	/** @var array<int, array> */
	public array $transactions = [];
	/** @var array<int, array> */
	public array $sessions = [];
	/** @var array<int, array> */
	public array $authTokens = [];
	public array $executedQueries = [];
	private int $nextId = 1;
	private int $affectedRows = 0;

	public static function create(): self
	{
		$ref = new \ReflectionClass(self::class);
		/** @var self $db */
		$db = $ref->newInstanceWithoutConstructor();
		return $db;
	}

	public function addUser(array $row): int
	{
		return $this->insert($this->users, $row);
	}

	public function addIdentity(array $row): int
	{
		return $this->insert($this->identities, $row);
	}

	public function addTransaction(array $row): int
	{
		return $this->insert($this->transactions, $row);
	}

	public function addSession(array $row): int
	{
		return $this->insert($this->sessions, $row);
	}

	public function insertRow(string $table, array $data): int
	{
		return match ($table) {
			'core_system_users'             => $this->insert($this->users, $data),
			'core_system_user_identities'   => $this->insert($this->identities, $data),
			// Nullable sloupce vrací reálná DB vždy — doplnit jako NULL.
			'core_system_auth_transactions' => $this->insert(
				$this->transactions,
				$data + ['handoff_code' => null, 'session_token' => null],
			),
			'core_system_sessions'          => $this->insert($this->sessions, $data),
			'core_system_auth_tokens'       => $this->insert($this->authTokens, $data),
			default => throw new \LogicException("InMemoryAuthDb: unexpected insert into {$table}"),
		};
	}

	public function fetchRow(mixed ...$args): ?array
	{
		$sql = (string) $args[0];

		if (str_contains($sql, 'core_system_user_identities')) {
			// WHERE issuer = %s AND subject = %s
			foreach ($this->identities as $row) {
				if ($row['issuer'] === $args[1] && $row['subject'] === $args[2]) {
					return $row;
				}
			}
			return null;
		}
		if (str_contains($sql, 'core_system_users')) {
			if (str_contains($sql, 'WHERE id =')) {
				return $this->users[(int) $args[1]] ?? null;
			}
			if (str_contains($sql, 'WHERE login =')) {
				foreach ($this->users as $row) {
					if ($row['login'] === $args[1]) {
						return $row;
					}
				}
				return null;
			}
		}
		if (str_contains($sql, 'core_system_auth_transactions')) {
			$column = str_contains($sql, 'handoff_code') ? 'handoff_code' : 'state';
			foreach ($this->transactions as $row) {
				if (($row[$column] ?? null) === $args[1]) {
					return $row;
				}
			}
			return null;
		}
		if (str_contains($sql, 'core_system_auth_tokens')) {
			// AuthTokenService::validate() — hash + purpose IN + nepoužitý +
			// neexpirovaný; post-consume lookup je jen WHERE token_hash.
			if (str_contains($sql, 'used_at IS NULL')) {
				foreach ($this->authTokens as $row) {
					if ($row['token_hash'] === $args[1]
						&& in_array($row['purpose'], (array) $args[2], true)
						&& $row['used_at'] === null
						&& $row['expires'] > $this->toDateString($args[3])) {
						return ['user_id' => $row['user_id']];
					}
				}
				return null;
			}
			foreach ($this->authTokens as $row) {
				if ($row['token_hash'] === $args[1]) {
					return ['user_id' => $row['user_id']];
				}
			}
			return null;
		}
		if (str_contains($sql, 'core_system_sessions')) {
			foreach ($this->sessions as $row) {
				if ($row['token'] === $args[1]) {
					return $row;
				}
			}
			return null;
		}

		throw new \LogicException("InMemoryAuthDb: unexpected fetchRow: {$sql}");
	}

	public function fetchAll(mixed ...$args): array
	{
		$sql = (string) $args[0];

		if (str_contains($sql, 'core_system_users') && str_contains($sql, 'email =')) {
			$result = [];
			foreach ($this->users as $row) {
				if (($row['email'] ?? null) === $args[1] && ($row['is_active'] ?? 0)) {
					$result[] = $row;
				}
			}
			return $result;
		}

		if (str_contains($sql, 'core_system_sessions') && str_contains($sql, 'user_id =')) {
			$result = [];
			foreach ($this->sessions as $row) {
				if ((int) $row['user_id'] === (int) $args[1]) {
					$result[] = $row;
				}
			}
			return $result;
		}

		throw new \LogicException("InMemoryAuthDb: unexpected fetchAll: {$sql}");
	}

	public function fetchSingle(mixed ...$args): mixed
	{
		$sql = (string) $args[0];

		// SettingsStore::get() — testy settings neplní, ds_name padá na
		// config->getName().
		if (str_contains($sql, 'core_system_settings')) {
			return null;
		}

		throw new \LogicException("InMemoryAuthDb: unexpected fetchSingle: {$sql}");
	}

	public function execute(mixed ...$args): void
	{
		$this->executedQueries[] = $args;
		$sql = (string) $args[0];

		if (str_contains($sql, 'UPDATE core_system_user_identities SET last_login')) {
			$this->identities[(int) $args[2]]['last_login'] = $args[1];
			return;
		}
		if (str_contains($sql, 'UPDATE core_system_auth_transactions SET handoff_code')) {
			$id = (int) $args[4];
			$this->transactions[$id]['handoff_code'] = $args[1];
			$this->transactions[$id]['session_token'] = $args[2];
			$this->transactions[$id]['expires'] = $args[3];
			return;
		}
		if (str_contains($sql, 'DELETE FROM core_system_auth_transactions WHERE id =')) {
			unset($this->transactions[(int) $args[1]]);
			return;
		}
		if (str_contains($sql, 'DELETE FROM core_system_auth_transactions WHERE expires <')) {
			foreach ($this->transactions as $id => $row) {
				if ($row['expires'] < $args[1]) {
					unset($this->transactions[$id]);
				}
			}
			return;
		}
		if (str_contains($sql, 'DELETE FROM core_system_sessions WHERE token =')) {
			foreach ($this->sessions as $id => $row) {
				if ($row['token'] === $args[1]) {
					unset($this->sessions[$id]);
				}
			}
			return;
		}
		if (str_contains($sql, 'DELETE FROM core_system_sessions WHERE id =')) {
			$this->affectedRows = 0;
			foreach ($this->sessions as $id => $row) {
				if ((int) $row['id'] === (int) $args[1] && (int) $row['user_id'] === (int) $args[2]) {
					unset($this->sessions[$id]);
					$this->affectedRows++;
				}
			}
			return;
		}
		if (str_contains($sql, 'DELETE FROM core_system_sessions WHERE user_id =')) {
			$keepToken = str_contains($sql, 'token !=') ? $args[2] : null;
			$this->affectedRows = 0;
			foreach ($this->sessions as $id => $row) {
				if ((int) $row['user_id'] === (int) $args[1]
					&& ($keepToken === null || $row['token'] !== $keepToken)) {
					unset($this->sessions[$id]);
					$this->affectedRows++;
				}
			}
			return;
		}
		if (str_contains($sql, 'UPDATE core_system_auth_tokens SET used_at')) {
			// AuthTokenService::consume() — atomický single-use UPDATE.
			$this->affectedRows = 0;
			foreach ($this->authTokens as $id => $row) {
				if ($row['token_hash'] === $args[2]
					&& in_array($row['purpose'], (array) $args[3], true)
					&& $row['used_at'] === null
					&& $row['expires'] > $this->toDateString($args[4])) {
					$this->authTokens[$id]['used_at'] = $this->toDateString($args[1]);
					$this->affectedRows++;
				}
			}
			return;
		}
		if (str_contains($sql, 'UPDATE core_system_users SET password_hash')) {
			$this->users[(int) $args[2]]['password_hash'] = $args[1];
			return;
		}

		throw new \LogicException("InMemoryAuthDb: unexpected execute: {$sql}");
	}

	public function deleteWhere(string $table, string $where, mixed ...$whereParams): void
	{
		if ($table === 'core_system_auth_tokens' && str_contains($where, 'user_id =')) {
			// AuthTokenService::issue() — poslední mail platí.
			foreach ($this->authTokens as $id => $row) {
				if ((int) $row['user_id'] === (int) $whereParams[0]
					&& $row['purpose'] === $whereParams[1]
					&& $row['used_at'] === null) {
					unset($this->authTokens[$id]);
				}
			}
			return;
		}
		if ($table === 'core_system_auth_tokens' && str_contains($where, 'expires <')) {
			foreach ($this->authTokens as $id => $row) {
				if ($row['expires'] < $this->toDateString($whereParams[0])) {
					unset($this->authTokens[$id]);
				}
			}
			return;
		}

		throw new \LogicException("InMemoryAuthDb: unexpected deleteWhere: {$table} WHERE {$where}");
	}

	public function getAffectedRows(): int
	{
		return $this->affectedRows;
	}

	private function toDateString(mixed $value): string
	{
		return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
	}

	private function insert(array &$storage, array $data): int
	{
		$id = $this->nextId++;
		$data['id'] = $id;
		$storage[$id] = $data;
		return $id;
	}
}
