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

        // ── Tab: Základní údaje ──────────────────────────────────────────────
        $basic = $this->tab('basic', 'Základní údaje')
            ->addInput('person_id', cols: 1, required: true)
            ->addSelect('person_type', cols: 1, options: $personTypeOptions, triggers: 'reload')
            ->addInput('full_name', cols: 2,
                required: $isCompany,
                readOnly: $isPerson,
            )
            // Identifikace firmy
            ->addSeparator('Identifikace firmy')
            ->addInput('company_id', cols: 1, hidden: $isPerson || $isUndefined)
            ->addInput('tax_id', cols: 1, hidden: $isPerson || $isUndefined)
            ->addInput('vat_id', cols: 1, hidden: $isPerson || $isUndefined)
            // Jméno
            ->addSeparator('Jméno')
            ->addInput('title_before', cols: 1, hidden: $isCompany || $isUndefined)
            ->addInput('first_name', cols: 1, hidden: $isCompany || $isUndefined, required: $isPerson)
            ->addInput('middle_name', cols: 1, hidden: $isCompany || $isUndefined)
            ->addInput('last_name', cols: 1, hidden: $isCompany || $isUndefined, required: $isPerson)
            ->addInput('title_after', cols: 1, hidden: $isCompany || $isUndefined)
            // Osobní údaje
            ->addSeparator('Osobní údaje')
            ->addInput('birth_date', cols: 1, hidden: $isCompany || $isUndefined, inputType: 'date')
            ->addInput('national_id', cols: 1, hidden: $isCompany || $isUndefined)
            ->addInput('id_card_number', cols: 1, hidden: $isCompany || $isUndefined)
            ->build();

        // ── Tab: Kontaktní údaje ─────────────────────────────────────────────
        $contact = $this->tab('contact', 'Kontaktní údaje')
            ->addInput('email', cols: 2)
            ->addInput('phone', cols: 1)
            ->addInput('web', cols: 2)
            ->build();

        // ── Tab: Kontakty (subtable) ─────────────────────────────────────────
        $contacts = $this->tab('contacts', 'Kontakty')
            ->addSubtable('base_persons_contacts', 'person', formId: 'base.persons.contacts')
            ->build();

        // ── Tab: Adresy (subtable) ───────────────────────────────────────────
        $addresses = $this->tab('addresses', 'Adresy')
            ->addSubtable('base_persons_addresses', 'person', formId: 'base.persons.addresses')
            ->build();

        // ── Tab: Bankovní účty (subtable) ────────────────────────────────────
        $bankAccounts = $this->tab('bank_accounts', 'Bankovní účty')
            ->addSubtable('base_persons_bank_accounts', 'person', formId: 'base.persons.bank_accounts')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [$basic, $contact, $contacts, $addresses, $bankAccounts],
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
