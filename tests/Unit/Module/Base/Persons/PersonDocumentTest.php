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

    public function testValidateIsOwnOnPersonFails(): void
    {
        $doc = $this->doc();
        $data = [
            'person_type' => 1,
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $codes = array_column($result->toArray(), 'code');
        $this->assertContains('is_own_not_company', $codes);
    }

    public function testValidateIsOwnDuplicateFails(): void
    {
        // Mock returns an existing row → uniqueness check fails.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row(['id' => 7]));

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'person_type' => 2,
            'full_name' => 'Acme s.r.o.',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $codes = array_column($result->toArray(), 'code');
        $this->assertContains('is_own_duplicate', $codes);
    }

    public function testValidateIsOwnUniqueWhenNoOtherOwnExists(): void
    {
        // Mock returns null → no other own company in DS.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'person_type' => 2,
            'full_name' => 'Acme s.r.o.',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsOwnUpdateOfSameRecordPasses(): void
    {
        // Mock returns null because the SQL excludes the current id.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'id' => 42,
            'person_type' => 2,
            'full_name' => 'Acme s.r.o.',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsOwnZeroSkipsChecks(): void
    {
        // No DB needed — the is_own block must short-circuit on falsy value.
        $doc = $this->doc();
        $data = [
            'person_type' => 1,
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'is_own' => 0,
        ];

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
