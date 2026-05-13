<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

class ItemsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        // Default unit = pcs ("ks") for new records when nothing was prefilled
        if ($isNew && empty($data['unit']) && $this->db !== null) {
            $row = $this->db->fetchRow(
                "SELECT id FROM core_units WHERE system_code = 'pcs'",
            );
            if ($row !== null) {
                $data['unit'] = (int) $row['id'];
            }
        }

        $itemKindOptions = $this->resolveItemKindOptions();
        $unitOptions = $this->resolveUnitOptions();
        $itemTypeOptions = $this->resolveItemTypeOptions();

        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('code',
                        hint: 'Necháte-li prázdné, kód se vygeneruje automaticky.',
                    )
                    ->input('name', required: true)
                    ->separator('Klasifikace')
                    ->select('item_kind',
                        options: $itemKindOptions,
                        required: true,
                        triggers: 'reload',
                    )
                    ->select('item_type',
                        options: $itemTypeOptions,
                        readOnly: true,
                    )
                    ->select('unit',
                        options: $unitOptions,
                        required: true,
                    )
                    ->separator('Cena')
                    ->number('sales_price_no_vat')
                    ->separator('Platnost')
                    ->date('valid_from')
                    ->date('valid_to')
            ->build();

        $description = $this->tab('description', 'Popis')
            ->section()
                ->col()
                    ->textarea('description')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Položka',
            titleNew: 'Nová položka',
            tabs: [$basic, $description, $this->attachmentsTab()],
            fullSize: true,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'item_kind' && !empty($data['item_kind']) && $this->db !== null) {
            $row = $this->db->fetchRow(
                'SELECT item_type FROM economy_items_kinds WHERE id = %i',
                (int) $data['item_kind'],
            );
            if ($row !== null) {
                $data['item_type'] = (int) $row['item_type'];
            }
        }

        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveItemKindOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, name FROM economy_items_kinds'
            . ' WHERE docState IN (10, 40, 80)'
            . ' ORDER BY name ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = ['value' => (int) $row['id'], 'label' => (string) $row['name']];
        }
        return $options;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveUnitOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, name, shortcut FROM core_units'
            . ' WHERE docState IN (10, 40, 80)'
            . ' ORDER BY name ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $shortcut = (string) ($row['shortcut'] ?? '');
            $label = $shortcut !== '' ? "{$name} ({$shortcut})" : $name;
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
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
