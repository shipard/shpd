<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\NumberSeriesProvisioner;

class NumberSeriesProvisionerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
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

    /**
     * @param array<string, array<string, mixed>> $docTypes
     */
    private function buildConfig(array $docTypes): ConfigRuntime
    {
        $items = ['docs.core.docTypes' => $docTypes];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /**
     * Recording mock — captures inserts into in-memory rows + answers
     * existence lookups by doc_type.
     *
     * @param list<array{id: int, doc_type: string, docState: int}> $existing
     */
    private function recordingDb(array $existing = []): object
    {
        $store = new \stdClass();
        $store->tables = ['docs_core_number_series' => $existing];
        $store->autoIncrement = count($existing);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                if (str_contains($sql, 'docs_core_number_series')
                    && str_contains($sql, 'doc_type')
                ) {
                    $needle = (string) ($params[0] ?? '');
                    $excluded = (int) ($params[1] ?? 0);
                    foreach ($store->tables['docs_core_number_series'] as $row) {
                        if (($row['doc_type'] ?? '') === $needle
                            && (int) ($row['docState'] ?? 0) !== $excluded
                        ) {
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

    public function testEmptyDsCreatesOneSeriesPerDocType(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig([
            'invno' => [
                'name'    => 'Issued invoice',
                'name:cs' => 'Faktura vydaná',
                'doc_number_pattern_default' => '%D%y%C%4',
            ],
            'invni' => [
                'name'    => 'Received invoice',
                'name:cs' => 'Faktura přijatá',
                'doc_number_pattern_default' => '%D%y%C%4',
            ],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(2, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertCount(2, $store->tables['docs_core_number_series']);

        $invno = $store->tables['docs_core_number_series'][0];
        $this->assertSame('invno', $invno['doc_type']);
        $this->assertSame('Faktura vydaná', $invno['name']);
        $this->assertSame('%D%y%C%4', $invno['doc_number_pattern']);
        $this->assertSame('fiscal_year', $invno['reset_scope']);
        $this->assertSame(40, $invno['docState']);
        $this->assertSame(3, $invno['docStateMain']);
        $this->assertNull($invno['doc_number_code']);
    }

    public function testSecondRunIsNoOp(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'doc_type' => 'invno', 'docState' => 40],
            ['id' => 2, 'doc_type' => 'invni', 'docState' => 40],
        ]);
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%C%4'],
            'invni' => ['name:cs' => 'Faktura přijatá', 'doc_number_pattern_default' => '%D%y%C%4'],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['numberSeries']['created']);
        $this->assertSame(2, $result['numberSeries']['existing']);
        $this->assertCount(2, $store->tables['docs_core_number_series']);
    }

    public function testDeletedSeriesDoesNotPreventCreation(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'doc_type' => 'invno', 'docState' => 90],
        ]);
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%C%4'],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(1, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertCount(2, $store->tables['docs_core_number_series']);
    }

    public function testMissingDocTypesCfgItemReturnsZeroes(): void
    {
        $store = $this->recordingDb();
        $items = [];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        $config = ConfigRuntime::load($this->tmpDir, 'cs');

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertCount(0, $store->tables['docs_core_number_series']);
    }

    public function testFallbackPatternUsedWhenDocTypeMissing(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná'],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $provisioner->provision();

        $row = $store->tables['docs_core_number_series'][0];
        $this->assertSame('%D%y%4', $row['doc_number_pattern']);
    }
}
