<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\AccountChartProvisioner;

class AccountChartProvisionerTest extends TestCase
{
    private string $seedFile;

    protected function setUp(): void
    {
        $this->seedFile = sys_get_temp_dir() . '/shpd_accseed_' . uniqid() . '.jsonc';
    }

    protected function tearDown(): void
    {
        if (is_file($this->seedFile)) {
            unlink($this->seedFile);
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeSeed(array $entries): void
    {
        file_put_contents($this->seedFile, json_encode($entries));
    }

    /**
     * Recording mock — lookup existence by `number`, capture inserts.
     *
     * @param list<array{id: int, number: string}> $existing
     */
    private function recordingDb(array $existing = []): object
    {
        $store = new \stdClass();
        $store->tables = ['economy_accounting_accounts' => $existing];
        $store->autoIncrement = count($existing);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                if (str_contains($sql, 'economy_accounting_accounts')
                    && str_contains($sql, 'number')
                ) {
                    $needle = (string) ($params[0] ?? '');
                    foreach ($store->tables['economy_accounting_accounts'] as $row) {
                        if ((string) ($row['number'] ?? '') === $needle) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }
        );

        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $store->autoIncrement++;
                $row = $data;
                $row['id'] = $store->autoIncrement;
                $store->tables[$table][] = $row;
                return $store->autoIncrement;
            }
        );

        $store->db = $db;
        return $store;
    }

    public function testEmptyDsCreatesAccounts(): void
    {
        $this->writeSeed([
            ['number' => '5', 'name' => 'Náklady', 'account_kind' => 2],
            ['number' => '501100', 'name' => 'Spotřeba materiálu', 'account_kind' => 2, 'costs_type' => 1, 'results_type' => 1],
        ]);
        $store = $this->recordingDb();

        $provisioner = new AccountChartProvisioner($store->db, $this->seedFile);
        $result = $provisioner->provision();

        $this->assertSame(2, $result['accountChart']['created']);
        $this->assertSame(0, $result['accountChart']['existing']);

        $rows = $store->tables['economy_accounting_accounts'];
        $this->assertCount(2, $rows);

        $class = $rows[0];
        $this->assertSame('5', $class['number']);
        $this->assertSame(1, $class['account_level']);
        $this->assertSame('5', $class['g1']);
        $this->assertNull($class['g2']);
        $this->assertSame(1, $class['is_system']);
        $this->assertSame(40, $class['docState']);
        $this->assertSame(3, $class['docStateMain']);

        $account = $rows[1];
        $this->assertSame(4, $account['account_level']);
        $this->assertSame('501', $account['g3']);
        $this->assertSame(1, $account['costs_type']);
        $this->assertSame(1, $account['results_type']);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->writeSeed([
            ['number' => '5', 'name' => 'Náklady', 'account_kind' => 2],
            ['number' => '501100', 'name' => 'Spotřeba materiálu', 'account_kind' => 2, 'costs_type' => 1],
        ]);
        $store = $this->recordingDb([
            ['id' => 1, 'number' => '5'],
            ['id' => 2, 'number' => '501100'],
        ]);

        $provisioner = new AccountChartProvisioner($store->db, $this->seedFile);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['accountChart']['created']);
        $this->assertSame(2, $result['accountChart']['existing']);
        $this->assertCount(2, $store->tables['economy_accounting_accounts']);
    }

    public function testExistingAccountInOtherStateNotOverwritten(): void
    {
        // Uživatel si účet zarchivoval (docState != 40) — nesmí se obnovit.
        $this->writeSeed([
            ['number' => '501100', 'name' => 'Spotřeba materiálu', 'account_kind' => 2],
        ]);
        $store = $this->recordingDb([
            ['id' => 7, 'number' => '501100', 'docState' => 80],
        ]);

        $provisioner = new AccountChartProvisioner($store->db, $this->seedFile);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['accountChart']['created']);
        $this->assertSame(1, $result['accountChart']['existing']);
        $this->assertCount(1, $store->tables['economy_accounting_accounts']);
        // Původní záznam beze změny.
        $this->assertSame(80, $store->tables['economy_accounting_accounts'][0]['docState']);
    }

    public function testAccountKindZeroIsInsertedButMissingTypesAreNull(): void
    {
        // account_kind = 0 (Aktiva) se vkládá; chybějící costs_type/results_type → NULL.
        $this->writeSeed([
            ['number' => '211100', 'name' => 'Pokladna', 'account_kind' => 0],
        ]);
        $store = $this->recordingDb();

        $provisioner = new AccountChartProvisioner($store->db, $this->seedFile);
        $provisioner->provision();

        $row = $store->tables['economy_accounting_accounts'][0];
        $this->assertSame(0, $row['account_kind']);
        $this->assertArrayNotHasKey('costs_type', $row);
        $this->assertArrayNotHasKey('results_type', $row);
        $this->assertSame(1, $row['is_system']);
    }

    public function testMissingNumberThrows(): void
    {
        $this->writeSeed([
            ['name' => 'Bez čísla'],
        ]);
        $store = $this->recordingDb();

        $this->expectException(\RuntimeException::class);
        (new AccountChartProvisioner($store->db, $this->seedFile))->provision();
    }
}
