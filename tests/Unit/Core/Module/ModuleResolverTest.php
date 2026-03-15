<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleResolver;

class ModuleResolverTest extends TestCase
{
    private function makeDef(string $id, array $deps = []): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id' => $id,
            'name' => ucfirst(str_replace('.', ' ', $id)),
            'dependencies' => $deps,
        ]);
    }

    public function testLinearChain(): void
    {
        $c = $this->makeDef('mod.c');
        $b = $this->makeDef('mod.b', ['mod.c']);
        $a = $this->makeDef('mod.a', ['mod.b']);

        $errors = [];
        $result = ModuleResolver::resolve([$c, $b, $a], ['mod.a'], $errors);

        $this->assertEmpty($errors);
        $this->assertCount(3, $result);
        $ids = array_map(fn($d) => $d->id, $result);
        $this->assertSame(['mod.c', 'mod.b', 'mod.a'], $ids);
    }

    public function testTree(): void
    {
        $b = $this->makeDef('mod.b');
        $c = $this->makeDef('mod.c');
        $a = $this->makeDef('mod.a', ['mod.b', 'mod.c']);

        $errors = [];
        $result = ModuleResolver::resolve([$b, $c, $a], ['mod.a'], $errors);

        $this->assertEmpty($errors);
        $this->assertCount(3, $result);
        $ids = array_map(fn($d) => $d->id, $result);
        $posA = array_search('mod.a', $ids);
        $posB = array_search('mod.b', $ids);
        $posC = array_search('mod.c', $ids);
        $this->assertGreaterThan($posB, $posA);
        $this->assertGreaterThan($posC, $posA);
    }

    public function testDiamond(): void
    {
        $d = $this->makeDef('mod.d');
        $b = $this->makeDef('mod.b', ['mod.d']);
        $c = $this->makeDef('mod.c', ['mod.d']);
        $a = $this->makeDef('mod.a', ['mod.b', 'mod.c']);

        $errors = [];
        $result = ModuleResolver::resolve([$d, $b, $c, $a], ['mod.a'], $errors);

        $this->assertEmpty($errors);
        $ids = array_map(fn($d) => $d->id, $result);

        // D appears exactly once
        $this->assertSame(1, count(array_filter($ids, fn($id) => $id === 'mod.d')));
        $this->assertCount(4, $result);

        $posA = array_search('mod.a', $ids);
        $posB = array_search('mod.b', $ids);
        $posC = array_search('mod.c', $ids);
        $posD = array_search('mod.d', $ids);

        $this->assertGreaterThan($posD, $posB);
        $this->assertGreaterThan($posD, $posC);
        $this->assertGreaterThan($posB, $posA);
        $this->assertGreaterThan($posC, $posA);
    }

    public function testCycle(): void
    {
        $a = $this->makeDef('mod.a', ['mod.b']);
        $b = $this->makeDef('mod.b', ['mod.a']);

        $errors = [];
        $result = ModuleResolver::resolve([$a, $b], ['mod.a', 'mod.b'], $errors);

        $this->assertNotEmpty($errors);
        $cycleError = array_filter($errors, fn($e) => str_contains($e, 'circular dependency'));
        $this->assertNotEmpty($cycleError);
    }

    public function testMissingDependency(): void
    {
        $a = $this->makeDef('mod.a', ['missing.mod']);

        $errors = [];
        $result = ModuleResolver::resolve([$a], ['mod.a'], $errors);

        $ids = array_map(fn($d) => $d->id, $result);
        $this->assertContains('mod.a', $ids);

        $this->assertNotEmpty($errors);
        $warning = array_filter($errors, fn($e) => str_contains($e, 'missing.mod'));
        $this->assertNotEmpty($warning);
    }

    public function testNoDependencies(): void
    {
        $x = $this->makeDef('mod.x');

        $errors = [];
        $result = ModuleResolver::resolve([$x], ['mod.x'], $errors);

        $this->assertEmpty($errors);
        $this->assertCount(1, $result);
        $this->assertSame('mod.x', $result[0]->id);
    }

    public function testInstallModule(): void
    {
        $coreSystem = $this->makeDef('core.system');
        $basePersons = $this->makeDef('base.persons');
        $installBase = $this->makeDef('install.base', ['core.system', 'base.persons']);

        $errors = [];
        $result = ModuleResolver::resolve(
            [$installBase, $coreSystem, $basePersons],
            ['install.base'],
            $errors,
        );

        $this->assertEmpty($errors);
        $this->assertCount(3, $result);
        $ids = array_map(fn($d) => $d->id, $result);

        $posInstall = array_search('install.base', $ids);
        $posCore = array_search('core.system', $ids);
        $posPersons = array_search('base.persons', $ids);

        $this->assertGreaterThan($posCore, $posInstall);
        $this->assertGreaterThan($posPersons, $posInstall);
    }

    public function testDeduplication(): void
    {
        $c = $this->makeDef('mod.c');
        $a = $this->makeDef('mod.a', ['mod.c']);
        $b = $this->makeDef('mod.b', ['mod.c']);

        $errors = [];
        $result = ModuleResolver::resolve([$c, $a, $b], ['mod.a', 'mod.b'], $errors);

        $this->assertEmpty($errors);
        $ids = array_map(fn($d) => $d->id, $result);

        // C appears exactly once
        $this->assertSame(1, count(array_filter($ids, fn($id) => $id === 'mod.c')));
        $this->assertCount(3, $result);
    }
}
