<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class TableGateway
{
    public function __construct(
        private string $tableId,
        private \Dibi\Connection $db,
        private DocumentRegistry $registry,
        private ?array $childTables = null,
    ) {}

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
        $doc->onLoad($data);

        return $data;
    }

    public function saveDocument(array $inputData): DocumentResult
    {
        $doc = $this->registry->getDocument($this->tableId, $inputData);
        $doc->setDb($this->db);
        $data = $inputData;

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            return DocumentResult::validationFailed($validation);
        }

        $doc->beforeSave($data);

        try {
            $this->beginTransaction();

            // Separate child data from head data
            $childDataByKey = [];
            foreach ($this->childTables ?? [] as $ct) {
                $childDataByKey[$ct['dataKey']] = $data[$ct['dataKey']] ?? [];
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
                $this->syncChildren($ct['table'], $ct['foreignKey'], $headId, $childDataByKey[$ct['dataKey']]);
                $data[$ct['dataKey']] = $childDataByKey[$ct['dataKey']];
            }

            $doc->afterPersist($data);

            $this->commitTransaction();
        } catch (\Throwable $e) {
            $this->rollbackTransaction();
            return DocumentResult::error($e->getMessage());
        }

        $doc->afterSave($data);
        return DocumentResult::ok($data);
    }

    public function deleteDocument(int $id): DocumentResult
    {
        $data = $this->loadDocument($id);
        if ($data === null) {
            return DocumentResult::error("Record {$id} not found");
        }

        $doc = $this->registry->getDocument($this->tableId, $data);
        $doc->beforeDelete($data);

        $this->beginTransaction();
        foreach ($this->childTables ?? [] as $ct) {
            $this->deleteChildren($ct['table'], $ct['foreignKey'], $id);
        }
        $this->deleteRow($this->tableId, $id);
        $this->commitTransaction();

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
