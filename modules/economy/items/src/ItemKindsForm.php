<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class ItemKindsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $hasSystemCode = !empty($data['system_code']);

        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select('item_type',
                        options: $this->resolveItemTypeOptions(),
                        required: true,
                        readOnly: !$isNew,
                    )
                    ->date('valid_from')
                    ->date('valid_to')
                    ->input('system_code', readOnly: true, hidden: !$hasSystemCode)
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Druh položky',
            titleNew: 'Nový druh položky',
            tabs: [$basic, $this->attachmentsTab()],
            fullSize: false,
        );
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveItemTypeOptions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfgData = $this->config->cfgItem('economy.items.itemTypes');
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
