<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * GitHub OAuth klient pro provider `kind: "github"` — GitHub nemluví OIDC
 * (žádné discovery, id_token ani JWKS), identita se skládá z REST API:
 * token exchange → /user → /user/emails. Subject je numerické `user.id`
 * (login se dá přejmenovat), issuer syntetická konstanta
 * OidcProviderConfig::GITHUB_ISSUER. Bez PKCE a nonce — GitHub OAuth apps
 * je nepodporují; CSRF drží single-use `state` transakce.
 */
class GithubOauthClient
{
	private const string AUTHORIZE_ENDPOINT = 'https://github.com/login/oauth/authorize';
	private const string TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';
	private const string API_BASE = 'https://api.github.com';

	private const int TIMEOUT_SECONDS = 10;
	private const int CONNECT_TIMEOUT_SECONDS = 5;

	public function buildAuthorizeUrl(OidcProviderConfig $provider, string $state, string $redirectUri): string
	{
		return self::AUTHORIZE_ENDPOINT . '?' . http_build_query([
			'client_id'    => $provider->clientId,
			'redirect_uri' => $redirectUri,
			'scope'        => implode(' ', $provider->scopes),
			'state'        => $state,
		]);
	}

	/**
	 * Výměna kódu za access token + složení identity z GitHub API.
	 * E-mail: první položka `primary && verified` z /user/emails; bez ní
	 * email null + emailVerified false → autoLink nenastane a flow skončí
	 * `oidc_no_account` (korektní — neověřený e-mail nesmí propojit účet).
	 */
	public function fetchIdentity(OidcProviderConfig $provider, string $code, string $redirectUri): OidcIdentity
	{
		$response = $this->performHttpPost(self::TOKEN_ENDPOINT, [
			'client_id'     => $provider->clientId,
			'client_secret' => $provider->clientSecret,
			'code'          => $code,
			'redirect_uri'  => $redirectUri,
		]);
		$tokens = $this->decodeResponse($response, 'GitHub token exchange');
		// GitHub vrací chybu s HTTP 200 a polem `error` v těle.
		if (isset($tokens['error'])) {
			$detail = (string) ($tokens['error_description'] ?? $tokens['error']);
			throw new OidcException('oidc_provider_error', "GitHub token exchange failed: {$detail}");
		}
		$accessToken = isset($tokens['access_token']) ? (string) $tokens['access_token'] : '';
		if ($accessToken === '') {
			throw new OidcException('oidc_provider_error', 'GitHub token response has no access_token');
		}

		$user = $this->decodeResponse(
			$this->performHttpGet(self::API_BASE . '/user', $accessToken),
			'GitHub /user',
		);
		if (!isset($user['id']) || !is_int($user['id'])) {
			throw new OidcException('oidc_provider_error', 'GitHub /user response has no numeric id');
		}
		$login = isset($user['login']) ? (string) $user['login'] : '';
		$name = isset($user['name']) && is_string($user['name']) ? $user['name'] : '';

		$email = null;
		$emailVerified = false;
		$emails = $this->decodeResponse(
			$this->performHttpGet(self::API_BASE . '/user/emails', $accessToken),
			'GitHub /user/emails',
		);
		foreach ($emails as $item) {
			if (is_array($item) && ($item['primary'] ?? false) && ($item['verified'] ?? false)) {
				$email = (string) $item['email'];
				$emailVerified = true;
				break;
			}
		}

		return new OidcIdentity(
			OidcProviderConfig::GITHUB_ISSUER,
			(string) $user['id'],
			$email,
			$emailVerified,
			$name !== '' ? $name : ($login !== '' ? $login : null),
		);
	}

	/** @param array{statusCode: int, body: string, error: ?string} $response */
	private function decodeResponse(array $response, string $what): array
	{
		if (($response['error'] ?? null) !== null || (int) $response['statusCode'] !== 200) {
			$detail = $response['error'] ?? "HTTP {$response['statusCode']}";
			throw new OidcException('oidc_provider_error', "{$what} failed: {$detail}");
		}
		$decoded = json_decode((string) $response['body'], true);
		if (!is_array($decoded)) {
			throw new OidcException('oidc_provider_error', "{$what} returned non-JSON body");
		}
		return $decoded;
	}

	/**
	 * Jeden HTTP POST — protected seam pro testy (vzor OidcClient).
	 * GitHub bez User-Agent vrací 403.
	 *
	 * @return array{statusCode: int, body: string, error: ?string}
	 */
	protected function performHttpPost(string $url, array $fields): array
	{
		$this->assertAllowedUrl($url);

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($fields),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
			CURLOPT_HTTPHEADER     => [
				'Accept: application/json',
				'Content-Type: application/x-www-form-urlencoded',
				'User-Agent: shipard',
			],
		]);
		return $this->finishCurl($ch);
	}

	/**
	 * Jeden HTTP GET s Bearer tokenem — protected seam pro testy.
	 *
	 * @return array{statusCode: int, body: string, error: ?string}
	 */
	protected function performHttpGet(string $url, string $accessToken): array
	{
		$this->assertAllowedUrl($url);

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
			CURLOPT_HTTPHEADER     => [
				'Accept: application/json',
				'Authorization: Bearer ' . $accessToken,
				'User-Agent: shipard',
			],
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
		]);
		return $this->finishCurl($ch);
	}

	private function assertAllowedUrl(string $url): void
	{
		if (!OidcProviderConfig::isAllowedIssuerUrl($url)) {
			throw new OidcException('oidc_provider_error', "GitHub endpoint must be https: {$url}");
		}
	}

	/** @return array{statusCode: int, body: string, error: ?string} */
	private function finishCurl(\CurlHandle $ch): array
	{
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = $errno !== 0 ? curl_error($ch) : null;
		$statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		return [
			'statusCode' => $statusCode,
			'body'       => $body === false ? '' : (string) $body,
			'error'      => $error,
		];
	}
}
