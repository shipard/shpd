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
	public array $executedQueries = [];
	private int $nextId = 1;

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

		throw new \LogicException("InMemoryAuthDb: unexpected fetchAll: {$sql}");
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

		throw new \LogicException("InMemoryAuthDb: unexpected execute: {$sql}");
	}

	private function insert(array &$storage, array $data): int
	{
		$id = $this->nextId++;
		$data['id'] = $id;
		$storage[$id] = $data;
		return $id;
	}
}
