<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

class PersonsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
        $isCompany = $personType === PersonType::Company;
        $isPerson = $personType === PersonType::Person;
        $isUndefined = $personType === null || $personType === PersonType::Undefined;

        $personTypeOptions = $this->resolvePersonTypeOptions();

        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('person_id', required: true)
                    ->select('person_type', options: $personTypeOptions, triggers: 'reload', required: true)
                    ->input('full_name',
                        required: $isCompany,
                        readOnly: $isPerson,
                    )
                    ->separator('Identifikace firmy', hidden: $isPerson || $isUndefined)
                    ->input('company_id', hidden: $isPerson || $isUndefined)
                    ->input('tax_id', hidden: $isPerson || $isUndefined)
                    ->input('vat_id', hidden: $isPerson || $isUndefined)
                    ->input('court_registration', hidden: $isPerson || $isUndefined)
                    ->separator('Jméno', hidden: $isCompany || $isUndefined)
                    ->input('title_before', hidden: $isCompany || $isUndefined)
                    ->input('first_name', hidden: $isCompany || $isUndefined, required: $isPerson)
                    ->input('middle_name', hidden: $isCompany || $isUndefined)
                    ->input('last_name', hidden: $isCompany || $isUndefined, required: $isPerson)
                    ->input('title_after', hidden: $isCompany || $isUndefined)
                    ->separator('Osobní údaje', hidden: $isCompany || $isUndefined)
                    ->date('birth_date', hidden: $isCompany || $isUndefined)
                    ->input('national_id', hidden: $isCompany || $isUndefined)
                    ->input('id_card_number', hidden: $isCompany || $isUndefined)
                    ->separator('Naše firma', hidden: $isPerson || $isUndefined)
                    ->checkbox('is_own', hidden: $isPerson || $isUndefined)
            ->build();

        $contact = $this->tab('contact', 'Kontaktní údaje')
            ->section()
                ->col()
                    ->input('email')
                    ->input('phone')
                    ->input('web')
                    ->separator('Platba')
                    ->number('payment_term_days')
            ->build();

        $contacts     = $this->subtableTab('contacts', 'Kontakty', 'base_persons_contacts', 'person', 'base.persons.contacts');
        $addresses    = $this->subtableTab('addresses', 'Adresy', 'base_persons_addresses', 'person', 'base.persons.addresses');
        $bankAccounts = $this->subtableTab('bank_accounts', 'Bankovní účty', 'base_persons_bank_accounts', 'person', 'base.persons.bank_accounts');

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [$basic, $contact, $contacts, $addresses, $bankAccounts, $this->attachmentsTab()],
            fullSize: true,
        );
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
