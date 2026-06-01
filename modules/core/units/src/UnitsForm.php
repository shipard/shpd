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
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('shortcut', required: true)
                    ->input('system_code', readOnly: true, hidden: !$hasSystemCode)
                    ->select('quantity', options: $this->resolveQuantityOptions(), required: true)
                    ->number('coefficient',
                        hint: 'Koeficient k základní jednotce. Prázdné = nepřevoditelné.',
                    )
                    ->checkbox('is_base')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Měrná jednotka',
            titleNew: 'Nová měrná jednotka',
            tabs: [$basic, $this->attachmentsTab()],
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
