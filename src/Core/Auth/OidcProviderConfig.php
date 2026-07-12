<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Konfigurace jednoho OIDC providera z `config/main.json` klíče `auth.providers[]`.
 * `clientSecret` se nikdy nesmí dostat do API odpovědí ani logů.
 */
final readonly class OidcProviderConfig
{
	private const array DEFAULT_SCOPES = ['openid', 'profile', 'email'];

	/** @param string[] $scopes */
	public function __construct(
		public string $id,
		public string $label,
		public string $issuer,
		public string $clientId,
		public string $clientSecret,
		public array $scopes = self::DEFAULT_SCOPES,
		public bool $autoLinkEmail = false,
		public bool $jitProvision = false,
	) {
		if (!preg_match('/^[a-z0-9-]+$/', $this->id)) {
			throw new \RuntimeException("Auth policy: provider id '{$this->id}' must match [a-z0-9-]+");
		}
		if (!self::isAllowedIssuerUrl($this->issuer)) {
			throw new \RuntimeException("Auth policy: provider '{$this->id}' issuer must be an https URL");
		}
		if ($this->clientId === '') {
			throw new \RuntimeException("Auth policy: provider '{$this->id}' is missing clientId");
		}
		if ($this->clientSecret === '') {
			throw new \RuntimeException("Auth policy: provider '{$this->id}' is missing clientSecret");
		}
	}

	/**
	 * https vždy; http výhradně pro localhost/127.0.0.1 — vývoj proti
	 * dockerovému Keycloaku/Zitadel bez TLS proxy. Stejné pravidlo vynucují
	 * OidcDiscovery a OidcClient na skutečných HTTP voláních.
	 */
	public static function isAllowedIssuerUrl(string $url): bool
	{
		if (str_starts_with($url, 'https://')) {
			return true;
		}
		if (!str_starts_with($url, 'http://')) {
			return false;
		}
		$host = (string) parse_url($url, PHP_URL_HOST);
		return in_array($host, ['localhost', '127.0.0.1'], true);
	}

	public static function fromArray(array $data): self
	{
		$id = isset($data['id']) ? (string) $data['id'] : '';
		if ($id === '') {
			throw new \RuntimeException('Auth policy: every provider needs a non-empty id');
		}

		$scopes = $data['scopes'] ?? self::DEFAULT_SCOPES;
		if (!is_array($scopes) || $scopes === []) {
			throw new \RuntimeException("Auth policy: provider '{$id}' scopes must be a non-empty array");
		}

		return new self(
			id: $id,
			label: isset($data['label']) && (string) $data['label'] !== '' ? (string) $data['label'] : $id,
			issuer: isset($data['issuer']) ? rtrim((string) $data['issuer'], '/') : '',
			clientId: isset($data['clientId']) ? (string) $data['clientId'] : '',
			clientSecret: isset($data['clientSecret']) ? (string) $data['clientSecret'] : '',
			scopes: array_values(array_map(strval(...), $scopes)),
			autoLinkEmail: (bool) ($data['autoLinkEmail'] ?? false),
			jitProvision: (bool) ($data['jitProvision'] ?? false),
		);
	}
}
