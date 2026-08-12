<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\LayerCParameters;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Settings\SetupChecklist;
use Shipard\Module\Economy\Accounting\AccountChartProvisioner;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;

/**
 * Endpoints:
 *   GET  /_setup/checklist                 — živý checklist (SetupChecklist::collect) + hodnoty parametrů vrstvy C
 *   POST /_setup/parameters                — zápis parametrů vrstvy C + okamžitý běh dotčených provisionerů
 *   GET  /_setup/vat-registration-prefill  — návrh hodnot Registrace DPH z vlastní Osoby + vrstvy A
 *   GET  /_setup/bank-account-candidates   — bankovní spojení vlastní Osoby k překlopení do číselníku
 *   POST /_setup/bank-accounts             — překlop vybraných spojení do economy_codebooks_bank_accounts
 *
 * Backend panelu dsSetup (docs/ds-setup.md D12/D14, Fáze 4 §5.4). Auth:
 * přihlášený uživatel, bez adminOnly — v jednouživatelských DS by to
 * zablokovalo majitele (stejná úroveň jako /_settings/page).
 */
class SetupController
{
    /** docState 10 = Koncept, 40 = V pořádku — "aktivní" záznamy (vzor checků). */
    private const ACTIVE_DOC_STATES = [10, 40];

    private const CODEBOOK_TABLE = 'economy_codebooks_bank_accounts';

