<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Persons;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end coverage for PersonApplier against a live DB. Run with
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id> vendor/bin/phpunit
 * --testsuite Integration`.
 *
 * Each fixture in tests/Fixtures/Exchange/persons/ exercises one of the
 * spec §11 paths. Test data is namespaced with the `IT-EX` prefix in
 * names / personIds / company_ids so tearDown can purge it cleanly.
 */
class PersonsApplyE2ETest extends IntegrationTestCase
{
    private const TEST_PERSON_ID_PREFIX = 'IT';   // varchar(10) — keep short
    private const TEST_COMPANY_ID_PREFIX = 'IT-EX-';

    private ConfigRuntime $config;
    private PersonApplier $applier;

    /** @var list<int> */
    private array $createdPersonIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        // Sanity check — DS must have docState columns on sub-tables
        // (added by the persons-phase1 migration).
        $row = $this->db->fetchRow(
            "SHOW COLUMNS FROM base_persons_addresses LIKE 'docState'",
        );
        if ($row === null) {
            $this->markTestSkipped('DS missing docState on base_persons_addresses — run ds-upgrade.');
        }

        $this->applier = PersonApplier::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
        );
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM base_persons_addresses WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_contacts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
        // Belt-and-suspenders cleanup of any rows left behind by failed
        // tests (matched by deterministic personId prefix).
        $rows = $dibi->fetchAll(
            'SELECT id FROM base_persons_persons WHERE person_id LIKE %s OR company_id LIKE %s',
            self::TEST_PERSON_ID_PREFIX . '%',
            self::TEST_COMPANY_ID_PREFIX . '%',
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $dibi->query('DELETE FROM base_persons_addresses WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_contacts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
    }

    // ── Fixtures: happy paths ─────────────────────────────────────────────

    public function testCompanyCreateHappyPath(): void
    {
        $payload = $this->loadFixture('company_create_happy.json');
        $payload['companyId'] = $this->uniqueCompanyId();

        $result = $this->applier->apply($payload);

        $this->assertTrue(
            $result->success,
            'Apply failed: ' . ($result->errorCode ?? '') . ': ' . ($result->errorMessage ?? '')
                . ' issues=' . json_encode($result->canonical['_resolve']['issues'] ?? []),
        );
        $personId = $result->savedId;
        $this->assertNotNull($personId);
        $this->createdPersonIds[] = $personId;

        // Header row
        $person = $this->db->fetchRow('SELECT * FROM base_persons_persons WHERE id = %i', $personId);
        $this->assertNotNull($person);
        $this->assertSame(2, (int) $person['person_type']);
        $this->assertSame('IT-EX New Company s.r.o.', $person['full_name']);
        $this->assertSame(40, (int) $person['docState']);
        $this->assertSame('itexnew9', $person['gov_e_box_id'], 'govEBoxId must round-trip via canonical → applier → DB');

        // 2 addresses (Sídlo + Provozovna)
        $addresses = $this->db->fetchAll(
            'SELECT * FROM base_persons_addresses WHERE person = %i ORDER BY address_type',
            $personId,
        );
        $this->assertCount(2, $addresses);
        $this->assertSame(40, (int) $addresses[0]['docState'], 'applier must insert with docState=40');
        $this->assertSame(40, (int) $addresses[1]['docState']);
        $this->assertSame('ICP', $addresses[1]['place_reg_type']);
        $this->assertSame('1234567890', $addresses[1]['place_reg_id']);

        // 1 bank account
        $banks = $this->db->fetchAll(
            'SELECT * FROM base_persons_bank_accounts WHERE person = %i', $personId,
        );
        $this->assertCount(1, $banks);
        $this->assertSame('1234567890/0100', $banks[0]['account_number']);
        $this->assertSame('czk', $banks[0]['currency']);

        // 1 contact
        $contacts = $this->db->fetchAll(
            'SELECT * FROM base_persons_contacts WHERE person = %i', $personId,
        );
        $this->assertCount(1, $contacts);
        $this->assertSame('Jan Novák', $contacts[0]['name']);

        // Lineage
        $this->assertSame('import.ares', $person['source_kind']);
        $this->assertNotNull($person['source_imported_at']);
    }

    public function testCompanyMergeAddWithAuthoritativeRefresh(): void
    {
        // Pre-seed an existing company with a provozovna whose place_reg_id
        // matches the payload — applier should overwrite it.
        $personId = $this->seedExistingCompany(
            companyId: $this->uniqueCompanyId(),
            fullName: 'IT-EX MergeAdd Pre-existing s.r.o.',
        );
        $oldAddrId = $this->seedAddress($personId, [
            'address_type'    => 3,
            'name'            => 'Stará provozovna',
            'place_reg_type'  => 'ICP',
            'place_reg_id'    => '9876543210',
            'street'          => 'Stará 1',
            'city'            => 'Plzeň',
            'is_standardized' => 1,
        ]);

        $payload = $this->loadFixture('company_mergeAdd.json');
        // Re-target the existing company.
        $payload['companyId'] = $this->companyIdOf($personId);

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame($personId, $result->savedId);

        // Same address id, but fields overwritten by the payload (refresh).
        $addr = $this->db->fetchRow(
            'SELECT * FROM base_persons_addresses WHERE id = %i', $oldAddrId,
        );
        $this->assertNotNull($addr);
        $this->assertSame('Nová ulice', $addr['street']);
        $this->assertSame('Plzeň', $addr['city']);
        $this->assertSame('ICP', $addr['place_reg_type']);

        // Bank account inserted (mergeAdd adds missing).
        $banks = $this->db->fetchAll(
            'SELECT * FROM base_persons_bank_accounts WHERE person = %i', $personId,
        );
        $this->assertCount(1, $banks);
        $this->assertSame('1111222233/0300', $banks[0]['account_number']);
    }

    public function testCompanyFullSyncClosesMissingSubRecords(): void
    {
        $personId = $this->seedExistingCompany(
            companyId: $this->uniqueCompanyId(),
            fullName: 'IT-EX FullSync Pre-existing s.r.o.',
        );
        // Old sídlo — not in payload → should be closed via valid_to.
        $oldSidloId = $this->seedAddress($personId, [
            'address_type' => 1,
            'name'         => 'Staré sídlo',
            'street'       => 'Stará 1',
            'city'         => 'Praha',
        ]);
        // Old bank account — not in payload → should be closed.
        $oldBankId = $this->seedBankAccount($personId, [
            'account_number' => '1234567890/0100',
            'iban'           => 'CZ6508000000001234567890',
            'currency'       => 'czk',
        ]);
        // Old contact — not in payload → should be closed.
        $oldContactId = $this->seedContact($personId, [
            'name'  => 'Stará Účetní',
            'email' => 'stara@itex-fs.cz',
        ]);

        $payload = $this->loadFixture('company_fullSync.json');
        $payload['companyId'] = $this->companyIdOf($personId);

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame($personId, $result->savedId);

        $today = date('Y-m-d');

        // Old sídlo address closed.
        $oldSidlo = $this->db->fetchRow('SELECT valid_to, docState FROM base_persons_addresses WHERE id = %i', $oldSidloId);
        $this->assertSame($today, $this->dateString($oldSidlo['valid_to']));
        $this->assertNotSame(90, (int) $oldSidlo['docState'], 'closing must NOT mark as deleted');

        // Old bank account closed.
        $oldBank = $this->db->fetchRow('SELECT valid_to FROM base_persons_bank_accounts WHERE id = %i', $oldBankId);
        $this->assertSame($today, $this->dateString($oldBank['valid_to']));

        // Old contact closed.
        $oldContact = $this->db->fetchRow('SELECT valid_to FROM base_persons_contacts WHERE id = %i', $oldContactId);
        $this->assertSame($today, $this->dateString($oldContact['valid_to']));

        // New sídlo + new EUR account + new contact added.
        $newAddrs = $this->db->fetchAll(
            'SELECT * FROM base_persons_addresses WHERE person = %i AND id != %i',
            $personId, $oldSidloId,
        );
        $this->assertCount(1, $newAddrs);
        $this->assertSame('Národní', $newAddrs[0]['street']);

        $newBanks = $this->db->fetchAll(
            'SELECT * FROM base_persons_bank_accounts WHERE person = %i AND id != %i',
            $personId, $oldBankId,
        );
        $this->assertCount(1, $newBanks);
        $this->assertSame('eur', $newBanks[0]['currency']);
    }

    public function testPersonCreate(): void
    {
        $payload = $this->loadFixture('person_create.json');
        $payload['name']['lastName'] = 'Novák' . substr(uniqid(), -4);

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $this->createdPersonIds[] = $result->savedId;

        $person = $this->db->fetchRow('SELECT * FROM base_persons_persons WHERE id = %i', $result->savedId);
        $this->assertSame(1, (int) $person['person_type']);
        $this->assertSame('Jan', $person['first_name']);
        $this->assertStringStartsWith('Novák', $person['last_name']);
        // PersonDocument::beforeSave for person assembles full_name.
        $this->assertStringStartsWith('Jan ', $person['full_name']);
        $this->assertSame(10, (int) $person['docState']);  // targetDocState=10
        $this->assertSame('manual', $person['source_kind']);
    }

    public function testPersonIdConflict(): void
    {
        // Seed a row with the personId from the fixture.
        $payload = $this->loadFixture('person_id_conflict.json');
        $collidingPersonId = self::TEST_PERSON_ID_PREFIX . substr(uniqid(), -8);
        $payload['personId'] = $collidingPersonId;

        $existing = $this->seedExistingCompany(
            companyId: $this->uniqueCompanyId(),
            fullName: 'IT-EX Pre-existing for collision',
            personId: $collidingPersonId,
        );
        $this->assertGreaterThan(0, $existing);

        // Now apply with a DIFFERENT companyId but the SAME personId → collide.
        $payload['companyId'] = $this->uniqueCompanyId();  // fresh — header is canCreate
        $result = $this->applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('person_id_conflict', $result->errorCode);
        $this->assertSame(409, $result->statusCode);

        // Pre-existing row must still be intact (transaction rolled back).
        $stillExists = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE id = %i', $existing);
        $this->assertNotNull($stillExists);
    }

    public function testUnstandardizedAddressCreate(): void
    {
        $payload = $this->loadFixture('unstandardized_address.json');
        $payload['companyId'] = 'AT-' . substr((string) (microtime(true) * 10000), -8);

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $this->createdPersonIds[] = $result->savedId;

        $addresses = $this->db->fetchAll(
            'SELECT * FROM base_persons_addresses WHERE person = %i', $result->savedId,
        );
        $this->assertCount(1, $addresses);
        $this->assertSame(0, (int) $addresses[0]['is_standardized']);
        $this->assertSame('at', $addresses[0]['country']);
        $this->assertSame('Mariahilfer Strasse 100', $addresses[0]['street']);
    }

    /**
     * Normalise a dibi date value to `Y-m-d` — fetchRow may return either
     * a `DateTimeInterface` (when the driver maps DATE columns) or a raw
     * string ("YYYY-MM-DD"). Helper hides the difference.
     */
    private function dateString(mixed $value): ?string
    {
        if ($value === null) return null;
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        if (is_string($value)) return substr($value, 0, 10);
        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Exchange/persons/' . $name;
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture: {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in fixture: {$path}");
        }
        return $decoded;
    }

    private function uniqueCompanyId(): string
    {
        return self::TEST_COMPANY_ID_PREFIX . substr((string) (microtime(true) * 10000), -8);
    }

    private function companyIdOf(int $personId): string
    {
        $row = $this->db->fetchRow('SELECT company_id FROM base_persons_persons WHERE id = %i', $personId);
        return (string) $row['company_id'];
    }

    private function seedExistingCompany(string $companyId, string $fullName, ?string $personId = null): int
    {
        $payload = [
            'person_id'    => $personId ?? (self::TEST_PERSON_ID_PREFIX . substr(uniqid(), -8)),
            'person_type'  => 2,
            'full_name'    => $fullName,
            'last_name'    => $fullName,
            'first_name'   => '',
            'company_id'   => $companyId,
            'docState'     => 40,
            'docStateMain' => 3,
        ];
        $this->db->getDibiConnection()->insert('base_persons_persons', $payload)->execute();
        $id = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdPersonIds[] = $id;
        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedAddress(int $personId, array $payload): int
    {
        $payload = array_merge([
            'person'       => $personId,
            'docState'     => 40,
            'docStateMain' => 2,
        ], $payload);
        $this->db->getDibiConnection()->insert('base_persons_addresses', $payload)->execute();
        return (int) $this->db->getDibiConnection()->getInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedBankAccount(int $personId, array $payload): int
    {
        $payload = array_merge([
            'person'       => $personId,
            'docState'     => 40,
            'docStateMain' => 2,
        ], $payload);
        $this->db->getDibiConnection()->insert('base_persons_bank_accounts', $payload)->execute();
        return (int) $this->db->getDibiConnection()->getInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedContact(int $personId, array $payload): int
    {
        $payload = array_merge([
            'person'       => $personId,
            'docState'     => 40,
            'docStateMain' => 2,
        ], $payload);
        $this->db->getDibiConnection()->insert('base_persons_contacts', $payload)->execute();
        return (int) $this->db->getDibiConnection()->getInsertId();
    }
}
