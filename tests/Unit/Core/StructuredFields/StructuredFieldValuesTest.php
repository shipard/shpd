<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\TestCase;
use Shipard\Core\StructuredFields\StructuredFieldValues;
use Shipard\Core\StructuredFields\StructuredSchema;

class StructuredFieldValuesTest extends TestCase
{
    private function schema(): StructuredSchema
    {
        return StructuredSchema::fromArray('test.profile', [
            'version' => '2026',
            'fields'  => [
                ['id' => 'typ_ds', 'type' => 'enumString', 'length' => 1, 'cfgItem' => 'test.types', 'name' => 'Typ'],
                ['id' => 'email', 'type' => 'varchar', 'length' => 255, 'name' => 'E-mail'],
                ['id' => 'psc', 'type' => 'varchar', 'length' => 10, 'name' => 'PSČ'],
            ],
        ]);
    }

    // ── decode ──────────────────────────────────────────────────────────────

    public function testDecodeAcceptsStringArrayAndNull(): void
    {
        $this->assertSame(['a' => 1], StructuredFieldValues::decode('{"a":1}'));
        $this->assertSame(['a' => 1], StructuredFieldValues::decode(['a' => 1]));
        $this->assertNull(StructuredFieldValues::decode(null));
        $this->assertNull(StructuredFieldValues::decode(''));
        $this->assertNull(StructuredFieldValues::decode('null'));
    }

    public function testDecodeIsLenientOnGarbage(): void
    {
        $this->assertNull(StructuredFieldValues::decode('{oops'));
        $this->assertNull(StructuredFieldValues::decode(42));
    }

    public function testDecodeStrictThrowsOnGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredFieldValues::decodeStrict('{oops');
    }

    public function testDecodeStrictThrowsOnScalar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredFieldValues::decodeStrict(42);
    }

    public function testDecodeStrictThrowsOnJsonScalar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredFieldValues::decodeStrict('"text"');
    }

    // ── flatten / unflatten ─────────────────────────────────────────────────

    public function testFlattenEmitsExactlySchemaFields(): void
    {
        $flat = StructuredFieldValues::flatten('filing_profile', [
            '_schema' => 'test.profile/2026',
            'email'   => 'a@b.cz',
            'dropped' => 'stará hodnota',
        ], $this->schema());

        $this->assertSame([
            'filing_profile.typ_ds' => null,
            'filing_profile.email'  => 'a@b.cz',
            'filing_profile.psc'    => null,
        ], $flat);
    }

    public function testFlattenOfNullGivesNullFields(): void
    {
        $flat = StructuredFieldValues::flatten('filing_profile', null, $this->schema());
        $this->assertSame(
            ['filing_profile.typ_ds' => null, 'filing_profile.email' => null, 'filing_profile.psc' => null],
            $flat,
        );
    }

    public function testUnflattenMergesOverBaseAndKeepsUnknownKeys(): void
    {
        $value = StructuredFieldValues::unflatten(
            'filing_profile',
            ['filing_profile.email' => 'new@b.cz', 'name' => 'jiný sloupec'],
            ['_schema' => 'test.profile/2025', 'email' => 'old@b.cz', 'psc' => '76001', 'dropped' => 'x'],
            $this->schema(),
        );

        // email přepsán, psc z uložené hodnoty, neznámý klíč zachován,
        // `_schema` z dat vyhozeno (stampuje ho gateway).
        $this->assertSame(['email' => 'new@b.cz', 'psc' => '76001', 'dropped' => 'x'], $value);
    }

    public function testUnflattenClearsFieldSentAsNull(): void
    {
        $value = StructuredFieldValues::unflatten(
            'filing_profile',
            ['filing_profile.email' => null],
            ['email' => 'old@b.cz', 'psc' => '76001'],
            $this->schema(),
        );

        $this->assertSame(['email' => null, 'psc' => '76001'], $value);
    }

    public function testFlattenUnflattenRoundTrip(): void
    {
        $schema = $this->schema();
        $stored = ['_schema' => $schema->key(), 'typ_ds' => 'P', 'email' => 'a@b.cz', 'psc' => '76001'];

        $flat = StructuredFieldValues::flatten('p', $stored, $schema);
        $back = StructuredFieldValues::unflatten('p', $flat, null, $schema);

        $this->assertSame(['typ_ds' => 'P', 'email' => 'a@b.cz', 'psc' => '76001'], $back);
    }

    public function testVirtualKeyHelpers(): void
    {
        $schema = $this->schema();
        $data = ['name' => 'x', 'filing_profile.email' => 'a@b.cz'];

        $this->assertTrue(StructuredFieldValues::hasVirtualKeys('filing_profile', $data, $schema));
        $this->assertFalse(StructuredFieldValues::hasVirtualKeys('other', $data, $schema));
        $this->assertSame(['name' => 'x'], StructuredFieldValues::stripVirtualKeys('filing_profile', $data, $schema));
    }

    // ── isEmpty ─────────────────────────────────────────────────────────────

    public function testIsEmptyIgnoresSchemaKeyNullsAndBlanks(): void
    {
        $this->assertTrue(StructuredFieldValues::isEmpty([]));
        $this->assertTrue(StructuredFieldValues::isEmpty(['_schema' => 'test.profile/2026']));
        $this->assertTrue(StructuredFieldValues::isEmpty(['email' => null, 'psc' => '   ']));
    }

    public function testIsEmptyCountsFalseAndZeroAsValues(): void
    {
        $this->assertFalse(StructuredFieldValues::isEmpty(['signed' => false]));
        $this->assertFalse(StructuredFieldValues::isEmpty(['count' => 0]));
        $this->assertFalse(StructuredFieldValues::isEmpty(['email' => 'a@b.cz']));
    }

    // ── encode ──────────────────────────────────────────────────────────────

    public function testEncodeStampsSchemaAndKeepsSchemaFieldOrder(): void
    {
        $json = StructuredFieldValues::encode(
            ['psc' => '76001', 'email' => 'a@b.cz', 'typ_ds' => 'P'],
            $this->schema(),
        );

        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"P","email":"a@b.cz","psc":"76001"}',
            $json,
        );
    }

    public function testEncodeKeepsUnknownKeysLast(): void
    {
        $json = StructuredFieldValues::encode(
            ['email' => 'a@b.cz', 'dropped' => 'stará hodnota'],
            $this->schema(),
        );

        $this->assertSame(
            '{"_schema":"test.profile/2026","email":"a@b.cz","dropped":"stará hodnota"}',
            $json,
        );
    }

    public function testEncodeSkipsNullsAndDoesNotEscapeDiacritics(): void
    {
        $json = StructuredFieldValues::encode(['email' => null, 'psc' => 'Žďár'], $this->schema());

        $this->assertSame('{"_schema":"test.profile/2026","psc":"Žďár"}', $json);
    }

    public function testEncodeOfEmptyValueGivesNull(): void
    {
        $this->assertNull(StructuredFieldValues::encode([], $this->schema()));
        $this->assertNull(StructuredFieldValues::encode(['email' => null], $this->schema()));
        $this->assertNull(StructuredFieldValues::encode(['_schema' => 'x/1'], $this->schema()));
    }
}
