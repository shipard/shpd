<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryClient;
use Shipard\Module\Base\Persons\Registry\RegistryImportException;
use Shipard\Module\Base\Persons\Registry\RegistryInvalidResponseException;
use Shipard\Module\Base\Persons\Registry\RegistryNotFoundException;
use Shipard\Module\Base\Persons\Registry\RegistryPersonImporter;
use Shipard\Module\Base\Persons\Registry\RegistryUnavailableException;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Person\PersonApplier;

/**
 * Unit coverage for {@see RegistryPersonImporter}.
 *
 * Strategy: in-memory subclass of {@see PersonsRegistryClient} scripts
 * the `fetchPerson` outcome (return value or throw); the
 * {@see PersonApplier} is a PHPUnit mock with a scripted ApplyResult.
 * No HTTP, no DB.
 */
class RegistryPersonImporterTest extends TestCase
{
    // ── Happy path: new person created ──────────────────────────────────────

    public function testEnsureImportedCreatesNewPersonOnFirstCall(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($this->minimalCanonical());

        $applier = $this->createMock(PersonApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->with($this->callback(function (array $canonical): bool {
                // Importer must overwrite applyOptions regardless of
                // what registry baked in.
                $this->assertSame('createOnly', $canonical['applyOptions']['mergeStrategy']);
                $this->assertSame(40, $canonical['applyOptions']['targetDocState']);
                return true;
            }))
            ->willReturn(ApplyResult::ok($this->minimalCanonical(), savedId: 1234));

        $importer = new RegistryPersonImporter($registry, $applier);
        $result = $importer->ensureImported('cz', '12345678');

        $this->assertSame(1234, $result->personId);
        $this->assertTrue($result->created, 'fresh insert must report created=true');
    }

    // ── Idempotence: matched header → existing id returned ──────────────────

    public function testEnsureImportedReturnsExistingIdForPersonExists(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($this->minimalCanonical());

        $enriched = $this->minimalCanonical();
        $enriched['_resolve'] = [
            'header' => [
                'status'    => 'matched',
                'matchedId' => 9876,
                'matchedBy' => 'companyId',
            ],
        ];
        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('person_exists', 'already in DB', $enriched, statusCode: 409),
        );

        $importer = new RegistryPersonImporter($registry, $applier);
        $result = $importer->ensureImported('cz', '12345678');

        $this->assertSame(9876, $result->personId);
        $this->assertFalse($result->created, 'matched existing must report created=false');
    }

    // ── Defensive: person_exists without matchedId is an importer error ────

    public function testEnsureImportedThrowsWhenPersonExistsLacksMatchedId(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($this->minimalCanonical());

        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('person_exists', 'malformed', [], statusCode: 409),
        );

        $importer = new RegistryPersonImporter($registry, $applier);

