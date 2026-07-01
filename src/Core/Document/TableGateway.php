<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Logging\ErrorLogger;

class TableGateway
{
    public function __construct(
        private string $tableId,
        private \Dibi\Connection $db,
        private DocumentRegistry $registry,
        private ?array $childTables = null,
        private ?ConfigRuntime $config = null,
        private ?DataSourceConfig $dsConfig = null,
        private ?DocumentEventDispatcher $eventDispatcher = null,
        private ?DocStatesDefinition $docStates = null,
    ) {}

    private function injectDocServices(Document $doc): void
    {
        $doc->setDb($this->db);
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }
        if ($this->dsConfig !== null) {
            $doc->setDsConfig($this->dsConfig);
        }
    }

    public function loadRecord(int $id): ?array
    {
        return $this->fetchRow($id);
    }

    public function loadDocument(int $id): ?array
    {
        $data = $this->fetchRow($id);
        if ($data === null) {
            return null;
        }

        foreach ($this->childTables ?? [] as $ct) {
            $data[$ct['dataKey']] = $this->fetchChildren($ct['table'], $ct['foreignKey'], $id);
        }

        $doc = $this->registry->getDocument($this->tableId, $data);
        $this->injectDocServices($doc);
        $doc->onLoad($data);

        return $data;
    }

    public function saveDocument(array $inputData): DocumentResult
    {
        $doc = $this->registry->getDocument($this->tableId, $inputData);
        $this->injectDocServices($doc);
        $data = $inputData;

        // Load original record (head + child rows) on update — Document hooks
        // need it to detect what changed (partner, docState, …). On insert: null.
        $originalData = null;
        if (isset($data['id']) && (int) $data['id'] > 0) {
            $originalData = $this->loadDocument((int) $data['id']);
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            return DocumentResult::validationFailed($validation);
        }

        $doc->beforeSave($data, $originalData);

        // Odvození docStateMain z cfgItemu — jediné místo pravdy pro všechny
        // zápisové cesty přes Document/Gateway (import Applier i FormController).
        if ($this->docStates !== null && $this->config !== null) {
            $stateCol = $this->docStates->stateColumn;
            $mainCol  = $this->docStates->mainColumn;
            if (array_key_exists($stateCol, $data) && $data[$stateCol] !== null) {
                $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStates->cfgItem));
                $data[$mainCol] = $cfg->getMainState((int) $data[$stateCol]);
            }
        }

        try {
            $this->beginTransaction();

            // Separate child data from head data. Only track child sets that
            // were actually provided in $data — either by the client or set
            // by Document::beforeSave. Children NOT present in $data stay
            // untouched on disk: this is what protects sub-form managed rows
            // (docs_core_rows) from being wiped when only the header is saved.
            $childDataByKey = [];
            foreach ($this->childTables ?? [] as $ct) {
                if (!array_key_exists($ct['dataKey'], $data)) {
                    continue;
                }
                $childDataByKey[$ct['dataKey']] = is_array($data[$ct['dataKey']]) ? $data[$ct['dataKey']] : [];
                unset($data[$ct['dataKey']]);
            }

            $headId = isset($data['id']) && $data['id'] > 0
                ? (int) $data['id']
                : null;

            if ($headId !== null) {
                $headData = $data;
                unset($headData['id']);
                $this->updateRow($this->tableId, $headId, $headData);
            } else {
                $headData = $data;
                unset($headData['id']);
                $headId = $this->insertRow($this->tableId, $headData);
                $data['id'] = $headId;
            }

            foreach ($this->childTables ?? [] as $ct) {
                if (!array_key_exists($ct['dataKey'], $childDataByKey)) {
                    continue;
                }
                $this->syncChildren($ct['table'], $ct['foreignKey'], $headId, $childDataByKey[$ct['dataKey']]);
                $data[$ct['dataKey']] = $childDataByKey[$ct['dataKey']];
            }

            $doc->afterPersist($data);

            $this->commitTransaction();
        } catch (\DomainException $e) {
            // Domain errors are expected business outcomes (e.g. "can't release
            // number with gap in sequence") — surface to caller, don't log.
            $this->rollbackTransaction();
            return DocumentResult::domainError($e->getMessage(), $e->getCode() !== 0 ? (string) $e->getCode() : null);
        } catch (\Throwable $e) {
            // Unexpected failure (SQL syntax, type mismatch, network) — log it
            // before returning the error. Without this, only exceptions that
            // bubble all the way up to index.php get logged; gateway-caught
            // ones produced silent 500 responses.
            $this->rollbackTransaction();
            ErrorLogger::logException($e, 'TableGateway::saveDocument failed for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        $doc->afterSave($data);

        // Dispatch documentEventHandlers až po commitu a po afterSave —
        // přechod poskytuje Document (trackStateChange), gateway nic
        // nedopočítává. Výjimky handlerů loguje a polyká dispatcher.
        $transition = $doc->getStateTransition();
        if ($transition !== null && $this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatchStateChanged(
                $this->tableId,
                $data,
                $transition['old'],
                $transition['new'],
            );
        }

        return DocumentResult::ok($data);
    }

    public function deleteDocument(int $id): DocumentResult
    {
        $data = $this->loadDocument($id);
        if ($data === null) {
            return DocumentResult::error("Record {$id} not found");
        }

        $doc = $this->registry->getDocument($this->tableId, $data);
        $this->injectDocServices($doc);
        $doc->beforeDelete($data);

        $this->beginTransaction();
        try {
            // beforeDelete handlery uvnitř transakce, před child delete —
            // mažou závislá data (deník). Výjimka = rollback, dokument
            // zůstává netknutý.
            $this->eventDispatcher?->dispatchBeforeDelete($this->tableId, $data);

            foreach ($this->childTables ?? [] as $ct) {
                $this->deleteChildren($ct['table'], $ct['foreignKey'], $id);
            }
            $this->deleteRow($this->tableId, $id);
            $this->commitTransaction();
        } catch (\Throwable $e) {
            $this->rollbackTransaction();
            ErrorLogger::logException($e, 'TableGateway::deleteDocument failed for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        $doc->afterDelete($data);
        return DocumentResult::ok($data);
    }

    private function syncChildren(string $table, string $foreignKey, int $parentId, array $inputRows): void
    {
        $existing = $this->fetchChildren($table, $foreignKey, $parentId);
        $existingIds = array_map(fn($row) => (int) $row['id'], $existing);
        $inputIds = [];

        foreach ($inputRows as $row) {
            if (!empty($row['id'])) {
                $rowId = (int) $row['id'];
                $inputIds[] = $rowId;
                $rowData = $row;
                unset($rowData['id']);
                $this->updateRow($table, $rowId, $rowData);
            } else {
                $rowData = $row;
                $rowData[$foreignKey] = $parentId;
                $this->insertRow($table, $rowData);
            }
        }

        foreach ($existingIds as $existingId) {
            if (!in_array($existingId, $inputIds, true)) {
                $this->deleteRow($table, $existingId);
            }
        }
    }

    protected function fetchRow(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE id = %i', $this->tableId, $id);
        return $row ? $row->toArray() : null;
    }

    protected function fetchChildren(string $table, string $foreignKey, int $parentId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM %n WHERE %n = %i', $table, $foreignKey, $parentId);
        return array_map(fn($row) => $row->toArray(), $rows);
    }

    protected function insertRow(string $table, array $data): int
    {
        $this->db->insert($table, $data)->execute();
        return (int) $this->db->getInsertId();
    }

    protected function updateRow(string $table, int $id, array $data): void
    {
        $this->db->update($table, $data)->where('id = %i', $id)->execute();
    }

    protected function deleteRow(string $table, int $id): void
    {
        $this->db->delete($table)->where('id = %i', $id)->execute();
    }

    protected function deleteChildren(string $table, string $foreignKey, int $parentId): void
    {
        $this->db->delete($table)->where('%n = %i', $foreignKey, $parentId)->execute();
    }

    protected function beginTransaction(): void
    {
        $this->db->begin();
    }

    protected function commitTransaction(): void
    {
        $this->db->commit();
    }

    protected function rollbackTransaction(): void
    {
        $this->db->rollback();
    }
}
