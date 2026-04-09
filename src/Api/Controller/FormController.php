<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\AutoFormBuilder;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\JsoncFormLoader;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;

class FormController
{
    /**
     * @param array<string, TableDefinition> $tables
     */
    public function meta(
        string $table,
        ?int $id,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        string $modulesBasePath,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $data = [];
        $isNew = $id === null;

        if ($id !== null) {
            $data = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $id);
            if ($data === null) {
                return Response::error('RECORD_NOT_FOUND', "Record {$id} not found", 404);
            }
        }

        $formDefinition = $this->resolveFormDefinition(
            $table, $def, $data, $isNew, $formRegistry, $db, $config, $modulesBasePath,
        );

        // Enrich with docStates if applicable
        if ($def->docStates !== null && $config !== null && !$isNew) {
            $docStatesInfo = $this->buildDocStatesInfo($def, $data, $config);
            $formDefinition = $formDefinition->withDocStates($docStatesInfo);
        }

        return Response::success([
            'formDefinition' => $formDefinition->toArray(),
            'data'           => $data ?: null,
        ]);
    }

    public function save(
        string $table,
        ?int $id,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        ?DocumentRegistry $documentRegistry = null,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        $dsDef    = $def->docStates;
        $stateCol = $dsDef?->stateColumn ?? 'docState';
        $mainCol  = $dsDef?->mainColumn  ?? 'docStateMain';

        // ── Detekce přechodu stavu ────────────────────────────────────────────
        // Přechod stavu = tělo obsahuje pouze docState (žádná běžná data).
        // Prochází přímým UPDATE bez Document lifecycle.
        $bodyKeys = array_keys($body);
        $isStateTransition = $id !== null
            && $dsDef !== null
            && count($bodyKeys) === 1
            && $bodyKeys[0] === $stateCol;

        if ($isStateTransition) {
            return $this->applyStateTransition($table, $id, $body, $def, $db, $config);
        }

        // ── Běžné uložení přes TableGateway + Document lifecycle ──────────────
        $inputData = $this->filterWritableFields($body, $def);
        if ($id !== null) {
            $inputData['id'] = $id;
        }

        // Auto-manage timestamps — only add if column exists in table definition
        $now = date('Y-m-d H:i:s');
        if ($id === null && $this->hasColumn($def, 'created')) {
            $inputData['created'] = $now;
        }
        if ($this->hasColumn($def, 'modified')) {
            $inputData['modified'] = $now;
        }

        // Init docState for new records
        if ($id === null) {
            $this->initDocState($body, $def, $inputData, $config);
        }

        $registry = $documentRegistry ?? new DocumentRegistry();
        $gateway  = new TableGateway($table, $db->getDibiConnection(), $registry);
        $result   = $gateway->saveDocument($inputData);

        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                $errors = array_map(
                    fn($e) => ['field' => $e->column, 'code' => $e->code ?: 'INVALID', 'message' => $e->message],
                    $validation->getErrors(),
                );
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
            }
            return Response::error('INTERNAL_ERROR', $result->getErrorMessage() ?? 'Save failed', 500);
        }

        $saved   = $result->getData();
        $savedId = $saved['id'] ?? $id;
        $record  = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $savedId);

        $httpStatus = ($id === null) ? 201 : 200;
        return Response::success(['id' => $savedId, 'data' => $record], $httpStatus);
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    public function recalculate(
        string $table,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        string $modulesBasePath,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        $changedColumn = $body['changedColumn'] ?? '';
        $data = $body['data'] ?? [];
        $isNew = !isset($data['id']) || $data['id'] === null;

        // Try PHP class form first
        $tableForm = $formRegistry->createForm($table, $db, $config);
        if ($tableForm !== null) {
            $result = $tableForm->recalculate($changedColumn, $data);
        } else {
            // JSONC or Auto — no custom recalculate logic, just rebuild definition
            $formDefinition = $this->resolveFormDefinition(
                $table, $def, $data, $isNew, $formRegistry, $db, $config, $modulesBasePath,
            );
            $result = new RecalculateResult($formDefinition, $data);
        }

        return Response::success([
            'formDefinition' => $result->formDefinition->toArray(),
            'data'           => $result->data,
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function resolveFormDefinition(
        string $table,
        TableDefinition $def,
        array $data,
        bool $isNew,
        FormRegistry $formRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $modulesBasePath,
    ): FormDefinition {
        // 1. PHP class from registry
        $tableForm = $formRegistry->createForm($table, $db, $config);
        if ($tableForm !== null) {
            return $tableForm->buildFormDefinition($data, $isNew);
        }

        // 2. JSONC form file
        $jsoncPath = $this->findJsoncFormPath($table, $modulesBasePath);
        if ($jsoncPath !== null) {
            $loader = new JsoncFormLoader();
            return $loader->load($jsoncPath, $def, $config, $table);
        }

        // 3. Auto-generate from TableDefinition
        $builder = new AutoFormBuilder();
        return $builder->build($def, $config, $table);
    }

    private function findJsoncFormPath(string $table, string $modulesBasePath): ?string
    {
        if ($modulesBasePath === '') {
            return null;
        }

        // Search for forms/{table}.jsonc in module directories
        $groupDirs = @scandir($modulesBasePath);
        if ($groupDirs === false) {
            return null;
        }

        foreach ($groupDirs as $group) {
            if ($group === '.' || $group === '..') {
                continue;
            }
            $groupPath = $modulesBasePath . '/' . $group;
            if (!is_dir($groupPath)) {
                continue;
            }
            $moduleDirs = @scandir($groupPath);
            if ($moduleDirs === false) {
                continue;
            }
            foreach ($moduleDirs as $module) {
                if ($module === '.' || $module === '..') {
                    continue;
                }
                $path = $groupPath . '/' . $module . '/forms/' . $table . '.jsonc';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function buildDocStatesInfo(TableDefinition $def, array $data, ConfigRuntime $config): array
    {
        $dsDef = $def->docStates;
        $cfg = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));
        $currentState = (int) ($data[$dsDef->stateColumn] ?? 10);
        $stateData = $cfg->getState($currentState);

        return [
            'currentState' => $currentState,
            'stateName'    => $stateData['stateName'] ?? '',
            'stateStyle'   => $stateData['stateStyle'] ?? '',
            'readOnly'     => $cfg->isReadOnly($currentState),
            'transitions'  => $cfg->getAvailableTransitions($currentState),
        ];
    }

    private function filterWritableFields(array $data, TableDefinition $def): array
    {
        $excluded = ['id', 'created', 'modified'];
        $colMap = [];
        foreach ($def->columns as $col) {
            if (!in_array($col->id, $excluded, true) && !$col->system) {
                $colMap[$col->id] = true;
            }
        }

        $result = [];
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if (isset($colMap[$k])) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    private function initDocState(array $rawBody, TableDefinition $def, array &$data, ?ConfigRuntime $config): void
    {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return;
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol = $dsDef->mainColumn;
        $cfg = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $newState = isset($rawBody[$stateCol]) ? (int) $rawBody[$stateCol] : 10;
        $data[$stateCol] = $newState;
        $data[$mainCol] = $cfg->getMainState($newState);
    }

    private function processDocState(
        string $table,
        int $id,
        array $rawBody,
        TableDefinition $def,
        array &$data,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
    ): ?Response {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return null;
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol = $dsDef->mainColumn;
        $cfg = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $currentRow = $db->fetchRow("SELECT `{$stateCol}` FROM `{$table}` WHERE `id` = %i", $id);
        $currentState = (int) ($currentRow[$stateCol] ?? 10);
        $isReadOnly = $cfg->isReadOnly($currentState);

        $hasStateChange = isset($rawBody[$stateCol]);

        if ($isReadOnly && $data !== []) {
            return Response::error(
                'DOCUMENT_READONLY',
                "Document is read-only in state {$currentState}.",
                422,
            );
        }

        if ($hasStateChange) {
            $newState = (int) $rawBody[$stateCol];
            if ($newState !== $currentState && !$cfg->isTransitionAllowed($currentState, $newState)) {
                return Response::error(
                    'INVALID_STATE_TRANSITION',
                    "Transition from state {$currentState} to {$newState} is not allowed.",
                    422,
                );
            }
            $data[$stateCol] = $newState;
            $data[$mainCol] = $cfg->getMainState($newState);
        }

        return null;
    }

    private function applyStateTransition(
        string $table,
        int $id,
        array $body,
        TableDefinition $def,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
    ): Response {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return Response::error('BAD_REQUEST', 'Table does not support doc states', 400);
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol  = $dsDef->mainColumn;
        $cfg      = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $currentRow   = $db->fetchRow("SELECT `{$stateCol}` FROM `{$table}` WHERE `id` = %i", $id);
        if ($currentRow === null) {
            return Response::error('NOT_FOUND', 'Record not found', 404);
        }
        $currentState = (int) $currentRow[$stateCol];
        $newState     = (int) $body[$stateCol];

        if ($newState !== $currentState && !$cfg->isTransitionAllowed($currentState, $newState)) {
            return Response::error(
                'INVALID_STATE_TRANSITION',
                "Transition from state {$currentState} to {$newState} is not allowed.",
                422,
            );
        }

        $db->updateWhere($table, [
            $stateCol => $newState,
            $mainCol  => $cfg->getMainState($newState),
        ], 'id = %i', $id);

        $record = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $id);
        return Response::success(['id' => $id, 'data' => $record]);
    }

    private function hasColumn(TableDefinition $def, string $colId): bool
    {
        foreach ($def->columns as $col) {
            if ($col->id === $colId) {
                return true;
            }
        }
        return false;
    }
}
