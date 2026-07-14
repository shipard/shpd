<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Registry;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * CRUD dokumentu Spisovny přes TableGateway vč. stavového automatu
 * archivační sady (10 → 40 → 80 → 40, 70, 90) — ověřuje derivaci
 * docStateMain a promote sync na reálné DB.
 */
class RegistryDocumentLifecycleTest extends IntegrationTestCase
{
    private ?TableGateway $gateway = null;
    private ?int $docId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $def = $this->tables['base_registry_documents'];
        $this->gateway = new TableGateway(
            'base_registry_documents',
            $this->db->getDibiConnection(),
            DocumentLoader::load($this->dsConfig, $resolver),
            $def->childTables,
            $configRuntime,
            $this->dsConfig,
            null,
            $def->docStates,
        );
    }

    protected function onTearDown(): void
    {
        if ($this->docId !== null) {
            $this->db->getDibiConnection()
                ->delete('base_registry_documents')->where('id = %i', $this->docId)->execute();
        }
    }

    public function testLifecycleTransitionsDeriveMainState(): void
    {
        // Vznik Konceptu (10) s promote syncem z metadata.
        $result = $this->gateway->saveDocument([
            'title' => 'IT-REG: lifecycle smlouva',
            'doc_kind' => 'contract',
            'metadata' => '{"contractNumber": "IT-REG-1", "validTo": "2027-12-31"}',
            'docState' => 10,
        ]);
        $this->assertTrue($result->isSuccess(), $result->getErrorMessage() ?? '');
        $data = $result->getData();
        $this->docId = (int) $data['id'];

        $this->assertSame(1, (int) $data['docStateMain']);
        $this->assertSame('IT-REG-1', $data['ref_number'], 'promote sync metadata → sloupec');

        // 10 → 40 (Zařazeno) → 80 (V opravě) → 40 → 70 (Archiv) → 90 (Koš)
        foreach ([[40, 3], [80, 2], [40, 3], [70, 4], [90, 5]] as [$state, $mainState]) {
            $row = $this->db->fetchRow('SELECT * FROM base_registry_documents WHERE id = %i', $this->docId);
            $row['docState'] = $state;
            $result = $this->gateway->saveDocument($this->normalizeRow($row));
            $this->assertTrue($result->isSuccess(), "transition to {$state}: " . ($result->getErrorMessage() ?? ''));

            $saved = $this->db->fetchRow(
                'SELECT docState, docStateMain FROM base_registry_documents WHERE id = %i',
                $this->docId,
            );
            $this->assertSame($state, (int) $saved['docState']);
            $this->assertSame($mainState, (int) $saved['docStateMain'], "docStateMain pro stav {$state}");
        }
    }

    public function testValidationRejectsUnknownKindAndBadRange(): void
    {
        $result = $this->gateway->saveDocument([
            'title' => 'IT-REG: invalid',
            'doc_kind' => 'nonsense',
            'docState' => 10,
        ]);
        $this->assertFalse($result->isSuccess());

        $result = $this->gateway->saveDocument([
            'title' => 'IT-REG: invalid range',
            'doc_kind' => 'other',
            'valid_from' => '2027-01-01',
            'valid_to' => '2026-01-01',
            'docState' => 10,
        ]);
        $this->assertFalse($result->isSuccess());
    }

    /**
     * DB řádek → vstup save (DateTime hodnoty na string).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        return $row;
    }
}
