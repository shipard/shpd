<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Alerts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Module\ModuleDefinition;

class AlertCheckRegistryTest extends TestCase
{
    private static function module(string $id, array $alertChecks): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id' => $id,
            'name' => ucfirst($id),
            'alertChecks' => $alertChecks,
        ]);
    }

    public function testEmptyRegistry(): void
    {
        $reg = new AlertCheckRegistry([], 'en');
        $this->assertSame([], $reg->getAll());
        $this->assertSame([], $reg->getEnabled());
        $this->assertNull($reg->get('x.y'));
    }

    public function testCollectsFromMultipleModules(): void
    {
        $m1 = self::module('base.persons', [
            ['id' => 'base.persons.missing_own', 'name' => 'A', 'class' => 'A', 'interval' => '1h'],
        ]);
        $m2 = self::module('iot.devices', [
            ['id' => 'iot.devices.low_battery', 'name' => 'B', 'class' => 'B', 'interval' => '4h'],
            ['id' => 'iot.devices.offline',     'name' => 'C', 'class' => 'C', 'interval' => '30m'],
        ]);

        $reg = new AlertCheckRegistry([$m1, $m2], 'en');

        $all = $reg->getAll();
        $this->assertCount(3, $all);

        $ids = array_map(static fn ($d) => $d->id, $all);
        $this->assertContains('base.persons.missing_own', $ids);
        $this->assertContains('iot.devices.low_battery', $ids);
        $this->assertContains('iot.devices.offline', $ids);
    }

    public function testLookupById(): void
    {
        $reg = new AlertCheckRegistry([self::module('x.y', [
            ['id' => 'x.y.foo', 'name' => 'Foo', 'class' => 'C', 'interval' => '1h'],
        ])], 'en');

        $def = $reg->get('x.y.foo');
        $this->assertNotNull($def);
        $this->assertSame('x.y.foo', $def->id);
        $this->assertSame('x.y', $def->moduleId);
        $this->assertNull($reg->get('x.y.bar'));
    }

    public function testGetEnabledExcludesDisabled(): void
    {
        $m = self::module('x.y', [
            ['id' => 'x.y.on',  'name' => 'On',  'class' => 'C', 'interval' => '1h'],
            ['id' => 'x.y.off', 'name' => 'Off', 'class' => 'C', 'interval' => '1h', 'enabled' => false],
        ]);

        $reg = new AlertCheckRegistry([$m], 'en');
        $this->assertCount(2, $reg->getAll());

        $enabled = $reg->getEnabled();
        $this->assertCount(1, $enabled);
        $this->assertSame('x.y.on', $enabled[0]->id);
    }

    public function testDuplicateCheckIdAcrossModulesThrows(): void
    {
        $m1 = self::module('a.b', [
            ['id' => 'shared.id', 'name' => 'A', 'class' => 'A', 'interval' => '1h'],
        ]);
        $m2 = self::module('c.d', [
            ['id' => 'shared.id', 'name' => 'B', 'class' => 'B', 'interval' => '1h'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("duplicate check id 'shared.id'");
        new AlertCheckRegistry([$m1, $m2], 'en');
    }

    public function testLocalizationApplied(): void
    {
        $m = self::module('base.persons', [
            [
                'id'          => 'base.persons.missing_own',
                'name'        => 'Own Person is missing',
                'name:cs'     => 'Chybí vlastní Osoba',
                'description' => 'Default desc',
                'description:cs' => 'Český popis',
                'class'       => 'C',
                'interval'    => '1h',
            ],
        ]);

        $regCs = new AlertCheckRegistry([$m], 'cs');
        $cs = $regCs->get('base.persons.missing_own');
        $this->assertSame('Chybí vlastní Osoba', $cs->name);
        $this->assertSame('Český popis', $cs->description);

        $regEn = new AlertCheckRegistry([$m], 'en');
        $en = $regEn->get('base.persons.missing_own');
        // Fallback: no :en variant → bare value
        $this->assertSame('Own Person is missing', $en->name);
        $this->assertSame('Default desc', $en->description);
    }

    public function testInvalidCheckBubblesUp(): void
    {
        $m = self::module('a.b', [
            ['id' => 'a.b.x', 'name' => 'x', 'class' => 'C', 'interval' => 'not-a-duration'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        new AlertCheckRegistry([$m], 'en');
    }
}
