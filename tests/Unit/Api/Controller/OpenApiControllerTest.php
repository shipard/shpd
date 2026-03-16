<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\OpenApiController;

class OpenApiControllerTest extends TestCase
{
	private OpenApiController $ctrl;

	protected function setUp(): void
	{
		$this->ctrl = new OpenApiController();
	}

	private function getStatus(mixed $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	public function testPublicAccessWithoutTokenReturns200(): void
	{
		$auth = AuthContext::anonymous();
		$resp = $this->ctrl->spec($auth, true, [], 'https://demo.shipard.cz');

		$this->assertSame(200, $this->getStatus($resp));
		$this->assertTrue($resp->getPayload()['success']);
	}

	public function testPrivateAccessWithoutTokenReturns401(): void
	{
		$auth = AuthContext::anonymous();
		$resp = $this->ctrl->spec($auth, false, [], 'https://demo.shipard.cz');

		$this->assertSame(401, $this->getStatus($resp));
		$this->assertSame('UNAUTHORIZED', $resp->getPayload()['error']['code']);
	}

	public function testPrivateAccessWithAuthenticatedUserReturns200(): void
	{
		$auth = new AuthContext(true, 1, 'session', 'shpd_st_token');
		$resp = $this->ctrl->spec($auth, false, [], 'https://demo.shipard.cz');

		$this->assertSame(200, $this->getStatus($resp));
		$this->assertTrue($resp->getPayload()['success']);
	}

	public function testResponseContainsOpenApiSpec(): void
	{
		$auth = AuthContext::anonymous();
		$resp = $this->ctrl->spec($auth, true, [], 'https://demo.shipard.cz');
		$data = $resp->getPayload()['data'];

		$this->assertSame('3.1.0', $data['openapi']);
		$this->assertArrayHasKey('paths',      $data);
		$this->assertArrayHasKey('components', $data);
	}
}
