<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocStatesDefinition;

class DocStatesDefinitionTest extends TestCase
{
    public function testFromArrayFull(): void
    {
        $def = DocStatesDefinition::fromArray([
            'stateColumn' => 'docState',
            'mainColumn'  => 'docStateMain',
            'cfgItem'     => 'core.system.docStatesArchive',
        ]);

        $this->assertSame('docState', $def->stateColumn);
        $this->assertSame('docStateMain', $def->mainColumn);
        $this->assertSame('core.system.docStatesArchive', $def->cfgItem);
    }

    public function testDefaultColumnNames(): void
    {
        $def = DocStatesDefinition::fromArray([
            'cfgItem' => 'core.system.docStatesArchive',
        ]);

        $this->assertSame('docState', $def->stateColumn);
        $this->assertSame('docStateMain', $def->mainColumn);
    }

    public function testCustomColumnNames(): void
    {
        $def = DocStatesDefinition::fromArray([
            'stateColumn' => 'state',
            'mainColumn'  => 'stateSort',
            'cfgItem'     => 'economy.docs.docStates',
        ]);

        $this->assertSame('state', $def->stateColumn);
        $this->assertSame('stateSort', $def->mainColumn);
        $this->assertSame('economy.docs.docStates', $def->cfgItem);
    }

    public function testMissingCfgItemThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocStatesDefinition::fromArray([]);
    }

    public function testEmptyCfgItemThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocStatesDefinition::fromArray(['cfgItem' => '']);
    }
}
