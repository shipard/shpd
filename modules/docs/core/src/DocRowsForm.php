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

        $operationOptions = $this->buildOperationOptions($headContext);

        if ($isNew) {
            $data['row_kind'] = $rowKind;
            if (!isset($data['price_calc_mode'])) {
                $data['price_calc_mode'] = 0;
            }
        }

        // Kontační řádek účetního dokladu: operace má `rowAccount` NEBO
        // saldo vlajky (rowPartner/rowPaymentId). Vlastní layout bez položkového
        // bloku; faktury (běžné operace) jdou stávající větví níže beze změny.
        $opAttrs = $this->resolveOperationAttrs((string) ($data['operation'] ?? ''));
        if (!$isText && $this->isContationOperation($opAttrs)) {
            $rowAccount = $opAttrs['rowAccount'] ?? null;
            return $this->buildContationDefinition(
                $operationOptions,
                $opAttrs,
                $rowAccount !== null ? (string) $rowAccount : null,
            );
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
                    ->select('operation',
                        options: $operationOptions,
                        required: !$isText,
                        hidden: $isText,
                    )
                    ->lookup('item',
                        table: 'economy_items',
                        placeholder: 'Hledat položku…',
                        triggers: 'reload',
                        hidden: $isText,
                        editForm: true,
                        createForm: true,
                        editTriggers: true,
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
        );
    }

    /**
     * Definice formuláře pro kontační řádek účetního dokladu. Skrývá položkový
     * blok (množství/cena/sleva/DPH) a zobrazuje: stranu MD/DAL, částku, popis
     * a per-řádkovou saldo identitu dle vlajek operace. `price_calc_mode` je
     * skryté a fixní 1 (z celkové) — částka se zadává přímo, nedopočítává se.
     *
     * Vstup účtu/položky se zobrazí jen u operací s `rowAccount`
     * (`direct`/`item`). U saldokontních operací (`$rowAccount === null`) je
     * účet implicitní z kategorie účtovacího předpisu (311/321) — vstup se
     * nestaví.
     *
     * @param list<array{value: string, label: string}> $operationOptions
     * @param array<string, mixed> $opAttrs
     */
    private function buildContationDefinition(
        array $operationOptions,
        array $opAttrs,
        ?string $rowAccount,
    ): FormDefinition {
        $section = $this->tab('basic', 'Řádek kontace')
            ->section()
                ->col()
                    ->select('row_kind',
                        options: $this->resolveCfgItemOptions('docs.core.rowKinds'),
                        triggers: 'reload',
                        required: true,
                    )
                    ->select('operation',
                        options: $operationOptions,
                        triggers: 'reload',
                        required: true,
                    );

        if ($rowAccount === 'item') {
            $section->lookup('item',
                table: 'economy_items',
                filter: ['item_type' => 2],
                placeholder: 'Hledat účetní položku…',
                triggers: 'reload',
                required: true,
            );
        } elseif ($rowAccount === 'direct') {
            $section->lookup('account',
                table: 'economy_accounting_accounts',
                filter: ['account_level' => 4],
                placeholder: 'Hledat účet…',
                required: true,
            );
        }

        $section
            ->select('acc_side',
                options: $this->resolveCfgItemOptions('docs.core.accSides'),
                required: true,
            )
            ->number('total_price', label: 'Částka', required: true)
            ->input('description')
            ->number('price_calc_mode', hidden: true);

        if (!empty($opAttrs['rowPartner']) || !empty($opAttrs['rowPaymentId'])) {
            $section->separator('Saldo identita');
        }
        if (!empty($opAttrs['rowPartner'])) {
            $section->lookup('partner',
                table: 'base_persons_persons',
                placeholder: 'Hledat partnera…',
            );
        }
        if (!empty($opAttrs['rowPaymentId'])) {
            $section
                ->input('payment_reference', label: 'Variabilní symbol')
                ->input('specific_symbol', label: 'Specifický symbol')
                ->input('constant_symbol', label: 'Konstantní symbol')
                ->date('due_date', label: 'Splatnost');
        }

        $section->separator('Pořadí')->number('order_pos');

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek kontace',
            titleNew: 'Nový řádek kontace',
            tabs: [$section->build()],
        );
    }

    /**
     * Default pohyb pro nový řádek = první povolený pro doc_type hlavičky
     * (nejnižší order). Hlavička je známá z prefillu `defaults[doc_head]`.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
        if ((int) ($data['row_kind'] ?? 1) !== 1 || !empty($data['operation'])) {
            return;
        }
        $options = $this->buildOperationOptions(
            $this->loadHeadContext($data['doc_head'] ?? null),
        );
        if ($options !== []) {
            $data['operation'] = $options[0]['value'];
            $this->applyContationRowDefaults($data);
        }
    }

    /**
     * Kontační řádek (vč. saldokontních operací) má `price_calc_mode = 1` (z
     * celkové), aby `calculateRowPrice` nepřepsal ručně zadanou `total_price`
     * výpočtem z množství × cena.
     */
    private function applyContationRowDefaults(array &$data): void
    {
        $attrs = $this->resolveOperationAttrs((string) ($data['operation'] ?? ''));
        if ($this->isContationOperation($attrs)) {
            $data['price_calc_mode'] = 1;
        }
    }

    /**
     * Kontační řádek = operace nese účet přímo/z položky (`rowAccount`) NEBO
     * per-řádkovou saldo identitu (`rowPartner`/`rowPaymentId`). Saldokontní
     * operace (zápočty) mají jen vlajky a účet z kategorie předpisu.
     *
     * @param array<string, mixed>|null $attrs
     */
    private function isContationOperation(?array $attrs): bool
    {
        if (!is_array($attrs)) {
            return false;
        }
        return isset($attrs['rowAccount'])
            || !empty($attrs['rowPartner'])
            || !empty($attrs['rowPaymentId']);
    }

    /**
     * Atributy operace z cfgItem `docs.core.rowOperations` (vlajky rowPartner /
     * rowPaymentId / rowAccount). Null, když operace není známá.
     *
     * @return array<string, mixed>|null
     */
    private function resolveOperationAttrs(string $operation): ?array
    {
        if ($operation === '' || $this->config === null) {
            return null;
        }
        $cfg = $this->config->cfgItem('docs.core.rowOperations');
        if (!is_array($cfg)) {
            return null;
        }
        $entry = $cfg[$operation] ?? null;
        return is_array($entry) ? $entry : null;
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        if ($changedColumn === 'row_kind') {
            if ((int) ($data['row_kind'] ?? 1) !== 1) {
                $data['operation'] = null;
            } elseif (empty($data['operation'])) {
                $options = $this->buildOperationOptions($headContext);
                if ($options !== []) {
                    $data['operation'] = $options[0]['value'];
                }
            }
        }

        if ($changedColumn === 'item' && !empty($data['item']) && $this->db !== null) {
            $item = $this->db->fetchRow(
                'SELECT `name`, `sales_price_no_vat`, `unit` FROM `economy_items` WHERE `id` = %i',
                (int) $data['item'],
            );
            if ($item !== null) {
                // Položka = řádek: name, sales_price_no_vat a unit z položky se vždy
                // propisují do řádku. Platí pro výběr z dropdownu, vytvoření nové položky,
                // i edit existující položky (editTriggers: true na lookup). Uživateláňské
                // úpravy unit_price (sleva) se řeší přes samostatná pole
                // discount_pct / discount_amount, ne přepisem unit_price.
                $data['description'] = (string) ($item['name'] ?? '');
                if (!empty($item['sales_price_no_vat'])) {
                    $data['unit_price'] = (float) $item['sales_price_no_vat'];
                }
                if (!empty($item['unit'])) {
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

        // Kontační řádek (cmnbkp): při změně operace / typu řádku zajisti
        // price_calc_mode = 1, ať se ručně zadaná částka nepřepíše výpočtem.
        if ($changedColumn === 'operation' || $changedColumn === 'row_kind') {
            $this->applyContationRowDefaults($data);
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

    /**
     * Options pohybů filtrované podle `doc_type` hlavičky, řazené dle
     * `docTypes[docType].order` vzestupně (první = default pro nový řádek).
     * `name` z cfgItem je už lokalizované compiled configem.
     *
     * @param array<string, mixed>|null $headContext
     * @return list<array{value: string, label: string}>
     */
    private function buildOperationOptions(?array $headContext): array
    {
        $docType = (string) ($headContext['doc_type'] ?? '');
        if ($docType === '' || $this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('docs.core.rowOperations');
        if (!is_array($cfg)) {
            return [];
        }

        $entries = [];
        foreach ($cfg as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $docTypeAttrs = $entry['docTypes'][$docType] ?? null;
            if (!is_array($docTypeAttrs)) {
                continue;
            }
            $entries[] = [
                'value' => (string) $key,
                'label' => (string) ($entry['name'] ?? $key),
                'order' => (int) ($docTypeAttrs['order'] ?? 0),
            ];
        }
        usort($entries, fn(array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(
            fn(array $e) => ['value' => $e['value'], 'label' => $e['label']],
            $entries,
        );
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
