<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
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
 *   GET  /_setup/checklist   — živý checklist (SetupChecklist::collect) + hodnoty parametrů vrstvy C
 *   POST /_setup/parameters  — zápis parametrů vrstvy C + okamžitý běh dotčených provisionerů
 *
 * Backend panelu dsSetup (docs/ds-setup.md D12/D14). Auth: přihlášený
 * uživatel, bez adminOnly — v jednouživatelských DS by to zablokovalo
 * majitele (stejná úroveň jako /_settings/page).
 */
class SetupController
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
        private readonly ModulePathResolver $modulePathResolver,
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
     * Panelové úpravy akcí položky. U `missing_own_person` se předřadí
     * primární akce `registry_import_own` (import vlastní Osoby z registru,
     * docs/ds-setup.md §5.4/D16) a akce z checku se degradují na sekundární
     * „Zadat ručně". Jen tady, ne v checku — kind `registry_import_own` umí
     * obsloužit jedině panel; v alertech/feedu by skončil s console.warn.
     *
     * @param list<array<string, mixed>> $checkActions
     * @return list<array<string, mixed>>
     */
    private function panelActions(string $checkId, array $checkActions): array
    {
        if ($checkId !== 'base.persons.missing_own_person') {
            return $checkActions;
        }

        $isCs = $this->language === 'cs';

        $secondary = array_map(
            static fn(array $action): array => [
                'label'   => $isCs ? 'Zadat ručně' : 'Enter manually',
                'primary' => false,
            ] + $action,
            $checkActions,
        );

        return [
            [
                'id'      => 'import_own_person_from_registry',
                'label'   => $isCs ? 'Načíst z registru' : 'Load from registry',
                'kind'    => 'registry_import_own',
                'target'  => [],
                'primary' => true,
            ],
            ...$secondary,
        ];
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
            // Stejná definice aktivní vlastní Osoby jako MissingOwnPersonCheck
            // (docState 10 = Koncept, 40 = V pořádku).
            $vatId = $this->db->fetchSingle(
                'SELECT vat_id FROM base_persons_persons'
                    . ' WHERE is_own = %i AND docState IN %in'
                    . ' ORDER BY id LIMIT 1',
                1,
                [10, 40],
            );
            if (!is_string($vatId) || trim($vatId) === '') {
                break;
            }
            $vatId = trim($vatId);
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
