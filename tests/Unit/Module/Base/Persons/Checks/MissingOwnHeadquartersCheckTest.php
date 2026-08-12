<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Checks\MissingOwnHeadquartersCheck;

class MissingOwnHeadquartersCheckTest extends TestCase
{
    /**
     * @param list<array{id: int}> $ownPersons aktivní vlastní Osoby
     */
    private function makeCheck(array $ownPersons, int $headquartersCount, string $language = 'cs'): MissingOwnHeadquartersCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($ownPersons);
        $db->method('fetchSingle')->willReturn($headquartersCount);

        return new MissingOwnHeadquartersCheck(
            $db,
            $this->createMock(ConfigRuntime::class),
            $language,
        );
    }

    public function testOwnPersonWithoutHeadquartersFires(): void
    {
        $findings = $this->makeCheck([['id' => 7]], 0)->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('', $f->findingKey);
        $this->assertSame('warning', $f->severity);
        $this->assertSame('Vlastní Osoba nemá adresu sídla', $f->title);

        // Akce otevírá edit form vlastní Osoby — sídlo se doplňuje v subformu.
        $this->assertCount(1, $f->actions);
        $action = $f->actions[0];
        $this->assertSame('open_form', $action['kind']);
        $this->assertSame('base_persons_persons', $action['target']['table']);
        $this->assertSame('edit', $action['target']['mode']);
        $this->assertSame(7, $action['target']['id']);
    }

    public function testOwnPersonWithHeadquartersIsSilent(): void
    {
        $this->assertSame([], $this->makeCheck([['id' => 7]], 1)->run());
    }

    public function testSilentWithoutOwnPerson(): void
    {
        // Bez vlastní Osoby mluví missing_own_person — dvě položky
        // o tomtéž jsou šum. Dotaz na adresy se vůbec nesmí položit.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->never())->method('fetchSingle');

        $check = new MissingOwnHeadquartersCheck(
            $db,
            $this->createMock(ConfigRuntime::class),
            'cs',
        );

        $this->assertSame([], $check->run());
    }

    public function testEnglishLocalization(): void
    {
        $findings = $this->makeCheck([['id' => 7]], 0, 'en')->run();

        $this->assertSame('Own Person has no registered office address', $findings[0]->title);
    }
}
