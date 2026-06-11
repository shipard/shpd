<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Core\Form\FormTab;


/**
 * Editační formulář pro Faktury přijaté (FPB) — `doc_type = 'invni'`.
 *
 * Dědí veškerou logiku z DocsHeadsFormBase. Přepisuje:
 *   - titulky modalu (Faktura přijatá / Nová faktura přijatá)
 *   - 3 header-info hooky (`getDocTypeLabel`, `getHeaderIcon`) — společný
 *     `buildHeaderInfo()` v base; FPB drží `supplier_snapshot` defaultní.
 *   - `buildHeaderTab()` — 2-sloupcový layout bez separátorů, jen pole
 *     potřebná pro každodenní práci s FPB
 *   - `buildExtraTabs()` — přidává tab „Nastavení" za Přílohy s poli,
 *     která se nastavují zřídka (vat_registration, bank_account,
 *     home_currency).
 *
 * Slouží jako rozšiřovací bod pro další FPB-specifické změny formuláře
 * (schvalovací workflow, vazba na příchozí poštu, AI extrakce,
 * DPH-PDP-specifické přepínače atd.).
 */
class ReceivedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura přijatá';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura přijatá';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Přijatá faktura';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'invoice-in';
    }

    // getPartnerSnapshotKey() — default 'supplier_snapshot' je správně
    // pro přijaté doklady, override není potřeba.

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasForeignCurrency = $docCurrency !== '' && $homeCurrency !== ''
            && $docCurrency !== $homeCurrency;
        $partnerId = (int) ($data['partner'] ?? 0);

        return $this->tab('basic', 'Hlavička')
            ->section()
            ->col()
            ->select(
                'number_series',
                options: $this->resolveNumberSeriesOptions(
                    !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
                ),
                required: true,
                readOnly: !$isNew,
            )
            ->input('doc_number', readOnly: true)
            ->input('partner_doc_number')

            ->lookup(
                'partner',
                table: 'base_persons_persons',
                placeholder: 'Hledat partnera…',
                triggers: 'reload',
                editForm: true,
                createForm: true,
            )
            ->lookup(
                'partner_address',
                table: 'base_persons_addresses',
                filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
                readOnly: $partnerId === 0,
            )
            ->lookup(
                'partner_bank',
                table: 'base_persons_bank_accounts',
                filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                placeholder: $partnerId !== 0 ? 'Vyberte bankovní účet…' : 'Nejdřív vyberte partnera',
                readOnly: $partnerId === 0,
            )
            ->input('partner_bank_iban', label: 'IBAN')

            ->date('issue_date', required: true, triggers: 'reload')
            ->date('due_date')
            ->date('accounting_date', required: true)
            ->date('vat_duzp', hidden: !$hasVat)
            ->date('vat_dppd', hidden: !$hasVat)
            ->date('period_from', hint: 'Volitelné, např. pronájem za období')
            ->date('period_to')

            ->col()
            ->select(
                'vat_mode',
                options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                triggers: 'reload',
            )
            ->select(
                'vat_calc_source',
                options: $this->resolveCfgItemOptions('docs.core.vatCalcSources'),
                hidden: !$hasVat,
            )
            ->select(
                'vat_place',
                options: $this->resolveCfgItemOptions('docs.core.vatPlaces'),
                triggers: 'reload',
                hidden: !$hasVat,
            )

            ->select(
                'doc_currency',
                options: $this->resolveCurrencyOptions(),
                triggers: 'reload',
            )
            ->number('exchange_rate', hidden: !$hasForeignCurrency)

            ->select(
                'total_rounding_mode',
                options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
            )
            ->select(
                'vat_rounding_mode',
                options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                hidden: !$hasVat,
            )

            ->select(
                'payment_method',
                options: $this->resolveCfgItemOptions('docs.core.paymentMethods'),
            )
            ->input('payment_reference')
            ->input('specific_symbol')
            ->input('constant_symbol')

            ->section()
            ->col()
            ->input('doc_text')
            ->build();
    }

    /**
     * Přidává tab „Nastavení" na úplný konec formuláře (za Přílohami).
     * Obsahuje pole, která se u FPB nastavují zřídka — uživatel je
     * většinou nepotřebuje při běžném pořizování faktury.
     *
     * @param array<string, mixed> $data
     * @return list<FormTab>
     */
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [$this->buildSettingsTab($data)];
    }

    /** @param array<string, mixed> $data */
    protected function buildSettingsTab(array $data): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));

        return $this->tab('settings', 'Nastavení')
            ->section(title: 'DPH', hidden: !$hasVat)
            ->col()
            ->select(
                'vat_registration',
                options: $this->resolveVatRegistrationOptions(),
                triggers: 'reload',
            )

            ->section(title: 'Bankovní spojení')
            ->col()
            ->select(
                'bank_account',
                options: $this->resolveBankAccountOptions($docCurrency),
            )

            ->section(title: 'Měna')
            ->col()
            ->input('home_currency', readOnly: true)
            ->build();
    }
}
