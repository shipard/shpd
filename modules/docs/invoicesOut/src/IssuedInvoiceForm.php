<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Core\Form\FormTab;


/**
 * Editační formulář pro Faktury vydané (FVB) — `doc_type = 'invno'`.
 *
 * Dědí veškerou logiku z DocsHeadsFormBase. Přepisuje:
 *   - titulky modalu (Faktura vydaná / Nová faktura vydaná)
 *   - `buildHeaderTab()` — 2-sloupcový layout bez separátorů, jen pole
 *     potřebná pro každodenní práci s FVB. Oproti FPB zachovává v hlavičce
 *     `vat_registration` (různé registrace podle odběratele) a `bank_account`
 *     (zákazník platí na tento účet, je viditelně na PDF).
 *   - `buildExtraTabs()` — přidává tab „Nastavení" za Přílohy s polem
 *     `home_currency` (readOnly).
 *
 * Slouží jako rozšiřovací bod pro další FVB-specifické změny formuláře
 * (splátkový kalendář, výzva k úhradě, AI checks atd.).
 */
class IssuedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura vydaná';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura vydaná';
    }

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
                        'vat_registration',
                        options: $this->resolveVatRegistrationOptions(),
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
                    ->select(
                        'bank_account',
                        options: $this->resolveBankAccountOptions($docCurrency),
                    )
                    ->input('variable_symbol')
                    ->input('specific_symbol')
                    ->input('constant_symbol')

            ->section()
                ->col()
                    ->input('doc_text')
            ->build();
    }

    /**
     * Přidává tab „Nastavení" na úplný konec formuláře (za Přílohami).
     * U FVB obsahuje jen readOnly domácí měnu — `vat_registration`
     * a `bank_account` patří do hlavičky, protože se u vydaných faktur
     * mění podle odběratele / měny dokladu.
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
        return $this->tab('settings', 'Nastavení')
            ->section(title: 'Měna')
                ->col()
                    ->input('home_currency', readOnly: true)
            ->build();
    }
}
