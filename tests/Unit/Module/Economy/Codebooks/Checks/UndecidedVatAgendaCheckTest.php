<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\Checks\UndecidedVatAgendaCheck;

class UndecidedVatAgendaCheckTest extends TestCase
{
    /** @param array<string, mixed> $settings klíč → hodnota; chybějící klíč = nerozhodnuto */
    private function makeCheck(array $settings): UndecidedVatAgendaCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static fn(mixed ...$args): mixed => array_key_exists($args[1] ?? '', $settings)
                ? json_encode($settings[$args[1]])
                : null,
        );

        return new UndecidedVatAgendaCheck(
            $db,
            $this->createMock(ConfigRuntime::class),
            'cs',
        );
    }

    public function testMissingKeyFires(): void
    {
        $findings = $this->makeCheck([])->run();

        $this->assertCount(1, $findings);
        $this->assertSame('', $findings[0]->findingKey);
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertSame([], $findings[0]->actions);
    }

    public function testDecidedTrueIsSilent(): void
    {
        $this->assertSame([], $this->makeCheck(['economy.vatAgenda' => true])->run());
    }

    public function testDecidedFalseIsSilent(): void
    {
        // false je platné rozhodnutí (neplátce) — nesmí se plést s null.
        $this->assertSame([], $this->makeCheck(['economy.vatAgenda' => false])->run());
    }
}
