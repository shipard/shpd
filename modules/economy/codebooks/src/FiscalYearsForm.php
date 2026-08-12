<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Settings\SettingsStore;

class FiscalYearsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew && empty($data['currency'])) {
            // Settings `economy.homeCurrency` (ds-setup.md §5.2); nerozhodnutý
            // klíč → 'czk'. Jen default nového záznamu, uložená data nese sloupec.
            $value = $this->db !== null
                ? (new SettingsStore($this->db))->get('economy.homeCurrency')
                : null;
            $data['currency'] = is_string($value) && $value !== '' ? $value : 'czk';
        }

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('doc_number_prefix', required: true)
                    ->select('currency', options: $this->resolveCurrencyOptions(), required: true)
                    ->checkbox('locked')
                    ->date('date_begin', required: true)
                    ->date('date_end', required: true)
            ->build();

        $months = $this->subtableTab(
            'months',
            'Měsíce',
            'economy_codebooks_fiscal_months',
            'fiscal_year',
            formId: 'economy.codebooks.fiscal_months',
            sort: 'date_begin:asc',
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Fiskální rok',
            titleNew: 'Nový fiskální rok',
            tabs: [$basic, $months],
        );
    }

    /** @return list<array{value: int|string, label: string}> */
    private function resolveCurrencyOptions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('world.base.currencies');
        if (!is_array($cfg)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');
    }
}
