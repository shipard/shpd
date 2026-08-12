<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\AlertsController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;

/**
 * D13 (docs/ds-setup.md): snooze/dismiss guard pro setup alerty.
 */
class AlertsControllerTest extends TestCase
{
    private function makeController(string $checkId, ?int &$updateCalls = null): AlertsController
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id'          => 1,
            'alert_state' => AlertReconciler::STATE_ACTIVE,
            'check_id'    => $checkId,
        ]);
        $db->method('updateWhere')->willReturnCallback(
            static function () use (&$updateCalls): void {
                if ($updateCalls !== null) {
                    $updateCalls++;
                }
            },
        );

        $registry = new AlertCheckRegistry([
            ModuleDefinition::fromArray([
                'id'          => 'x.setup',
                'name'        => 'X setup',
                'alertChecks' => [[
                    'id'       => 'x.setup.check',
                    'name'     => 'Setup check',
                    'class'    => 'Irrelevant',
                    'interval' => '5m',
                    'tags'     => ['setup'],
                ]],
            ]),
            ModuleDefinition::fromArray([
                'id'          => 'x.normal',
                'name'        => 'X normal',
                'alertChecks' => [[
                    'id'       => 'x.normal.check',
                    'name'     => 'Normal check',
                    'class'    => 'Irrelevant',
                    'interval' => '1h',
                    'tags'     => ['accounting'],
                ]],
            ]),
        ], 'en');

        return new AlertsController(
            $db,
            $registry,
            $this->createMock(ConfigRuntime::class),
            'en',
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    private static function snoozeRequest(): Request
    {
        return Request::fromArray('POST', '/_alerts/alerts/1/snooze', [], '{"hours":1}', []);
    }

    public function testSnoozeSetupAlertReturns409(): void
    {
        $updateCalls = 0;
        $resp = $this->makeController('x.setup.check', $updateCalls)
            ->snooze(1, self::snoozeRequest());

        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('SETUP_ALERT', $resp->getPayload()['error']['code']);
        $this->assertSame(0, $updateCalls);
    }

    public function testDismissSetupAlertReturns409(): void
    {
        $updateCalls = 0;
        $resp = $this->makeController('x.setup.check', $updateCalls)->dismiss(1);

        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('SETUP_ALERT', $resp->getPayload()['error']['code']);
        $this->assertSame(0, $updateCalls);
    }

    public function testSnoozeNormalAlertStillWorks(): void
    {
        $resp = $this->makeController('x.normal.check')
            ->snooze(1, self::snoozeRequest());

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertTrue($resp->getPayload()['success']);
    }

    public function testDismissNormalAlertStillWorks(): void
    {
        $resp = $this->makeController('x.normal.check')->dismiss(1);

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertTrue($resp->getPayload()['success']);
    }

    public function testUnregisteredCheckIdIsNotBlocked(): void
    {
        // Fail-open: chybějící definice v registry nesmí alert zablokovat.
        $resp = $this->makeController('ghost.gone.check')->dismiss(1);

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertTrue($resp->getPayload()['success']);
    }
}
