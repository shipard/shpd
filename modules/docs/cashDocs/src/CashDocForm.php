<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashDocs;

use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\FormTab;
use Shipard\Module\Docs\Core\CashDeskFormBase;
use Shipard\Module\Docs\Core\CashDirection;
use Shipard\Module\Docs\Core\DocDocument;

/**
 * Editační formulář pokladního dokladu — `doc_type = 'cash'`.
 *
 * Hlavička: směr (`cash_dir`, povinný, bez defaultu — špatný default by
 * potichu vyráběl příjmové doklady; po vzniku řádků jen pro čtení, protože
 * změna směru zneplatní pohyby řádků), způsob úhrady Hotovost / Kartou,
 * nepovinný partner, datumy, DPH, měna dokladu jen pro čtení (= měna
 * pokladny), readOnly sekce „Pokladna". Řádky, rekapitulace, poznámky a
 * přílohy z base.
 *
 * Header info: titulek = partner, u anonymního dokladu „Příjmový / Výdajový
 * pokladní doklad" (base by bez partnera hlavičku vůbec nevrátila).
 */
class CashDocForm extends CashDeskFormBase
{
    protected function getFormTitle(): string
    {
        return 'Pokladní doklad';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nový pokladní doklad';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Pokladní doklad';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'wallet';
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
        $hasRows = $this->hasRows($data);

        $tab = $this->tab('basic', 'Hlavička')
            ->section()
                ->col();
        $this->addSeriesSelect($tab, $data, $isNew)
                    ->input('doc_number', readOnly: true, hidden: true)

                    ->select(
                        'cash_dir',
                        options: $this->cashDirectionOptions(),
                        triggers: 'reload',
                        required: true,
                        readOnly: $hasRows,
                        hint: $hasRows ? 'Směr nelze měnit — doklad už má řádky' : null,
                    )
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
                    ->lookup(
                        'partner_address',
                        table: 'base_persons_addresses',
                        filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                        placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
                        readOnly: $partnerId === 0,
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
                    ->select(
                        'vat_calc_source',
                        options: $this->resolveCfgItemOptions('docs.core.vatCalcSources'),
                        hidden: !$hasVat,
                    )

                    ->input('doc_currency', readOnly: true, hint: 'Měna pokladny')
                    ->number('exchange_rate', hidden: !$hasForeignCurrency)

                    ->select(
                        'total_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                    )
                    ->select(
                        'vat_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                        hidden: !$hasVat,
                    );

        $this->addCashDeskSection($tab, $data);

        return $tab
            ->section()
                ->col()
                    ->input('doc_text')
            ->build();
    }

    /**
     * Hlavička modalu: titulek partner, bez partnera směr dokladu; info řádka
     * nese směr místo obecného „Pokladní doklad".
     *
     * @param array<string, mixed> $data
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $dirLabel = $this->directionLabel($data);
        $partnerName = $this->resolvePartnerName($data);

        $info = [['label' => '', 'value' => $dirLabel]];
        $docNumber = trim((string) ($data['doc_number'] ?? ''));
        if ($docNumber !== '') {
            $info[] = ['label' => 'Číslo', 'value' => $docNumber];
        }

        return new FormHeaderInfo(
            title: $partnerName !== '' ? $partnerName : $dirLabel,
            info: $info,
            icon: $this->getHeaderIcon(),
            summary: $this->buildHeaderSummary($data),
        );
    }

    /**
     * Snapshot partnera podle směru dokladu: příjem → odběratel
     * (customer_snapshot), výdej → dodavatel (supplier_snapshot) — stejné
     * mapování jako DocDocument::assignSnapshots. Bez snapshotu fallback
     * base (SELECT partnera).
     *
     * @param array<string, mixed> $data
     */
    protected function resolvePartnerName(array $data): string
    {
        $key = DocDocument::resolveTradeDir($data, $this->config) === 1
            ? 'customer_snapshot'
            : 'supplier_snapshot';
        $snap = $this->decodeSnapshot($data[$key] ?? null);
        if ($snap !== null && !empty($snap['name'])) {
            return trim((string) $snap['name']);
        }
        unset($data['supplier_snapshot'], $data['customer_snapshot']);
        return parent::resolvePartnerName($data);
    }

    /** @param array<string, mixed> $data */
    private function directionLabel(array $data): string
    {
        return match (CashDirection::tryFrom((int) ($data['cash_dir'] ?? 0))) {
            CashDirection::Receipt      => 'Příjmový pokladní doklad',
            CashDirection::Disbursement => 'Výdajový pokladní doklad',
            default                     => 'Pokladní doklad',
        };
    }

    /** Směry 1 / 2 z cfgItem docs.core.cashDirections (0 „nepoužito" se nenabízí). */
    private function cashDirectionOptions(): array
    {
        return array_values(array_filter(
            $this->resolveCfgItemOptions('docs.core.cashDirections'),
            static fn(array $o) => (int) $o['value'] !== 0,
        ));
    }
}
