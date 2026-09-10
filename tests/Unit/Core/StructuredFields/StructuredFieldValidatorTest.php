<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\StructuredFields\StructuredFieldValidator;
use Shipard\Core\StructuredFields\StructuredSchema;

class StructuredFieldValidatorTest extends TestCase
{
    private function schema(): StructuredSchema
    {
        return StructuredSchema::fromArray('test.profile', [
            'version' => '2026',
            'fields'  => [
                [
                    'id' => 'typ_ds', 'type' => 'enumString', 'length' => 1,
                    'cfgItem' => 'test.subjectTypes', 'name' => 'Typ subjektu', 'required' => true,
                ],
                ['id' => 'email', 'type' => 'varchar', 'length' => 10, 'name' => 'E-mail'],
                ['id' => 'note', 'type' => 'text', 'name' => 'Poznámka'],
                ['id' => 'count', 'type' => 'int', 'name' => 'Počet'],
                ['id' => 'ratio', 'type' => 'numeric', 'precision' => 5, 'scale' => 2, 'name' => 'Podíl'],
                ['id' => 'born', 'type' => 'date', 'name' => 'Datum narození'],
                ['id' => 'signed', 'type' => 'boolean', 'name' => 'Podepsáno'],
                ['id' => 'kind', 'type' => 'enumInt', 'cfgItem' => 'test.kinds', 'name' => 'Druh'],
            ],
        ]);
    }

    private function config(): ConfigRuntime
    {
        $items = [
            'test.subjectTypes' => ['P' => ['name' => 'Právnická'], 'F' => ['name' => 'Fyzická']],
            'test.kinds'        => ['1' => ['name' => 'Jeden'], '2' => ['name' => 'Dva']],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return $config;
    }

    /** @return array{value: array<string, mixed>, errors: list<ValidationError>} */
    private function validate(array $value): array
    {
        return StructuredFieldValidator::validate('filing_profile', $value, $this->schema(), $this->config());
    }

    // ── prázdná hodnota ─────────────────────────────────────────────────────

    public function testEmptyValueSkipsValidationIncludingRequired(): void
    {
        $result = $this->validate(['email' => '', 'note' => null]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['value']);
    }

    public function testRequiredIsEnforcedOnceValueIsNotEmpty(): void
    {
        $result = $this->validate(['email' => 'a@b.cz']);

        $this->assertCount(1, $result['errors']);
        $this->assertSame('filing_profile.typ_ds', $result['errors'][0]->column);
        $this->assertSame('required', $result['errors'][0]->code);
    }

    // ── normalizace ─────────────────────────────────────────────────────────

    public function testValidValueNormalizes(): void
    {
        $result = $this->validate([
            'typ_ds' => ' P ',
            'email'  => '  a@b.cz  ',
            'count'  => '42',
            'ratio'  => ' 12.50 ',
            'born'   => '1980-02-29',
            'signed' => 1,
            'kind'   => '2',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('P', $result['value']['typ_ds']);
        $this->assertSame('a@b.cz', $result['value']['email']);
        $this->assertSame(42, $result['value']['count']);
        $this->assertSame('12.50', $result['value']['ratio']);
        $this->assertSame('1980-02-29', $result['value']['born']);
        $this->assertTrue($result['value']['signed']);
        $this->assertSame(2, $result['value']['kind']);
    }

    public function testBlankStringsBecomeNull(): void
    {
        $result = $this->validate(['typ_ds' => 'P', 'email' => '   ', 'count' => '', 'born' => '']);

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['value']['email']);
        $this->assertNull($result['value']['count']);
        $this->assertNull($result['value']['born']);
    }

    public function testUnknownKeysArePreserved(): void
    {
        $result = $this->validate(['typ_ds' => 'P', 'dropped' => 'stará hodnota']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('stará hodnota', $result['value']['dropped']);
    }

    // ── typové chyby ────────────────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidValueProvider(): array
    {
        return [
            'varchar too long'   => [['email' => 'a@velmi-dlouhy.cz'], 'filing_profile.email', 'too_long'],
            'int not a number'   => [['count' => '12abc'], 'filing_profile.count', 'invalid_int'],
            'int as float text'  => [['count' => '12.5'], 'filing_profile.count', 'invalid_int'],
            'numeric with comma' => [['ratio' => '12,50'], 'filing_profile.ratio', 'invalid_number'],
            'numeric over scale' => [['ratio' => '1.234'], 'filing_profile.ratio', 'invalid_number'],
            'numeric over precision' => [['ratio' => '12345.67'], 'filing_profile.ratio', 'invalid_number'],
            'date wrong format'  => [['born' => '29.2.1980'], 'filing_profile.born', 'invalid_date'],
            'date not a date'    => [['born' => '1981-02-29'], 'filing_profile.born', 'invalid_date'],
            'bool garbage'       => [['signed' => 'maybe'], 'filing_profile.signed', 'invalid_bool'],
            'enumString unknown' => [['typ_ds' => 'X'], 'filing_profile.typ_ds', 'invalid_enum'],
            'enumString too long' => [['typ_ds' => 'PF'], 'filing_profile.typ_ds', 'too_long'],
            'enumInt unknown'    => [['kind' => 9], 'filing_profile.kind', 'invalid_enum'],
            'enumInt not int'    => [['kind' => 'two'], 'filing_profile.kind', 'invalid_int'],
            'list instead of scalar' => [['note' => ['a']], 'filing_profile.note', 'invalid_value'],
            'bool where text expected' => [['note' => true], 'filing_profile.note', 'invalid_value'],
        ];
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidValueYieldsFieldError(array $value, string $expectedColumn, string $expectedCode): void
    {
        // typ_ds doplňujeme, aby chybu nehlásil `required` (kromě testů typ_ds).
        $result = $this->validate($value + ['typ_ds' => 'P']);

        $codes = [];
        foreach ($result['errors'] as $error) {
            $codes[$error->column] = $error->code;
        }
        $this->assertArrayHasKey($expectedColumn, $codes);
        $this->assertSame($expectedCode, $codes[$expectedColumn]);
    }

    public function testAllErrorsAreReportedAtOnce(): void
    {
        $result = $this->validate(['typ_ds' => 'X', 'count' => 'x', 'born' => 'y']);

        $this->assertCount(3, $result['errors']);
    }

    public function testDateAcceptsDateTimeInstance(): void
    {
        $result = $this->validate(['typ_ds' => 'P', 'born' => new \DateTimeImmutable('1980-02-29 13:45:00')]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('1980-02-29', $result['value']['born']);
    }

    public function testEnumWithoutCompiledCfgItemSkipsMembershipCheck(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturn(null);

        $result = StructuredFieldValidator::validate(
            'filing_profile',
            ['typ_ds' => 'X'],
            $this->schema(),
            $config,
        );

        // Bez číselníku nelze členství ověřit — typ a délka se ověří dál.
        $this->assertSame([], $result['errors']);
        $this->assertSame('X', $result['value']['typ_ds']);
    }
}
