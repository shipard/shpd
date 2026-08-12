<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\Checks\UndecidedAccountChartCheck;

class UndecidedAccountChartCheckTest extends TestCase
{
    /** @param array<string, mixed> $settings klíč → hodnota; chybějící klíč = nerozhodnuto */
    private function makeCheck(array $settings): UndecidedAccountChartCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static fn(mixed ...$args): mixed => array_key_exists($args[1] ?? '', $settings)
                ? json_encode($settings[$args[1]])
                : null,
        );

        return new UndecidedAccountChartCheck(
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
        $this->assertSame('Není zvolena účtová osnova', $findings[0]->title);
        // open_panel dodá Task 06/07 — do té doby bez akce.
        $this->assertSame([], $findings[0]->actions);
    }

    public function testDecidedDefaultIsSilent(): void
    {
        $this->assertSame([], $this->makeCheck(['economy.accountChart' => 'default'])->run());
    }

    public function testDecidedNoneIsSilent(): void
    {
        // 'none' (vlastní osnova, neseedovat) je platné rozhodnutí.
        $this->assertSame([], $this->makeCheck(['economy.accountChart' => 'none'])->run());
    }
}
