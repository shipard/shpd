<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Registry;

use Shipard\Api\AlertCheckLoader;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Alerts\AlertRunResult;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * base.registry.expirations end-to-end přes AlertReconciler na reálné DB:
 * dokument s blízkým valid_to → alert vznikne; prodloužení platnosti →
 * další běh alert auto-resolvne (finding se nevrátí).
 */
class RegistryExpirationAlertTest extends IntegrationTestCase
{
    private const CHECK_ID = 'base.registry.expirations';

    private ?AlertReconciler $reconciler = null;
    private ?int $docId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry = AlertCheckLoader::load($this->dsConfig, $resolver, 'cs');
        if ($registry->get(self::CHECK_ID) === null) {
            $this->markTestSkipped('Check ' . self::CHECK_ID . ' is not registered on this DS (rebuild compiled config).');
        }
        $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->reconciler = new AlertReconciler($this->db, $registry, $configRuntime, 'cs');
    }

    protected function onTearDown(): void
    {
        if ($this->docId !== null) {
            $this->db->getDibiConnection()
                ->delete('base_registry_documents')->where('id = %i', $this->docId)->execute();
            $this->db->getDibiConnection()
                ->delete(AlertReconciler::ALERTS_TABLE)
                ->where('check_id = %s AND finding_key = %s', self::CHECK_ID, 'doc_' . $this->docId)
                ->execute();
        }
    }

    public function testAlertLifecycle(): void
    {
        $validTo = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $this->db->getDibiConnection()->insert('base_registry_documents', [
            'title'    => 'IT-REG: expirační smlouva',
            'doc_kind' => 'contract',
            'valid_to' => $validTo,
            'docState' => 40,
            'docStateMain' => 3,
            'created'  => new \DateTimeImmutable(),
        ])->execute();
        $this->docId = (int) $this->db->getDibiConnection()->getInsertId();

        // 1. běh — finding vznikne, alert aktivní (3 dny ≤ min warn 7 → warning)
        $result = $this->reconciler->runCheck(self::CHECK_ID);
        $this->assertSame(AlertRunResult::STATUS_FOUND, $result->status, $result->errorMessage ?? '');

        $alert = $this->fetchAlert();
        $this->assertNotNull($alert, 'alert row must exist');
        $this->assertSame(AlertReconciler::STATE_ACTIVE, (int) $alert['alert_state']);
        $this->assertSame(20, (int) $alert['severity'], 'warning = 20');
        $this->assertSame(428, (int) $alert['subject_table_id']);
        $this->assertSame($this->docId, (int) $alert['subject_row_id']);

        // 2. běh beze změny — stejný finding_key se UPDATEuje, nezakládá duplicitu
        $this->reconciler->runCheck(self::CHECK_ID);
        $count = $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE check_id = %s AND finding_key = %s',
            AlertReconciler::ALERTS_TABLE, self::CHECK_ID, 'doc_' . $this->docId,
        );
        $this->assertSame(1, (int) $count);

        // Prodloužení platnosti → finding se nevrátí → alert se resolvne
        $this->db->getDibiConnection()->update('base_registry_documents', [
            'valid_to' => (new \DateTimeImmutable('+2 years'))->format('Y-m-d'),
        ])->where('id = %i', $this->docId)->execute();

        $this->reconciler->runCheck(self::CHECK_ID);
        $alert = $this->fetchAlert();
        $this->assertSame(AlertReconciler::STATE_RESOLVED, (int) $alert['alert_state']);
    }

    /** @return array<string, mixed>|null */
    private function fetchAlert(): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM %n WHERE check_id = %s AND finding_key = %s ORDER BY id DESC',
            AlertReconciler::ALERTS_TABLE, self::CHECK_ID, 'doc_' . $this->docId,
        );
        return $row === null ? null : ($row instanceof \Dibi\Row ? $row->toArray() : (array) $row);
    }
}
