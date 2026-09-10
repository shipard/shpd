<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\RoundingModes;

/**
 * `RoundingModes` je autorita sémantiky zaokrouhlovacích módů; jsonc
 * `roundingModes` / `vatRoundingModes` nesou jen názvy pro UI. Test drží
 * oba zdroje na stejné množině kódů — DocDocument běží i bez konfigurace,
 * takže rozjetí by se za běhu neprojevilo chybou, jen prázdnou roletkou
 * nebo módem, který nejde v UI zvolit (#63).
 */
class RoundingModesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function cfg(string $file): array
    {
        $root = dirname(__DIR__, 5);
        $data = JsoncParser::parseFile($root . '/modules/docs/core/config/' . $file . '.jsonc');
        $this->assertIsArray($data);
        return $data;
    }

    /** @param array<string, mixed> $cfg @return list<int> */
    private function keys(array $cfg): array
    {
        $keys = array_map('intval', array_keys($cfg));
        sort($keys);
        return $keys;
    }

    public function testRoundingModesJsoncMatchesTable(): void
    {
        $expected = array_keys(RoundingModes::TABLE);
        sort($expected);
        $this->assertSame($expected, $this->keys($this->cfg('roundingModes')));
    }

    public function testVatRoundingModesJsoncMatchesVatModes(): void
    {
        $expected = RoundingModes::VAT_MODES;
        sort($expected);
        $this->assertSame($expected, $this->keys($this->cfg('vatRoundingModes')));
    }

    public function testVatRoundingModesShareNamesWithRoundingModes(): void
    {
        // Sdílené kódy → sdílená data ve sloupcích; názvy se nesmí rozejít.
        $all = $this->cfg('roundingModes');
        foreach ($this->cfg('vatRoundingModes') as $key => $entry) {
            $this->assertArrayHasKey($key, $all, "vat mode {$key} missing in roundingModes");
            $this->assertSame($all[$key], $entry, "names differ for mode {$key}");
        }
    }

    public function testHistoricalModeTwoIsGone(): void
    {
        $this->assertFalse(RoundingModes::isKnown(2));
        $this->assertArrayNotHasKey('2', $this->cfg('roundingModes'));
        $this->assertTrue(RoundingModes::isKnown(RoundingModes::MATH_FIVE_CENT));
    }

    /** @return iterable<string, array{float, int, float}> */
    public static function applyCases(): iterable
    {
        yield 'cents'                  => [123.4567, RoundingModes::ON_CENT, 123.46];
        yield 'unit math up'           => [123.55, RoundingModes::MATH_UNIT, 124.0];
        yield 'unit math down'         => [123.49, RoundingModes::MATH_UNIT, 123.0];
        yield 'unit ceil'              => [123.05, RoundingModes::UP_UNIT, 124.0];
        yield 'unit ceil negative'     => [-1709.05, RoundingModes::UP_UNIT, -1709.0];
        yield 'unit floor'             => [123.95, RoundingModes::DOWN_UNIT, 123.0];
        yield 'unit floor negative'    => [-1709.05, RoundingModes::DOWN_UNIT, -1710.0];
        yield 'five cents up'          => [1709.03, RoundingModes::MATH_FIVE_CENT, 1709.05];
        yield 'five cents down'        => [1709.02, RoundingModes::MATH_FIVE_CENT, 1709.0];
        yield 'five cents exact (P1)'  => [1709.05, RoundingModes::MATH_FIVE_CENT, 1709.05];
        yield 'five cents half'        => [0.125, RoundingModes::MATH_FIVE_CENT, 0.15];
        yield 'five cents negative'    => [-1709.03, RoundingModes::MATH_FIVE_CENT, -1709.05];
        yield 'unknown 2 → cents'      => [123.456, 2, 123.46];
        yield 'unknown 99 → cents'     => [123.456, 99, 123.46];
    }

    #[DataProvider('applyCases')]
    public function testApply(float $amount, int $mode, float $expected): void
    {
        $this->assertSame($expected, RoundingModes::apply($amount, $mode));
    }
}
