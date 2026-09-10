<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DefaultDocument;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;

/**
 * Krok „structured fields" v `TableGateway::saveDocument` (#74, I3) — jedna
 * autorita zápisu pro formulář, API i applier. DB je odstíněná
 * (`RecordingGateway`), takže se testuje reálná cesta `saveDocument` včetně
 * pořadí kroků vůči `Document` hookům.
 */
class RecordingGateway extends TableGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $storedRows = [];
    /** @var list<array{table: string, data: array<string, mixed>}> */
    public array $insertCalls = [];
    /** @var list<array{table: string, id: int, data: array<string, mixed>}> */
    public array $updateCalls = [];

    protected function fetchRow(int $id): ?array
    {
        return $this->storedRows[$id] ?? null;
    }

    protected function fetchChildren(string $table, string $foreignKey, int $parentId): array
    {
        return [];
    }

    protected function insertRow(string $table, array $data): int
    {
        $this->insertCalls[] = ['table' => $table, 'data' => $data];
        return 7;
    }

    protected function updateRow(string $table, int $id, array $data): void
    {
        $this->updateCalls[] = ['table' => $table, 'id' => $id, 'data' => $data];
    }

    protected function deleteRow(string $table, int $id): void {}
    protected function deleteChildren(string $table, string $foreignKey, int $parentId): void {}
    protected function beginTransaction(): void {}
    protected function commitTransaction(): void {}
    protected function rollbackTransaction(): void {}
}

/** Dokument, který si zapamatuje, co viděl v beforeSave. */
class ProfileWatchingDocument extends Document
{
    public mixed $seenInBeforeSave = 'not-called';

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->seenInBeforeSave = $data['filing_profile'] ?? null;
    }
}

/** Dokument dopisující hodnotu v beforeSave (gateway ji musí serializovat). */
class ProfileWritingDocument extends Document
{
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $data['filing_profile']['email'] = 'z-hooku@b.cz';
    }
}

/** Dokument s hookem I2 — schéma podle typu záznamu. */
class PinnedSchemaDocument extends Document
{
    public function structuredSchemaFor(string $column, array $data): ?string
    {
        return $column === 'filing_profile' ? 'test.profileAlt' : null;
    }
}

class FixedRegistry extends DocumentRegistry
{
    public function __construct(private Document $doc) {}

    public function getDocument(string $tableId, array $data = []): Document
    {
        return $this->doc;
    }
}

class TableGatewayStructuredFieldsTest extends TestCase
{
    private const SCHEMA = [
        'version' => '2026',
        'fields'  => [
            [
                'id' => 'typ_ds', 'type' => 'enumString', 'length' => 1,
                'cfgItem' => 'test.subjectTypes', 'name' => 'Typ subjektu', 'required' => true,
            ],
            ['id' => 'email', 'type' => 'varchar', 'length' => 255, 'name' => 'E-mail'],
            ['id' => 'psc', 'type' => 'varchar', 'length' => 10, 'name' => 'PSČ'],
        ],
    ];

    private const SCHEMA_ALT = [
        'version' => '2027',
        'fields'  => [['id' => 'email', 'type' => 'varchar', 'length' => 255, 'name' => 'E-mail']],
    ];

