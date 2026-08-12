<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Settings;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Settings\SetupChecklist;
use Shipard\Tests\Fixtures\Core\Settings\SetupChecklistBrokenCheck;
use Shipard\Tests\Fixtures\Core\Settings\SetupChecklistEmptyCheck;
use Shipard\Tests\Fixtures\Core\Settings\SetupChecklistFindingCheck;

class SetupChecklistTest extends TestCase
{
    private static function module(string $id, array $alertChecks): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id'          => $id,
            'name'        => ucfirst($id),
            'alertChecks' => $alertChecks,
        ]);
    }

    /** @param list<string> $extra extra klíče záznamu (např. enabled) */
    private static function checkEntry(string $id, string $class, array $extra = []): array
    {
        return $extra + [
            'id'       => $id,
            'name'     => "Name of {$id}",
            'class'    => $class,
            'severity' => 'warning',
            'interval' => '5m',
            'tags'     => ['setup'],
        ];
    }

    /** @param ModuleDefinition[] $modules */
    private function makeChecklist(array $modules, ?MockObject $db = null): SetupChecklist
    {
        return new SetupChecklist(
            $db ?? $this->createMock(DataSourceConnection::class),
            new AlertCheckRegistry($modules, 'cs'),
            $this->createMock(ConfigRuntime::class),
            'cs',
        );
    }

    public function testReturnsOnlySetupChecksInDefinedOrder(): void
    {
        // Registrace v záměrně přeházeném pořadí + neznámé setup id
        // (není v ORDER) + check bez setup tagu.
        $nonSetup = self::checkEntry('x.other.non_setup', SetupChecklistFindingCheck::class);
        $nonSetup['tags'] = ['accounting'];

        $items = $this->makeChecklist([
            self::module('economy.codebooks', [
                self::checkEntry('economy.codebooks.undecided_home_currency', SetupChecklistFindingCheck::class),
                self::checkEntry('economy.codebooks.undecided_vat_agenda', SetupChecklistFindingCheck::class),
            ]),
            self::module('zzz.extra', [
                self::checkEntry('zzz.extra.custom_setup', SetupChecklistFindingCheck::class),
            ]),
            self::module('x.other', [$nonSetup]),
            self::module('base.persons', [
                self::checkEntry('base.persons.missing_own_person', SetupChecklistFindingCheck::class),
            ]),
        ])->collect();

        $this->assertSame(
            [
                'base.persons.missing_own_person',
                'economy.codebooks.undecided_vat_agenda',
                'economy.codebooks.undecided_home_currency',
                'zzz.extra.custom_setup',   // mimo ORDER → na konec, ne výjimka
            ],
            array_column($items, 'checkId'),
        );
        $this->assertSame('Name of base.persons.missing_own_person', $items[0]['name']);
        $this->assertInstanceOf(AlertFinding::class, $items[0]['finding']);
    }

    public function testDisabledSetupCheckIsSkipped(): void
    {
        $items = $this->makeChecklist([
            self::module('base.persons', [
                self::checkEntry(
                    'base.persons.missing_own_person',
                    SetupChecklistFindingCheck::class,
                    ['enabled' => false],
                ),
            ]),
        ])->collect();

        $this->assertSame([], $items);
    }

    public function testBrokenCheckDoesNotStopCollection(): void
    {
        // Rozbitý check je v pořadí PRVNÍ — ostatní položky musí přijít.
        $items = $this->makeChecklist([
            self::module('base.persons', [
                self::checkEntry('base.persons.missing_own_person', SetupChecklistBrokenCheck::class),
            ]),
            self::module('economy.codebooks', [
                self::checkEntry('economy.codebooks.undecided_vat_agenda', SetupChecklistFindingCheck::class),
            ]),
        ])->collect();

        $this->assertSame(
            ['economy.codebooks.undecided_vat_agenda'],
            array_column($items, 'checkId'),
        );
    }

    public function testEmptyResultWhenEverythingIsSet(): void
    {
        $items = $this->makeChecklist([
            self::module('base.persons', [
                self::checkEntry('base.persons.missing_own_person', SetupChecklistEmptyCheck::class),
                self::checkEntry('base.persons.missing_own_headquarters', SetupChecklistEmptyCheck::class),
            ]),
        ])->collect();

        $this->assertSame([], $items);
    }

    public function testParameterByCheckCoversAllUndecidedChecksInOrder(): void
    {
        // Regresní: nový parametrový check v ORDER nesmí zůstat bez ovládání
        // v panelu — a mapa nesmí odkazovat na check, který v ORDER není.
        foreach (SetupChecklist::ORDER as $checkId) {
            if (str_contains($checkId, '.undecided_')) {
                $this->assertArrayHasKey(
                    $checkId,
                    SetupChecklist::PARAMETER_BY_CHECK,
                    "undecided check '{$checkId}' nemá mapovaný parametr",
                );
            }
        }
        foreach (SetupChecklist::PARAMETER_BY_CHECK as $checkId => $parameter) {
            $this->assertContains($checkId, SetupChecklist::ORDER);
            $this->assertContains($parameter, \Shipard\Core\Settings\LayerCParameters::keys());
        }
    }

    public function testDoesNotWriteToAlertsTable(): void
    {
        // Regresní test na D12: živý běh panelu nesmí zapisovat do DB —
        // zápisy do tabulky alertů dělá výhradně cron (AlertReconciler).
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('insertRow');
        $db->expects($this->never())->method('updateWhere');
        $db->expects($this->never())->method('deleteWhere');
        $db->expects($this->never())->method('execute');
        $db->expects($this->never())->method('executeSQL');

        $items = $this->makeChecklist([
            self::module('base.persons', [
                self::checkEntry('base.persons.missing_own_person', SetupChecklistFindingCheck::class),
            ]),
        ], $db)->collect();

        $this->assertCount(1, $items);
    }
}
