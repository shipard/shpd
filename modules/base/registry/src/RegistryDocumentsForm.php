<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Formulář dokumentu Spisovny (design §6.3).
 *
 * Taby:
 *   1) „Dokument" — vlevo title, druh (trigger reload — mění hint metadata),
 *      šanon, partner, číslo/značka, platnosti, poznámka; vpravo read-only
 *      náhledy příloh (attachmentsView)
 *   2) „Přílohy" — standardní AttachmentPanel (tableId 428)
 *   3) „Metadata" — generický JSON editor (textarea); dynamický formulář
 *      dle docKinds.fields je mimo rozsah fáze 1 (design §11 bod 3)
 *
 * Promoted sloupce (ref_number, valid_from, valid_to) edituje formulář
 * přímo — sync do metadata řeší RegistryDocumentDocument::beforeSave.
 */
class RegistryDocumentsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $tableId = $this->tableDef?->tableId ?? 0;

        $basic = $this->tab('basic', 'Dokument')
            ->section()
                ->col()
                    ->input('title', required: true)
                    ->select('doc_kind',
                        options: $this->resolveKindOptions(),
                        required: true,
                        triggers: 'reload',
                    )
                    ->select('binder', options: $this->resolveBinderOptions())
                    ->lookup('partner', 'base_persons_persons')
                    ->input('ref_number')
                    ->separator('Platnost')
                    ->date('valid_from')
                    ->date('valid_to')
                    ->separator()
                    ->textarea('notice')
                ->col()
                    ->component('attachmentsView', params: ['table_id' => $tableId])
            ->build();

        $metadata = $this->tab('metadata', 'Metadata')
            ->section()
                ->col()
                    ->textarea('metadata',
                        hint: $this->metadataHint((string) ($data['doc_kind'] ?? '')),
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Dokument spisovny',
            titleNew: 'Nový dokument spisovny',
            tabs: [$basic, $this->attachmentsTab(), $metadata],
        );
    }

    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $info = [];
        $kindLabel = $this->resolveKindLabel((string) ($data['doc_kind'] ?? ''));
        if ($kindLabel !== '') {
            $info[] = ['label' => 'Druh', 'value' => $kindLabel];
        }

        return new FormHeaderInfo(
            title: $title,
            info: $info,
            icon: 'folder',
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // Změna druhu jen přestaví definici — hint metadata tabu ukazuje
        // pole nového druhu. Hodnoty (metadata, promoted sloupce) se nemění;
        // jejich sync řeší Document::beforeSave při uložení.
        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return list<array{value: string, label: string}> */
    private function resolveKindOptions(): array
    {
        $cfgData = $this->config?->cfgItem('base.registry.docKinds');
        if (!is_array($cfgData)) {
            return [['value' => 'other', 'label' => 'Other']];
        }

        $entries = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $entries[] = [
                'value' => (string) $key,
                'label' => (string) $entry['name'],
                'order' => (int) ($entry['order'] ?? 999),
            ];
        }
        usort($entries, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(
            static fn(array $e): array => ['value' => $e['value'], 'label' => $e['label']],
            $entries,
        );
    }

    /**
     * Živé šanony (docState ∈ {10, 40, 80}) dle order_pos. Prázdná volba
     * = Nezařazené (binder je nullable).
     *
     * @return list<array{value: int, label: string}>
     */
    private function resolveBinderOptions(): array
    {
        if ($this->db === null) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `base_registry_binders`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `order_pos` ASC, `name` ASC',
        );

        $options = [];
        foreach ($rows as $row) {
            $options[] = ['value' => (int) $row['id'], 'label' => (string) $row['name']];
        }
        return $options;
    }

    private function resolveKindLabel(string $kind): string
    {
        if ($kind === '') {
            return '';
        }
        $cfgData = $this->config?->cfgItem('base.registry.docKinds');
        if (is_array($cfgData) && isset($cfgData[$kind]['name'])) {
            return (string) $cfgData[$kind]['name'];
        }
        return $kind;
    }

    /** Hint metadata textarey — vyjmenuje pole aktuálního druhu. */
    private function metadataHint(string $kind): string
    {
        $base = 'JSON objekt druhově specifických polí. Promoted pole '
            . '(číslo, platnosti) synchronizuje uložení automaticky.';

        $cfgData = $this->config?->cfgItem('base.registry.docKinds');
        $fields = is_array($cfgData) ? ($cfgData[$kind]['fields'] ?? []) : [];
        if (!is_array($fields) || $fields === []) {
            return $base;
        }
        return $base . ' Pole druhu: ' . implode(', ', $fields) . '.';
    }
}
