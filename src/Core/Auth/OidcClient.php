<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * OIDC klient (relying party): authorize URL, výměna kódu (PKCE +
 * client_secret_post) a validace `id_token` proti JWKS providera.
 */
class OidcClient
{
	/** Povolené podpisové algoritmy — nikdy `none` ani symetrické HS*. */
	private const array ALLOWED_ALGS = ['RS256', 'ES256'];
	private const int CLOCK_LEEWAY_SECONDS = 60;
	private const int TIMEOUT_SECONDS = 10;
	private const int CONNECT_TIMEOUT_SECONDS = 5;

	public function __construct(private readonly OidcDiscovery $discovery)
	{
	}

	public function buildAuthorizeUrl(
		OidcProviderConfig $provider,
		string $state,
		string $nonce,
		string $codeChallenge,
		string $redirectUri,
	): string {
		$doc = $this->discovery->getDiscovery($provider->issuer);

		$params = http_build_query([
			'response_type'         => 'code',
			'client_id'             => $provider->clientId,
			'redirect_uri'          => $redirectUri,
			'scope'                 => implode(' ', $provider->scopes),
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => $codeChallenge,
			'code_challenge_method' => 'S256',
		]);

		$endpoint = $doc['authorization_endpoint'];
		return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $params;
	}

	/** @return array Token response providera (obsahuje `id_token`). */
	public function exchangeCode(
		OidcProviderConfig $provider,
		string $code,
		string $codeVerifier,
		string $redirectUri,
	): array {
		$doc = $this->discovery->getDiscovery($provider->issuer);

		$response = $this->performHttpPost($doc['token_endpoint'], [
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $redirectUri,
			'client_id'     => $provider->clientId,
			'client_secret' => $provider->clientSecret,
			'code_verifier' => $codeVerifier,
		]);

		if (($response['error'] ?? null) !== null || (int) $response['statusCode'] !== 200) {
			$detail = $response['error'] ?? "HTTP {$response['statusCode']}";
			throw new OidcException('oidc_provider_error', "OIDC code exchange failed: {$detail}");
		}

		$tokens = json_decode((string) $response['body'], true);
		if (!is_array($tokens) || !isset($tokens['id_token']) || !is_string($tokens['id_token'])) {
			throw new OidcException('oidc_provider_error', 'OIDC token response has no id_token');
		}
		return $tokens;
	}

	public function validateIdToken(OidcProviderConfig $provider, string $jwt, string $expectedNonce): OidcIdentity
	{
		$header = $this->decodeHeader($jwt);

		$alg = isset($header['alg']) ? (string) $header['alg'] : '';
		if (!in_array($alg, self::ALLOWED_ALGS, true)) {
			throw new OidcException('oidc_provider_error', "OIDC id_token uses disallowed algorithm '{$alg}'");
		}

		$jwks = $this->discovery->getJwks($provider->issuer);
		$kid = isset($header['kid']) ? (string) $header['kid'] : null;
		$keys = JWK::parseKeySet($jwks, $alg);

		// Neznámý kid = pravděpodobně rotace klíčů u IdP → vynucený refresh
		// JWKS (throttlovaný v OidcDiscovery na 1×/5 min).
		if ($kid !== null && !isset($keys[$kid])) {
			$fresh = $this->discovery->refreshJwks($provider->issuer);
			if ($fresh !== null) {
				$keys = JWK::parseKeySet($fresh, $alg);
			}
		}

		$previousLeeway = JWT::$leeway;
		JWT::$leeway = self::CLOCK_LEEWAY_SECONDS;
		try {
			$payload = (array) JWT::decode($jwt, $keys);
		} catch (\Throwable $e) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token validation failed: ' . $e->getMessage());
		} finally {
			JWT::$leeway = $previousLeeway;
		}

		$doc = $this->discovery->getDiscovery($provider->issuer);
		if (($payload['iss'] ?? null) !== $doc['issuer']) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token issuer mismatch');
		}

		$aud = $payload['aud'] ?? null;
		$audiences = is_array($aud) ? $aud : [$aud];
		if (!in_array($provider->clientId, $audiences, true)) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token audience mismatch');
		}

		if (!isset($payload['exp'])) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token has no expiration');
		}

		if (($payload['nonce'] ?? null) !== $expectedNonce) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token nonce mismatch');
		}

		$subject = isset($payload['sub']) ? (string) $payload['sub'] : '';
		if ($subject === '') {
			throw new OidcException('oidc_provider_error', 'OIDC id_token has no subject');
		}

		$email = isset($payload['email']) && (string) $payload['email'] !== ''
			? (string) $payload['email']
			: null;
		$name = isset($payload['name']) && (string) $payload['name'] !== ''
			? (string) $payload['name']
			: null;

		return new OidcIdentity(
			issuer: (string) $payload['iss'],
			subject: $subject,
			email: $email,
			// Někteří IdP posílají bool, jiní string "true".
			emailVerified: (bool) filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
			name: $name,
		);
	}

	private function decodeHeader(string $jwt): array
	{
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token is not a valid JWT');
		}
		$header = json_decode(
			(string) base64_decode(strtr($parts[0], '-_', '+/'), false),
			true,
		);
		if (!is_array($header)) {
			throw new OidcException('oidc_provider_error', 'OIDC id_token header is not valid JSON');
		}
		return $header;
	}

	/**
	 * Jeden HTTP POST (form-encoded) — protected seam pro testy.
	 *
	 * @return array{statusCode: int, body: string, error: ?string}
	 */
	protected function performHttpPost(string $url, array $fields): array
	{
		// https vždy; http jen pro localhost (dev Keycloak) — viz
		// OidcProviderConfig::isAllowedIssuerUrl().
		if (!OidcProviderConfig::isAllowedIssuerUrl($url)) {
			throw new OidcException('oidc_provider_error', "OIDC endpoint must be https: {$url}");
		}

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
			],
		]);
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
