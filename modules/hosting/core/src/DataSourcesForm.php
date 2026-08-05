<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class DataSourcesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section('Identifikace')
                ->col()
                    ->input('ds_id', required: true, hint: 'Formát xxxx-xxxx-xxxx-xxxx.')
                    ->input('name', required: true)
                    ->input('web_id', hint: 'Slug pro mail adresy. Prázdné = nepřidělen.')
            ->section('Umístění')
                ->col()
                    ->select('server', options: $this->resolveServerOptions())
                    ->input('url_app', required: true)
                    ->input('install_module')
                    ->select('lifecycle', options: $this->resolveLifecycleOptions(), required: true)
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Zdroj dat',
            titleNew: 'Nový zdroj dat',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveServerOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `fqdn` FROM `hosting_core_servers`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `name` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) $row['name'] . ' (' . $row['fqdn'] . ')',
            ];
        }
        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveLifecycleOptions(): array
    {
        $cfgData = $this->config?->cfgItem('hosting.core.dsLifecycle');
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
