<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class DataSourcesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        // ds_id a url_app nejsou required — u požadavku (lifecycle request)
        // je generuje HostingDataSourceDocument::beforeSave; povinnost polí
        // požadavku hlídá validate().
        $basic = $this->tab('basic', 'Základní údaje')
            ->section('Identifikace')
                ->col()
                    ->input('ds_id', hint: 'Formát xxxx-xxxx-xxxx-xxxx. Prázdné u požadavku = vygeneruje se.')
                    ->input('name', required: true)
                    ->input('web_id', hint: 'Slug pro mail adresy a URL aplikace. Prázdné = nepřidělen.')
            ->section('Umístění')
                ->col()
                    ->select('server', options: $this->resolveServerOptions())
                    ->input('url_app', hint: 'Prázdné u požadavku = odvodí se z web_id a základní domény.')
                    ->input('install_module')
                    ->select('lifecycle', options: $this->resolveLifecycleOptions(), required: true)
                    ->select('owner', options: $this->resolveOwnerOptions())
            ->section('Pošta')
                ->col()
                    // Sensitive pole (opt-in přes getEditableSensitiveColumns):
                    // data ho nikdy neobsahují, input startuje prázdný, prázdný
                    // submit hodnotu nemění. Slouží pro ruční backfill tokenů
                    // existujících DS; provisioning ho plní confirmem.
                    ->input(
                        'mail_token',
                        placeholder: '●●●●●● (zadat pro změnu)',
                        hint: 'Token DS pro příjem pošty (shpd_ak_…). Normálně ho hlásí provisioning; ručně jen backfill.',
                        inputType: 'password',
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Zdroj dat',
            titleNew: 'Nový zdroj dat',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }

    /**
     * mail_token je jediné sensitive pole editovatelné tímto formem —
     * ruční backfill tokenu (D4); šifrování řeší HostingDataSourceDocument.
     *
     * @return list<string>
     */
    public function getEditableSensitiveColumns(): array
    {
        return ['mail_token'];
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
     * Vlastník DS (U1) — záměrně select s přednačtenými options (vzor
     * DsUsersForm), ne lookup: LookupController nemá TableAccessGuard
     * a lookup na core_system_users by vystavil seznam uživatelů
     * ne-admin portálovým účtům.
     *
     * @return list<array{value: int, label: string}>
     */
    private function resolveOwnerOptions(): array
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
