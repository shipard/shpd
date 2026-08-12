<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Živé spuštění setup checků (`tags: ["setup"]`) pro panel checklistu —
 * druhá čtecí cesta vedle tabulky alertů (docs/ds-setup.md D12).
 *
 * Checky mají vlastní `interval` a cron runner přeskakuje ty, kde
 * `next_run_at > NOW` — panel by z tabulky alertů hlásil chybějící
 * nastavení ještě dlouho poté, co ho uživatel doplnil. Proto se tady
 * checky spouštějí naživo; implementace checku je jedna (AlertCheck),
 * `SetupChecklist` je jen druhý volající.
 *
 * **Do tabulky alertů nezapisuje nic** — to je práce cronu
 * (`alerts-run` → AlertReconciler). Panel se načítá při každém otevření
 * a nesmí si míchat zápisy s reconcilerem.
 *
 * Umístění vedle LayerCParameters: setup je vlastnost nastavení DS,
 * ne alertů.
 */
final class SetupChecklist
{
    /**
     * Pořadí položek checklistu — kontrakt pro panel (Task 06) i budoucího
     * průvodce (kroky se z něj generují). Řídí se závislostmi, ne abecedou:
     * bez vlastní Osoby nemá smysl sídlo, bez rozhodnutí o agendě DPH
     * registrace. Check, který v konstantě není (přidaný později bez zápisu
     * sem), jde na konec — ne výjimka.
     */
    public const ORDER = [
        'base.persons.missing_own_person',
        'base.persons.missing_own_headquarters',
        'economy.codebooks.undecided_vat_agenda',
        'economy.codebooks.missing_vat_registration',
        'economy.codebooks.missing_own_bank_account',
        'economy.accounting.undecided_account_chart',
        'economy.codebooks.undecided_fiscal_year_start',
        'economy.codebooks.undecided_home_currency',
    ];

    /**
     * Mapa check → klíč vrstvy C pro checky nad nerozhodnutým parametrem.
     * Čte ji SetupController (`GET /_setup/checklist` pole `parameter`),
     * aby panel věděl, jaké ovládání k položce vykreslit — mapování patří
     * na server, ne do Svelte. Mění se spolu s ORDER; pokrytí všech
     * `undecided_*` checků hlídá regresní test v SetupChecklistTest.
     */
    public const PARAMETER_BY_CHECK = [
        'economy.codebooks.undecided_vat_agenda'        => 'economy.vatAgenda',
        'economy.accounting.undecided_account_chart'    => 'economy.accountChart',
        'economy.codebooks.undecided_fiscal_year_start' => 'economy.fiscalYearStartMonth',
        'economy.codebooks.undecided_home_currency'     => 'economy.homeCurrency',
    ];

    private const SETUP_TAG = 'setup';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
    ) {}

    /**
     * Spustí naživo všechny enabled checky s tagem `setup` a vrátí jejich
     * nálezy v pořadí ORDER. Vše nastaveno → prázdný list.
     *
     * Fail-open po jednotlivých checcích: výjimka se zaloguje a sběr
     * pokračuje — rozbitý check nesmí zneprůchodnit celý panel.
     *
     * @return list<array{checkId: string, name: string, finding: AlertFinding}>
     */
    public function collect(): array
    {
        $defs = array_filter(
            $this->registry->getEnabled(),
            static fn($def): bool => in_array(self::SETUP_TAG, $def->tags, true),
        );

        $orderIndex = array_flip(self::ORDER);
        usort($defs, static function ($a, $b) use ($orderIndex): int {
            $ia = $orderIndex[$a->id] ?? PHP_INT_MAX;
            $ib = $orderIndex[$b->id] ?? PHP_INT_MAX;
            return $ia <=> $ib ?: strcmp($a->id, $b->id);
        });

        $items = [];
        foreach ($defs as $def) {
            try {
                $check = $this->instantiateCheck($def->class);
                foreach ($check->run() as $finding) {
                    $items[] = [
                        'checkId' => $def->id,
                        'name'    => $def->name,
                        'finding' => $finding,
                    ];
                }
            } catch (\Throwable $e) {
                ErrorLogger::error("SetupChecklist: check '{$def->id}' failed", [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        return $items;
    }

    /** Stejná instanciace jako AlertReconciler::instantiateCheck(). */
    private function instantiateCheck(string $fqcn): AlertCheck
    {
        if (!class_exists($fqcn)) {
            throw new \RuntimeException("AlertCheck class not found: {$fqcn}");
        }
        $instance = new $fqcn($this->db, $this->config, $this->language);
        if (!$instance instanceof AlertCheck) {
            throw new \RuntimeException("Class {$fqcn} does not extend AlertCheck");
        }
        return $instance;
    }
}
