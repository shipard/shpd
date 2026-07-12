<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * OIDC discovery dokument + JWKS s file cache per issuer
 * (`{ds}/cache/oidc/{sha256(issuer)}.discovery.json` / `.jwks.json`).
 *
 * Discovery se cachuje 24 h. JWKS se čte z cache bez TTL a refreshuje se
 * vynuceně při neznámém `kid` (rotace klíčů u IdP), ale nejvýš jednou za
 * 5 minut — ochrana proti DoS podvrženými `kid` v tokenech.
 */
class OidcDiscovery
{
	private const int DISCOVERY_TTL_SECONDS = 86400;
	private const int JWKS_REFRESH_MIN_INTERVAL_SECONDS = 300;
	private const int TIMEOUT_SECONDS = 10;
	private const int CONNECT_TIMEOUT_SECONDS = 5;

	public function __construct(private readonly string $cacheDir)
	{
	}

	/**
	 * Discovery dokument issueru. Vyžaduje `issuer`, `authorization_endpoint`,
	 * `token_endpoint` a `jwks_uri`; `issuer` musí odpovídat konfiguraci.
	 */
	public function getDiscovery(string $issuer): array
	{
		$cacheFile = $this->cacheFile($issuer, 'discovery');
		$cached = $this->readCache($cacheFile, self::DISCOVERY_TTL_SECONDS);
		if ($cached !== null) {
			return $cached;
		}

		$doc = $this->fetchJson(rtrim($issuer, '/') . '/.well-known/openid-configuration');

		foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
			if (!isset($doc[$key]) || !is_string($doc[$key]) || $doc[$key] === '') {
				throw new OidcException('oidc_provider_error', "OIDC discovery document is missing '{$key}'");
			}
		}
		if (rtrim($doc['issuer'], '/') !== rtrim($issuer, '/')) {
			throw new OidcException(
				'oidc_provider_error',
				"OIDC discovery issuer mismatch: expected '{$issuer}', got '{$doc['issuer']}'",
			);
		}

		$this->writeCache($cacheFile, $doc);
		return $doc;
	}

	/** JWKS issueru — z cache, chybějící cache se stáhne. */
	public function getJwks(string $issuer): array
	{
		$cacheFile = $this->cacheFile($issuer, 'jwks');
		$cached = $this->readCache($cacheFile, null);
		if ($cached !== null) {
			return $cached;
		}
		return $this->fetchAndCacheJwks($issuer);
	}

	/**
	 * Vynucený refresh JWKS (neznámý `kid`). Vrací čerstvý JWKS, nebo null
	 * když byl poslední refresh před méně než 5 minutami (anti-DoS throttle).
	 */
	public function refreshJwks(string $issuer): ?array
	{
		$cacheFile = $this->cacheFile($issuer, 'jwks');
		if (is_file($cacheFile)
			&& time() - (int) filemtime($cacheFile) < self::JWKS_REFRESH_MIN_INTERVAL_SECONDS) {
			return null;
		}
		return $this->fetchAndCacheJwks($issuer);
	}

	private function fetchAndCacheJwks(string $issuer): array
	{
		$doc = $this->getDiscovery($issuer);
		$jwks = $this->fetchJson($doc['jwks_uri']);
		if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
			throw new OidcException('oidc_provider_error', 'OIDC JWKS response has no keys');
		}
		$this->writeCache($this->cacheFile($issuer, 'jwks'), $jwks);
		return $jwks;
	}

	private function cacheFile(string $issuer, string $kind): string
	{
		return $this->cacheDir . '/' . hash('sha256', rtrim($issuer, '/')) . ".{$kind}.json";
	}

	private function readCache(string $file, ?int $ttlSeconds): ?array
	{
		if (!is_file($file)) {
			return null;
		}
		if ($ttlSeconds !== null && time() - (int) filemtime($file) >= $ttlSeconds) {
			return null;
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : null;
	}

	private function writeCache(string $file, array $data): void
	{
		if (!is_dir($this->cacheDir)) {
			@mkdir($this->cacheDir, 0750, true);
		}
		@file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
	}

	private function fetchJson(string $url): array
	{
		if (!str_starts_with($url, 'https://')) {
			throw new OidcException('oidc_provider_error', "OIDC endpoint must be https: {$url}");
		}

		$response = $this->performHttpGet($url);

		if (($response['error'] ?? null) !== null || (int) $response['statusCode'] !== 200) {
			$detail = $response['error'] ?? "HTTP {$response['statusCode']}";
			throw new OidcException('oidc_provider_error', "OIDC fetch failed for {$url}: {$detail}");
		}

		$decoded = json_decode((string) $response['body'], true);
		if (!is_array($decoded)) {
			throw new OidcException('oidc_provider_error', "OIDC endpoint returned non-JSON body: {$url}");
		}
		return $decoded;
	}

	/**
	 * Jeden HTTP GET — protected seam pro testy (vzor PersonsRegistryClient).
	 *
	 * @return array{statusCode: int, body: string, error: ?string}
	 */
	protected function performHttpGet(string $url): array
	{
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
			CURLOPT_HTTPHEADER     => ['Accept: application/json'],
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
		]);
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = $errno !== 0 ? curl_error($ch) : null;
		$statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return [
			'statusCode' => $statusCode,
			'body'       => $body === false ? '' : (string) $body,
			'error'      => $error,
		];
	}
}
