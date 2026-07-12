<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\AuthPolicy;
use Shipard\Core\Auth\OidcProviderConfig;

class AuthPolicyTest extends TestCase
{
	private function providerData(array $overrides = []): array
	{
		return array_merge([
			'id'           => 'entra',
			'label'        => 'Přihlásit přes Firma a.s.',
			'issuer'       => 'https://login.example.com/tenant/v2.0',
			'clientId'     => 'client-123',
			'clientSecret' => 'secret-456',
		], $overrides);
	}

	// --- Defaults ---

	public function testEmptyConfigMeansLocalOnlyPolicy(): void
	{
		$policy = AuthPolicy::fromArray([]);

		$this->assertTrue($policy->localLogin);
		$this->assertSame([], $policy->providers);
	}

	public function testProviderDefaults(): void
	{
		$policy = AuthPolicy::fromArray(['providers' => [$this->providerData()]]);
		$provider = $policy->providers[0];

		$this->assertSame(['openid', 'profile', 'email'], $provider->scopes);
		$this->assertFalse($provider->autoLinkEmail);
		$this->assertFalse($provider->jitProvision);
	}

	public function testProviderLabelFallsBackToId(): void
	{
		$data = $this->providerData();
		unset($data['label']);
		$policy = AuthPolicy::fromArray(['providers' => [$data]]);

		$this->assertSame('entra', $policy->providers[0]->label);
	}

	public function testIssuerTrailingSlashIsStripped(): void
	{
		$policy = AuthPolicy::fromArray(['providers' => [
			$this->providerData(['issuer' => 'https://idp.example.com/realm/']),
		]]);

		$this->assertSame('https://idp.example.com/realm', $policy->providers[0]->issuer);
	}

	public function testExplicitValuesAreParsed(): void
	{
		$policy = AuthPolicy::fromArray([
			'local'     => false,
			'providers' => [$this->providerData([
				'scopes'        => ['openid', 'email'],
				'autoLinkEmail' => true,
				'jitProvision'  => true,
			])],
		]);

		$this->assertFalse($policy->localLogin);
		$provider = $policy->providers[0];
		$this->assertInstanceOf(OidcProviderConfig::class, $provider);
		$this->assertSame(['openid', 'email'], $provider->scopes);
		$this->assertTrue($provider->autoLinkEmail);
		$this->assertTrue($provider->jitProvision);
	}

	public function testGetProvider(): void
	{
		$policy = AuthPolicy::fromArray(['providers' => [$this->providerData()]]);

		$this->assertSame('entra', $policy->getProvider('entra')?->id);
		$this->assertNull($policy->getProvider('unknown'));
	}

	// --- Validation failures ---

	public function testLocalFalseWithoutProvidersIsRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("'local: false'");
		AuthPolicy::fromArray(['local' => false]);
	}

	public function testDuplicateProviderIdsAreRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('duplicate provider id');
		AuthPolicy::fromArray(['providers' => [$this->providerData(), $this->providerData()]]);
	}

	public function testMissingProviderIdIsRejected(): void
	{
		$data = $this->providerData();
		unset($data['id']);
		$this->expectException(\RuntimeException::class);
		AuthPolicy::fromArray(['providers' => [$data]]);
	}

	public function testInvalidProviderIdPatternIsRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('[a-z0-9-]+');
		AuthPolicy::fromArray(['providers' => [$this->providerData(['id' => 'Entra ID'])]]);
	}

	public function testNonHttpsIssuerIsRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('https URL');
		AuthPolicy::fromArray(['providers' => [$this->providerData(['issuer' => 'http://idp.example.com'])]]);
	}

	public function testHttpLocalhostIssuerIsAllowedForDev(): void
	{
		$policy = AuthPolicy::fromArray(['providers' => [
			$this->providerData(['issuer' => 'http://localhost:8080/realms/shipard-dev']),
		]]);

		$this->assertSame('http://localhost:8080/realms/shipard-dev', $policy->providers[0]->issuer);
	}

	public function testMissingClientIdIsRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('clientId');
		AuthPolicy::fromArray(['providers' => [$this->providerData(['clientId' => ''])]]);
	}

	public function testMissingClientSecretIsRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('clientSecret');
		AuthPolicy::fromArray(['providers' => [$this->providerData(['clientSecret' => ''])]]);
	}

	public function testEmptyScopesAreRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('scopes');
		AuthPolicy::fromArray(['providers' => [$this->providerData(['scopes' => []])]]);
	}
}
