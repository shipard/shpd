<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\GithubOauthClient;
use Shipard\Core\Auth\OidcException;
use Shipard\Core\Auth\OidcProviderConfig;

/** GithubOauthClient bez HTTP — skriptované odpovědi per URL prefix. */
class ScriptedGithubClient extends GithubOauthClient
{
	/** @var array<string, array{statusCode: int, body: string, error: ?string}> */
	public array $responses = [];
	public array $capturedPosts = [];
	public array $capturedGets = [];

	public function scriptJson(string $urlPrefix, array $payload, int $status = 200): void
	{
		$this->responses[$urlPrefix] = [
			'statusCode' => $status,
			'body'       => json_encode($payload),
			'error'      => null,
		];
	}

	private function lookup(string $url): array
	{
		foreach ($this->responses as $prefix => $response) {
			if (str_starts_with($url, $prefix)) {
				return $response;
			}
		}
		throw new \LogicException("ScriptedGithubClient: no response scripted for {$url}");
	}

	protected function performHttpPost(string $url, array $fields): array
	{
		$this->capturedPosts[] = ['url' => $url, 'fields' => $fields];
		return $this->lookup($url);
	}

	protected function performHttpGet(string $url, string $accessToken): array
	{
		$this->capturedGets[] = ['url' => $url, 'token' => $accessToken];
		return $this->lookup($url);
	}
}

class GithubOauthClientTest extends TestCase
{
	private ScriptedGithubClient $client;
	private OidcProviderConfig $provider;

	protected function setUp(): void
	{
		$this->client = new ScriptedGithubClient();
		$this->provider = OidcProviderConfig::fromArray([
			'id'           => 'github',
			'label'        => 'GitHub',
			'kind'         => 'github',
			'clientId'     => 'cid',
			'clientSecret' => 'secret',
		]);
	}

	public function testBuildAuthorizeUrl(): void
	{
		$url = $this->client->buildAuthorizeUrl($this->provider, 'state-1', 'https://ds.example.com/api/v1/_auth/oidc/callback');

		$this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
		$this->assertSame('cid', $params['client_id']);
		$this->assertSame('state-1', $params['state']);
		$this->assertSame('read:user user:email', $params['scope']);
		$this->assertSame('https://ds.example.com/api/v1/_auth/oidc/callback', $params['redirect_uri']);
		// Bez PKCE a nonce — GitHub OAuth apps je nepodporují.
		$this->assertArrayNotHasKey('code_challenge', $params);
		$this->assertArrayNotHasKey('nonce', $params);
	}

	public function testFetchIdentityHappyPath(): void
	{
		$this->client->scriptJson('https://github.com/login/oauth/access_token', ['access_token' => 'gho_token']);
		$this->client->scriptJson('https://api.github.com/user/emails', [
			['email' => 'stary@example.com', 'primary' => false, 'verified' => true],
			['email' => 'jan@example.com', 'primary' => true, 'verified' => true],
		]);
		$this->client->scriptJson('https://api.github.com/user', ['id' => 123456, 'login' => 'jannovak', 'name' => 'Jan Novák']);

		$identity = $this->client->fetchIdentity($this->provider, 'authcode', 'https://ds.example.com/cb');

		$this->assertSame('https://github.com', $identity->issuer);
		$this->assertSame('123456', $identity->subject);
		$this->assertSame('jan@example.com', $identity->email);
		$this->assertTrue($identity->emailVerified);
		$this->assertSame('Jan Novák', $identity->name);

		// Token exchange nese client credentials + code, API volání Bearer token.
		$this->assertSame('authcode', $this->client->capturedPosts[0]['fields']['code']);
		$this->assertSame('gho_token', $this->client->capturedGets[0]['token']);
	}

	public function testFetchIdentityWithoutVerifiedPrimaryEmail(): void
	{
		$this->client->scriptJson('https://github.com/login/oauth/access_token', ['access_token' => 'gho_token']);
		$this->client->scriptJson('https://api.github.com/user/emails', [
			['email' => 'neoveren@example.com', 'primary' => true, 'verified' => false],
		]);
		$this->client->scriptJson('https://api.github.com/user', ['id' => 7, 'login' => 'someone', 'name' => null]);

		$identity = $this->client->fetchIdentity($this->provider, 'authcode', 'https://ds.example.com/cb');

		$this->assertNull($identity->email);
		$this->assertFalse($identity->emailVerified);
		// name null → fallback na login.
		$this->assertSame('someone', $identity->name);
	}

	public function testFetchIdentityTokenErrorThrows(): void
	{
		// GitHub vrací chybu s HTTP 200 a error polem v těle.
		$this->client->scriptJson('https://github.com/login/oauth/access_token', [
			'error'             => 'bad_verification_code',
			'error_description' => 'The code passed is incorrect or expired.',
		]);

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('The code passed is incorrect');
		try {
			$this->client->fetchIdentity($this->provider, 'bad-code', 'https://ds.example.com/cb');
		} catch (OidcException $e) {
			$this->assertSame('oidc_provider_error', $e->errorCode);
			throw $e;
		}
	}

	public function testFetchIdentityHttpFailureThrows(): void
	{
		$this->client->responses['https://github.com/login/oauth/access_token'] = [
			'statusCode' => 503,
			'body'       => '',
			'error'      => null,
		];

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('HTTP 503');
		$this->client->fetchIdentity($this->provider, 'code', 'https://ds.example.com/cb');
	}

	public function testFetchIdentityMissingUserIdThrows(): void
	{
		$this->client->scriptJson('https://github.com/login/oauth/access_token', ['access_token' => 'gho_token']);
		$this->client->scriptJson('https://api.github.com/user', ['login' => 'ghost']);

		$this->expectException(OidcException::class);
		$this->expectExceptionMessage('no numeric id');
		$this->client->fetchIdentity($this->provider, 'code', 'https://ds.example.com/cb');
	}
}
