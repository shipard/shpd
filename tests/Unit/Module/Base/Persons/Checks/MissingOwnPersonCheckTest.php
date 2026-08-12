<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Checks\MissingOwnPersonCheck;

class MissingOwnPersonCheckTest extends TestCase
{
    private function makeCheck(int $ownPersonCount, string $language = 'cs'): MissingOwnPersonCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn($ownPersonCount);

        return new MissingOwnPersonCheck(
            $db,
            $this->createMock(ConfigRuntime::class),
            $language,
        );
    }

    public function testMissingOwnPersonFires(): void
    {
        $findings = $this->makeCheck(0)->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('', $f->findingKey);
        $this->assertSame('warning', $f->severity);
        $this->assertSame('Chybí vlastní Osoba', $f->title);
    }

    public function testOwnPersonPresentIsSilent(): void
    {
        $this->assertSame([], $this->makeCheck(1)->run());
    }

    /**
     * Regrese k tasks/ds-setup-08: akce `registry_import_own` je jen
     * panelová serializace v SetupControlleru. Finding checku putuje cronem
     * do core_alerts_alerts a odtud do feedu / vieweru alertů, které umí
     * obsloužit jen `open_form` — cizí kind by tam skončil s console.warn.
     */
    public function testFindingCarriesOnlyOpenFormAction(): void
    {
        $f = $this->makeCheck(0)->run()[0];

        $this->assertCount(1, $f->actions);
        $action = $f->actions[0];
        $this->assertSame('create_own_person', $action['id']);
        $this->assertSame('open_form', $action['kind']);
        $this->assertTrue($action['primary']);
        $this->assertSame('base_persons_persons', $action['target']['table']);
        $this->assertSame(['is_own' => true, 'person_type' => 2], $action['target']['preset']);
    }

    public function testEnglishLocalization(): void
    {
        $findings = $this->makeCheck(0, 'en')->run();

        $this->assertSame('Own Person is missing', $findings[0]->title);
    }
}
