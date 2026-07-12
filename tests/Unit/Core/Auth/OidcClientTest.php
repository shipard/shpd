<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\OidcClient;
use Shipard\Core\Auth\OidcDiscovery;
use Shipard\Core\Auth\OidcException;
use Shipard\Core\Auth\OidcProviderConfig;

/**
 * Fake discovery — servíruje fixture discovery dokument a JWKS bez HTTP
 * a bez file cache.
 */
class FixtureDiscovery extends OidcDiscovery
{
	public array $jwks = ['keys' => []];
	public ?array $refreshedJwks = null;
	public int $refreshCalls = 0;

	public function __construct(private readonly string $issuer)
	{
		parent::__construct(sys_get_temp_dir() . '/shpd-oidc-test-unused');
	}

	public function getDiscovery(string $issuer): array
	{
		return [
			'issuer'                 => $this->issuer,
			'authorization_endpoint' => $this->issuer . '/authorize',
			'token_endpoint'         => $this->issuer . '/token',
			'jwks_uri'               => $this->issuer . '/jwks',
		];
	}

	public function getJwks(string $issuer): array
	{
		return $this->jwks;
	}

	public function refreshJwks(string $issuer): ?array
	{
		$this->refreshCalls++;
		return $this->refreshedJwks;
	}
}

class OidcClientTest extends TestCase
{
	private const ISSUER = 'https://idp.example.com/realm';
	private const CLIENT_ID = 'shipard-client';
	private const KID = 'test-key-1';
	private const NONCE = 'nonce-abc123';

	private FixtureDiscovery $discovery;
	private OidcClient $client;
	private string $privateKey;

	protected function setUp(): void
	{
		$this->privateKey = (string) file_get_contents(__DIR__ . '/../../../Fixtures/oidc/rsa_private.pem');
		$this->discovery = new FixtureDiscovery(self::ISSUER);
		$this->discovery->jwks = ['keys' => [self::jwkFromPem($this->privateKey, self::KID)]];
		$this->client = new OidcClient($this->discovery);
	}

	private function provider(): OidcProviderConfig
	{
		return OidcProviderConfig::fromArray([
			'id'           => 'test',
			'issuer'       => self::ISSUER,
			'clientId'     => self::CLIENT_ID,
			'clientSecret' => 'secret',
		]);
	}

	private function claims(array $overrides = []): array
	{
		return array_merge([
			'iss'            => self::ISSUER,
			'aud'            => self::CLIENT_ID,
			'sub'            => 'user-42',
			'exp'            => time() + 300,
			'iat'            => time(),
			'nonce'          => self::NONCE,
			'email'          => 'jan@example.com',
			'email_verified' => true,
			'name'           => 'Jan Novák',
		], $overrides);
	}

	private function sign(array $claims, ?string $key = null, string $alg = 'RS256', ?string $kid = self::KID): string
	{
		return JWT::encode($claims, $key ?? $this->privateKey, $alg, $kid);
	}

	/** JWK (public) z PEM privátního klíče. */
	private static function jwkFromPem(string $privatePem, string $kid): array
	{
		$details = openssl_pkey_get_details(openssl_pkey_get_private($privatePem));
		$b64url = static fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
		return [
			'kty' => 'RSA',
			'use' => 'sig',
			'alg' => 'RS256',
			'kid' => $kid,
			'n'   => $b64url($details['rsa']['n']),
			'e'   => $b64url($details['rsa']['e']),
		];
	}

	// --- validateIdToken ---

	public function testValidTokenReturnsIdentity(): void
	{
		$jwt = $this->sign($this->claims());

		$identity = $this->client->validateIdToken($this->provider(), $jwt, self::NONCE);

		$this->assertSame(self::ISSUER, $identity->issuer);
		$this->assertSame('user-42', $identity->subject);
		$this->assertSame('jan@example.com', $identity->email);
		$this->assertTrue($identity->emailVerified);
		$this->assertSame('Jan Novák', $identity->name);
	}

	public function testAudienceAsArrayIsAccepted(): void
	{
		$jwt = $this->sign($this->claims(['aud' => ['other-client', self::CLIENT_ID]]));

		$identity = $this->client->validateIdToken($this->provider(), $jwt, self::NONCE);

		$this->assertSame('user-42', $identity->subject);
	}

