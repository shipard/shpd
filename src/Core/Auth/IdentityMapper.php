<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Mapování ověřené OIDC identity na lokálního uživatele (D10):
 *
 *   1. lookup (issuer, subject) → kontrola is_active → update last_login,
 *   2. jinak auto-link podle ověřeného e-mailu (jen autoLinkEmail provideři):
 *      právě 1 aktivní uživatel = propojit; 0 = JIT provisioning (opt-in),
 *      jinak oidc_no_account; >1 = oidc_email_ambiguous (řeší admin),
 *   3. jinak oidc_no_account.
 *
 * Selhání = OidcException s kódem pro redirect na login obrazovku.
 */
class IdentityMapper
{
	/** @return int user_id */
	public function resolve(OidcIdentity $identity, OidcProviderConfig $provider, DataSourceConnection $db): int
	{
		$existing = $db->fetchRow(
			'SELECT * FROM core_system_user_identities WHERE issuer = %s AND subject = %s',
			$identity->issuer,
			$identity->subject,
		);

		if ($existing !== null) {
			$user = $db->fetchRow(
				'SELECT * FROM core_system_users WHERE id = %i',
				(int) $existing['user_id'],
			);
			if ($user === null || !$user['is_active']) {
				throw new OidcException('oidc_account_inactive', 'Linked user account is inactive');
			}
			$db->execute(
				'UPDATE core_system_user_identities SET last_login = %s WHERE id = %i',
				date('Y-m-d H:i:s'),
				(int) $existing['id'],
			);
			return (int) $user['id'];
		}

		if (!$provider->autoLinkEmail || !$identity->emailVerified
			|| $identity->email === null || $identity->email === '') {
			throw new OidcException('oidc_no_account', 'No linked account for this identity');
		}

		$matches = $db->fetchAll(
			'SELECT * FROM core_system_users WHERE email = %s AND is_active = 1',
			$identity->email,
		);

		if (count($matches) > 1) {
			throw new OidcException('oidc_email_ambiguous', "E-mail '{$identity->email}' matches multiple accounts");
		}

		if (count($matches) === 1) {
			$userId = (int) $matches[0]['id'];
			$this->linkIdentity($userId, $identity, $provider, $db);
			return $userId;
		}

		if (!$provider->jitProvision) {
			throw new OidcException('oidc_no_account', 'No account matches this identity and JIT provisioning is off');
		}

		$loginTaken = $db->fetchRow(
			'SELECT id FROM core_system_users WHERE login = %s',
			$identity->email,
		);
		if ($loginTaken !== null) {
			// Login existuje, ale e-mail filtr ho nenašel (neaktivní účet nebo
			// jiný e-mail) — kolizi musí vyřešit admin.
			throw new OidcException('oidc_login_conflict', "Login '{$identity->email}' already exists");
		}

		$userId = $db->insertRow('core_system_users', [
			'login'         => $identity->email,
			'password_hash' => null,
			'full_name'     => $identity->name ?? $identity->email,
			'email'         => $identity->email,
			'is_active'     => 1,
		]);
		$this->linkIdentity($userId, $identity, $provider, $db);
		return $userId;
	}

	private function linkIdentity(
		int $userId,
		OidcIdentity $identity,
		OidcProviderConfig $provider,
		DataSourceConnection $db,
	): void {
		$db->insertRow('core_system_user_identities', [
			'user_id'       => $userId,
			'provider'      => $provider->id,
			'issuer'        => $identity->issuer,
			'subject'       => $identity->subject,
			'email_at_link' => $identity->email,
			'created'       => date('Y-m-d H:i:s'),
			'last_login'    => date('Y-m-d H:i:s'),
		]);
	}
}
