<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormSection;
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
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                        // Also look inside inline groups.
                        if ($el->type === 'inline' && $el->elements !== null) {
                            foreach ($el->elements as $inner) {
                                if ($inner->column === $column) {
                                    return $inner;
                                }
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    private function findSection(FormDefinition $def, string $tabId, ?string $title): ?FormSection
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== $tabId) {
                continue;
            }
            foreach ($tab->sections as $section) {
                if ($section->title === $title) {
                    return $section;
                }
            }
        }
        return null;
    }

    private function getTabIds(FormDefinition $def): array
    {
        return array_map(fn($tab) => $tab->id, $def->tabs);
    }

    // ── Form-level ───────────────────────────────────────────────────────────

    public function testFormHasSixTabs(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $this->assertSame(
            ['basic', 'contact', 'contacts', 'addresses', 'bank_accounts', 'attachments'],
            $this->getTabIds($def),
        );
    }

    public function testFormFullSize(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $this->assertTrue($def->fullSize);
    }

    public function testFormTitles(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $this->assertSame('Osoba', $def->title);
        $this->assertSame('Nová osoba', $def->titleNew);
        $this->assertSame('base_persons_persons', $def->table);
    }

    // ── Basic tab section layout ─────────────────────────────────────────────

    public function testBasicTabHasFourSections(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $basicTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'basic') {
                $basicTab = $tab;
                break;
            }
        }

        $this->assertNotNull($basicTab);
        $this->assertCount(4, $basicTab->sections);

        $titles = array_map(fn($s) => $s->title, $basicTab->sections);
        $this->assertSame(
            [null, 'Identifikace firmy', 'Jméno', 'Osobní údaje'],
            $titles,
        );
    }

    public function testContactTabHasTwoSections(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $contactTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'contact') {
                $contactTab = $tab;
                break;
            }
        }

        $this->assertNotNull($contactTab);
        $this->assertCount(2, $contactTab->sections);
        $this->assertSame(null, $contactTab->sections[0]->title);
        $this->assertSame('Platba', $contactTab->sections[1]->title);
    }

    // ── Section visibility — Company ─────────────────────────────────────────

    public function testCompanyShowsIdentifikaceFirmy(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Identifikace firmy');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testCompanyHidesJmenoSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Jméno');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testCompanyHidesOsobniUdajeSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Osobní údaje');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testCompanyFullNameRequiredAndEditable(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'basic', 'full_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
        $this->assertFalse($el->readOnly);
    }

    public function testIsOwnCheckboxIsInIdentifikaceFirmy(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        // is_own should live in the "Identifikace firmy" section, not its own section.
        $section = $this->findSection($def, 'basic', 'Identifikace firmy');
        $this->assertNotNull($section);

        $columns = [];
        foreach ($section->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->column !== null) {
                    $columns[] = $el->column;
                }
            }
        }

        $this->assertContains('is_own', $columns);
    }

    // ── Section visibility — Person ──────────────────────────────────────────

    public function testPersonHidesIdentifikaceFirmy(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Identifikace firmy');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testPersonShowsJmenoSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Jméno');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testPersonShowsOsobniUdajeSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Osobní údaje');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testPersonFirstNameRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'first_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
    }

    public function testPersonLastNameRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'last_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
    }

    public function testPersonFullNameReadOnlyAndNotRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'full_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->readOnly);
        $this->assertFalse($el->required);
    }

    public function testPersonNamesAreInsideInlineGroup(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $jmenoSection = $this->findSection($def, 'basic', 'Jméno');
        $this->assertNotNull($jmenoSection);

        $foundInline = null;
        foreach ($jmenoSection->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->type === 'inline') {
                    $foundInline = $el;
                    break 2;
                }
            }
        }

        $this->assertNotNull($foundInline, 'Inline group expected in Jméno section');
        $this->assertNotNull($foundInline->elements);
        $columns = array_map(fn($e) => $e->column, $foundInline->elements);
        $this->assertSame(['first_name', 'middle_name', 'last_name'], $columns);
    }

    // ── Section visibility — Undefined ───────────────────────────────────────

    public function testUndefinedHidesAllConditionalSections(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $sIdent = $this->findSection($def, 'basic', 'Identifikace firmy');
        $sJmeno = $this->findSection($def, 'basic', 'Jméno');
        $sOsobni = $this->findSection($def, 'basic', 'Osobní údaje');

        $this->assertTrue($sIdent->hidden);
        $this->assertTrue($sJmeno->hidden);
        $this->assertTrue($sOsobni->hidden);
    }

    public function testUndefinedWithExplicitZeroBehavesSame(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 0], true);

        $this->assertTrue($this->findSection($def, 'basic', 'Identifikace firmy')->hidden);
        $this->assertTrue($this->findSection($def, 'basic', 'Jméno')->hidden);
        $this->assertTrue($this->findSection($def, 'basic', 'Osobní údaje')->hidden);
    }

    // ── Person type select ───────────────────────────────────────────────────

    public function testPersonTypeSelectHasTriggers(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'person_type');
        $this->assertNotNull($el);
        $this->assertSame('select', $el->type);
        $this->assertSame('reload', $el->triggers);
    }

    // ── Contact tab ──────────────────────────────────────────────────────────

    public function testContactInputTypesAreSemantic(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $this->assertSame('email', $this->findElement($def, 'contact', 'email')->inputType);
        $this->assertSame('tel',   $this->findElement($def, 'contact', 'phone')->inputType);
        $this->assertSame('url',   $this->findElement($def, 'contact', 'web')->inputType);
    }

    // ── Recalculate ──────────────────────────────────────────────────────────

    public function testRecalculateToPersonComputesFullName(): void
    {
        $form   = $this->createForm();
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
        $form   = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 2,
            'full_name'   => 'ACME s.r.o.',
        ]);

        $this->assertSame('ACME s.r.o.', $result->data['full_name']);
    }

    public function testRecalculateFormDefinitionReflectsNewType(): void
    {
        $form   = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 1,
            'first_name'  => 'Jan',
            'last_name'   => 'Novák',
        ]);

        // Switched to Person → Identifikace firmy hidden, Jméno visible.
        $this->assertTrue(
            $this->findSection($result->formDefinition, 'basic', 'Identifikace firmy')->hidden,
        );
        $this->assertFalse(
            $this->findSection($result->formDefinition, 'basic', 'Jméno')->hidden,
        );
    }

    // ── Subtable tabs ────────────────────────────────────────────────────────

    public function testContactsSubtableTab(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $contactsTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'contacts') {
                $contactsTab = $tab;
                break;
            }
        }

        $this->assertNotNull($contactsTab);
        $this->assertSame('subtable', $contactsTab->type);
        $this->assertSame('base_persons_contacts', $contactsTab->subtable['table']);
        $this->assertSame('person', $contactsTab->subtable['foreignKey']);
        $this->assertSame('base.persons.contacts', $contactsTab->subtable['formId']);
    }
}
