<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentSnapshotsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_snap_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.docTypes' => [
                'invno' => ['doc_id_code' => '1', 'trade_dir' => 1],
                'invni' => ['doc_id_code' => '2', 'trade_dir' => 2],
            ],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /**
     * Helper: build a Connection mock that returns rows in a given sequence.
     *
     * @param list<?Row> $rows
     */
    private function dbReturning(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $iter = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$iter, $rows): ?Row {
                return $rows[$iter++] ?? null;
            }
        );
        return $db;
    }

    // ── buildPersonSnapshot ────────────────────────────────────────────────

    public function testBuildPersonSnapshotMinimal(): void
    {
        $db = $this->dbReturning([
            new Row([
                'id' => 5, 'full_name' => 'Beta s.r.o.',
                'company_id' => '12345678', 'tax_id' => 'CZ12345678', 'vat_id' => 'CZ12345678',
                'court_registration' => 'Městský soud v Praze',
                'email' => 'info@beta.cz', 'phone' => '+420 1 23 45 67 89',
            ]),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $snap = $doc->buildPersonSnapshotPub(5, null, null, null);

        $this->assertSame('Beta s.r.o.', $snap['name']);
        $this->assertSame('12345678', $snap['company_id']);
        $this->assertSame('Městský soud v Praze', $snap['court_registration']);
        $this->assertSame('info@beta.cz', $snap['contact']['email']);
        $this->assertArrayNotHasKey('address', $snap);
        $this->assertArrayNotHasKey('bank_account', $snap);
        $this->assertArrayNotHasKey('vat_registration', $snap);
    }

    public function testBuildPersonSnapshotWithAddressBankAndVatRegistration(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 5, 'full_name' => 'Naše s.r.o.']),
            new Row(['id' => 11, 'street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'cz']),
            new Row(['id' => 21, 'name' => 'ČSOB', 'account_number' => '123/0300', 'iban' => 'CZ65...', 'bic' => 'CEKO', 'currency' => 'czk']),
            new Row(['id' => 31, 'country' => 'cz', 'vat_id' => 'CZ12345678']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $snap = $doc->buildPersonSnapshotPub(5, 11, 21, 31);

        $this->assertSame('Praha', $snap['address']['city']);
        $this->assertSame('123/0300', $snap['bank_account']['account_number']);
        $this->assertSame('cz', $snap['vat_registration']['country']);
        $this->assertSame('CZ12345678', $snap['vat_registration']['vat_id']);
    }

    public function testBuildPersonSnapshotPersonNotFound(): void
    {
        $db = $this->dbReturning([null]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $snap = $doc->buildPersonSnapshotPub(99, null, null, null);
        $this->assertSame([], $snap);
    }

    public function testBuildPersonSnapshotZeroIdReturnsEmpty(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbReturning([]));

        $this->assertSame([], $doc->buildPersonSnapshotPub(0, null, null, null));
    }

    // ── buildSnapshots ─────────────────────────────────────────────────────

    public function testBuildSnapshotsForIssuedInvoice(): void
    {
        // Order of fetch calls inside buildSnapshots:
        // 1) partner person — buildPersonSnapshot
        // 2) own person id (OwnCompanyResolver::getOwnPersonId)
        // 3) own person id (OwnCompanyResolver::getOwnHeadquartersAddress → getOwnPersonId)
        // 4) own HQ address (OwnCompanyResolver::getOwnHeadquartersAddress → SELECT addresses)
        // 5) own person — buildPersonSnapshot
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            new Row(['id' => 100, 'street' => 'Vlastní 1', 'city' => 'Brno']),
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'doc_type' => 'invno', // trade_dir = 1 (output, we are supplier)
            'partner' => 50,
            'partner_address' => null,
            'bank_account' => null,
            'vat_registration' => null,
        ];
        $doc->buildSnapshotsPub($data);

        $supplier = json_decode($data['supplier_snapshot'], true);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Naše firma s.r.o.', $supplier['name']);
        $this->assertSame('Partner s.r.o.', $customer['name']);
    }

    public function testBuildSnapshotsForReceivedInvoiceFlipsRoles(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),                       // getOwnPersonId
            new Row(['id' => 1]),                       // getOwnHeadquartersAddress → getOwnPersonId
            null,                                        // address lookup → null
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'doc_type' => 'invni', // trade_dir = 2 (input, we are customer)
            'partner' => 50,
            'partner_address' => null,
            'bank_account' => null,
            'vat_registration' => null,
        ];
        $doc->buildSnapshotsPub($data);

        $supplier = json_decode($data['supplier_snapshot'], true);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Partner s.r.o.', $supplier['name']);
        $this->assertSame('Naše firma s.r.o.', $customer['name']);
    }

    public function testBuildSnapshotsThrowsWhenOwnCompanyMissing(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            null, // own person id lookup → null
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'partner' => 50];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Není nastavena vlastní firma');
        $doc->buildSnapshotsPub($data);
    }

    // ── maintainSnapshots ──────────────────────────────────────────────────

    public function testMaintainSnapshotsSkipsKonceptState(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 10, 'partner' => 5];
        $doc->maintainSnapshotsPub($data, null);

        $this->assertArrayNotHasKey('supplier_snapshot', $data);
    }

    public function testMaintainSnapshotsBuildsOnFirstConfirmedTransition(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            null,
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState' => 20, 'doc_type' => 'invno', 'partner' => 50,
            'partner_address' => null, 'bank_account' => null, 'vat_registration' => null,
        ];
        $doc->maintainSnapshotsPub($data, ['docState' => 10, 'partner' => 50]);

        $this->assertNotEmpty($data['supplier_snapshot']);
    }

    public function testMaintainSnapshotsRebuildsWhenPartnerChanged(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 60, 'full_name' => 'Nový Partner']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            null,
            new Row(['id' => 1, 'full_name' => 'Naše firma']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState' => 80, 'doc_type' => 'invno', 'partner' => 60,
            'partner_address' => null, 'bank_account' => null, 'vat_registration' => null,
            'supplier_snapshot' => ['name' => 'Naše firma'], // pre-existing
            'customer_snapshot' => ['name' => 'Starý Partner'],
        ];
        $original = ['docState' => 80, 'partner' => 50];
        $doc->maintainSnapshotsPub($data, $original);

        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Nový Partner', $customer['name']);
    }

    public function testMaintainSnapshotsDataSaveWithoutDocStateFallsBackToOriginal(): void
    {
        // Data-save bez docState v payloadu (volání mimo gateway, např.
        // recomputeHeader): efektivní stav je stav z originálu (20) —
        // snapshoty se při změně partnera musí přestavět, ne přeskočit.
        $db = $this->dbReturning([
            new Row(['id' => 60, 'full_name' => 'Nový Partner']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            null,
            new Row(['id' => 1, 'full_name' => 'Naše firma']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'doc_type' => 'invno', 'partner' => 60,
            'partner_address' => null, 'bank_account' => null, 'vat_registration' => null,
            'supplier_snapshot' => ['name' => 'Naše firma'],
            'customer_snapshot' => ['name' => 'Starý Partner'],
        ];
        $original = ['docState' => 20, 'partner' => 50];
        $doc->maintainSnapshotsPub($data, $original);

        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Nový Partner', $customer['name']);
    }

    public function testMaintainSnapshotsKeepsExistingWhenUnchanged(): void
    {
        $doc = new TestableDocsHeadsDocument();
        // No DB calls expected — early return path
        $data = [
            'docState' => 80, 'doc_type' => 'invno', 'partner' => 50,
            'supplier_snapshot' => ['name' => 'Naše firma'],
            'customer_snapshot' => ['name' => 'Partner'],
        ];
        $doc->maintainSnapshotsPub($data, ['docState' => 80, 'partner' => 50]);

        // Snapshots untouched
        $this->assertSame('Naše firma', $data['supplier_snapshot']['name']);
        $this->assertSame('Partner', $data['customer_snapshot']['name']);
    }
}
