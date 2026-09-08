<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashRegister;

use Shipard\Core\Form\FormTab;
use Shipard\Module\Docs\Core\CashDeskFormBase;

/**
 * Editační formulář prodejky — `doc_type = 'cashreg'`.
 *
 * Minimalistická hlavička: způsob úhrady (Hotovost / Kartou), nepovinný
 * partner, datum vystavení (+ účetní datum a DUZP, které se z něj doplní),
 * DPH, měna jen pro čtení (= měna pokladny), readOnly sekce „Pokladna",
 * text dokladu. Řádky, rekapitulace, poznámky a přílohy z base.
 */
class CashRegisterForm extends CashDeskFormBase
{
    protected function getFormTitle(): string
    {
        return 'Prodejka';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová prodejka';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Prodejka';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'cash-register';
    }

    protected function getPartnerSnapshotKey(): string
    {
        // Prodejka = výstup, partner je odběratel.
        return 'customer_snapshot';
    }

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;

        $tab = $this->tab('basic', 'Hlavička')
            ->section()
                ->col();
        $this->addSeriesSelect($tab, $data, $isNew)
                    ->input('doc_number', readOnly: true, hidden: true)

                    ->select(
                        'payment_method',
                        options: $this->paymentMethodOptions(),
                        triggers: 'reload',
                    )
                    ->lookup(
                        'partner',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat partnera… (nepovinné)',
                        triggers: 'reload',
                        editForm: true,
                        createForm: true,
                    )

                    ->date('issue_date', required: true, triggers: 'reload')
                    ->date('accounting_date', required: true)
                    ->date('vat_duzp', hidden: !$hasVat)

                ->col()
                    ->select(
                        'vat_mode',
                        options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                        triggers: 'reload',
                    )
                    ->select(
                        'vat_registration',
                        options: $this->resolveVatRegistrationOptions(),
                        triggers: 'reload',
                        required: $hasVat,
                        hidden: !$hasVat,
                    )
                    ->input('doc_currency', readOnly: true, hint: 'Měna pokladny');

        $this->addCashDeskSection($tab, $data);

        return $tab
            ->section()
                ->col()
                    ->input('doc_text')
            ->build();
    }
}
