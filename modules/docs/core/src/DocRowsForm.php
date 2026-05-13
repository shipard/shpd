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

        $showVat = $headHasVat && !$isText;

        $tab = $this->tab('basic', 'Řádek')
            ->section()
                ->col()
                    ->select('row_kind',
                        options: $this->resolveCfgItemOptions('docs.core.rowKinds'),
                        triggers: 'reload',
                        required: true,
                    )
                    ->select('item',
                        options: $this->resolveItemOptions(),
                        triggers: 'reload',
                        hidden: $isText,
                    )
                    ->input('description')

                    ->separator('Množství a cena', hidden: $isText)
                    ->number('quantity', triggers: 'reload', hidden: $isText)
                    ->select('unit',
                        options: $this->resolveUnitOptions(),
                        hidden: $isText,
                    )
                    ->number('unit_price', triggers: 'reload', hidden: $isText)
                    ->number('total_price', triggers: 'reload', hidden: $isText)
                    ->select('price_calc_mode',
                        options: $this->resolveCfgItemOptions('docs.core.priceCalcModes'),
                        hidden: $isText,
                    )

                    ->separator('Sleva', hidden: $isText)
                    ->number('discount_pct', hidden: $isText, hint: 'Sleva v %')
                    ->number('discount_amount', hidden: $isText, hint: 'Sleva absolutně')

                    ->separator('DPH', hidden: !$showVat)
                    ->select('vat_code',
                        options: $this->buildVatCodeOptions($headContext),
                        triggers: 'reload',
                        required: $showVat,
                        hidden: !$showVat,
                    )
                    ->number('vat_pct',
                        hidden: !$showVat,
                        hint: 'Lze přepsat pro doklady z jiného státu',
                    )
                    ->number('vat_base', readOnly: true, hidden: !$showVat,
                        label: 'Základ DPH (vypočteno)')
                    ->number('vat_amount', readOnly: true, hidden: !$showVat,
                        label: 'Částka DPH (vypočteno)')
                    ->number('vat_total', readOnly: true, hidden: !$showVat,
                        label: 'Celkem (vypočteno)')

                    ->separator('Pořadí')
                    ->number('order_pos');

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
