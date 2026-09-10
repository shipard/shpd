<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\StructuredFields\StructuredFieldResolver;

class StructuredFieldResolverTest extends TestCase
{
    private const PROFILE = [
        'version' => '2026',
        'fields'  => [['id' => 'email', 'type' => 'varchar', 'length' => 255, 'name' => 'E-mail']],
    ];

    private const HEADER_DP3 = [
        'version' => '2026',
        'fields'  => [['id' => 'c_ufo', 'type' => 'enumString', 'length' => 5, 'cfgItem' => 'x.y', 'name' => 'FÚ']],
    ];

    private function resolver(): StructuredFieldResolver
    {
        $items = [
            'economy.vat.filingProfileCz' => self::PROFILE,
            'economy.vat.filingHeaderDp3' => self::HEADER_DP3,
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return new StructuredFieldResolver($config);
    }

    // ── zápis / editace: hook > statický atribut ────────────────────────────

    public function testForWriteUsesStaticKeyWithoutHook(): void
    {
        $schema = $this->resolver()->forWrite(null, 'economy.vat.filingProfileCz');

        $this->assertSame('economy.vat.filingProfileCz/2026', $schema?->key());
    }

    public function testForWriteHookWins(): void
    {
        $schema = $this->resolver()->forWrite('economy.vat.filingHeaderDp3', 'economy.vat.filingProfileCz');

        $this->assertSame('economy.vat.filingHeaderDp3/2026', $schema?->key());
        $this->assertArrayHasKey('c_ufo', $schema?->fields ?? []);
    }

    public function testForWriteWithoutAnyKeyIsNull(): void
    {
        $this->assertNull($this->resolver()->forWrite(null, null));
    }

    public function testRequireForWriteThrowsWithoutKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Column 'tbl.col' has no structured schema");

        $this->resolver()->requireForWrite('tbl.col', null, null);
    }

    public function testRequireForWriteThrowsOnUnavailableSchema(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("structured schema 'nope.missing' is not available");

        $this->resolver()->requireForWrite('tbl.col', null, 'nope.missing');
    }

    // ── zobrazení: `_schema` hodnoty > statický atribut ─────────────────────

    public function testForValueFollowsSchemaKeyInValue(): void
    {
        $value = '{"_schema":"economy.vat.filingHeaderDp3/2026","c_ufo":"451"}';

        $schema = $this->resolver()->forValue($value, 'economy.vat.filingProfileCz');

        $this->assertSame('economy.vat.filingHeaderDp3/2026', $schema?->key());
    }

    public function testForValueIgnoresVersionMismatchAndUsesCurrentFile(): void
    {
        // Starší verze schémat se neuchovávají — cfgItem z `_schema` platí,
        // verze se bere z aktuálního souboru (viz doc-comment StructuredSchema).
        $value = ['_schema' => 'economy.vat.filingProfileCz/2019', 'email' => 'a@b.cz'];

        $schema = $this->resolver()->forValue($value, null);

        $this->assertSame('economy.vat.filingProfileCz/2026', $schema?->key());
    }

    public function testForValueFallsBackToStaticKey(): void
    {
        $schema = $this->resolver()->forValue(null, 'economy.vat.filingProfileCz');

        $this->assertSame('economy.vat.filingProfileCz/2026', $schema?->key());
    }

    public function testForValueWithoutSchemaKeyOrStaticKeyIsNull(): void
    {
        $this->assertNull($this->resolver()->forValue('{"email":"a@b.cz"}', null));
    }

    public function testForValueWithoutConfigIsNull(): void
    {
        $resolver = new StructuredFieldResolver(null);

        $this->assertNull($resolver->forValue(null, 'economy.vat.filingProfileCz'));
    }
}
