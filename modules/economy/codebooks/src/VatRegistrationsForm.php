<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

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
            ->addInput('name', cols: 2, required: true)
            ->addInput('vat_id', cols: 2)
            ->addSelect('region', cols: 1, options: $regionOptions, required: true)
            ->addSelect('country', cols: 1, options: $countryOptions, required: true)
            ->addSelect('taxpayer_kind', cols: 1, options: $taxpayerKindOptions, required: true)
            ->addSeparator('Periodicita')
            ->addSelect('tax_period_kind', cols: 1, options: $periodKindOptions, required: true)
            ->addSelect('report_period_kind', cols: 1, options: $periodKindOptions, required: true)
            ->addSeparator('Platnost')
            ->addDate('valid_from', cols: 1, required: true)
            ->addDate('valid_to', cols: 1)
            ->build();

        $periods = $this->tab('periods', 'Období DPH')
            ->addSubtable(
                'economy_codebooks_vat_periods',
                'vat_registration',
                formId: 'economy.codebooks.vat_periods',
                sort: 'date_begin:asc',
            )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Registrace DPH',
            titleNew: 'Nová registrace DPH',
            tabs: [$basic, $periods],
            fullSize: true,
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

        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }

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

        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (int) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}
