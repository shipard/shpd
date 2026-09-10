<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\FormController;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/** Form, který schéma připíná hookem (paralela k Document hooku I2). */
class PinnedSchemaForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        return new FormDefinition(table: $this->table, title: 'T', titleNew: 'N', tabs: []);
    }

    public function structuredSchemaFor(string $column, array $data): ?string
    {
        return 'test.profileAlt';
    }
}

/**
 * Plochý tvar strukturovaných sloupců v odpovědích formuláře (#74, S4) —
 * `meta`/`save`/`recalculate` posílají `<sloupec>.<pole>`, `save` je zpátky
 * propouští do gateway.
 */
class FormControllerStructuredFieldsTest extends TestCase
{
    private const SCHEMA = [
        'version' => '2026',
        'fields'  => [
            ['id' => 'typ_ds', 'type' => 'enumString', 'length' => 1, 'cfgItem' => 't.types',
                'name' => 'Typ', 'default' => 'P'],
            ['id' => 'email', 'type' => 'varchar', 'length' => 255, 'name' => 'E-mail'],
        ],
    ];

    private const SCHEMA_ALT = [
        'version' => '2027',
        'fields'  => [['id' => 'jen_alt', 'type' => 'text', 'name' => 'Alt']],
    ];

    private FormController $ctrl;
    private \ReflectionMethod $flatten;
    private \ReflectionMethod $filter;

    protected function setUp(): void
    {
        $this->ctrl = new FormController();
        $ref = new \ReflectionClass(FormController::class);
        $this->flatten = $ref->getMethod('flattenStructuredColumns');
        $this->filter  = $ref->getMethod('filterWritableFields');
    }

    private function tableDef(bool $sensitive = false): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 9102,
            'name'    => 'registrations',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                ['id' => 'name', 'name' => 'Název', 'type' => 'varchar', 'length' => 50],
                ['id' => 'created', 'name' => 'Created', 'type' => 'datetime'],
                ['id' => 'docState', 'name' => 'Stav', 'type' => 'tinyint', 'system' => true],
                [
                    'id' => 'filing_profile', 'name' => 'Profil', 'type' => 'json', 'nullable' => true,
                    'schema' => 'test.profile', 'sensitive' => $sensitive,
                ],
            ],
        ]);
    }

    private function config(): ConfigRuntime
    {
        $items = ['test.profile' => self::SCHEMA, 'test.profileAlt' => self::SCHEMA_ALT];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return $config;
    }

    /** @param array<string, mixed> $data */
    private function flatten(array $data, bool $isNew, ?TableForm $form = null, bool $sensitive = false): array
    {
        return $this->flatten->invoke(
            $this->ctrl,
            $data,
            $this->tableDef($sensitive),
            $this->config(),
            $form,
            $isNew,
        );
    }

    public function testStoredValueBecomesVirtualColumns(): void
    {
        $out = $this->flatten([
            'id'             => 3,
            'name'           => 'CZ',
            'filing_profile' => '{"_schema":"test.profile/2026","typ_ds":"F","email":"a@b.cz"}',
        ], false);

        $this->assertArrayNotHasKey('filing_profile', $out);
        $this->assertSame('F', $out['filing_profile.typ_ds']);
        $this->assertSame('a@b.cz', $out['filing_profile.email']);
        $this->assertSame('CZ', $out['name']);
    }

    public function testNullValueGivesNullFields(): void
    {
        $out = $this->flatten(['id' => 3, 'filing_profile' => null], false);

        $this->assertNull($out['filing_profile.typ_ds']);
        $this->assertNull($out['filing_profile.email']);
    }

    public function testNewRecordGetsSchemaDefaults(): void
    {
        $out = $this->flatten(['name' => 'CZ'], true);

        $this->assertSame('P', $out['filing_profile.typ_ds']);
        $this->assertNull($out['filing_profile.email']);
    }

    public function testRecalculateKeepsClientValues(): void
    {
        // Bez surového sloupce (recalculate posílá jen ploché klíče) se
        // hodnoty od klienta nesmí přepsat nully.
        $out = $this->flatten([
            'id'                    => 3,
            'filing_profile.typ_ds' => 'F',
            'filing_profile.email'  => 'rozepsany@b.cz',
        ], false);

        $this->assertSame('F', $out['filing_profile.typ_ds']);
        $this->assertSame('rozepsany@b.cz', $out['filing_profile.email']);
    }

    public function testRawColumnWinsOverClientKeys(): void
    {
        $out = $this->flatten([
            'filing_profile'        => ['typ_ds' => 'P'],
            'filing_profile.typ_ds' => 'F',
        ], false);

        $this->assertSame('P', $out['filing_profile.typ_ds']);
    }

    public function testSensitiveStructuredColumnIsSkipped(): void
    {
        $out = $this->flatten(['id' => 3, 'name' => 'CZ'], false, null, sensitive: true);

        $this->assertArrayNotHasKey('filing_profile.typ_ds', $out);
    }

    public function testFormHookSelectsSchema(): void
    {
        $out = $this->flatten(
            ['filing_profile' => ['jen_alt' => 'x']],
            false,
            new PinnedSchemaForm('registrations'),
        );

        $this->assertSame('x', $out['filing_profile.jen_alt']);
        $this->assertArrayNotHasKey('filing_profile.typ_ds', $out);
    }

    public function testMissingConfigLeavesColumnAlone(): void
    {
        $out = $this->flatten->invoke(
            $this->ctrl,
            ['filing_profile' => '{"typ_ds":"P"}'],
            $this->tableDef(),
            null,
            null,
            false,
        );

        $this->assertSame('{"typ_ds":"P"}', $out['filing_profile']);
    }

    // ── filterWritableFields ────────────────────────────────────────────────

    public function testVirtualColumnsPassAsWritable(): void
    {
        $out = $this->filter->invoke($this->ctrl, [
            'name'                  => 'CZ',
            'filing_profile.typ_ds' => 'P',
            'filing_profile'        => ['typ_ds' => 'P'],
            'id'                    => 9,
            'created'               => 'x',
            'docState'              => 20,
            'nesmysl'               => 1,
        ], $this->tableDef());

        $this->assertSame(
            ['name' => 'CZ', 'filing_profile.typ_ds' => 'P', 'filing_profile' => ['typ_ds' => 'P']],
            $out,
        );
    }
}
