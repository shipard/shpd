<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Settings\SettingsStore;

/**
 * Přepočet hlavičky dokladu po změně dětské tabulky (řádky, rekapitulace
 * DPH). Sdílejí ho `DocRowsDocument` a `VatRecapDocument`: obě mění data,
 * ze kterých hlavička počítá součty, a obě chtějí přesně jednu věc —
 * pustit compute pipeline hlavičky znovu a zapsat výsledek.
 *
 * Proč se instancuje `DocsHeadsDocument` přímo a ne přes `TableGateway`:
 * gateway by znovu syncoval řádky, volal `validate()` a řešil přechody
 * stavů. Tady jde jen o výpočet — `beforeSave` hlavičky dá součty,
 * rekapitulaci i dorovnané řádky. `validate()` se záměrně přeskakuje:
 * doklad v Konceptu nemusí splňovat kontroly pro Potvrdit (bez partnera,
 * bez řádků) a přepočet ho nemá blokovat.
 *
 * Rekapitulace se zapisuje dvěma způsoby:
 * - **přepočítaná** — smazat a vložit znovu (vzniká celá z řádků);
 * - **převzatá** — UPDATE na místě podle `id`, aby ručně editovaná
 *   rekapitulace nepřišla o identitu řádků (sub-tabulka na ni odkazuje)
 *   a aby přepočet nikdy nezměnil částky, které jsou vstupem.
 */
final class DocHeadRecomputer
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?SettingsStore $settings = null,
    ) {}

    /** Přepočte hlavičku dokladu z aktuálního stavu jejích dětských tabulek. */
    public function recompute(int $headId): void
    {
        if ($headId <= 0) {
            return;
        }
        $headRow = $this->db->fetch('SELECT * FROM [docs_core_heads] WHERE [id] = %i', $headId);
        if ($headRow === null) {
            return;
        }
        $headData = $headRow->toArray();

        $headDoc = new DocsHeadsDocument();
        $headDoc->setDb($this->db);
        if ($this->config !== null) {
            $headDoc->setConfig($this->config);
        }
        if ($this->dsConfig !== null) {
            $headDoc->setDsConfig($this->dsConfig);
        }
        if ($this->settings !== null) {
            $headDoc->setSettings($this->settings);
        }

        // `rows` v $headData nejsou → resolveRowsForCompute je načte z DB,
        // stejně jako rekapitulaci u převzatého zdroje.
        $headDoc->beforeSave($headData);

        $declared = (int) ($headData['vat_recap_source'] ?? 0) === 1;

        $this->db->begin();
        try {
            $this->db->update('docs_core_heads', [
                'total_base'         => $headData['total_base']         ?? 0,
                'total_vat'          => $headData['total_vat']          ?? 0,
                'total_amount'       => $headData['total_amount']       ?? 0,
                'total_rounding'     => $headData['total_rounding']     ?? 0,
                'total_base_dom'     => $headData['total_base_dom']     ?? 0,
                'total_vat_dom'      => $headData['total_vat_dom']      ?? 0,
                'total_amount_dom'   => $headData['total_amount_dom']   ?? 0,
                'total_rounding_dom' => $headData['total_rounding_dom'] ?? 0,
            ])->where('id = %i', $headId)->execute();

            // Computed row columns (vat_* + _dom) — rows byly načtené z DB,
            // takže všechny mají id.
            $headDoc->persistRowComputedColumns();

            $recap = is_array($headData['vatRecap'] ?? null) ? $headData['vatRecap'] : [];
            $declared ? $this->updateRecapInPlace($headId, $recap) : $this->replaceRecap($headId, $recap);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Přepočítaná rekapitulace: celá znovu (delete + insert).
     *
     * @param array<int, array<string, mixed>> $recap
     */
    private function replaceRecap(int $headId, array $recap): void
    {
        $this->db->delete('docs_core_vat_recap')->where('doc_head = %i', $headId)->execute();
        foreach ($recap as $line) {
            if (!is_array($line)) {
                continue;
            }
            unset($line['id']);
            $line['doc_head'] = $headId;
            $this->db->insert('docs_core_vat_recap', $line)->execute();
        }
    }

    /**
     * Převzatá rekapitulace: UPDATE podle `id` (částky jsou vstup, mění se
     * jen odvozené `_dom` a flagy sčítání). Řádek bez `id` je nový, řádek
     * v DB mimo sadu se maže — stejná sémantika jako child sync gateway.
     *
     * @param array<int, array<string, mixed>> $recap
     */
    private function updateRecapInPlace(int $headId, array $recap): void
    {
        $keptIds = [];
        foreach ($recap as $line) {
            if (!is_array($line)) {
                continue;
            }
            $line['doc_head'] = $headId;
            $id = (int) ($line['id'] ?? 0);
            unset($line['id']);
            if ($id > 0) {
                $keptIds[] = $id;
                $this->db->update('docs_core_vat_recap', $line)->where('id = %i', $id)->execute();
                continue;
            }
            $this->db->insert('docs_core_vat_recap', $line)->execute();
            $keptIds[] = (int) $this->db->getInsertId();
        }

        if ($keptIds === []) {
            $this->db->delete('docs_core_vat_recap')->where('doc_head = %i', $headId)->execute();
            return;
        }
        $this->db->delete('docs_core_vat_recap')
            ->where('doc_head = %i', $headId)
            ->where('id NOT IN %in', $keptIds)
            ->execute();
    }
}
