<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;

class ColumnDefinitionTest extends TestCase
{
    public function testReferenceLoaded(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'contact_id',
            'name' => 'Contact',
            'type' => 'int',
            'reference' => 'base_persons_contacts',
        ]);

        $this->assertSame('base_persons_contacts', $col->reference);
    }

    public function testReferenceDefaultsToNull(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'contact_id',
            'name' => 'Contact',
            'type' => 'int',
        ]);

        $this->assertNull($col->reference);
    }

    public function testEncryptedTextTypeIsAccepted(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
            'nullable' => true,
            'group' => 'credentials',
        ]);

        $this->assertSame('encrypted_text', $col->type);
        $this->assertTrue($col->nullable);
    }

    public function testEncryptedTextIsSensitiveWithoutFlag(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testEncryptedTextIsSensitiveWithExplicitFlag(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
            'sensitive' => true,
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testEncryptedTextOverridesExplicitSensitiveFalse(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
            'sensitive' => false,
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testNonEncryptedTypeIsNotSensitiveByDefault(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'note',
            'name' => 'Note',
            'type' => 'text',
        ]);

        $this->assertFalse($col->sensitive);
    }

    public function testStructuredSchemaOnJsonColumn(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'filing_profile',
            'name' => 'Filing profile',
            'type' => 'json',
            'nullable' => true,
            'schema' => 'economy.vat.filingProfileCz',
        ]);

        $this->assertSame('economy.vat.filingProfileCz', $col->schema);
    }

    public function testSchemaDefaultsToNull(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'snapshot',
            'name' => 'Snapshot',
            'type' => 'json',
        ]);

        $this->assertNull($col->schema);
    }

    public function testSchemaOnNonJsonColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("attribute 'schema' is allowed only for type 'json'");

        ColumnDefinition::fromArray([
            'id' => 'note',
            'name' => 'Note',
            'type' => 'text',
            'schema' => 'economy.vat.filingProfileCz',
        ]);
    }
}