    /** Memo pro ownPerson() — null = zatím nenačteno, false = žádná není. */
    private array|false|null $ownPersonCache = null;

    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
        private readonly ModulePathResolver $modulePathResolver,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly array $tables = [],
        private readonly ?DocumentRegistry $documentRegistry = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
    ) {}

    /** GET /_setup/checklist */
    public function checklist(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        return Response::success($this->buildState(new SettingsStore($this->db)));
    }

    /** POST /_setup/parameters — body: {"values": {"economy.accountChart": "npo", ...}} */
    public function saveParameters(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body   = $request->getBody();
        $values = $body['values'] ?? null;
        if (!is_array($values)) {
            return Response::error('BAD_REQUEST', 'Body must contain a `values` object', 400);
        }

        // Validace celého payloadu PŘED prvním zápisem — uloží se všechno,
        // nebo nic. Hodnoty validuje výhradně LayerCParameters::validate()
        // (jediné místo pravdy) — tady se jen normalizuje JSON typ na
        // stringovou formu, kterou validate() bere.
        $known  = LayerCParameters::keys();
        $toSave = [];
        $errors = [];
        foreach ($values as $key => $raw) {
            $key = (string) $key;
            if (!in_array($key, $known, true)) {
                $errors[] = [
                    'field'   => $key,
                    'code'    => 'UNKNOWN_PARAMETER',
                    'message' => "Unknown layer C parameter: {$key}",
                ];
                continue;
            }
            if ($raw === null) {
                // Smazání klíče = vrácení do nerozhodnutého stavu — legální akce.
                $toSave[$key] = null;
                continue;
            }
            if (!is_scalar($raw)) {
                $errors[] = [
                    'field'   => $key,
                    'code'    => 'INVALID_TYPE',
                    'message' => 'Value must be a scalar or null',
                ];
                continue;
            }
            try {
                $rawStr = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;
                $toSave[$key] = LayerCParameters::validate($key, $rawStr);
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'field'   => $key,
                    'code'    => 'INVALID_VALUE',
                    'message' => $e->getMessage(),
                ];
            }
        }
        if ($errors !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
        }

        $settings = new SettingsStore($this->db);
        foreach ($toSave as $key => $value) {
            $settings->set($key, $value);
        }

        $warnings = $this->runProvisioners(array_keys($toSave), $settings);

        // Stejný tvar jako GET + warnings — panel po uložení nedělá druhý request.
        return Response::success($this->buildState($settings) + ['warnings' => $warnings]);
    }

    /**
     * GET /_setup/vat-registration-prefill — návrh hodnot Registrace DPH.
     * Uložení dělá frontend přes existující POST /_ui/form/.../save, aby
     * prošlo VatRegistrationDocument (afterSave → VatPeriodsProvisioner);
     * tenhle endpoint jen skládá předvyplnění.
     *
     * valid_from a frekvence jsou záměrně null — registr datum registrace
     * ani příznak plátce nevrací a default by sváděl k odkliknutí (D2/D5).
     */
    public function vatRegistrationPrefill(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $own = $this->ownPerson();
        if ($own === null) {
            // Akce se bez vlastní Osoby v panelu nenabízí — přímé volání je
            // abnormální stav, ne prázdná odpověď.
            return Response::error('NO_OWN_PERSON', 'No active own Person exists', 409);
        }

        $vatId = trim((string) ($own['vat_id'] ?? ''));

        return Response::success([
            'values' => [
                'vat_id'             => $vatId === '' ? null : $vatId,
                'country'            => $this->dsConfig?->getCountry() ?? 'cz',
                // Default sloupce region — jemnější odvození ze země až bude
                // k čemu (jiné unie než EU zatím nikdo nezakládá).
                'region'             => 'eu',
                'name'               => mb_substr(trim((string) ($own['full_name'] ?? '')), 0, 50),
                'taxpayer_kind'      => 0,
                'valid_from'         => null,
                'tax_period_kind'    => null,
                'report_period_kind' => null,
            ],
            'periodKindOptions' => $this->periodKindOptions(),
        ]);
    }

    /**
     * GET /_setup/bank-account-candidates — bankovní spojení vlastní Osoby
     * s příznakem, jestli už v číselníku jsou (match IBAN, bez něj číslo účtu).
     */
    public function bankAccountCandidates(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $own = $this->ownPerson();
        if ($own === null) {
            return Response::error('NO_OWN_PERSON', 'No active own Person exists', 409);
        }

        [$codebookIbans, $codebookNumbers] = $this->existingCodebookKeys();

        $candidates = [];
        foreach ($this->ownPersonBankAccounts((int) $own['id']) as $row) {
            $candidates[] = [
                'id'               => (int) $row['id'],
                'name'             => trim((string) ($row['name'] ?? '')),
                'accountNumber'    => trim((string) ($row['account_number'] ?? '')),
                'iban'             => strtoupper(trim((string) ($row['iban'] ?? ''))),
                'bic'              => strtoupper(trim((string) ($row['bic'] ?? ''))),
                'currency'         => strtolower(trim((string) ($row['currency'] ?? ''))),
                'source'           => (int) ($row['source'] ?? 0),
                'validFrom'        => $row['valid_from'] ?? null,
                'validTo'          => $row['valid_to'] ?? null,
                'existsInCodebook' => $this->existsInCodebook($row, $codebookIbans, $codebookNumbers),
            ];
        }

        return Response::success(['candidates' => $candidates]);
    }

    /**
     * POST /_setup/bank-accounts — body {"personBankAccountIds": [12, 13], "defaultId": 12}.
     * Překlopí vybraná bankovní spojení vlastní Osoby do číselníku. Každý
     * řádek jde přes BankAccountDocument (TableGateway) — validace,
     * normalizace měny/IBAN i per-currency unikátnost is_default
     * (afterPersist) se přebírají z dokumentu, ne duplikují.
     *
     * All-or-nothing validace PŘED prvním zápisem (vzor saveParameters):
     * neznámé id nebo účet už v číselníku → 422 a nic se neuloží.
     */
    public function bridgeBankAccounts(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody();
        $ids  = $body['personBankAccountIds'] ?? null;
        if (!is_array($ids) || $ids === [] || $ids !== array_filter($ids, is_int(...))) {
            return Response::error('BAD_REQUEST', '`personBankAccountIds` must be a non-empty list of integers', 400);
        }
        $ids = array_values(array_unique($ids));

        $defaultId = $body['defaultId'] ?? null;
        if ($defaultId !== null && !is_int($defaultId)) {
            return Response::error('BAD_REQUEST', '`defaultId` must be an integer or null', 400);
        }
        // Jediný překlápěný účet je výchozí automaticky (server-side pojistka
        // stejného pravidla, které drží frontend).
        if (count($ids) === 1) {
            $defaultId = $ids[0];
        }
        if ($defaultId !== null && !in_array($defaultId, $ids, true)) {
            return Response::error('BAD_REQUEST', '`defaultId` must be one of `personBankAccountIds`', 400);
        }

        $own = $this->ownPerson();
        if ($own === null) {
            return Response::error('NO_OWN_PERSON', 'No active own Person exists', 409);
        }

        // Jen spojení vlastní Osoby — cizí id je chyba klienta, ne no-op.
        $rows = [];
        foreach ($this->ownPersonBankAccounts((int) $own['id']) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $errors = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                $errors[] = [
                    'field'   => (string) $id,
                    'code'    => 'UNKNOWN_ACCOUNT',
                    'message' => "Bank account #{$id} does not belong to the own Person",
                ];
            }
        }

        [$codebookIbans, $codebookNumbers] = $this->existingCodebookKeys();
        foreach ($ids as $id) {
            if (isset($rows[$id]) && $this->existsInCodebook($rows[$id], $codebookIbans, $codebookNumbers)) {
                $errors[] = [
                    'field'   => (string) $id,
                    'code'    => 'ALREADY_IN_CODEBOOK',
                    'message' => "Bank account #{$id} is already present in the codebook",
                ];
            }
        }
        if ($errors !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
        }

        // Pořadí překlopu: order_pos (null u ručně pořízených až nakonec), pak id.
        $selected = array_map(static fn(int $id): array => $rows[$id], $ids);
        usort($selected, static function (array $a, array $b): int {
            $pa = $a['order_pos'] ?? null;
            $pb = $b['order_pos'] ?? null;
            return [$pa === null, (int) $pa] <=> [$pb === null, (int) $pb]
                ?: (int) $a['id'] <=> (int) $b['id'];
        });

        $codes     = $this->generateCodes(count($selected));
        $sortOrder = (int) $this->db->fetchSingle(
            'SELECT COALESCE(MAX(sort_order), 0) FROM ' . self::CODEBOOK_TABLE,
        );

        $created = [];
        foreach ($selected as $i => $row) {
            $payload = $this->codebookPayload($row, $codes[$i], ++$sortOrder, (int) $row['id'] === $defaultId);
            $result  = $this->saveBankAccountRow($payload);
            if (!$result->isSuccess()) {
                // Dřívější řádky už jsou uložené — opakovaný pokus je díky
                // existsInCodebook přeskočí, duplicita nevznikne.
                ErrorLogger::error('SetupController: bank account bridge failed', [
                    'personBankAccountId' => (int) $row['id'],
                    'message'             => $result->getErrorMessage(),
                ]);
                return Response::error(
                    'SAVE_FAILED',
                    "Saving bank account #{$row['id']} failed: " . ($result->getErrorMessage() ?? 'unknown error'),
                    500,
                );
            }
            $saved = $result->getData() ?? [];
            $created[] = [
                'id'   => (int) ($saved['id'] ?? 0),
                'code' => $payload['code'],
                'name' => $payload['name'],
            ];
        }

        return Response::success(['created' => $created]);
    }

    /**
     * Aktivní vlastní Osoba (is_own, docState 10/40) — memoizovaně; jeden
     * request se na ni ptá z více míst (suggestion, panelové akce, prefill,
     * můstek). null = žádná není.
     *
     * @return array{id: int|string, full_name: ?string, vat_id: ?string}|null
     */
    private function ownPerson(): ?array
    {
        if ($this->ownPersonCache === null) {
            $row = $this->db->fetchRow(
                'SELECT id, full_name, vat_id FROM base_persons_persons'
                    . ' WHERE is_own = %i AND docState IN %in ORDER BY id LIMIT 1',
                1,
                self::ACTIVE_DOC_STATES,
            );
            $this->ownPersonCache = $row ?? false;
        }
        return $this->ownPersonCache === false ? null : $this->ownPersonCache;
    }

    /** @return list<array<string, mixed>> aktivní bankovní spojení vlastní Osoby */
    private function ownPersonBankAccounts(int $personId): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, account_number, iban, bic, currency, source,'
                . ' order_pos, valid_from, valid_to'
                . ' FROM base_persons_bank_accounts'
                . ' WHERE person = %i AND docState IN %in'
                . ' ORDER BY (order_pos IS NULL), order_pos, id',
            $personId,
            self::ACTIVE_DOC_STATES,
        );
    }

    /**
     * Klíče aktivních řádků číselníku pro detekci „už překlopeno":
     * množina IBANů (uppercase) a čísel účtů.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function existingCodebookKeys(): array
    {
        $ibans   = [];
        $numbers = [];
        $rows = $this->db->fetchAll(
            'SELECT iban, account_number FROM ' . self::CODEBOOK_TABLE
                . ' WHERE docState IN %in',
            self::ACTIVE_DOC_STATES,
        );
        foreach ($rows as $row) {
            $iban   = strtoupper(trim((string) ($row['iban'] ?? '')));
            $number = trim((string) ($row['account_number'] ?? ''));
            if ($iban !== '') {
                $ibans[$iban] = true;
            }
            if ($number !== '') {
                $numbers[$number] = true;
            }
        }
        return [$ibans, $numbers];
    }

    /**
     * Match bankovního spojení proti číselníku: primárně IBAN, bez něj
     * číslo účtu. Spojení bez obojího (nemělo by projít validací Osoby)
     * se považuje za nepřeklopené.
     *
     * @param array<string, mixed> $row řádek base_persons_bank_accounts
     * @param array<string, true> $codebookIbans
     * @param array<string, true> $codebookNumbers
     */
    private function existsInCodebook(array $row, array $codebookIbans, array $codebookNumbers): bool
    {
        $iban = strtoupper(trim((string) ($row['iban'] ?? '')));
        if ($iban !== '') {
            return isset($codebookIbans[$iban]);
        }
        $number = trim((string) ($row['account_number'] ?? ''));
        return $number !== '' && isset($codebookNumbers[$number]);
    }

    /**
     * Krátké sekvenční kódy BU1, BU2, … s posunem přes existující kódy
     * (kolize řeší posun sekvence, ne selhání). Číselník žádnou konvenci
     * kódů nemá — seedy jiných číselníků mají fixní kódy a
     * NumberSeriesProvisioner řeší řady dokladů, ne kódy záznamů.
     *
     * @return list<string>
     */
    private function generateCodes(int $count): array
    {
        $existing = [];
        foreach ($this->db->fetchAll('SELECT code FROM ' . self::CODEBOOK_TABLE) as $row) {
            $existing[strtoupper(trim((string) ($row['code'] ?? '')))] = true;
        }

        $codes = [];
        $n = 1;
        while (count($codes) < $count) {
            $candidate = 'BU' . $n++;
            if (!isset($existing[$candidate])) {
                $codes[] = $candidate;
                $existing[$candidate] = true;
            }
        }
        return $codes;
    }

    /**
     * Mapování řádku base_persons_bank_accounts → economy_codebooks_bank_accounts.
     * bank_name zůstává null (v bankovních spojeních Osoby neexistuje a název
     * banky z kódu banky se nedopočítává — číselník bank není). Normalizaci
     * měny/IBAN/BIC dotáhne BankAccountDocument::beforeSave, tady jen
     * fallbacky pro not null sloupce.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function codebookPayload(array $row, string $code, int $sortOrder, bool $isDefault): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            // Název banky není z čeho vzít — poslední čtyřčíslí účtu je
            // nejkratší identifikace, kterou uživatel pozná.
            $digits = preg_replace('/\D/', '', (string) ($row['account_number'] ?? ''));
            if ($digits === null || $digits === '') {
                $digits = preg_replace('/\D/', '', (string) ($row['iban'] ?? '')) ?? '';
            }
            $tail = $digits !== '' ? substr($digits, -4) : $code;
            $name = ($this->language === 'cs' ? 'Účet …' : 'Account …') . $tail;
        }

        $currency = strtolower(trim((string) ($row['currency'] ?? '')));

        return [
            'code'           => $code,
            'name'           => mb_substr($name, 0, 150),
            'account_number' => trim((string) ($row['account_number'] ?? '')),
            'iban'           => trim((string) ($row['iban'] ?? '')),
            'bic'            => trim((string) ($row['bic'] ?? '')),
            'currency'       => $currency === '' ? 'czk' : $currency,
            'is_default'     => $isDefault ? 1 : 0,
            'valid_from'     => $row['valid_from'] ?? null,
            'valid_to'       => $row['valid_to'] ?? null,
            'sort_order'     => $sortOrder,
            // Data z registru / evidence Osoby — rovnou V pořádku,
            // stejně jako targetDocState registrového importu (Task 08).
            'docState'       => 40,
        ];
    }

    /**
     * Uložení jednoho řádku číselníku přes Document flow. Protected seam
     * pro testy (subclassing, vzor TestableDsCreateCommand) — TableGateway
     * potřebuje živé dibi spojení, které unit test nemá.
     */
    protected function saveBankAccountRow(array $payload): DocumentResult
    {
        $gateway = $this->buildBankAccountsGateway();
        if ($gateway === null) {
            return DocumentResult::error('Table definition or document registry unavailable');
        }
        return $gateway->saveDocument($payload);
    }

    /** Paralela k AnalysisController::buildHeadsGateway(). */
    private function buildBankAccountsGateway(): ?TableGateway
    {
        $def = $this->tables[self::CODEBOOK_TABLE] ?? null;
        if ($def === null || $this->documentRegistry === null) {
            return null;
        }
        return new TableGateway(
            self::CODEBOOK_TABLE,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->eventDispatcher,
            $def->docStates,
        );
    }

    /**
     * Options frekvencí DPH z cfgItem vatPeriodKinds — obsahuje jen 1/2
     * (Měsíční/Čtvrtletní), rezervovaná 0 v cfgItem není, takže se do
     * nabídky nedostane.
     *
     * @return list<array{value: int|string, label: string}>
     */
    private function periodKindOptions(): array
    {
        $cfg = $this->config->cfgItem('economy.codebooks.vatPeriodKinds');
        if (!is_array($cfg)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfg, 'enumInt', 'economy.codebooks.vatPeriodKinds');
    }

    /**
     * @return array{items: list<array<string, mixed>>, parameters: array<string, mixed>,
     *               currencyOptions: list<array{value: int|string, label: string}>}
     */
    private function buildState(SettingsStore $settings): array
    {
        $checklist = new SetupChecklist($this->db, $this->registry, $this->config, $this->language);

        $items = [];
        foreach ($checklist->collect() as $item) {
            $finding = $item['finding'];
            $items[] = [
                'checkId'   => $item['checkId'],
                'name'      => $item['name'],
                'title'     => $finding->title,
                'message'   => $finding->message,
                'severity'  => $finding->severity,
                // Panelové akce se skládají TADY, ne v checku — finding
                // checku putuje cronem do core_alerts_alerts a feed/viewer
                // alertů umí jen open_form/open_viewer/open_panel.
                'actions'   => $this->panelActions($item['checkId'], $finding->actions),
                // U položek nad nerozhodnutým parametrem klíč vrstvy C —
                // panel podle něj vykreslí ovládání. Mapování drží server.
                'parameter' => SetupChecklist::PARAMETER_BY_CHECK[$item['checkId']] ?? null,
            ];
        }

        $items = $this->attachVatAgendaSuggestion($items);

        return [
            'items'           => $items,
            // Hodnoty VŠECH klíčů včetně null — panel potřebuje i rozhodnuté
            // parametry, aby šly změnit, ne jen doplnit.
            'parameters'      => $settings->getMany(LayerCParameters::keys()),
            'currencyOptions' => $this->currencyOptions(),
        ];
    }

    /**
     * Panelové úpravy akcí položky (docs/ds-setup.md §5.4). Vybrané checky
     * dostanou předřazenou primární akci s kindem, který umí obsloužit
     * jedině panel — proto vzniká jen tady, ne v checku: finding checku
     * putuje cronem do core_alerts_alerts a ve feedu/vieweru alertů by
     * neznámý kind skončil s console.warn. Akce z checku (open_form) se
     * degradují na sekundární „Zadat ručně".
     *
     *   - missing_own_person       → registry_import_own (Task 08, D16)
     *   - missing_vat_registration → prefill_vat_registration (Task 09);
     *     jen s vlastní Osobou — bez ní není z čeho předvyplnit
     *   - missing_own_bank_account → bridge_bank_accounts (Task 09, D17);
     *     jen když má vlastní Osoba aspoň jedno bankovní spojení
     *
     * @param list<array<string, mixed>> $checkActions
     * @return list<array<string, mixed>>
     */
    private function panelActions(string $checkId, array $checkActions): array
    {
        $isCs = $this->language === 'cs';

        switch ($checkId) {
            case 'base.persons.missing_own_person':
                return $this->withPrimaryPanelAction($checkActions, [
                    'id'      => 'import_own_person_from_registry',
                    'label'   => $isCs ? 'Načíst z registru' : 'Load from registry',
                    'kind'    => 'registry_import_own',
                    'target'  => [],
                    'primary' => true,
                ]);

            case 'economy.codebooks.missing_vat_registration':
                if ($this->ownPerson() === null) {
                    return $checkActions;
                }
                return $this->withPrimaryPanelAction($checkActions, [
                    'id'      => 'prefill_vat_registration',
                    'label'   => $isCs ? 'Založit Registraci DPH' : 'Add VAT registration',
                    'kind'    => 'prefill_vat_registration',
                    'target'  => [],
                    'primary' => true,
                ]);

            case 'economy.codebooks.missing_own_bank_account':
                $own = $this->ownPerson();
                if ($own === null || $this->ownPersonBankAccounts((int) $own['id']) === []) {
                    return $checkActions;
                }
                return $this->withPrimaryPanelAction($checkActions, [
                    'id'      => 'bridge_bank_accounts',
                    'label'   => $isCs ? 'Převzít z vlastní Osoby' : 'Take over from own Person',
                    'kind'    => 'bridge_bank_accounts',
                    'target'  => [],
                    'primary' => true,
                ]);
        }

        return $checkActions;
    }

    /**
     * Předřadí panelovou primární akci; akce z checku degraduje na
     * sekundární „Zadat ručně" (ruční cesta zůstává dostupná vždy).
     *
     * @param list<array<string, mixed>> $checkActions
     * @param array<string, mixed> $primary
     * @return list<array<string, mixed>>
     */
    private function withPrimaryPanelAction(array $checkActions, array $primary): array
    {
        $isCs = $this->language === 'cs';

        $secondary = array_map(
            static fn(array $action): array => [
                'label'   => $isCs ? 'Zadat ručně' : 'Enter manually',
                'primary' => false,
            ] + $action,
            $checkActions,
        );

        return [$primary, ...$secondary];
    }

    /**
     * Návrh hodnoty `economy.vatAgenda` podle DIČ vlastní Osoby (D5:
     * přítomnost DIČ = použitelný default, ne pravda). Jen předvolba v UI —
     * `parameters` dál drží null, dokud uživatel nepotvrdí (D2).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function attachVatAgendaSuggestion(array $items): array
    {
        foreach ($items as $i => $item) {
            if ($item['checkId'] !== 'economy.codebooks.undecided_vat_agenda') {
                continue;
            }
            $vatId = trim((string) ($this->ownPerson()['vat_id'] ?? ''));
            if ($vatId === '') {
                break;
            }
            $items[$i]['suggestion'] = [
                'value'  => true,
                'reason' => $this->language === 'cs'
                    ? "Vlastní Osoba má vyplněné DIČ {$vatId} — pravděpodobně jste plátce DPH."
                    : "The own Person has VAT ID {$vatId} filled in — you are probably a VAT payer.",
            ];
            break;
        }

        return $items;
    }

    /**
     * Options pro select domácí měny — cfgItem world.base.currencies žije
     * v compiled configu, frontend seznam měn nezná (a nemá hardcodovat).
     *
     * @return list<array{value: int|string, label: string}>
     */
    private function currencyOptions(): array
    {
        $cfg = $this->config->cfgItem('world.base.currencies');
        if (!is_array($cfg)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');
    }

    /**
     * Okamžitý běh provisionerů dotčených zapsanými klíči — bez něj by
     * uživatel parametr rozhodl a nic by se nestalo až do dalšího
     * ds-upgrade (D12). Selhání provisioneru parametr NEODUKLÁDÁ:
     * uloženo-a-neprovisionováno dorovná ds-upgrade, opačný stav je horší.
     *
     * @param list<string> $writtenKeys
     * @return list<string> lidsky čitelná varování pro panel
     */
    private function runProvisioners(array $writtenKeys, SettingsStore $settings): array
    {
        $warnings = [];

        if (in_array('economy.accountChart', $writtenKeys, true)) {
            $warnings = [...$warnings, ...$this->provisionAccountChart($settings)];
        }

        if (in_array('economy.fiscalYearStartMonth', $writtenKeys, true)
            || in_array('economy.homeCurrency', $writtenKeys, true)) {
            $warnings = [...$warnings, ...$this->provisionFiscalYears($settings)];
        }

        return $warnings;
    }

    /** @return list<string> */
    private function provisionAccountChart(SettingsStore $settings): array
    {
        // none = vlastní osnova (neseeduje se), null = vráceno do nerozhodnuto.
        $variant = $settings->get('economy.accountChart');
        $file = match ($variant) {
            'default' => 'accountChartDefault.jsonc',
            'npo'     => 'accountChartNpo.jsonc',
            default   => null,
        };
        if ($file === null) {
            return [];
        }

        // Stejná cesta k seed souboru jako DsUpgradeCommand::provisionAccountChart.
        $modulePath = $this->modulePathResolver->getPath('economy.accounting');
        $seedFile   = ($modulePath ?? '') . '/config/' . $file;
        if ($modulePath === null || !is_file($seedFile)) {
            ErrorLogger::error('SetupController: account chart seed file not found', ['file' => $file]);
            return [$this->warnProvisionerFailed('accountChart')];
        }

        try {
            (new AccountChartProvisioner($this->db, $seedFile))->provision();
        } catch (\Throwable $e) {
            ErrorLogger::error('SetupController: account chart provisioning failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return [$this->warnProvisionerFailed('accountChart')];
        }
        return [];
    }

    /** @return list<string> */
    private function provisionFiscalYears(SettingsStore $settings): array
    {
        // Gate na OBA klíče — stejná logika jako DsUpgradeCommand (D6).
        $startMonth = $settings->get('economy.fiscalYearStartMonth');
        $currency   = $settings->get('economy.homeCurrency');
        if ($startMonth === null || !is_string($currency) || $currency === '') {
            return [];
        }

        try {
            (new FiscalYearsProvisioner($this->db, $this->config, (int) $startMonth, $currency))->provision();
        } catch (\Throwable $e) {
            ErrorLogger::error('SetupController: fiscal years provisioning failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return [$this->warnProvisionerFailed('fiscalYears')];
        }
        return [];
    }

    private function warnProvisionerFailed(string $what): string
    {
        $isCs = $this->language === 'cs';
        $label = match ($what) {
            'accountChart' => $isCs ? 'účtové osnovy' : 'account chart',
            default        => $isCs ? 'fiskálních roků' : 'fiscal years',
        };
        return $isCs
            ? "Parametr je uložený, ale naseedování {$label} selhalo — dorovná ho příští ds-upgrade."
            : "The parameter was saved, but seeding of the {$label} failed — the next ds-upgrade will catch up.";
    }
}
