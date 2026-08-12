<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\Checks\MissingVatRegistrationCheck;

class MissingVatRegistrationCheckTest extends TestCase
{
    /** @param array<string, mixed> $settings klíč → hodnota; chybějící klíč = nerozhodnuto */
    private function makeCheck(array $settings, int $registrationCount): MissingVatRegistrationCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($settings, $registrationCount): mixed {
                if (str_contains((string) $args[0], 'core_system_settings')) {
                    return array_key_exists($args[1] ?? '', $settings)
                        ? json_encode($settings[$args[1]])
                        : null;
                }
                return $registrationCount;
            },
        );

        return new MissingVatRegistrationCheck(
            $db,
            $this->createMock(ConfigRuntime::class),
            'cs',
        );
    }

    public function testPayerWithoutRegistrationFires(): void
    {
        $findings = $this->makeCheck(['economy.vatAgenda' => true], 0)->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('', $f->findingKey);
        $this->assertSame('warning', $f->severity);
        $this->assertSame('Chybí Registrace DPH', $f->title);
        $this->assertSame('open_form', $f->actions[0]['kind']);
        $this->assertSame('economy_codebooks_vat_registrations', $f->actions[0]['target']['table']);
        $this->assertSame('create', $f->actions[0]['target']['mode']);
    }

    public function testPayerWithRegistrationIsSilent(): void
    {
        $this->assertSame([], $this->makeCheck(['economy.vatAgenda' => true], 1)->run());
    }

    public function testNonPayerIsSilentEvenWithoutRegistration(): void
    {
        $this->assertSame([], $this->makeCheck(['economy.vatAgenda' => false], 0)->run());
    }

    public function testUndecidedIsSilent(): void
    {
        // U nerozhodnutého klíče mluví undecided_vat_agenda — striktně
        // === true, null se nesmí chovat jako rozhodnutí.
        $this->assertSame([], $this->makeCheck([], 0)->run());
    }
}
