<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\AuthTokenService;
use Shipard\Tests\Fixtures\Core\Auth\InMemoryAuthDb;

class AuthTokenServiceTest extends TestCase
{
	private InMemoryAuthDb $db;
	private AuthTokenService $service;

	protected function setUp(): void
	{
		$this->db = InMemoryAuthDb::create();
		$this->service = new AuthTokenService($this->db);
	}

	public function testIssueStoresOnlyHash(): void
	{
		$plaintext = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);

		$this->assertStringStartsWith('shpd_pt_', $plaintext);
		$this->assertCount(1, $this->db->authTokens);

		$row = array_values($this->db->authTokens)[0];
		$this->assertSame(hash('sha256', $plaintext), $row['token_hash']);
		$this->assertSame(7, $row['user_id']);
		$this->assertSame('password_reset', $row['purpose']);
		$this->assertNull($row['used_at']);
		$this->assertStringNotContainsString($plaintext, json_encode($row));
	}

	public function testValidateReturnsUserIdWithoutBurningToken(): void
	{
		$token = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);
		$purposes = [AuthTokenService::PURPOSE_PASSWORD_RESET];

		$this->assertSame(7, $this->service->validate($token, $purposes));
		$this->assertSame(7, $this->service->validate($token, $purposes));
		// Token není spálený — consume po validate projde.
		$this->assertSame(7, $this->service->consume($token, $purposes));
	}

	public function testValidateRejectsPurposeMismatch(): void
	{
		$token = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);

		$this->assertNull($this->service->validate($token, ['nonexistent_purpose']));
	}

	public function testValidateAcceptsAnyListedPurpose(): void
	{
		$token = $this->service->issue(7, AuthTokenService::PURPOSE_INVITE, 3600);
		$purposes = [AuthTokenService::PURPOSE_PASSWORD_RESET, AuthTokenService::PURPOSE_INVITE];

		$this->assertSame(7, $this->service->validate($token, $purposes));
	}

	public function testConsumeIsSingleUse(): void
	{
		$token = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);
		$purposes = [AuthTokenService::PURPOSE_PASSWORD_RESET];

		$this->assertSame(7, $this->service->consume($token, $purposes));
		$this->assertNull($this->service->consume($token, $purposes));
		$this->assertNull($this->service->validate($token, $purposes));
	}

	public function testExpiredTokenIsRejected(): void
	{
		$token = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, -60);
		$purposes = [AuthTokenService::PURPOSE_PASSWORD_RESET];

		$this->assertNull($this->service->validate($token, $purposes));
		$this->assertNull($this->service->consume($token, $purposes));
	}

	public function testReissueInvalidatesPreviousToken(): void
	{
		$purposes = [AuthTokenService::PURPOSE_PASSWORD_RESET];
		$first = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);
		$second = $this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);

		$this->assertNull($this->service->validate($first, $purposes));
		$this->assertSame(7, $this->service->validate($second, $purposes));
		$this->assertCount(1, $this->db->authTokens);
	}

	public function testReissueKeepsOtherPurposeAndOtherUser(): void
	{
		$invite = $this->service->issue(7, AuthTokenService::PURPOSE_INVITE, 3600);
		$otherUser = $this->service->issue(8, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);
		$this->service->issue(7, AuthTokenService::PURPOSE_PASSWORD_RESET, 3600);

		$this->assertSame(7, $this->service->validate($invite, [AuthTokenService::PURPOSE_INVITE]));
		$this->assertSame(8, $this->service->validate($otherUser, [AuthTokenService::PURPOSE_PASSWORD_RESET]));
	}
}
