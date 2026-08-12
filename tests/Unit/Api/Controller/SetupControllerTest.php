<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\SetupController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Persons\Checks\MissingOwnPersonCheck;
use Shipard\Module\Economy\Codebooks\Checks\UndecidedVatAgendaCheck;
use Shipard\Tests\Fixtures\Core\Settings\SetupChecklistFindingCheck;

/**
 * Backend panelu dsSetup — GET /_setup/checklist a POST /_setup/parameters
 * (docs/ds-setup.md D12/D14).
 */
class SetupControllerTest extends TestCase
{
    /** Zachycené zápisy z mock DB — viz makeDb(). */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->writes = ['execute' => [], 'deleteWhere' => [], 'insertRow' => []];
    }

    private static function auth(): AuthContext
    {
        return new AuthContext(isAuthenticated: true, userId: 1);
    }

    private function getStatus(Response $response): int
    {
        $ref  = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    private static function postRequest(array $body): Request
    {
        return Request::fromArray('POST', '/_setup/parameters', [], json_encode($body), []);
    }

    /**
     * Mock DB: settings čtení vrací $settings (jinak null/0), zápisy se
     * zachytávají do $this->writes. $insertRowThrows simuluje spadlý
     * provisioner. $ownVatId je odpověď na dotaz `SELECT vat_id` vlastní
     * Osoby (suggestion u vatAgenda) — null simuluje žádnou vlastní Osobu,
     * '' Osobu bez DIČ.
     *
     * @param array<string, mixed> $settings klíč → hodnota
     */
    private function makeDb(
        array $settings = [],
        bool $insertRowThrows = false,
        ?string $ownVatId = null,
    ): MockObject&DataSourceConnection {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($settings, $ownVatId): mixed {
                if (str_contains((string) $args[0], 'core_system_settings')) {
                    return array_key_exists($args[1] ?? '', $settings)
                        ? json_encode($settings[$args[1]])
                        : null;
                }
                if (str_contains((string) $args[0], 'SELECT vat_id')) {
                    return $ownVatId;
                }
                return 0;   // COUNT dotazy checků → vše "chybí"
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($settings): array {
                // SettingsStore::getMany — vrátit řádky existujících klíčů.
                if (str_contains((string) $args[0], 'core_system_settings')) {
                    $rows = [];
                    foreach ($settings as $key => $value) {
                        $rows[] = ['key' => $key, 'value' => json_encode($value)];
                    }
                    return $rows;
                }
                return [];
            },
        );
        $db->method('fetchRow')->willReturn(null);
        $db->method('execute')->willReturnCallback(
            function (mixed ...$args): void {
                $this->writes['execute'][] = $args;
            },
        );
        $db->method('deleteWhere')->willReturnCallback(
            function (string $table, string $where, mixed ...$params): void {
                $this->writes['deleteWhere'][] = [$table, $where, $params];
            },
        );
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $row) use ($insertRowThrows): int {
                if ($insertRowThrows) {
                    throw new \RuntimeException('boom');
                }
                $this->writes['insertRow'][] = [$table, $row];
                return 1;
            },
        );

        return $db;
    }

    /**
     * Registry se setup checky v přeházeném pořadí registrace — GET je musí
     * vrátit dle SetupChecklist::ORDER. missing_own_person a
     * undecided_vat_agenda jsou reálné checky (testují se panelové akce
     * a suggestion nad jejich skutečnými findingy), zbytek fixture.
     */
    private function makeRegistry(): AlertCheckRegistry
    {
        $entry = static fn(string $id, string $class = SetupChecklistFindingCheck::class): array => [
            'id'       => $id,
            'name'     => "Name of {$id}",
            'class'    => $class,
            'severity' => 'warning',
            'interval' => '5m',
            'tags'     => ['setup'],
        ];

        return new AlertCheckRegistry([
            ModuleDefinition::fromArray([
                'id'          => 'economy.codebooks',
                'name'        => 'Codebooks',
                'alertChecks' => [
                    $entry('economy.codebooks.undecided_home_currency'),
                    $entry('economy.codebooks.undecided_vat_agenda', UndecidedVatAgendaCheck::class),
                ],
            ]),
            ModuleDefinition::fromArray([
                'id'          => 'base.persons',
                'name'        => 'Persons',
                'alertChecks' => [$entry('base.persons.missing_own_person', MissingOwnPersonCheck::class)],
            ]),
        ], 'cs');
    }

    /**
     * Položka checklistu podle checkId — fail, když v odpovědi není.
     *
     * @return array<string, mixed>
     */
    private function findItem(Response $response, string $checkId): array
    {
        foreach ($response->getPayload()['data']['items'] as $item) {
            if ($item['checkId'] === $checkId) {
                return $item;
            }
        }
        $this->fail("Checklist item {$checkId} not found in response");
    }

    private function makeController(MockObject&DataSourceConnection $db): SetupController
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'world.base.currencies'
                ? ['czk' => ['name' => 'Koruna česká'], 'eur' => ['name' => 'Euro']]
                : null,
        );

        return new SetupController(
            $db,
            $this->makeRegistry(),
            $config,
            'cs',
            new ModulePathResolver([dirname(__DIR__, 4) . '/modules']),
        );
    }

    public function testChecklistRequiresAuthentication(): void
    {
        $resp = $this->makeController($this->makeDb())
            ->checklist(AuthContext::anonymous());

        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testChecklistReturnsItemsInOrderAndAllParameters(): void
    {
        $resp = $this->makeController($this->makeDb(['economy.vatAgenda' => true]))
            ->checklist(self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];

        // Pořadí dle SetupChecklist::ORDER, ne dle registrace v registry.
        $this->assertSame(
            ['base.persons.missing_own_person', 'economy.codebooks.undecided_home_currency'],
            array_column($data['items'], 'checkId'),
        );
        // Parametrová položka nese klíč vrstvy C, řádková null.
        $this->assertNull($data['items'][0]['parameter']);
        $this->assertSame('economy.homeCurrency', $data['items'][1]['parameter']);

        // parameters = VŠECHNY klíče LayerCParameters, včetně null.
        $this->assertSame(
            ['economy.accountChart', 'economy.fiscalYearStartMonth', 'economy.vatAgenda', 'economy.homeCurrency'],
            array_keys($data['parameters']),
        );
        $this->assertNull($data['parameters']['economy.accountChart']);
        $this->assertTrue($data['parameters']['economy.vatAgenda']);

        $this->assertSame('czk', $data['currencyOptions'][0]['value']);
    }

    public function testSaveUnknownKeyRejectedWithoutWrites(): void
    {
        $resp = $this->makeController($this->makeDb())
            ->saveParameters(self::postRequest(['values' => ['economy.unknown' => 'x']]), self::auth());

        $this->assertSame(422, $this->getStatus($resp));
        $error = $resp->getPayload()['error'];
        $this->assertSame('VALIDATION_ERROR', $error['code']);
        $this->assertSame('economy.unknown', $error['details'][0]['field']);
        $this->assertSame([], $this->writes['execute']);
        $this->assertSame([], $this->writes['deleteWhere']);
    }

    public function testSaveInvalidValueRejectedByLayerCParameters(): void
    {
        $resp = $this->makeController($this->makeDb())
            ->saveParameters(self::postRequest(['values' => ['economy.homeCurrency' => 'CZK']]), self::auth());

        $this->assertSame(422, $this->getStatus($resp));
        $detail = $resp->getPayload()['error']['details'][0];
        $this->assertSame('economy.homeCurrency', $detail['field']);
        // Znění chyby pochází z LayerCParameters::validate() — controller
        // vlastní validaci hodnot nemá.
        $this->assertStringContainsString('ISO 4217', $detail['message']);
        $this->assertSame([], $this->writes['execute']);
    }

    public function testSaveNullDeletesKey(): void
    {
        $resp = $this->makeController($this->makeDb(['economy.vatAgenda' => true]))
            ->saveParameters(self::postRequest(['values' => ['economy.vatAgenda' => null]]), self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertCount(1, $this->writes['deleteWhere']);
        $this->assertSame('core_system_settings', $this->writes['deleteWhere'][0][0]);
        $this->assertSame(['economy.vatAgenda'], $this->writes['deleteWhere'][0][2]);
    }

    public function testSaveAccountChartRunsProvisioner(): void
    {
        $resp = $this->makeController($this->makeDb())
            ->saveParameters(self::postRequest(['values' => ['economy.accountChart' => 'default']]), self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame([], $data['warnings']);

        // Osnova se naseedovala hned z requestu — reálný seed soubor
        // z modules/economy/accounting/config, inserty zachycené mockem.
        $accountInserts = array_filter(
            $this->writes['insertRow'],
            static fn(array $w): bool => $w[0] === 'economy_accounting_accounts',
        );
        $this->assertNotEmpty($accountInserts);
    }

    public function testSaveBothFiscalKeysSeedsFiscalYears(): void
    {
        $resp = $this->makeController($this->makeDb())
            ->saveParameters(
                self::postRequest(['values' => ['economy.fiscalYearStartMonth' => 1, 'economy.homeCurrency' => 'eur']]),
                self::auth(),
            );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame([], $resp->getPayload()['data']['warnings']);

        $yearInserts = array_values(array_filter(
            $this->writes['insertRow'],
            static fn(array $w): bool => $w[0] === 'economy_codebooks_fiscal_years',
        ));
        $this->assertNotEmpty($yearInserts);
        $this->assertSame('eur', $yearInserts[0][1]['currency']);
    }

    public function testSaveOnlyStartMonthDoesNotSeedFiscalYears(): void
    {
        // Gate na oba klíče (D6) platí i pro okamžitý běh z panelu.
        $resp = $this->makeController($this->makeDb())
            ->saveParameters(self::postRequest(['values' => ['economy.fiscalYearStartMonth' => 1]]), self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame([], $this->writes['insertRow']);
    }

    public function testProvisionerFailureKeepsParameterAndWarns(): void
    {
        $resp = $this->makeController($this->makeDb(insertRowThrows: true))
            ->saveParameters(self::postRequest(['values' => ['economy.accountChart' => 'default']]), self::auth());

        // Parametr zůstal uložený (execute proběhl), odpověď je 200 s warnings.
        $this->assertSame(200, $this->getStatus($resp));
        $this->assertNotEmpty($resp->getPayload()['data']['warnings']);
        $this->assertNotEmpty($this->writes['execute']);
    }

    public function testMissingOwnPersonHasRegistryImportAsPrimaryPanelAction(): void
    {
        $resp = $this->makeController($this->makeDb())->checklist(self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $actions = $this->findItem($resp, 'base.persons.missing_own_person')['actions'];
        $this->assertCount(2, $actions);

        // Primární cesta je registr — akci skládá až SetupController,
        // check ji nenese (regrese v MissingOwnPersonCheckTest).
        $this->assertSame('import_own_person_from_registry', $actions[0]['id']);
        $this->assertSame('registry_import_own', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
        $this->assertSame('Načíst z registru', $actions[0]['label']);

        // Ruční formulář zůstává jako sekundární záloha pro subjekty,
        // které v registru nejsou — preset z checku beze změny.
        $this->assertSame('create_own_person', $actions[1]['id']);
        $this->assertSame('open_form', $actions[1]['kind']);
        $this->assertFalse($actions[1]['primary']);
        $this->assertSame('Zadat ručně', $actions[1]['label']);
        $this->assertSame(['is_own' => true, 'person_type' => 2], $actions[1]['target']['preset']);
    }

    public function testVatAgendaSuggestionFromOwnPersonVatId(): void
    {
        $resp = $this->makeController($this->makeDb(ownVatId: 'CZ12345678'))->checklist(self::auth());

        $item = $this->findItem($resp, 'economy.codebooks.undecided_vat_agenda');
        $this->assertTrue($item['suggestion']['value']);
        // DIČ musí být v důvodu vidět — uživatel má vědět, z čeho návrh vychází.
        $this->assertStringContainsString('CZ12345678', $item['suggestion']['reason']);

        // Návrh je jen předvolba v UI — parametr dál drží null (D2).
        $this->assertNull($resp->getPayload()['data']['parameters']['economy.vatAgenda']);
    }

    public function testVatAgendaSuggestionAbsentWithEmptyVatId(): void
    {
        $resp = $this->makeController($this->makeDb(ownVatId: ''))->checklist(self::auth());

        $this->assertArrayNotHasKey(
            'suggestion',
            $this->findItem($resp, 'economy.codebooks.undecided_vat_agenda'),
        );
    }

    public function testVatAgendaSuggestionAbsentWithoutOwnPerson(): void
    {
        $resp = $this->makeController($this->makeDb())->checklist(self::auth());

        $this->assertArrayNotHasKey(
            'suggestion',
            $this->findItem($resp, 'economy.codebooks.undecided_vat_agenda'),
        );
    }
}
