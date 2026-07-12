<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\IdentityMapper;
use Shipard\Core\Auth\OidcException;
use Shipard\Core\Auth\OidcIdentity;
use Shipard\Core\Auth\OidcProviderConfig;
use Shipard\Tests\Fixtures\Core\Auth\InMemoryAuthDb;

class IdentityMapperTest extends TestCase
{
	private const ISSUER = 'https://idp.example.com/realm';

	private InMemoryAuthDb $db;
	private IdentityMapper $mapper;

	protected function setUp(): void
	{
		$this->db = InMemoryAuthDb::create();
		$this->mapper = new IdentityMapper();
	}

	private function provider(bool $autoLinkEmail = false, bool $jitProvision = false): OidcProviderConfig
	{
		return OidcProviderConfig::fromArray([
			'id'            => 'test',
			'issuer'        => self::ISSUER,
			'clientId'      => 'cid',
			'clientSecret'  => 'secret',
			'autoLinkEmail' => $autoLinkEmail,
			'jitProvision'  => $jitProvision,
		]);
	}

	private function identity(array $overrides = []): OidcIdentity
	{
		$data = array_merge([
			'issuer'        => self::ISSUER,
			'subject'       => 'user-42',
			'email'         => 'jan@example.com',
			'emailVerified' => true,
			'name'          => 'Jan Novák',
		], $overrides);
		return new OidcIdentity($data['issuer'], $data['subject'], $data['email'], $data['emailVerified'], $data['name']);
	}

	private function expectCode(string $code): void
	{
		try {
			$this->mapper->resolve($this->identity(), $this->provider(), $this->db);
			$this->fail('Expected OidcException');
		} catch (OidcException $e) {
			$this->assertSame($code, $e->errorCode);
		}
	}

	// --- Existující identita ---

	public function testExistingIdentityResolvesAndUpdatesLastLogin(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1]);
		$identityId = $this->db->addIdentity([
			'user_id' => $userId,
			'issuer'  => self::ISSUER,
			'subject' => 'user-42',
		]);

		$resolved = $this->mapper->resolve($this->identity(), $this->provider(), $this->db);

		$this->assertSame($userId, $resolved);
		$this->assertNotNull($this->db->identities[$identityId]['last_login']);
	}

	public function testExistingIdentityWithInactiveUserThrows(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 0]);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => self::ISSUER, 'subject' => 'user-42']);

		$this->expectCode('oidc_account_inactive');
	}

	public function testExistingIdentityMatchesEvenAfterEmailChange(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'stara@example.com', 'is_active' => 1]);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => self::ISSUER, 'subject' => 'user-42']);

		$resolved = $this->mapper->resolve(
			$this->identity(['email' => 'nova@example.com']),
			$this->provider(),
			$this->db,
		);

		$this->assertSame($userId, $resolved);
	}

	// --- Auto-link podle e-mailu ---

	public function testAutoLinkWithExactlyOneEmailMatchCreatesIdentity(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1]);

		$resolved = $this->mapper->resolve($this->identity(), $this->provider(autoLinkEmail: true), $this->db);

		$this->assertSame($userId, $resolved);
		$this->assertCount(1, $this->db->identities);
		$link = array_values($this->db->identities)[0];
		$this->assertSame($userId, $link['user_id']);
		$this->assertSame('user-42', $link['subject']);
		$this->assertSame('jan@example.com', $link['email_at_link']);
	}

	public function testAmbiguousEmailThrows(): void
	{
		$this->db->addUser(['login' => 'jan1', 'email' => 'jan@example.com', 'is_active' => 1]);
		$this->db->addUser(['login' => 'jan2', 'email' => 'jan@example.com', 'is_active' => 1]);

		try {
			$this->mapper->resolve($this->identity(), $this->provider(autoLinkEmail: true), $this->db);
			$this->fail('Expected OidcException');
		} catch (OidcException $e) {
			$this->assertSame('oidc_email_ambiguous', $e->errorCode);
		}
		$this->assertCount(0, $this->db->identities);
	}

	public function testUnverifiedEmailNeverAutoLinks(): void
	{
		$this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1]);

		try {
			$this->mapper->resolve(
				$this->identity(['emailVerified' => false]),
				$this->provider(autoLinkEmail: true),
				$this->db,
			);
			$this->fail('Expected OidcException');
		} catch (OidcException $e) {
			$this->assertSame('oidc_no_account', $e->errorCode);
		}
		$this->assertCount(0, $this->db->identities);
	}

	public function testAutoLinkDisabledThrowsNoAccount(): void
	{
		$this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1]);

		$this->expectCode('oidc_no_account');
	}

	// --- JIT provisioning ---

	public function testJitOffWithNoMatchThrowsNoAccount(): void
	{
		try {
			$this->mapper->resolve($this->identity(), $this->provider(autoLinkEmail: true), $this->db);
			$this->fail('Expected OidcException');
		} catch (OidcException $e) {
			$this->assertSame('oidc_no_account', $e->errorCode);
		}
	}

	public function testJitOnCreatesUserWithoutPasswordAndLinksIdentity(): void
	{
		$resolved = $this->mapper->resolve(
			$this->identity(),
			$this->provider(autoLinkEmail: true, jitProvision: true),
			$this->db,
		);

		$user = $this->db->users[$resolved];
		$this->assertSame('jan@example.com', $user['login']);
		$this->assertNull($user['password_hash']);
		$this->assertSame('Jan Novák', $user['full_name']);
		$this->assertSame(1, $user['is_active']);
		$this->assertCount(1, $this->db->identities);
	}

	public function testJitWithoutNameClaimFallsBackToEmail(): void
	{
		$resolved = $this->mapper->resolve(
			$this->identity(['name' => null]),
			$this->provider(autoLinkEmail: true, jitProvision: true),
			$this->db,
		);

		$this->assertSame('jan@example.com', $this->db->users[$resolved]['full_name']);
	}

	public function testJitLoginConflictThrows(): void
	{
		// Login existuje, ale je neaktivní — e-mail lookup ho nenajde,
		// unique login by kolidoval.
		$this->db->addUser(['login' => 'jan@example.com', 'email' => 'jan@example.com', 'is_active' => 0]);

		try {
			$this->mapper->resolve(
				$this->identity(),
				$this->provider(autoLinkEmail: true, jitProvision: true),
				$this->db,
			);
			$this->fail('Expected OidcException');
		} catch (OidcException $e) {
			$this->assertSame('oidc_login_conflict', $e->errorCode);
		}
	}

	public function testMissingEmailClaimThrowsNoAccount(): void
	{
		try {
			$this->mapper->resolve(
				$this->identity(['email' => null]),
				$this->provider(autoLinkEmail: true, jitProvision: true),
				$this->db,
			);
			$this->fail('Expected OidcException');
		} catch (OidcException $e) {
			$this->assertSame('oidc_no_account', $e->errorCode);
		}
	}
}
