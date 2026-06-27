<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\AccountingDocs;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;

/**
 * Editační formulář pro Účetní doklady (FÚD) — `doc_type = 'cmnbkp'`.
 *
 * Lehčí než faktura: bez DPH (`vat_mode = 0`), partner nepovinný (hlavní
 * osoba dokladu; saldo identita ale žije per řádek), bez polí měny /
 * zaokrouhlení / DPH. Řádky kontace řeší sdílený `DocRowsForm` (větev
 * `rowAccount`).
 *
 * Přepisuje `buildFormDefinition()`, aby vynechal tab „Rekapitulace DPH"
 * (na bezDPH dokladu nemá smysl) — sada tabů: Hlavička, Řádky, Poznámky,
 * Přílohy.
 */
class AccountingDocsForm extends DocsHeadsFormBase
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $this->applyClientDefaults($data, $isNew);

        $tabs = [
            $this->buildHeaderTab($data, $isNew),
            $this->buildRowsTab(),
            $this->buildNotesTab(),
            $this->attachmentsTab(),
        ];

        return new FormDefinition(
            table: $this->table,
            title: $this->getFormTitle(),
            titleNew: $this->getNewFormTitle(),
            tabs: $tabs,
        );
    }

    /** Účetní doklad je bez DPH — vynuť `vat_mode = 0` pro nový záznam. */
    protected function applyClientDefaults(array &$data, bool $isNew): void
    {
        parent::applyClientDefaults($data, $isNew);
        if ($isNew) {
            $data['vat_mode'] = 0;
        }
    }

    protected function getFormTitle(): string
    {
        return 'Účetní doklad';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nový účetní doklad';
    }

    protected function getDocTypeLabel(): string
    {
        return 'Účetní doklad';
    }

    protected function getHeaderIcon(): ?string
    {
        return 'doc-accounting';
    }

    /**
     * Lehká hlavička: identifikace, nepovinný partner, datumy, popis, období.
     * Žádná DPH / měna / zaokrouhlení / platební pole — účetní doklad je
     * `vat_mode = 0` a platební identita žije per řádek.
     *
     * @param array<string, mixed> $data
     */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        return $this->tab('basic', 'Hlavička')
            ->section()
                ->col()
                    ->separator('Identifikace')
                    ->select('number_series',
                        options: $this->resolveNumberSeriesOptions(
                            !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
                        ),
                        required: true,
                        readOnly: !$isNew,
                        hidden: true,
                    )
                    ->input('doc_number', readOnly: true, hidden: true)
                    ->input('doc_text')

                    ->separator('Partner')
                    ->lookup('partner',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat partnera…',
                        editForm: true,
                        createForm: true,
                    )

                    ->separator('Datumy')
                    ->date('accounting_date', required: true)
                    ->date('issue_date', required: true, triggers: 'reload')
                    ->date('due_date')
                    ->date('period_from', hint: 'Volitelné, např. mzdy za období')
                    ->date('period_to')
            ->build();
    }
}
