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
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Persons\Checks\MissingOwnPersonCheck;
use Shipard\Module\Economy\Codebooks\Checks\MissingOwnBankAccountCheck;
use Shipard\Module\Economy\Codebooks\Checks\MissingVatRegistrationCheck;
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

    /** Vzorová aktivní vlastní Osoba pro makeDb(ownPerson: …). */
    private const OWN_PERSON = ['id' => 7, 'full_name' => 'Vzorová firma s.r.o.', 'vat_id' => 'CZ12345678'];

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
     * provisioner. $ownPerson je řádek aktivní vlastní Osoby (fetchRow nad
     * base_persons_persons; null = žádná není), $personBankAccounts její
     * bankovní spojení, $codebookAccounts aktivní řádky číselníku
     * economy_codebooks_bank_accounts.
     *
     * @param array<string, mixed> $settings klíč → hodnota
     * @param array<string, mixed>|null $ownPerson
     * @param list<array<string, mixed>> $personBankAccounts
     * @param list<array<string, mixed>> $codebookAccounts
     */
    private function makeDb(
        array $settings = [],
        bool $insertRowThrows = false,
        ?array $ownPerson = null,
        array $personBankAccounts = [],
        array $codebookAccounts = [],
    ): MockObject&DataSourceConnection {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($settings, $codebookAccounts): mixed {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_system_settings')) {
                    return array_key_exists($args[1] ?? '', $settings)
                        ? json_encode($settings[$args[1]])
                        : null;
                }
                if (str_contains($sql, 'MAX(sort_order)')) {
                    return array_reduce(
                        $codebookAccounts,
                        static fn(int $max, array $row): int => max($max, (int) ($row['sort_order'] ?? 0)),
                        0,
                    );
                }
                return 0;   // COUNT dotazy checků → vše "chybí"
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($settings, $personBankAccounts, $codebookAccounts): array {
                $sql = (string) $args[0];
                // SettingsStore::getMany — vrátit řádky existujících klíčů.
                if (str_contains($sql, 'core_system_settings')) {
                    $rows = [];
                    foreach ($settings as $key => $value) {
                        $rows[] = ['key' => $key, 'value' => json_encode($value)];
                    }
                    return $rows;
                }
                if (str_contains($sql, 'base_persons_bank_accounts')) {
                    return $personBankAccounts;
                }
                if (str_contains($sql, 'economy_codebooks_bank_accounts')) {
                    return $codebookAccounts;
                }
                return [];
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            static fn(mixed ...$args): ?array => str_contains((string) $args[0], 'base_persons_persons')
                ? $ownPerson
                : null,
        );
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
                    $entry('economy.codebooks.missing_vat_registration', MissingVatRegistrationCheck::class),
                    $entry('economy.codebooks.missing_own_bank_account', MissingOwnBankAccountCheck::class),
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

    private function makeConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => match ($id) {
                'world.base.currencies'            => ['czk' => ['name' => 'Koruna česká'], 'eur' => ['name' => 'Euro']],
                'economy.codebooks.vatPeriodKinds' => ['1' => ['name' => 'Měsíční'], '2' => ['name' => 'Čtvrtletní']],
                default                            => null,
            },
        );
        return $config;
    }

    private function makeController(
        MockObject&DataSourceConnection $db,
        ?DataSourceConfig $dsConfig = null,
    ): SetupController {
        return new SetupController(
            $db,
            $this->makeRegistry(),
            $this->makeConfig(),
            'cs',
            new ModulePathResolver([dirname(__DIR__, 4) . '/modules']),
            $dsConfig,
        );
    }

    /**
     * Controller se zachytáváním překlopů bankovních účtů — seam
     * saveBankAccountRow místo TableGateway (unit test nemá dibi spojení).
     */
    private function makeBridgeController(MockObject&DataSourceConnection $db): SetupController
    {
        return new class(
            $db,
            $this->makeRegistry(),
            $this->makeConfig(),
            'cs',
            new ModulePathResolver([dirname(__DIR__, 4) . '/modules']),
        ) extends SetupController {
            /** @var list<array<string, mixed>> */
            public array $savedRows = [];

            protected function saveBankAccountRow(array $payload): DocumentResult
            {
                $this->savedRows[] = $payload;
                return DocumentResult::ok(['id' => 100 + count($this->savedRows)] + $payload);
            }
        };
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
        // undecided_vat_agenda mlčí (parametr je rozhodnutý), zbytek svítí.
        $this->assertSame(
            [
                'base.persons.missing_own_person',
                'economy.codebooks.missing_vat_registration',
                'economy.codebooks.missing_own_bank_account',
                'economy.codebooks.undecided_home_currency',
            ],
            array_column($data['items'], 'checkId'),
        );
        // Parametrová položka nese klíč vrstvy C, řádkové null.
        $this->assertNull($data['items'][0]['parameter']);
        $this->assertSame('economy.homeCurrency', $data['items'][3]['parameter']);

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
        $resp = $this->makeController($this->makeDb(ownPerson: self::OWN_PERSON))->checklist(self::auth());

        $item = $this->findItem($resp, 'economy.codebooks.undecided_vat_agenda');
        $this->assertTrue($item['suggestion']['value']);
        // DIČ musí být v důvodu vidět — uživatel má vědět, z čeho návrh vychází.
        $this->assertStringContainsString('CZ12345678', $item['suggestion']['reason']);

        // Návrh je jen předvolba v UI — parametr dál drží null (D2).
        $this->assertNull($resp->getPayload()['data']['parameters']['economy.vatAgenda']);
    }

    public function testVatAgendaSuggestionAbsentWithEmptyVatId(): void
    {
        $resp = $this->makeController($this->makeDb(ownPerson: ['vat_id' => '', 'full_name' => 'X', 'id' => 7]))
            ->checklist(self::auth());

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

    // ── Task 09: prefill Registrace DPH ──────────────────────────────────

    public function testVatPrefillReturnsValuesFromOwnPersonAndLayerA(): void
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getCountry')->willReturn('sk');

        $resp = $this->makeController($this->makeDb(ownPerson: self::OWN_PERSON), $dsConfig)
            ->vatRegistrationPrefill(self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];

        $this->assertSame('CZ12345678', $data['values']['vat_id']);
        $this->assertSame('sk', $data['values']['country']);
        $this->assertSame('eu', $data['values']['region']);
        $this->assertSame('Vzorová firma s.r.o.', $data['values']['name']);
        $this->assertSame(0, $data['values']['taxpayer_kind']);
        // Registr datum registrace ani frekvence nevrací — musí zůstat
        // prázdné, uživatel je doplní vědomě (D2/D5).
        $this->assertNull($data['values']['valid_from']);
        $this->assertNull($data['values']['tax_period_kind']);
        $this->assertNull($data['values']['report_period_kind']);

        // Frekvence z cfgItem — jen 1/2, rezervovaná 0 se nenabízí.
        $this->assertSame([1, 2], array_column($data['periodKindOptions'], 'value'));
    }

    public function testVatPrefillWithoutOwnPersonConflicts(): void
    {
        $resp = $this->makeController($this->makeDb())->vatRegistrationPrefill(self::auth());

        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('NO_OWN_PERSON', $resp->getPayload()['error']['code']);
    }

    public function testMissingVatRegistrationActionOnlyWithOwnPerson(): void
    {
        // Check svítí jen u plátce (vatAgenda === true). S vlastní Osobou:
        // primární prefill akce, open_form z checku degradovaný na
        // sekundární „Zadat ručně".
        $vatPayer = ['economy.vatAgenda' => true];
        $resp = $this->makeController($this->makeDb($vatPayer, ownPerson: self::OWN_PERSON))
            ->checklist(self::auth());
        $actions = $this->findItem($resp, 'economy.codebooks.missing_vat_registration')['actions'];
        $this->assertCount(2, $actions);
        $this->assertSame('prefill_vat_registration', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
        $this->assertSame('open_form', $actions[1]['kind']);
        $this->assertFalse($actions[1]['primary']);

        // Bez vlastní Osoby není z čeho předvyplňovat — akce z checku beze změny.
        $resp = $this->makeController($this->makeDb($vatPayer))->checklist(self::auth());
        $actions = $this->findItem($resp, 'economy.codebooks.missing_vat_registration')['actions'];
        $this->assertCount(1, $actions);
        $this->assertSame('open_form', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
    }

    // ── Task 09: kandidáti a můstek bankovních účtů ──────────────────────

    /** @return list<array<string, mixed>> dvě bankovní spojení vlastní Osoby */
    private static function personAccounts(): array
    {
        return [
            [
                'id' => 12, 'name' => 'Hlavní účet', 'account_number' => '123456789/0100',
                'iban' => 'CZ6501000000000123456789', 'bic' => 'KOMBCZPP', 'currency' => 'CZK',
                'source' => 2, 'order_pos' => 1, 'valid_from' => '2020-05-01', 'valid_to' => null,
            ],
            [
                'id' => 13, 'name' => null, 'account_number' => '987654321/0300',
                'iban' => null, 'bic' => null, 'currency' => null,
                'source' => 0, 'order_pos' => null, 'valid_from' => null, 'valid_to' => null,
            ],
        ];
    }

    public function testBankCandidatesMarkExistingByIbanAndAccountNumber(): void
    {
        $resp = $this->makeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
            codebookAccounts: [
                // První účet už v číselníku podle IBAN, druhý podle čísla
                // účtu (IBAN v číselníku chybí).
                ['iban' => 'CZ6501000000000123456789', 'account_number' => '', 'code' => 'BU1', 'sort_order' => 1],
                ['iban' => '', 'account_number' => '987654321/0300', 'code' => 'BU2', 'sort_order' => 2],
            ],
        ))->bankAccountCandidates(self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $candidates = $resp->getPayload()['data']['candidates'];
        $this->assertCount(2, $candidates);

        $this->assertTrue($candidates[0]['existsInCodebook']);
        $this->assertTrue($candidates[1]['existsInCodebook']);
        $this->assertSame(2, $candidates[0]['source']);
        $this->assertSame('czk', $candidates[0]['currency']);
        $this->assertSame('2020-05-01', $candidates[0]['validFrom']);
    }

    public function testBankCandidatesNotInCodebookAndWithoutOwnPerson(): void
    {
        $resp = $this->makeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
        ))->bankAccountCandidates(self::auth());
        $candidates = $resp->getPayload()['data']['candidates'];
        $this->assertFalse($candidates[0]['existsInCodebook']);
        $this->assertFalse($candidates[1]['existsInCodebook']);

        $resp = $this->makeController($this->makeDb())->bankAccountCandidates(self::auth());
        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('NO_OWN_PERSON', $resp->getPayload()['error']['code']);
    }

    private static function bridgeRequest(array $body): Request
    {
        return Request::fromArray('POST', '/_setup/bank-accounts', [], json_encode($body), []);
    }

    public function testBridgeCreatesRowsWithUniqueCodesAndSingleDefault(): void
    {
        $ctrl = $this->makeBridgeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
            // Obsazený kód BU1 — sekvence se posune, nekoliduje ani neselže.
            codebookAccounts: [
                ['iban' => 'CZ111', 'account_number' => '1/0100', 'code' => 'BU1', 'sort_order' => 5],
            ],
        ));

        $resp = $ctrl->bridgeBankAccounts(
            self::bridgeRequest(['personBankAccountIds' => [12, 13], 'defaultId' => 13]),
            self::auth(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertCount(2, $ctrl->savedRows);

        // Pořadí dle order_pos (null až nakonec): 12 (order_pos 1) před 13.
        [$first, $second] = $ctrl->savedRows;
        $this->assertSame('BU2', $first['code']);
        $this->assertSame('BU3', $second['code']);
        $this->assertSame(6, $first['sort_order']);
        $this->assertSame(7, $second['sort_order']);
        $this->assertSame(40, $first['docState']);

        // Měna normalizovaná na malá, chybějící → default czk.
        $this->assertSame('czk', $first['currency']);
        $this->assertSame('czk', $second['currency']);

        // bank_name se nedopočítává — v payloadu vůbec není.
        $this->assertArrayNotHasKey('bank_name', $first);

        // Výchozí je právě řádek z defaultId.
        $this->assertSame(0, $first['is_default']);
        $this->assertSame(1, $second['is_default']);

        // Prázdný název → fallback z posledního čtyřčíslí účtu.
        $this->assertSame('Hlavní účet', $first['name']);
        $this->assertSame('Účet …0300', $second['name']);

        // valid_from se překlápí 1:1.
        $this->assertSame('2020-05-01', $first['valid_from']);

        $this->assertSame(['BU2', 'BU3'], array_column($resp->getPayload()['data']['created'], 'code'));
    }

    public function testBridgeSingleAccountBecomesDefaultAutomatically(): void
    {
        $ctrl = $this->makeBridgeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
        ));

        $resp = $ctrl->bridgeBankAccounts(
            self::bridgeRequest(['personBankAccountIds' => [12]]),
            self::auth(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame(1, $ctrl->savedRows[0]['is_default']);
    }

    public function testBridgeRejectsAccountAlreadyInCodebook(): void
    {
        $ctrl = $this->makeBridgeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
            codebookAccounts: [
                ['iban' => 'CZ6501000000000123456789', 'account_number' => '', 'code' => 'BU1', 'sort_order' => 1],
            ],
        ));

        $resp = $ctrl->bridgeBankAccounts(
            self::bridgeRequest(['personBankAccountIds' => [12, 13], 'defaultId' => 12]),
            self::auth(),
        );

        // All-or-nothing: duplicitní účet odmítne celou dávku, nic se neuloží.
        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('ALREADY_IN_CODEBOOK', $resp->getPayload()['error']['details'][0]['code']);
        $this->assertSame([], $ctrl->savedRows);
    }

    public function testBridgeRejectsForeignAccountId(): void
    {
        $ctrl = $this->makeBridgeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
        ));

        $resp = $ctrl->bridgeBankAccounts(
            self::bridgeRequest(['personBankAccountIds' => [12, 999]]),
            self::auth(),
        );

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('UNKNOWN_ACCOUNT', $resp->getPayload()['error']['details'][0]['code']);
        $this->assertSame([], $ctrl->savedRows);
    }

    public function testBridgeWithoutOwnPersonConflicts(): void
    {
        $ctrl = $this->makeBridgeController($this->makeDb());

        $resp = $ctrl->bridgeBankAccounts(
            self::bridgeRequest(['personBankAccountIds' => [12]]),
            self::auth(),
        );

        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('NO_OWN_PERSON', $resp->getPayload()['error']['code']);
    }

    public function testMissingOwnBankAccountActionGatedOnPersonAccounts(): void
    {
        // Vlastní Osoba s účty → primární bridge akce + sekundární ruční.
        $resp = $this->makeController($this->makeDb(
            ownPerson: self::OWN_PERSON,
            personBankAccounts: self::personAccounts(),
        ))->checklist(self::auth());
        $actions = $this->findItem($resp, 'economy.codebooks.missing_own_bank_account')['actions'];
        $this->assertSame('bridge_bank_accounts', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
        $this->assertSame('open_form', $actions[1]['kind']);
        $this->assertFalse($actions[1]['primary']);

        // Osoba bez bankovních spojení → není co překlápět, akce beze změny.
        $resp = $this->makeController($this->makeDb(ownPerson: self::OWN_PERSON))->checklist(self::auth());
        $actions = $this->findItem($resp, 'economy.codebooks.missing_own_bank_account')['actions'];
        $this->assertCount(1, $actions);
        $this->assertSame('open_form', $actions[0]['kind']);
    }
}
