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
            // Vždy viditelné základy
            ->section()
            ->col()
            ->input('person_id', required: true)
            ->select(
                'person_type',
                options: $personTypeOptions,
                triggers: 'reload',
                required: true,
            )
            ->input(
                'full_name',
                required: $isCompany,
                readOnly: $isPerson,
            )

            // Jen pro Company
            ->section(title: 'Identifikace firmy', hidden: $isPerson || $isUndefined)
            ->col()
            ->input('company_id')
            ->input('tax_id')
            ->input('vat_id')
            ->input('court_registration')
            ->checkbox('is_own')

            // Jen pro Person
            ->section(title: 'Jméno', hidden: $isCompany || $isUndefined)
            ->col()
            ->input('title_before')
            ->inline()
            ->input('first_name', required: $isPerson)
            ->input('middle_name')
            ->input('last_name', required: $isPerson)
            ->endInline()
            ->input('title_after')

            // Jen pro Person
            ->section(title: 'Osobní údaje', hidden: $isCompany || $isUndefined)
            ->col()
            ->date('birth_date')
            ->input('national_id')
            ->input('id_card_number')
            ->build();

        // ── Tab: Kontaktní údaje ─────────────────────────────────────────────
        $contact = $this->tab('contact', 'Kontaktní údaje')
            ->section()
            ->col()
            ->input('email', inputType: 'email')
            ->input('phone', inputType: 'tel')
            ->input('web', inputType: 'url')
            ->section(title: 'Platba')
            ->col()
            ->number('payment_term_days')
            ->build();

        // ── Subtable a attachments taby ──────────────────────────────────────
        $contacts     = $this->subtableTab('contacts',      'Kontakty',       'base_persons_contacts',      'person', 'base.persons.contacts');
        $addresses    = $this->subtableTab('addresses',     'Adresy',         'base_persons_addresses',     'person', 'base.persons.addresses');
        $bankAccounts = $this->subtableTab('bank_accounts', 'Bankovní účty', 'base_persons_bank_accounts', 'person', 'base.persons.bank_accounts');

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [$basic, $contact, $contacts, $addresses, $bankAccounts, $this->attachmentsTab()],
            fullSize: true,
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
        if ($personType === PersonType::Company) {
            $companyId = trim((string) ($data['company_id'] ?? ''));
            if ($companyId !== '') {
                $info[] = ['label' => 'IČO', 'value' => $companyId];
            }
        } elseif ($personType === PersonType::Person) {
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

        return new FormHeaderInfo(title: $fullName, info: $info);
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
