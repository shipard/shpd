<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class VatRegistrationsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew) {
            if (empty($data['region'])) {
                $data['region'] = 'eu';
            }
            if (empty($data['country'])) {
                $data['country'] = 'cz';
            }
            if (!isset($data['taxpayer_kind']) || $data['taxpayer_kind'] === '' || $data['taxpayer_kind'] === null) {
                $data['taxpayer_kind'] = 0;
            }
            if (!isset($data['tax_period_kind']) || $data['tax_period_kind'] === '' || $data['tax_period_kind'] === null) {
                $data['tax_period_kind'] = 1;
            }
            if (!isset($data['report_period_kind']) || $data['report_period_kind'] === '' || $data['report_period_kind'] === null) {
                $data['report_period_kind'] = 1;
            }
        }

        $regionOptions = $this->resolveStringOptions('world.trade.unions');
        $countryOptions = $this->resolveStringOptions('world.base.countries', sortByLabel: true);
        $taxpayerKindOptions = $this->resolveIntOptions('economy.codebooks.vatTaxpayerKinds');
        $periodKindOptions = $this->resolveIntOptions('economy.codebooks.vatPeriodKinds');

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('vat_id')
                    ->select('region', options: $regionOptions, required: true)
                    ->select('country', options: $countryOptions, required: true)
                    ->select('taxpayer_kind', options: $taxpayerKindOptions, required: true)
                    ->separator('Periodicita')
                    ->select('tax_period_kind', options: $periodKindOptions, required: true)
                    ->select('report_period_kind', options: $periodKindOptions, required: true)
                    ->separator('Platnost')
                    ->date('valid_from', required: true)
                    ->date('valid_to')
            ->build();

        $periods = $this->subtableTab(
            'periods',
            'Období DPH',
            'economy_codebooks_vat_periods',
            'vat_registration',
            formId: 'economy.codebooks.vat_periods',
            sort: 'date_begin:asc',
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Registrace DPH',
            titleNew: 'Nová registrace DPH',
            tabs: [$basic, $periods],
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function resolveStringOptions(string $cfgItemId, bool $sortByLabel = false): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }

        $options = EnumOptionsHelper::fromCfgData($cfgData, 'enumString', $cfgItemId);

        if ($sortByLabel) {
            usort($options, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        }

        return $options;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function resolveIntOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }

        return EnumOptionsHelper::fromCfgData($cfgData, 'enumInt', $cfgItemId);
    }
}
