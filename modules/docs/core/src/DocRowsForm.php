<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Sub-form for docs_core_rows (Phase 3).
 *
 * Loads parent header context (vat_registration → country, doc_type →
 * direction, vat_place, vat_duzp, vat_mode) on every render in order to
 * filter VAT codes and resolve vat_pct.
 */
class DocRowsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        $rowKind = (int) ($data['row_kind'] ?? 1);
        $isText = $rowKind === 0;
        $headHasVat = $headContext !== null
            && (int) ($headContext['vat_mode'] ?? 0) !== 0;

        if ($isNew) {
            $data['row_kind'] = $rowKind;
            if (!isset($data['price_calc_mode'])) {
                $data['price_calc_mode'] = 0;
            }
        }

        $tab = $this->tab('basic', 'Řádek')
            ->addSelect('row_kind', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.rowKinds'),
                triggers: 'reload',
                required: true,
            )
            ->addSelect('item', cols: 2,
                options: $this->resolveItemOptions(),
                triggers: 'reload',
                hidden: $isText,
            )
            ->addInput('description', cols: 4)

            ->addSeparator('Množství a cena', hidden: $isText)
            ->addNumber('quantity', cols: 1, triggers: 'reload', hidden: $isText)
            ->addSelect('unit', cols: 1,
                options: $this->resolveUnitOptions(),
                hidden: $isText,
            )
            ->addNumber('unit_price', cols: 1, triggers: 'reload', hidden: $isText)
            ->addNumber('total_price', cols: 1, triggers: 'reload', hidden: $isText)
            ->addSelect('price_calc_mode', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.priceCalcModes'),
                hidden: $isText,
            )

            ->addSeparator('Sleva', hidden: $isText)
            ->addNumber('discount_pct', cols: 1, hidden: $isText, hint: 'Sleva v %')
            ->addNumber('discount_amount', cols: 1, hidden: $isText, hint: 'Sleva absolutně');

        $showVat = $headHasVat && !$isText;
        $tab = $tab
            ->addSeparator('DPH', hidden: !$showVat)
            ->addSelect('vat_code', cols: 2,
                options: $this->buildVatCodeOptions($headContext),
                triggers: 'reload',
                required: $showVat,
                hidden: !$showVat,
            )
            ->addNumber('vat_pct', cols: 1,
                hidden: !$showVat,
                hint: 'Lze přepsat pro doklady z jiného státu',
            )
            ->addNumber('vat_base', cols: 1, readOnly: true, hidden: !$showVat,
                label: 'Základ DPH (vypočteno)')
            ->addNumber('vat_amount', cols: 1, readOnly: true, hidden: !$showVat,
                label: 'Částka DPH (vypočteno)')
            ->addNumber('vat_total', cols: 1, readOnly: true, hidden: !$showVat,
                label: 'Celkem (vypočteno)');

        $tab = $tab
            ->addSeparator('Pořadí')
            ->addNumber('sort_order', cols: 1);

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek dokladu',
            titleNew: 'Nový řádek',
            tabs: [$tab->build()],
            fullSize: false,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        if ($changedColumn === 'item' && !empty($data['item']) && $this->db !== null) {
            $item = $this->db->fetchRow(
                'SELECT `name`, `sales_price_no_vat`, `unit` FROM `economy_items` WHERE `id` = %i',
                (int) $data['item'],
            );
            if ($item !== null) {
                if (empty($data['description'])) {
                    $data['description'] = (string) ($item['name'] ?? '');
                }
                if (empty($data['unit_price']) && !empty($item['sales_price_no_vat'])) {
                    $data['unit_price'] = (float) $item['sales_price_no_vat'];
                }
                if (empty($data['unit']) && !empty($item['unit'])) {
                    $data['unit'] = (int) $item['unit'];
                }
            }
        }

        if ($changedColumn === 'vat_code'
            && !empty($data['vat_code'])
            && $headContext !== null
            && !empty($headContext['country'])
            && !empty($headContext['vat_duzp'])
            && $this->config !== null
        ) {
            $resolver = new VatRateResolver($this->config);
            try {
                $data['vat_pct'] = $resolver->resolveVatPct(
                    (string) $headContext['country'],
                    (string) $data['vat_code'],
                    (string) $headContext['vat_duzp'],
                );
            } catch (\LogicException) {
                // Unknown rate / no period — leave manual entry; UI shows warning.
            }
        }

        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    /**
     * @return array{
     *     country: ?string,
     *     direction: ?string,
     *     place: string,
     *     vat_duzp: ?string,
     *     vat_mode: int,
     *     doc_type: string,
     *     vat_place: int,
     * }|null
     */
    private function loadHeadContext(mixed $docHeadId): ?array
    {
        if ($docHeadId === null || $docHeadId === '' || $this->db === null) {
            return null;
        }
        $head = $this->db->fetchRow(
            'SELECT `vat_registration`, `doc_type`, `vat_place`, `vat_duzp`, `vat_mode`'
            . ' FROM `docs_core_heads` WHERE `id` = %i',
            (int) $docHeadId,
        );
        if ($head === null) {
            return null;
        }

        $context = [
            'doc_type'  => (string) ($head['doc_type'] ?? ''),
            'vat_place' => (int) ($head['vat_place'] ?? 0),
            'vat_duzp'  => $head['vat_duzp'] ?? null,
            'vat_mode'  => (int) ($head['vat_mode'] ?? 1),
            'country'   => null,
            'direction' => null,
            'place'     => 'domestic',
        ];

        if (!empty($head['vat_registration'])) {
            $reg = $this->db->fetchRow(
                'SELECT `country` FROM `economy_codebooks_vat_registrations` WHERE `id` = %i',
                (int) $head['vat_registration'],
            );
            if ($reg !== null && !empty($reg['country'])) {
                $context['country'] = (string) $reg['country'];
            }
        }

        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        if (is_array($docTypes) && isset($docTypes[$context['doc_type']]['trade_dir'])) {
            $tradeDir = (int) $docTypes[$context['doc_type']]['trade_dir'];
            $context['direction'] = match ($tradeDir) {
                1 => 'output',
                2 => 'input',
                default => null,
            };
        }

        $context['place'] = match ($context['vat_place']) {
            0 => 'domestic',
            1 => 'intracom',
            2 => 'foreign',
            default => 'domestic',
        };

        return $context;
    }

    /**
     * @param array<string, mixed>|null $context
     * @return list<array{value: string, label: string}>
     */
    private function buildVatCodeOptions(?array $context): array
    {
        if ($context === null
            || empty($context['country'])
            || empty($context['direction'])
            || $this->config === null
        ) {
            return [];
        }
        $resolver = new VatRateResolver($this->config);
        try {
            $codes = $resolver->getVatCodes(
                (string) $context['country'],
                (string) $context['direction'],
                (string) $context['place'],
                includeHidden: false,
            );
        } catch (\LogicException) {
            return [];
        }
        $options = [];
        foreach ($codes as $key => $code) {
            $label = (string) ($code['fullName'] ?? $code['name'] ?? $key);
            $options[] = ['value' => (string) $key, 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function resolveItemOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `code`, `name` FROM `economy_items`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `name` ASC'
            . ' LIMIT 500',
        );
        $options = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $label = $code !== '' ? "{$code} — {$name}" : $name;
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function resolveUnitOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `shortcut` FROM `core_units`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `name` ASC',
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

    /** @return list<array{value: int, label: string}> */
    private function resolveCfgItemOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfg)) {
            return [];
        }
        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (int) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}
