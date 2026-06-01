<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

class PersonsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $personType  = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
        $isCompany   = $personType === PersonType::Company;
        $isPerson    = $personType === PersonType::Person;
        $isUndefined = $personType === null || $personType === PersonType::Undefined;

        $personTypeOptions = $this->resolvePersonTypeOptions();

        // ── Tab: Základní údaje ──────────────────────────────────────────────
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->select(
                        'person_type',
                        options: $personTypeOptions,
                        triggers: 'reload',
                        required: true,
                    )
                    ->input(
                        'full_name',
                        required: $isCompany,
                        hidden: $isPerson,
                    )

            ->section(title: 'Identifikace firmy', hidden: $isPerson || $isUndefined)
                ->col()
                    ->inline()
                        ->input('company_id')
                        ->input('tax_id')
                    ->endInline()

            ->section(title: 'Jméno', hidden: $isCompany || $isUndefined)
                ->col()
                    ->input('title_before')
                    ->inline()
                        ->input('first_name', required: $isPerson)
                        ->input('middle_name')
                        ->input('last_name', required: $isPerson)
                    ->endInline()
                    ->input('title_after')

            ->section(title: 'Osobní údaje', hidden: $isCompany || $isUndefined)
                ->col()
                    ->inline()
                        ->date('birth_date')
                        ->input('national_id')
                    ->endInline()
                    ->input('id_card_number')

            ->section(title: 'Kontakt')
                ->col()
                    ->inline()
                        ->input('email', inputType: 'email')
                        ->input('phone', inputType: 'tel')
                    ->endInline()
                    ->input('web', inputType: 'url')
            ->build();

        // ── Subtable a attachments taby ──────────────────────────────────────
        $contacts     = $this->subtableTab('contacts',      'Kontakty',      'base_persons_contacts',      'person', 'base.persons.contacts');
        $addresses    = $this->subtableTab('addresses',     'Adresy',        'base_persons_addresses',     'person', 'base.persons.addresses');
        $bankAccounts = $this->subtableTab('bank_accounts', 'Bankovní účty', 'base_persons_bank_accounts', 'person', 'base.persons.bank_accounts');

        // ── Tab: Nastavení (úplně na konci, za Přílohami) ───────────────────
        $settings = $this->tab('settings', 'Nastavení')
            ->section(title: 'Identifikace')
                ->col()
                    ->input('person_id', required: true)

            ->section(title: 'Identifikace firmy - doplňující', hidden: $isPerson || $isUndefined)
                ->col()
                    ->input('vat_id')
                    ->input('court_registration')
                    ->checkbox('is_own')

            ->section(title: 'Obchodní podmínky')
                ->col()
                    ->number('payment_term_days')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [$basic, $contacts, $addresses, $bankAccounts, $this->attachmentsTab(), $settings],
        );
    }

    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '') {
            return null;
        }

        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        $info = [];
        $icon = null;
        if ($personType === PersonType::Company) {
            $icon = 'company';
            $companyId = trim((string) ($data['company_id'] ?? ''));
            if ($companyId !== '') {
                $info[] = ['label' => 'IČO', 'value' => $companyId];
            }
        } elseif ($personType === PersonType::Person) {
            $icon = 'user';
            $birthDate = $data['birth_date'] ?? null;
            if ($birthDate !== null && $birthDate !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $birthDate);
                if ($dt instanceof \DateTimeImmutable) {
                    $info[] = ['label' => 'Datum narození', 'value' => $dt->format('d.m.Y')];
                }
            }
        } else {
            return null;
        }

        $personId = trim((string) ($data['person_id'] ?? ''));
        if ($personId !== '') {
            $info[] = ['label' => 'Kód osoby', 'value' => $personId];
        }

        return new FormHeaderInfo(title: $fullName, info: $info, icon: $icon);
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
        $isPerson = $personType === PersonType::Person;

        if ($isPerson) {
            $data['full_name'] = trim(
                ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''),
            );
        }

        $formDefinition = $this->buildFormDefinition($data, empty($data['id']));
        return new RecalculateResult($formDefinition, $data);
    }

    private function resolvePersonTypeOptions(): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem('base.persons.personTypes');
        if (!is_array($cfgData)) {
            return [];
        }

        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (int) $key, 'label' => $entry['name']];
            }
        }
        return $options;
    }
}
