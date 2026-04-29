<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Units;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class UnitsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $hasSystemCode = !empty($data['system_code']);

        $basic = $this->tab('basic', 'Základní údaje')
            ->addInput('name', cols: 2, required: true)
            ->addInput('shortcut', cols: 1, required: true)
            ->addInput('system_code', cols: 1, readOnly: true, hidden: !$hasSystemCode)
            ->addSelect('quantity', cols: 1, options: $this->resolveQuantityOptions(), required: true)
            ->addNumber('coefficient', cols: 1,
                hint: 'Koeficient k základní jednotce. Prázdné = nepřevoditelné.',
            )
            ->addCheckbox('is_base', cols: 1)
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Měrná jednotka',
            titleNew: 'Nová měrná jednotka',
            tabs: [$basic, $this->attachmentsTab()],
            fullSize: false,
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveQuantityOptions(): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem('core.units.quantities');
        if (!is_array($cfgData)) {
            return [];
        }

        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}