        try {
            $importer->ensureImported('cz', '12345678');
            $this->fail('Expected RegistryImportException');
        } catch (RegistryImportException $e) {
            $this->assertSame('person_exists', $e->applierErrorCode);
            $this->assertStringContainsString('no matchedId', $e->getMessage());
        }
    }

    // ── Registry errors propagate untouched ────────────────────────────────

    public function testRegistryUnavailablePropagates(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetchError(new RegistryUnavailableException('DNS failed'));

        $applier = $this->createMock(PersonApplier::class);
        $applier->expects($this->never())->method('apply');

        $importer = new RegistryPersonImporter($registry, $applier);

        $this->expectException(RegistryUnavailableException::class);
        $importer->ensureImported('cz', '12345678');
    }

    public function testRegistryNotFoundPropagates(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetchError(new RegistryNotFoundException('unknown IČO'));

        $applier = $this->createMock(PersonApplier::class);
        $applier->expects($this->never())->method('apply');

        $importer = new RegistryPersonImporter($registry, $applier);

        $this->expectException(RegistryNotFoundException::class);
        $importer->ensureImported('cz', '99999999');
    }

    public function testRegistryInvalidResponsePropagates(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetchError(new RegistryInvalidResponseException('bad json'));

        $applier = $this->createMock(PersonApplier::class);
        $applier->expects($this->never())->method('apply');

        $importer = new RegistryPersonImporter($registry, $applier);

        $this->expectException(RegistryInvalidResponseException::class);
        $importer->ensureImported('cz', '12345678');
    }

    // ── Apply failures wrap into RegistryImportException ───────────────────

    public function testApplyValidationFailureBecomesImportException(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($this->minimalCanonical());

        $enriched = $this->minimalCanonical();
        $enriched['_resolve']['issues'] = [
            ['severity' => 'error', 'path' => 'name.fullName', 'code' => 'required', 'message' => 'missing'],
        ];
        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('validation_failed', 'name missing', $enriched),
        );

        $importer = new RegistryPersonImporter($registry, $applier);

        try {
            $importer->ensureImported('cz', '12345678');
            $this->fail('Expected RegistryImportException');
        } catch (RegistryImportException $e) {
            $this->assertSame('validation_failed', $e->applierErrorCode);
            $this->assertStringContainsString('cz/12345678', $e->getMessage());
            $this->assertStringContainsString('validation_failed', $e->getMessage());
            // Enriched canonical with issues is preserved for caller inspection.
            $this->assertSame($enriched, $e->canonical);
        }
    }

    public function testPersonIdConflictBecomesImportException(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($this->minimalCanonical());

        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('person_id_conflict', 'collision', [], statusCode: 409),
        );

        $importer = new RegistryPersonImporter($registry, $applier);

        $this->expectException(RegistryImportException::class);
        $this->expectExceptionMessageMatches('/person_id_conflict/');
        $importer->ensureImported('cz', '12345678');
    }

    // ── Defensive: success result without savedId is an importer error ─────

    public function testSuccessWithoutSavedIdThrows(): void
    {
        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($this->minimalCanonical());

        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::ok($this->minimalCanonical(), savedId: null),
        );

        $importer = new RegistryPersonImporter($registry, $applier);

        $this->expectException(RegistryImportException::class);
        $this->expectExceptionMessageMatches('/savedId is missing/');
        $importer->ensureImported('cz', '12345678');
    }

    // ── ApplyOptions overwrite: importer is authoritative ──────────────────

    public function testImporterOverwritesApplyOptionsFromRegistryPayload(): void
    {
        // Registry hands us a canonical with fullSync baked in — importer
        // must reject that and use its own createOnly policy.
        $canonical = $this->minimalCanonical();
        $canonical['applyOptions'] = [
            'mergeStrategy'  => 'fullSync',
            'targetDocState' => 10,
        ];

        $registry = new FakeRegistry('https://example.org/persons');
        $registry->scriptFetch($canonical);

        $captured = null;
        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturnCallback(
            function (array $c) use (&$captured): ApplyResult {
                $captured = $c;
                return ApplyResult::ok($c, savedId: 42);
            },
        );

        $importer = new RegistryPersonImporter($registry, $applier);
        $importer->ensureImported('cz', '12345678');

        $this->assertSame('createOnly', $captured['applyOptions']['mergeStrategy']);
        $this->assertSame(40, $captured['applyOptions']['targetDocState']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function minimalCanonical(): array
    {
        return [
            'format'        => 'shpd.persons.person',
            'formatVersion' => '1.0',
            'personType'    => 'company',
            'country'       => 'cz',
            'companyId'     => '12345678',
            'name'          => ['fullName' => 'Zkušební firma s.r.o.'],
        ];
    }
}

/**
 * Test double for {@see PersonsRegistryClient}. Bypasses HTTP entirely
 * — the importer's contract with the client is just `fetchPerson()`,
 * so we override that method directly. (HTTP-level branching is
 * covered by {@see PersonsRegistryClientTest}; duplicating it here
 * would only retest the client.)
 */
final class FakeRegistry extends PersonsRegistryClient
{
    private array|\Throwable|null $scripted = null;

    /** @param array<string, mixed> $canonical */
    public function scriptFetch(array $canonical): void
    {
        $this->scripted = $canonical;
    }

    public function scriptFetchError(\Throwable $error): void
    {
        $this->scripted = $error;
    }

    public function fetchPerson(string $country, string $companyId): array
    {
        if ($this->scripted instanceof \Throwable) {
            throw $this->scripted;
        }
        if (is_array($this->scripted)) {
            return $this->scripted;
        }
        throw new \LogicException('FakeRegistry::fetchPerson called without a scripted response.');
    }
}