    private function tableDef(bool $withSchema = true): TableDefinition
    {
        $profile = [
            'id' => 'filing_profile', 'name' => 'Profil podatele', 'type' => 'json', 'nullable' => true,
        ];
        if ($withSchema) {
            $profile['schema'] = 'test.profile';
        }

        return TableDefinition::fromArray([
            'tableId' => 9101,
            'name'    => 'registrations',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                ['id' => 'name', 'name' => 'Název', 'type' => 'varchar', 'length' => 50],
                $profile,
            ],
        ]);
    }

    private function config(): ConfigRuntime
    {
        $items = [
            'test.profile'       => self::SCHEMA,
            'test.profileAlt'    => self::SCHEMA_ALT,
            'test.subjectTypes'  => ['P' => ['name' => 'Právnická'], 'F' => ['name' => 'Fyzická']],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return $config;
    }

    private function gateway(Document $doc, bool $withSchema = true, bool $withConfig = true): RecordingGateway
    {
        return new RecordingGateway(
            'registrations',
            $this->createMock(\Dibi\Connection::class),
            new FixedRegistry($doc),
            null,
            $withConfig ? $this->config() : null,
            null,
            null,
            null,
            $this->tableDef($withSchema),
        );
    }

    /** @return array<string, mixed> */
    private function lastInsert(RecordingGateway $gw): array
    {
        $this->assertNotEmpty($gw->insertCalls, 'expected an INSERT');
        return $gw->insertCalls[array_key_last($gw->insertCalls)]['data'];
    }

    // ── zápis z formuláře (virtuální sloupce) ───────────────────────────────

    public function testFormWriteSerializesWithSchemaStamp(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument([
            'name'                  => 'CZ',
            'filing_profile.typ_ds' => 'P',
            'filing_profile.email'  => ' a@b.cz ',
            'filing_profile.psc'    => '',
        ]);

        $this->assertTrue($result->isSuccess());
        $data = $this->lastInsert($gw);
        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"P","email":"a@b.cz"}',
            $data['filing_profile'],
        );
        // Virtuální sloupce se do SQL nedostanou.
        foreach (array_keys($data) as $key) {
            $this->assertStringNotContainsString('.', (string) $key);
        }
    }

    public function testApiWriteWithWholeColumnValidatesTheSameWay(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument(['name' => 'CZ', 'filing_profile' => ['email' => 'a@b.cz']]);

        // Chybí povinné typ_ds — stejná chyba jako z formuláře.
        $this->assertFalse($result->isSuccess());
        $errors = $result->getValidation()?->getErrors() ?? [];
        $this->assertCount(1, $errors);
        $this->assertSame('filing_profile.typ_ds', $errors[0]->column);
        $this->assertSame('required', $errors[0]->code);
        $this->assertSame([], $gw->insertCalls);
    }

    public function testApiWriteWithJsonStringIsAccepted(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument([
            'name'           => 'CZ',
            'filing_profile' => '{"typ_ds":"P","email":"a@b.cz"}',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"P","email":"a@b.cz"}',
            $this->lastInsert($gw)['filing_profile'],
        );
    }

    public function testInvalidJsonIsRejectedInsteadOfWipingTheValue(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument(['name' => 'CZ', 'filing_profile' => '{oops']);

        $this->assertFalse($result->isSuccess());
        $errors = $result->getValidation()?->getErrors() ?? [];
        $this->assertSame('filing_profile', $errors[0]->column);
        $this->assertSame('invalid_value', $errors[0]->code);
    }

    public function testValidationErrorsFromFieldsBlockTheWrite(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument([
            'name'                  => 'CZ',
            'filing_profile.typ_ds' => 'X',
            'filing_profile.psc'    => '1234567890123',
        ]);

        $this->assertFalse($result->isSuccess());
        $columns = array_map(
            fn($e) => $e->column,
            $result->getValidation()?->getErrors() ?? [],
        );
        $this->assertSame(['filing_profile.typ_ds', 'filing_profile.psc'], $columns);
    }

    // ── prázdno, nedotčený sloupec ──────────────────────────────────────────

    public function testEmptyValueBecomesNullNotEmptyObject(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument([
            'name'                  => 'CZ',
            'filing_profile.typ_ds' => '',
            'filing_profile.email'  => null,
            'filing_profile.psc'    => '   ',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($this->lastInsert($gw)['filing_profile']);
    }

    public function testUntouchedColumnStaysOutOfThePayload(): void
    {
        $gw = $this->gateway(new DefaultDocument());

        $result = $gw->saveDocument(['name' => 'CZ']);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayNotHasKey('filing_profile', $this->lastInsert($gw));
    }

    // ── update: slití nad uloženou hodnotou ─────────────────────────────────

    public function testPartialUpdateMergesOverStoredValue(): void
    {
        $gw = $this->gateway(new DefaultDocument());
        $gw->storedRows[7] = [
            'id'             => 7,
            'name'           => 'CZ',
            'filing_profile' => '{"_schema":"test.profile/2026","typ_ds":"P","email":"old@b.cz","psc":"76001"}',
        ];

        $result = $gw->saveDocument(['id' => 7, 'filing_profile.email' => 'new@b.cz']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"P","email":"new@b.cz","psc":"76001"}',
            $gw->updateCalls[0]['data']['filing_profile'],
        );
    }

    public function testOlderSchemaVersionIsReadAndFieldsDroppedBySchemaSurvive(): void
    {
        $gw = $this->gateway(new DefaultDocument());
        $gw->storedRows[7] = [
            'id'             => 7,
            'name'           => 'CZ',
            'filing_profile' => '{"_schema":"test.profile/2019","typ_ds":"P","zruseno":"stará hodnota"}',
        ];

        $result = $gw->saveDocument(['id' => 7, 'filing_profile.email' => 'a@b.cz']);

        $this->assertTrue($result->isSuccess());
        // Hodnota se přeznačí na aktuální verzi, ale klíč, který dnešní
        // schéma nezná, se nezahodí (I6).
        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"P","email":"a@b.cz","zruseno":"stará hodnota"}',
            $gw->updateCalls[0]['data']['filing_profile'],
        );
    }

    public function testWholeColumnValueReplacesStoredValue(): void
    {
        $gw = $this->gateway(new DefaultDocument());
        $gw->storedRows[7] = [
            'id'             => 7,
            'name'           => 'CZ',
            'filing_profile' => '{"_schema":"test.profile/2026","typ_ds":"P","psc":"76001"}',
        ];

        $result = $gw->saveDocument(['id' => 7, 'filing_profile' => ['typ_ds' => 'F']]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"F"}',
            $gw->updateCalls[0]['data']['filing_profile'],
        );
    }

    // ── Document hooky ──────────────────────────────────────────────────────

    public function testBeforeSaveSeesDecodedArray(): void
    {
        $doc = new ProfileWatchingDocument();
        $gw = $this->gateway($doc);

        $gw->saveDocument(['name' => 'CZ', 'filing_profile.typ_ds' => 'P']);

        $this->assertIsArray($doc->seenInBeforeSave);
        $this->assertSame('P', $doc->seenInBeforeSave['typ_ds']);
    }

    public function testValueWrittenByBeforeSaveIsSerialized(): void
    {
        $gw = $this->gateway(new ProfileWritingDocument());

        $result = $gw->saveDocument(['name' => 'CZ', 'filing_profile.typ_ds' => 'P']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            '{"_schema":"test.profile/2026","typ_ds":"P","email":"z-hooku@b.cz"}',
            $this->lastInsert($gw)['filing_profile'],
        );
    }

    public function testDocumentHookPicksSchema(): void
    {
        $gw = $this->gateway(new PinnedSchemaDocument());

        // Alternativní schéma zná jen `email`; `typ_ds` v něm není povinné.
        $result = $gw->saveDocument(['name' => 'CZ', 'filing_profile.email' => 'a@b.cz']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            '{"_schema":"test.profileAlt/2027","email":"a@b.cz"}',
            $this->lastInsert($gw)['filing_profile'],
        );
    }

    // ── degradace ───────────────────────────────────────────────────────────

    public function testMissingSchemaFailsLoudlyInsteadOfWritingUnvalidated(): void
    {
        $gw = $this->gateway(new DefaultDocument(), withSchema: true, withConfig: false);

        $result = $gw->saveDocument(['name' => 'CZ', 'filing_profile.typ_ds' => 'P']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('structured schema', (string) $result->getErrorMessage());
        $this->assertSame([], $gw->insertCalls);
    }

    public function testTableWithoutStructuredColumnsIsUntouched(): void
    {
        $gw = $this->gateway(new DefaultDocument(), withSchema: false);

        $result = $gw->saveDocument(['name' => 'CZ', 'filing_profile' => '{"a":1}']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('{"a":1}', $this->lastInsert($gw)['filing_profile']);
    }
}
