<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class DsUsersForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        // `last_entered` ve formuláři záměrně není — plní ho až portál
        // (Fáze 5), ručně se needituje.
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->select('user', options: $this->resolveUserOptions(), required: true)
                    ->select('data_source', options: $this->resolveDataSourceOptions(), required: true)
                    ->select('role', options: $this->resolveRoleOptions(), required: true)
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Uživatel zdroje dat',
            titleNew: 'Nová vazba uživatele',
            tabs: [$basic],
        );
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveUserOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `full_name`, `login` FROM `core_system_users`'
            . ' WHERE `is_active` = 1 AND `is_system` = 0'
            . ' ORDER BY `full_name` ASC, `login` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $label = $name !== '' ? $name . ' (' . $row['login'] . ')' : (string) $row['login'];
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveDataSourceOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `ds_id` FROM `hosting_core_data_sources`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `name` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) $row['name'] . ' (' . $row['ds_id'] . ')',
            ];
        }
        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveRoleOptions(): array
    {
        $cfgData = $this->config?->cfgItem('hosting.core.dsUserRoles');
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
