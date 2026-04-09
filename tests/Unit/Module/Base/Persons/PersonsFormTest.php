<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Base\Persons\PersonsForm;

class PersonsFormTest extends TestCase
{
    private function createForm(): PersonsForm
    {
        return new PersonsForm('base_persons_persons');
    }

    private function findElement(FormDefinition $def, string $tabId, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== $tabId) {
                continue;
            }
            foreach ($tab->elements as $el) {
                if ($el->column === $column) {
                    return $el;
                }
            }
        }
        return null;
    }

    private function getTabIds(FormDefinition $def): array
    {
        return array_map(fn($tab) => $tab->id, $def->tabs);
    }

    // ── Company ──────────────────────────────────────────────────────────────

    public function testCompanyFormHasFiveTabs(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 2], false);

        $this->assertSame(
            ['basic', 'contact', 'contacts', 'addresses', 'bank_accounts'],
            $this->getTabIds($def),
        );
    }

    public function testCompanyFormFullSize(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 2], false);

        $this->assertTrue($def->fullSize);
    }

    public function testCompanyFormCompanyIdVisible(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'basic', 'company_id');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden);
    }

    public function testCompanyFormFirstNameHidden(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'basic', 'first_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->hidden);
    }

    public function testCompanyFormFullNameRequiredAndEditable(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'basic', 'full_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
        $this->assertFalse($el->readOnly);
    }

    // ── Person ───────────────────────────────────────────────────────────────

    public function testPersonFormCompanyIdHidden(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'company_id');
        $this->assertNotNull($el);
        $this->assertTrue($el->hidden);
    }

    public function testPersonFormFirstNameVisibleAndRequired(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'first_name');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden);
        $this->assertTrue($el->required);
    }

    public function testPersonFormLastNameVisibleAndRequired(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'last_name');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden);
        $this->assertTrue($el->required);
    }

    public function testPersonFormFullNameReadOnlyAndNotRequired(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'full_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->readOnly);
        $this->assertFalse($el->required);
    }

    // ── Undefined ────────────────────────────────────────────────────────────

    public function testUndefinedFormBothSectionsHidden(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        // Company fields hidden
        $companyId = $this->findElement($def, 'basic', 'company_id');
        $this->assertNotNull($companyId);
        $this->assertTrue($companyId->hidden);

        // Person fields hidden
        $firstName = $this->findElement($def, 'basic', 'first_name');
        $this->assertNotNull($firstName);
        $this->assertTrue($firstName->hidden);

        // Personal details hidden
        $birthDate = $this->findElement($def, 'basic', 'birth_date');
        $this->assertNotNull($birthDate);
        $this->assertTrue($birthDate->hidden);
    }

    public function testUndefinedWithExplicitZero(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['person_type' => 0], true);

        $companyId = $this->findElement($def, 'basic', 'company_id');
        $this->assertTrue($companyId->hidden);

        $firstName = $this->findElement($def, 'basic', 'first_name');
        $this->assertTrue($firstName->hidden);
    }

    // ── Recalculate ──────────────────────────────────────────────────────────

    public function testRecalculateToPersonComputesFullName(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 1,
            'first_name'  => 'Jan',
            'last_name'   => 'Novák',
            'full_name'   => '',
        ]);

        $this->assertSame('Jan Novák', $result->data['full_name']);
        $this->assertNotNull($result->formDefinition);
    }

    public function testRecalculateToCompanyKeepsFullName(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 2,
            'full_name'   => 'ACME s.r.o.',
        ]);

        $this->assertSame('ACME s.r.o.', $result->data['full_name']);
    }

    public function testRecalculateFormDefinitionReflectsNewType(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 1,
            'first_name'  => 'Jan',
            'last_name'   => 'Novák',
        ]);

        // After switching to Person, company_id should be hidden
        $companyId = $this->findElement($result->formDefinition, 'basic', 'company_id');
        $this->assertTrue($companyId->hidden);

        // first_name should be visible
        $firstName = $this->findElement($result->formDefinition, 'basic', 'first_name');
        $this->assertFalse($firstName->hidden);
    }

    // ── Title & table ────────────────────────────────────────────────────────

    public function testFormTitles(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertSame('Osoba', $def->title);
        $this->assertSame('Nová osoba', $def->titleNew);
        $this->assertSame('base_persons_persons', $def->table);
    }

    // ── Person type select has triggers ──────────────────────────────────────

    public function testPersonTypeSelectHasTriggers(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'person_type');
        $this->assertNotNull($el);
        $this->assertSame('select', $el->type);
        $this->assertSame('reload', $el->triggers);
    }

    // ── Subtable tabs ────────────────────────────────────────────────────────

    public function testContactsSubtableTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $contactsTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'contacts') {
                $contactsTab = $tab;
                break;
            }
        }

        $this->assertNotNull($contactsTab);
        $this->assertCount(1, $contactsTab->elements);
        $this->assertSame('subtable', $contactsTab->elements[0]->type);
        $this->assertSame('base_persons_contacts', $contactsTab->elements[0]->table);
        $this->assertSame('person', $contactsTab->elements[0]->foreignKey);
        $this->assertSame('base.persons.contacts', $contactsTab->elements[0]->formId);
    }
}
