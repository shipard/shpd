<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Vznik a zánik session tokenů `shpd_st_`. Jediné místo, které sessions
 * zapisuje — sdílí ho lokální login (AuthController), OIDC callback
 * (OidcController) i break-glass CLI (auth-emergency-login). Čtení a kontrola
 * expirace zůstává v AuthMiddleware.
 */
class SessionService
{
	public const int SESSION_TTL_SECONDS = 86400; // 24 hours

	/** @return array{0: string, 1: string} [token, expires_at ISO-8601] */
	public function createSession(int $userId, DataSourceConnection $db, ?string $clientIp = null): array
	{
		$token = 'shpd_st_' . $this->generateToken(48);
		$expiresAt = date('c', time() + self::SESSION_TTL_SECONDS);

		$db->insertRow('core_system_sessions', [
			'user_id'    => $userId,
			'token'      => $token,
			'ip_address' => $clientIp,
			'created'    => date('Y-m-d H:i:s'),
			'expires'    => date('Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS),
		]);

		return [$token, $expiresAt];
	}

	public function invalidateSession(string $token, DataSourceConnection $db): void
	{
		$db->execute(
			'DELETE FROM core_system_sessions WHERE token = %s',
			$token,
		);
	}

	public function generateToken(int $length): string
	{
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$charCount = strlen($chars);
		$result = '';
		for ($i = 0; $i < $length; $i++) {
			$result .= $chars[random_int(0, $charCount - 1)];
		}
		return $result;
	}
}