	public function testStringEmailVerifiedIsParsed(): void
	{
		$jwt = $this->sign($this->claims(['email_verified' => 'true']));

		$this->assertTrue($this->client->validateIdToken($this->provider(), $jwt, self::NONCE)->emailVerified);

		$jwt2 = $this->sign($this->claims(['email_verified' => 'false']));
		$this->assertFalse($this->client->validateIdToken($this->provider(), $jwt2, self::NONCE)->emailVerified);
	}

	public function testWrongIssuerIsRejected(): void
	{
		$jwt = $this->sign($this->claims(['iss' => 'https://evil.example.com']));

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('issuer mismatch');
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testWrongAudienceIsRejected(): void
	{
		$jwt = $this->sign($this->claims(['aud' => 'someone-else']));

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('audience mismatch');
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testWrongNonceIsRejected(): void
	{
		$jwt = $this->sign($this->claims(['nonce' => 'different-nonce']));

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('nonce mismatch');
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testExpiredTokenIsRejected(): void
	{
		$jwt = $this->sign($this->claims(['exp' => time() - 300, 'iat' => time() - 600]));

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('validation failed');
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testExpiredWithinLeewayIsAccepted(): void
	{
		$jwt = $this->sign($this->claims(['exp' => time() - 30]));

		$identity = $this->client->validateIdToken($this->provider(), $jwt, self::NONCE);

		$this->assertSame('user-42', $identity->subject);
	}

	public function testAlgNoneIsRejected(): void
	{
		$b64url = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
		$jwt = $b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']))
			. '.' . $b64url(json_encode($this->claims()))
			. '.';

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage("disallowed algorithm 'none'");
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testHs256IsRejected(): void
	{
		$jwt = JWT::encode($this->claims(), str_repeat('shared-secret-32-bytes-minimum!!', 2), 'HS256', self::KID);

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage("disallowed algorithm 'HS256'");
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testUnknownKidTriggersJwksRefreshAndSucceedsWithRotatedKeys(): void
	{
		$otherKey = (string) file_get_contents(__DIR__ . '/../../../Fixtures/oidc/rsa_private_other.pem');
		$jwt = $this->sign($this->claims(), $otherKey, 'RS256', 'rotated-key');
		// Refresh vrátí JWKS s novým klíčem — rotace u IdP.
		$this->discovery->refreshedJwks = ['keys' => [self::jwkFromPem($otherKey, 'rotated-key')]];

		$identity = $this->client->validateIdToken($this->provider(), $jwt, self::NONCE);

		$this->assertSame(1, $this->discovery->refreshCalls);
		$this->assertSame('user-42', $identity->subject);
	}

	public function testUnknownKidWithThrottledRefreshIsRejected(): void
	{
		$otherKey = (string) file_get_contents(__DIR__ . '/../../../Fixtures/oidc/rsa_private_other.pem');
		$jwt = $this->sign($this->claims(), $otherKey, 'RS256', 'rotated-key');
		$this->discovery->refreshedJwks = null; // throttle

		$this->expectException(OidcException::class);
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testTamperedSignatureIsRejected(): void
	{
		$otherKey = (string) file_get_contents(__DIR__ . '/../../../Fixtures/oidc/rsa_private_other.pem');
		// Podepsáno cizím klíčem, ale hlásí se známým kid.
		$jwt = $this->sign($this->claims(), $otherKey, 'RS256', self::KID);

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('validation failed');
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	public function testMissingExpIsRejected(): void
	{
		$claims = $this->claims();
		unset($claims['exp']);
		$jwt = $this->sign($claims);

		$this->expectException(OidcException::class);
		$this->client->validateIdToken($this->provider(), $jwt, self::NONCE);
	}

	// --- buildAuthorizeUrl ---

	public function testBuildAuthorizeUrlContainsAllParams(): void
	{
		$url = $this->client->buildAuthorizeUrl(
			$this->provider(),
			'state-1',
			'nonce-1',
			'challenge-1',
			'https://app.example.com/api/v1/_auth/oidc/callback',
		);

		$this->assertStringStartsWith(self::ISSUER . '/authorize?', $url);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
		$this->assertSame('code', $params['response_type']);
		$this->assertSame(self::CLIENT_ID, $params['client_id']);
		$this->assertSame('https://app.example.com/api/v1/_auth/oidc/callback', $params['redirect_uri']);
		$this->assertSame('openid profile email', $params['scope']);
		$this->assertSame('state-1', $params['state']);
		$this->assertSame('nonce-1', $params['nonce']);
		$this->assertSame('challenge-1', $params['code_challenge']);
		$this->assertSame('S256', $params['code_challenge_method']);
	}
}
