<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
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

        // Účet jen pro typ 2 (Účetní položka) — řádek dokladu s pohybem
        // acc.entry se účtuje přímo na tento účet. item_type se odvozuje
        // z item_kind v recalculate (triggers: reload), viditelnost se tedy
        // přepočítá při změně druhu.
        $isAccEntryItem = (int) ($data['item_type'] ?? 0) === 2;

        // ── Tab: Základní údaje ──────────────────────────────────────────────
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('name', required: true)

            ->section(title: 'Klasifikace')
                ->col()
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

            ->section(title: 'Účetnictví', hidden: !$isAccEntryItem)
                ->col()
                    ->lookup('accounting_account',
                        table: 'economy_accounting_accounts',
                        filter: ['account_level' => 4],
                        placeholder: 'Hledat účet…',
                        hidden: !$isAccEntryItem,
                    )

            ->section(title: 'Cena')
                ->col()
                    ->number('sales_price_no_vat')

            ->section(title: 'Platnost')
                ->col()
                    ->date('valid_from')
                    ->date('valid_to')
            ->build();

        // ── Tab: Popis ───────────────────────────────────────────────────────
        $description = $this->tab('description', 'Popis')
            ->section()
                ->col()
                    ->textarea('description')
            ->build();

        // ── Tab: Nastavení (úplně na konci, za Přílohami) ───────────────────
        $settings = $this->tab('settings', 'Nastavení')
            ->section(title: 'Identifikace')
                ->col()
                    ->input('code')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Položka',
            titleNew: 'Nová položka',
            tabs: [$basic, $description, $this->attachmentsTab(), $settings],
        );
    }

    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $info = [];

        $kindName = $this->resolveItemKindName((int) ($data['item_kind'] ?? 0));
        if ($kindName !== '') {
            $info[] = ['label' => 'Druh', 'value' => $kindName];
        }

        $code = trim((string) ($data['code'] ?? ''));
        if ($code !== '') {
            $info[] = ['label' => 'Kód', 'value' => $code];
        }

        $validity = $this->formatValidityRange(
            $data['valid_from'] ?? null,
            $data['valid_to'] ?? null,
        );
        if ($validity !== '') {
            $info[] = ['label' => 'Platí', 'value' => $validity];
        }

        return new FormHeaderInfo(
            title: $name,
            info: $info,
            icon: 'box',
        );
    }

    /**
     * Resolvuje jméno druhu položky z FK item_kind. Vrací prázdný string,
     * pokud druh není vybraný, není DB k dispozici, nebo druh již neexistuje
     * (referenční integrita je aplikační). Archivované druhy (`docState=70`)
     * se schválně nefiltrují — položka může být v archivovaném druhu a uživatel
     * to pořád potřebuje vidět.
     */
    protected function resolveItemKindName(int $kindId): string
    {
        if ($kindId === 0 || $this->db === null) {
            return '';
        }
        $row = $this->db->fetchRow(
            'SELECT `name` FROM `economy_items_kinds` WHERE `id` = %i',
            $kindId,
        );
        if (!is_array($row) || empty($row['name'])) {
            return '';
        }
        return trim((string) $row['name']);
    }

    /**
     * Formátuje rozsah platnosti položky pro subtitle hlavičky:
     *   - oba data:   „14.05.2024 – 31.12.2024"
     *   - jen od:     „od 14.05.2024"
     *   - jen do:     „do 31.12.2024"
     *   - ani jedno:  prázdný string (volající pak položku v info vynechá)
     */
    protected function formatValidityRange(mixed $validFrom, mixed $validTo): string
    {
        $from = $this->formatHeaderDate($validFrom);
        $to   = $this->formatHeaderDate($validTo);

        if ($from !== '' && $to !== '') {
            return $from . ' – ' . $to;
        }
        if ($from !== '') {
            return 'od ' . $from;
        }
        if ($to !== '') {
            return 'do ' . $to;
        }
        return '';
    }

    /**
     * Bezpečně z DB datové hodnoty (DATE jako 'Y-m-d' string) udělá formát
     * vhodný pro hlavičku („14.05.2024"). Konzistentní s PersonsForm.
     */
    protected function formatHeaderDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
        return $dt instanceof \DateTimeImmutable ? $dt->format('d.m.Y') : '';
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
