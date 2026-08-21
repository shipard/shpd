<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\ReportsController;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Core\Utils\JsoncParser;

/** Testovací builder pro ReportRunner — echo reportId + parametrů. */
final class ReportsApiTestBuilder implements ReportBuilder
{
    public function build(ReportRequest $request): ReportResult
    {
        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: [],
            columns: [],
            rows: [],
        );
    }
}

class ReportsApiTest extends TestCase
{
    // ── Routing ─────────────────────────────────────────────────────────────

    public function testRouterResolvesCatalogAndRun(): void
    {
        $router = new Router();

        $route = $router->resolve('/api/v1/_reports', 'GET');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['reports', 'catalog'], [$route->controller, $route->action]);

        $route = $router->resolve('/api/v1/_reports/economy.accounting.generalLedger', 'GET');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('reports', $route->controller);
        $this->assertSame('run', $route->action);
        $this->assertSame('economy.accounting.generalLedger', $route->table);
    }

    public function testRouterRejectsBadMethodAndBadId(): void
    {
        $router = new Router();

        $response = $router->resolve('/api/v1/_reports', 'POST');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(405, $this->statusOf($response));

        $response = $router->resolve('/api/v1/_reports/economy.accounting.generalLedger', 'DELETE');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(405, $this->statusOf($response));

        // Nevalidní znaky v id (path traversal apod.) → 404 už na routeru.
        $response = $router->resolve('/api/v1/_reports/../etc', 'GET');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $this->statusOf($response));
    }

    // ── Katalog ─────────────────────────────────────────────────────────────

    public function testCatalogContainsGeneralLedgerLocalized(): void
    {
        // Reálná deklarace z modulu — katalog musí obsahovat hlavní knihu
        // s lokalizovaným názvem (cs).
        $declarations = JsoncParser::parseFile(
            dirname(__DIR__, 4) . '/modules/economy/accounting/config/reports.jsonc',
        );
        $registry = new ReportRegistry();
        foreach ($declarations as $raw) {
            $registry->add(ReportDefinition::fromArray(
                ConfigLocalizer::localize($raw, 'cs'),
                'economy.accounting',
            ));
        }

        $controller = new ReportsController($registry, $this->makeRunner($registry));
        $payload    = $controller->catalog()->getPayload();

        $this->assertTrue($payload['success']);
        $items = array_column($payload['data']['items'], null, 'id');
        $this->assertArrayHasKey('economy.accounting.generalLedger', $items);

        $ledger = $items['economy.accounting.generalLedger'];
        $this->assertSame('Hlavní kniha', $ledger['name']);
        $this->assertSame(['month', 'quarter', 'halfYear', 'year'], $ledger['periodGranularities']);
        $this->assertSame(
            [['id' => 'detail', 'type' => 'enum', 'options' => ['analytic', 'synthetic'], 'default' => 'analytic']],
            $ledger['params'],
        );
    }

    // ── Spuštění ────────────────────────────────────────────────────────────

    public function testRunReturnsReportResultShape(): void
    {
        $controller = $this->makeControllerWithFakeReport();

        $response = $controller->run('test.fake', [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '3',
        ]);
        $payload = $response->getPayload();

        $this->assertSame(200, $this->statusOf($response));
        $this->assertTrue($payload['success']);

        $data = $payload['data'];
        $this->assertSame(
            ['reportId', 'params', 'generatedAt', 'dataSource', 'status', 'messages', 'columns', 'rows'],
            array_keys($data),
        );
        $this->assertSame('test.fake', $data['reportId']);
        $this->assertSame('test-ds-id', $data['dataSource']);
        $this->assertSame('ok', $data['status']);
        $this->assertSame(
            ['fiscalYear' => '2026', 'monthFrom' => 1, 'monthTo' => 3],
            $data['params']['period'],
        );
    }

    public function testRunInvalidParamsReturns400(): void
    {
        $controller = $this->makeControllerWithFakeReport();

        $response = $controller->run('test.fake', [
            'fiscalYear' => '2026', 'monthFrom' => '3', 'monthTo' => '1',
        ]);

        $this->assertSame(400, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('BAD_REQUEST', $payload['error']['code']);
    }

    public function testRunUnknownReportReturns404(): void
    {
        $controller = $this->makeControllerWithFakeReport();

        $response = $controller->run('no.such.report', [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '1',
        ]);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('REPORT_NOT_FOUND', $response->getPayload()['error']['code']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeControllerWithFakeReport(): ReportsController
    {
        $registry = new ReportRegistry();
        $registry->add(new ReportDefinition(
            id: 'test.fake',
            name: 'Fake report',
            builderClass: ReportsApiTestBuilder::class,
            periodGranularities: ['month', 'quarter', 'year'],
            params: [],
            moduleId: 'test.module',
        ));
        return new ReportsController($registry, $this->makeRunner($registry));
    }

    private function makeRunner(ReportRegistry $registry): ReportRunner
    {
        $periods = new class implements FiscalPeriodProvider {
            public function findYearByName(string $name): ?array
            {
                return $name === '2026' ? ['id' => 1, 'name' => '2026'] : null;
            }

            public function monthsOfYear(int $fiscalYearId): array
            {
                $months = [];
                for ($i = 1; $i <= 12; $i++) {
                    $months[] = ['id' => $i, 'periodType' => 1];
                }
                return $months;
            }
        };

        return new ReportRunner(
            $registry,
            $this->createMock(DataSourceConnection::class),
            null,
            'test-ds-id',
            'cs',
            $periods,
        );
    }

    private function statusOf(Response $response): int
    {
        $ref  = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }
}
