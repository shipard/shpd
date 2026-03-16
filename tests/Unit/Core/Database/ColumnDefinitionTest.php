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
}
