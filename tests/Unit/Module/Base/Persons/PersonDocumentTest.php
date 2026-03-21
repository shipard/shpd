<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonDocument;

class PersonDocumentTest extends TestCase
{
    private function doc(): PersonDocument
    {
        return new PersonDocument();
    }

    // --- validate -----------------------------------------------------------

    public function testValidateCompanyWithoutFullNameFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => ''];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('full_name', $columns);
    }

    public function testValidatePersonWithoutLastNameFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => ''];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('last_name', $columns);
    }

    public function testValidatePersonWithoutFirstNameFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => '', 'last_name' => 'Novák'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('first_name', $columns);
    }

    public function testValidateMissingPersonTypeFails(): void
    {
        $doc = $this->doc();
        $data = ['full_name' => 'Test'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('person_type', $columns);
    }

    public function testValidateUndefinedPersonTypeFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 0, 'full_name' => 'Test'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('person_type', $columns);
    }

    public function testValidateValidCompany(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateValidPerson(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => 'Novák'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveCompanyClearsFirstNameAndSetsLastName(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'first_name' => 'ignored'];

        $doc->beforeSave($data);

        $this->assertSame('', $data['first_name']);
        $this->assertSame('Acme s.r.o.', $data['last_name']);
    }

    public function testBeforeSavePersonCombinesFullName(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => 'Novák'];

        $doc->beforeSave($data);

        $this->assertSame('Jan Novák', $data['full_name']);
    }

    public function testBeforeSavePersonTrimsFullName(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => '', 'last_name' => 'Novák'];

        $doc->beforeSave($data);

        $this->assertSame('Novák', $data['full_name']);
    }
}
