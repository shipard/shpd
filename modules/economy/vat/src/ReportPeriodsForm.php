<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Editační formulář instance daňového tvrzení. Registrace jako select
 * (registrací je pár), typ z cfgItem `economy.vat.reportTypes`.
 */
class ReportPeriodsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew) {
            if (empty($data['report_type'])) {
                $data['report_type'] = 'return';
            }
            if (!isset($data['locked'])) {
                $data['locked'] = 0;
            }
        }

        $registrationOptions = $this->resolveRegistrationOptions();
        if ($isNew && empty($data['vat_registration']) && count($registrationOptions) === 1) {
            $data['vat_registration'] = $registrationOptions[0]['value'];
        }

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->select('vat_registration', options: $registrationOptions, required: true)
                    ->select('report_type', options: $this->resolveTypeOptions(), required: true)
                    ->input('name', required: true, hint: 'Např. 01/2026 nebo Q1/2026')
                    ->separator('Období')
                    ->date('date_begin', required: true)
                    ->date('date_end', required: true)
                    ->separator('Zámek')
                    ->checkbox('locked', hint: 'Vynucení zámku nad doklady přijde v další fázi.')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Daňové tvrzení',
            titleNew: 'Nové daňové tvrzení',
            tabs: [$basic],
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function resolveTypeOptions(): array
    {
        $cfgData = $this->config?->cfgItem('economy.vat.reportTypes');
        if (!is_array($cfgData)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfgData, 'enumString', 'economy.vat.reportTypes');
    }

    /** @return list<array{value: int, label: string}> */
    private function resolveRegistrationOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `vat_id` FROM `economy_codebooks_vat_registrations`'
            . ' WHERE `docState` != 90 ORDER BY `name`, `id`',
        );
        $options = [];
        foreach ($rows as $row) {
            $label = (string) $row['name'];
            if (!empty($row['vat_id'])) {
                $label .= ' (' . $row['vat_id'] . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }
}
