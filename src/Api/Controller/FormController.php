<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\AutoFormBuilder;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\JsoncFormLoader;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Module\ModulePathResolver;
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
        LookupRegistry $lookupRegistry,
        ModulePathResolver $modulePathResolver,
        string $language = 'en',
        array $newRecordDefaults = [],
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
        } else {
            // Pro nový záznam sestav výchozí data z defaultů sloupců
            foreach ($def->columns as $col) {
                if ($col->primaryKey || $col->system) {
                    continue;
                }
                if ($col->default !== null) {
                    $data[$col->id] = $col->default;
                }
            }
            // Klient může poslat per-typ prefill (např. doc_type z per-type
            // vieweru) přes query string ?defaults[doc_type]=invno. Tyto
            // hodnoty mají přednost před column defaults a viz je server-side
            // form (DocsHeadsForm::applyClientDefaults), který z nich může
            // odvodit další pole (číselná řada apod.).
            //
            // Query string vždy přijde jako string — pro číselné/bool sloupce
            // zkoercujeme na cílový typ. Bez toho by Svelte `<select bind:value>`
            // nerozpoznal shodu (string '2' !== int 2) a roletka zůstala prázdná.
            $colByName = [];
            foreach ($def->columns as $c) {
                $colByName[$c->id] = $c;
            }
            foreach ($newRecordDefaults as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $col = $colByName[$key] ?? null;
                $data[$key] = $col !== null ? $this->coerceDefaultValue($value, $col->type) : $value;
            }
        }

        $formDefinition = $this->resolveFormDefinition(
            $table, $def, $data, $isNew, $formRegistry, $db, $config, $modulePathResolver, $language,
        );

        // Enrich with docStates if applicable
        if ($def->docStates !== null && $config !== null) {
            // Pro nový záznam použij výchozí stav (10 = Koncept)
            $docData = $isNew ? [$def->docStates->stateColumn => 10] : $data;
            $docStatesInfo = $this->buildDocStatesInfo($def, $docData, $config);
            $formDefinition = $formDefinition->withDocStates($docStatesInfo);
        }

        // Header info — jen pro existující záznam. Pro nový formulář nemá co
        // zobrazovat; nechává se na fallback `title_new`.
        if (!$isNew) {
            $formDefinition = $this->enrichHeaderInfo(
                $formDefinition, $table, $data, $formRegistry, $db, $config,
            );
        }

        $dataResolved = $this->buildDataResolved(
            $formDefinition, $data, $lookupRegistry, $db, $config, $tables,
        );

        return Response::success([
            'formDefinition' => $formDefinition->toArray(),
            'data'           => $isNew ? ($data ?: null) : $data,
            'dataResolved'   => $dataResolved,
        ]);
    }

    public function save(
        string $table,
        ?int $id,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        FormRegistry $formRegistry,
        ModulePathResolver $modulePathResolver,
        LookupRegistry $lookupRegistry,
        string $language = 'en',
        ?DocumentRegistry $documentRegistry = null,
        ?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
        ?AuthContext $auth = null,
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
            // Tables that opt-in via `stateTransitionsRunDocumentHooks` route
            // through saveDocument — Document::beforeSave fires and can run
            // business logic (assign number, build snapshots, …).
            if ($def->stateTransitionsRunDocumentHooks) {
                return $this->applyStateTransitionViaDocument(
                    $table, $id, (int) $body[$stateCol], $def, $db, $config, $documentRegistry, $dsConfig,
                    $formRegistry, $modulePathResolver, $lookupRegistry, $language, $tables,
                );
            }
            return $this->applyStateTransition(
                $table, $id, $body, $def, $db, $config,
                $formRegistry, $modulePathResolver, $lookupRegistry, $language, $tables,
            );
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

        // Auto-manage created_by — only on insert, only when we know the user.
        // `created_by` is system:true, so it never arrives through
        // filterWritableFields and the client can't forge it.
        if ($id === null
            && $this->hasColumn($def, 'created_by')
            && $auth !== null
            && $auth->isAuthenticated
            && $auth->userId !== null
        ) {
            $inputData['created_by'] = $auth->userId;
        }

        // Init docState for new records
        if ($id === null) {
            $this->initDocState($body, $def, $inputData, $config);
        }

        $registry = $documentRegistry ?? new DocumentRegistry();
        $gateway  = new TableGateway(
            $table,
            $db->getDibiConnection(),
            $registry,
            $def->childTables,
            $config,
            $dsConfig,
        );
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
            if ($result->isDomainError()) {
                return Response::error(
                    $result->getDomainErrorCode() ?: 'DOMAIN_ERROR',
                    $result->getErrorMessage() ?? 'Domain rule violated',
                    422,
                );
            }
            return Response::error('INTERNAL_ERROR', $result->getErrorMessage() ?? 'Save failed', 500);
        }

        $saved   = $result->getData();
        $savedId = $saved['id'] ?? $id;
        $record  = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $savedId);

        $httpStatus = ($id === null) ? 201 : 200;
        $dataResolved = $this->resolveLookupValuesForRecord(
            $table, $def, $record ?? [], $formRegistry, $db, $config,
            $lookupRegistry, $modulePathResolver, $language, $tables,
        );
        return Response::success([
            'id'           => $savedId,
            'data'         => $record,
            'dataResolved' => $dataResolved,
        ], $httpStatus);
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
        LookupRegistry $lookupRegistry,
        ModulePathResolver $modulePathResolver,
        string $language = 'en',
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
            $tableForm->setTableDef($def);
            $result = $tableForm->recalculate($changedColumn, $data);
        } else {
            // JSONC or Auto — no custom recalculate logic, just rebuild definition
            $formDefinition = $this->resolveFormDefinition(
                $table, $def, $data, $isNew, $formRegistry, $db, $config, $modulePathResolver, $language,
            );
            $result = new RecalculateResult($formDefinition, $data);
        }

        // Doplň doc_states stejně jako v meta endpointu
        $formDefinition = $result->formDefinition;
        if ($def->docStates !== null && $config !== null) {
            $docData = $isNew
                ? [$def->docStates->stateColumn => ($data[$def->docStates->stateColumn] ?? 10)]
                : $data;
            $formDefinition = $formDefinition->withDocStates(
                $this->buildDocStatesInfo($def, $docData, $config)
            );
        }

        $dataResolved = $this->buildDataResolved(
            $formDefinition, $result->data, $lookupRegistry, $db, $config, $tables,
        );

        return Response::success([
            'formDefinition' => $formDefinition->toArray(),
            'data'           => $result->data,
            'dataResolved'   => $dataResolved,
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
        ModulePathResolver $modulePathResolver,
        string $language = 'en',
    ): FormDefinition {
        // 1. PHP class from registry
        $tableForm = $formRegistry->createForm($table, $db, $config);
        if ($tableForm !== null) {
            $tableForm->setTableDef($def);
            return $tableForm->buildFormDefinition($data, $isNew);
        }

        // 2. JSONC form file
        $jsoncPath = $this->findJsoncFormPath($table, $modulePathResolver);
        if ($jsoncPath !== null) {
            $loader = new JsoncFormLoader();
            return $loader->load($jsoncPath, $def, $config, $table, $language);
        }

        // 3. Auto-generate from TableDefinition
        $builder = new AutoFormBuilder();
        return $builder->build($def, $config, $table);
    }

    private function findJsoncFormPath(string $table, ModulePathResolver $resolver): ?string
    {
        foreach ($resolver->allModuleIds() as $moduleId) {
            $moduleDir = $resolver->getPath($moduleId);
            if ($moduleDir === null) continue;
            $candidate = $moduleDir . '/forms/' . $table . '.jsonc';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Doplní `headerInfo` do FormDefinition, pokud table-specific form
     * (`PersonsForm` apod.) override vrací non-null `FormHeaderInfo`. Pro
     * JSONC / Auto formuláře (TableForm bez override) zůstává null.
     */
    private function enrichHeaderInfo(
        FormDefinition $formDefinition,
        string $table,
        array $data,
        FormRegistry $formRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
    ): FormDefinition {
        $tableForm = $formRegistry->createForm($table, $db, $config);
        if ($tableForm === null) {
            return $formDefinition;
        }
        $headerInfo = $tableForm->buildHeaderInfo($data);
        if ($headerInfo === null) {
            return $formDefinition;
        }
        return $formDefinition->withHeaderInfo($headerInfo);
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
            'read_only'    => $cfg->isReadOnly($currentState),
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

    /**
     * State transition routed through TableGateway::saveDocument so Document
     * hooks fire (assignDocumentNumber on Concept→Confirmed, etc.). Triggered
     * by `stateTransitionsRunDocumentHooks: true` on the table definition.
     */
    private function applyStateTransitionViaDocument(
        string $table,
        int $id,
        int $newState,
        TableDefinition $def,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        ?DocumentRegistry $documentRegistry,
        ?\Shipard\Core\Config\DataSourceConfig $dsConfig,
        FormRegistry $formRegistry,
        ModulePathResolver $modulePathResolver,
        LookupRegistry $lookupRegistry,
        string $language,
        array $tables,
    ): Response {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return Response::error('BAD_REQUEST', 'Table does not support doc states', 400);
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol  = $dsDef->mainColumn;
        $cfg      = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $registry = $documentRegistry ?? new DocumentRegistry();
        $gateway  = new TableGateway(
            $table,
            $db->getDibiConnection(),
            $registry,
            $def->childTables,
            $config,
            $dsConfig,
        );

        $existing = $gateway->loadDocument($id);
        if ($existing === null) {
            return Response::error('NOT_FOUND', 'Record not found', 404);
        }

        $currentState = (int) ($existing[$stateCol] ?? 0);
        if ($newState !== $currentState && !$cfg->isTransitionAllowed($currentState, $newState)) {
            return Response::error(
                'INVALID_STATE_TRANSITION',
                "Transition from state {$currentState} to {$newState} is not allowed.",
                422,
            );
        }

        $existing[$stateCol] = $newState;
        $existing[$mainCol]  = $cfg->getMainState($newState);

        $result = $gateway->saveDocument($existing);

        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                $errors = array_map(
                    fn($e) => ['field' => $e->column, 'code' => $e->code ?: 'INVALID', 'message' => $e->message],
                    $validation->getErrors(),
                );
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
            }
            if ($result->isDomainError()) {
                return Response::error(
                    $result->getDomainErrorCode() ?: 'INVALID_STATE_TRANSITION',
                    $result->getErrorMessage() ?? 'State transition failed',
                    422,
                );
            }
            return Response::error('INTERNAL_ERROR', $result->getErrorMessage() ?? 'Save failed', 500);
        }

        $record = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $id);
        $dataResolved = $this->resolveLookupValuesForRecord(
            $table, $def, $record ?? [], $formRegistry, $db, $config,
            $lookupRegistry, $modulePathResolver, $language, $tables,
        );
        return Response::success([
            'id'           => $id,
            'data'         => $record,
            'dataResolved' => $dataResolved,
        ]);
    }

    private function applyStateTransition(
        string $table,
        int $id,
        array $body,
        TableDefinition $def,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        FormRegistry $formRegistry,
        ModulePathResolver $modulePathResolver,
        LookupRegistry $lookupRegistry,
        string $language,
        array $tables,
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
        $dataResolved = $this->resolveLookupValuesForRecord(
            $table, $def, $record ?? [], $formRegistry, $db, $config,
            $lookupRegistry, $modulePathResolver, $language, $tables,
        );
        return Response::success([
            'id'           => $id,
            'data'         => $record,
            'dataResolved' => $dataResolved,
        ]);
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

    /**
     * Vrátí všechny lookup elementy z FormDefinition (rekurze přes
     * tabs → sections → columns → elements). Inline group neobsahuje
     * lookup elementy (validace na úrovni FormElement to zaručuje).
     *
     * @return list<FormElement>
     */
    private function collectLookupElements(FormDefinition $formDef): array
    {
        $out = [];
        foreach ($formDef->tabs as $tab) {
            if ($tab->type !== 'fields') {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $column) {
                    foreach ($column->elements as $el) {
                        if ($el->type === 'lookup') {
                            $out[] = $el;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Vrátí mapu `{column → {id, primary, secondary}}` pro každý lookup element,
     * jehož hodnota v `$data` je ne-null a kde resolve uspěl. Klíče se nevkládají
     * pro null hodnoty ani pro lookup elementy ukazující na nezaregistrovanou tabulku.
     *
     * @param array<string, TableDefinition> $tables
     * @return array<string, array{id: int|string, primary: string, secondary: string|null}>
     */
    private function buildDataResolved(
        FormDefinition $formDef,
        array $data,
        LookupRegistry $lookupRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        array $tables,
    ): array {
        $result = [];
        foreach ($this->collectLookupElements($formDef) as $element) {
            $column = $element->column;
            if ($column === null || $column === '') {
                continue;
            }
            $value = $data[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $lookupCfg = $element->lookup;
            if (!is_array($lookupCfg)) {
                continue;
            }
            $targetTable = $lookupCfg['table'] ?? null;
            if (!is_string($targetTable) || $targetTable === '') {
                continue;
            }
            $targetDef = $tables[$targetTable] ?? null;
            $lookup = $lookupRegistry->create($targetTable, $db, $config, $targetDef);
            if ($lookup === null) {
                continue;
            }
            $items = $lookup->resolve([$value]);
            if ($items === []) {
                continue;
            }
            $result[$column] = $items[0]->toArray();
        }
        return $result;
    }

    /**
     * Rebuild FormDefinition for a saved record and resolve all its lookup
     * values. Used in save() and state-transition responses to keep the
     * client's `dataResolved` keš in sync.
     *
     * @param array<string, TableDefinition> $tables
     * @return array<string, array{id: int|string, primary: string, secondary: string|null}>
     */
    private function resolveLookupValuesForRecord(
        string $table,
        TableDefinition $def,
        array $record,
        FormRegistry $formRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        LookupRegistry $lookupRegistry,
        ModulePathResolver $modulePathResolver,
        string $language,
        array $tables,
    ): array {
        if ($record === []) {
            return [];
        }
        $formDef = $this->resolveFormDefinition(
            $table, $def, $record, /*$isNew*/ false,
            $formRegistry, $db, $config, $modulePathResolver, $language,
        );
        return $this->buildDataResolved($formDef, $record, $lookupRegistry, $db, $config, $tables);
    }

    /**
     * Convert a defaults[] query-string value (always string) to the column's
     * native PHP type, so the frontend can match it against typed option lists
     * (Svelte `<select bind:value>` is strict-equality).
     */
    private function coerceDefaultValue(mixed $value, string $columnType): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($columnType) {
            'tinyint', 'smallint', 'int', 'bigint', 'enumInt' => is_numeric($value) ? (int) $value : $value,
            'numeric', 'float' => is_numeric($value) ? (float) $value : $value,
            'boolean' => is_string($value)
                ? in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)
                : (bool) $value,
            default => $value,
        };
    }
}
