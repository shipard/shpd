<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Orchestrator of the canonical → DB save pipeline. See
 * docs/exchange-format.md §10 for the full step sequence.
 *
 *   /validate  — schema + DocumentValidator, no DB writes, no resolve.
 *   /preview   — validate + full resolve, populates `_resolve`.
 *   /apply     — validate + resolve + reconcile with userAction +
 *                outer transaction { side-creates + saveDocument +
 *                lineage update }.
 *
 * The Applier never reaches below the Document layer — all business
 * logic (number assignment, snapshots, totals, recap) stays in
 * DocDocument::beforeSave. Applier's job is only to translate
 * canonical → internal $data and call the existing TableGateway.
 */
class DocumentApplier
{
    public const FORMAT_ID = 'shpd.docs.document';
    public const FORMAT_VERSION = '1';

    private const ACTIVE_STATES = [10, 40, 80];

    /** Map canonical vat.mode → docs_core_heads.vat_mode (cfgItem docs.core.vatModes). */
    private const VAT_MODE_MAP = [
        'none'      => 0,
        'fromBase'  => 1,
        'fromTotal' => 2,
    ];

    /** Map canonical vat.place → docs_core_heads.vat_place (cfgItem docs.core.vatPlaces). */
    private const VAT_PLACE_MAP = [
        'domestic'    => 0,
        'intracom'    => 1,
        'thirdCountry' => 2,
    ];

    /** Map canonical payment.method → docs_core_heads.payment_method. */
    private const PAYMENT_METHOD_MAP = [
        'cash'           => 0,
        'bankTransfer'   => 1,
        'card'           => 2,
        'cashOnDelivery' => 3,
        'setOff'         => 4,
    ];

    /** Map canonical row.priceCalcMode → docs_core_rows.price_calc_mode. */
    private const PRICE_CALC_MODE_MAP = [
        'fromUnitPrice' => 0,
        'fromTotal'     => 1,
    ];

    /** Map canonical rowKind → docs_core_rows.row_kind. */
    private const ROW_KIND_MAP = [
        'text'    => 0,
        'item'    => 1,
        'section' => 2,
    ];

    /**
     * Map canonical docType (descriptive name from docs/exchange-format.md
     * section 5) → docs.core.docTypes cfgItem key (short code stored in
     * docs_core_heads.doc_type). Passing the short code directly is also
     * accepted — passthrough when no alias matches.
     */
    private const DOC_TYPE_MAP = [
        'invoiceReceived'    => 'invni',
        'invoiceIssued'      => 'invno',
        'accountingDocument' => 'cmnbkp',
    ];

    /**
     * Apply-time options pulled from `$canonical['applyOptions']` at the
     * start of apply(). Read by resolveOne() for autoCreateMode behaviour.
     * Reset to [] on every apply().
     *
     * @var array<string, mixed>
     */
    private array $applyOptionsCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly TransactionlessTableGateway $headsGateway,
        private readonly TransactionlessTableGateway $personsGateway,
        private readonly TransactionlessTableGateway $itemsGateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly DocumentValidator $documentValidator,
        private readonly PartyResolver $partyResolver,
        private readonly ItemResolver $itemResolver,
        private readonly UnitResolver $unitResolver,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly BankAccountResolver $bankAccountResolver,
        private readonly AccountResolver $accountResolver,
    ) {}

    /**
     * Production factory wiring a full Applier with all resolvers and
     * gateways from a DataSourceConnection-backed environment.
     *
     * @param array<string, TableDefinition> $tables Indexed by table name —
     *        used to fish out childTables config for each gateway.
     */
    public static function create(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
    ): self {
        $vatRateResolver = new \Shipard\Module\World\Vat\VatRateResolver($config);
        $own = new OwnCompanyResolver($db);

        return new self(
            db: $db,
            config: $config,
            // Only the heads gateway needs the event dispatcher — a document
            // saved at state 40 must trigger DocsHeadsEventHandler (accounting).
            // Persons/items never transition to an accounted state.
            headsGateway: self::buildGateway('docs_core_heads', $db, $registry, $config, $dsConfig, $tables, $eventDispatcher),
            personsGateway: self::buildGateway('base_persons_persons', $db, $registry, $config, $dsConfig, $tables),
            itemsGateway: self::buildGateway('economy_items', $db, $registry, $config, $dsConfig, $tables),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: new PartyResolver($db, $own),
            itemResolver: new ItemResolver($db),
            unitResolver: new UnitResolver($db),
            vatCodeResolver: new VatCodeResolver($vatRateResolver),
            bankAccountResolver: new BankAccountResolver($db),
            accountResolver: new AccountResolver($db),
        );
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    private static function buildGateway(
        string $tableName,
        Connection $db,
        DocumentRegistry $registry,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
    ): TransactionlessTableGateway {
        $childTables = $tables[$tableName]?->childTables ?? [];
        return new TransactionlessTableGateway(
            $tableName,
            $db,
            $registry,
            $childTables,
            $config,
            $dsConfig,
            $eventDispatcher,
        );
    }

    // ── Public entry points ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    public function validate(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                code: 'schema_invalid',
                message: 'Struktura dokumentu neodpovídá schématu.',
                canonical: $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $validatorIssues = $this->documentValidator->validate($canonical);
        $enriched = $this->withResolveIssues($canonical, $validatorIssues);

        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error(
                code: 'validation_failed',
                message: 'Validace dokumentu selhala.',
                canonical: $enriched,
                statusCode: 422,
            );
        }

        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function preview(array $canonical): ApplyResult
    {
        // 1. Static checks first; schema errors abort early.
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura dokumentu neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        // 2. Semantic checks + resolve. Both contribute to _resolve.issues.
        $issues = $this->documentValidator->validate($canonical);
        $resolved = $this->resolveAll($canonical, $issues);
        $enriched = $this->withResolve($canonical, $resolved, $issues);

        // preview always succeeds even with errors — client renders the
        // payload and decides what to do.
        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        $this->applyOptionsCache = is_array($canonical['applyOptions'] ?? null)
            ? $canonical['applyOptions']
            : [];

        // 0. Idempotency check — same extracted_document already applied?
        //    Return existing savedDocId without re-saving. See Phase 2 spec
        //    "Idempotency apply".
        $idempotent = $this->checkIdempotent($canonical);
        if ($idempotent !== null) {
            return $idempotent;
        }

        // 1+2. Schema + DocumentValidator.
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura dokumentu neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }
        $validatorIssues = $this->documentValidator->validate($canonical);

        // 3. Re-run resolve (fresh DB read; client's _resolve might be stale).
        $resolved = $this->resolveAll($canonical, $validatorIssues);

        // 4. Reconcile with client _resolve.*.userAction.
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $plan = $this->reconcile($resolved, $clientResolve, $validatorIssues);

        $enriched = $this->withResolve($canonical, $resolved, $validatorIssues);

        if ($plan['errorCode'] !== null) {
            return ApplyResult::error(
                $plan['errorCode'],
                $plan['errorMessage'] ?? 'Reconcile selhal.',
                $enriched,
                statusCode: $plan['errorCode'] === 'conflict' ? 409 : 422,
            );
        }

        // 5. Validation gate — errors block save.
        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error('validation_failed', 'Validace dokumentu selhala.', $enriched);
        }

        // 5b. Resolve the target number series up-front. An explicit but
        //     unknown numberSeriesCode must fail as a clean apply-level error
        //     (422) here, not blow up mid-transaction as internal_error (500).
        $seriesCode = $canonical['applyOptions']['numberSeriesCode'] ?? null;
        $seriesCode = is_string($seriesCode) && $seriesCode !== '' ? $seriesCode : null;
        try {
            $numberSeriesId = $this->resolveNumberSeriesFor($this->mapDocType($canonical), $seriesCode);
        } catch (NumberSeriesNotFoundException $e) {
            return ApplyResult::error('number_series_not_found', $e->getMessage(), $enriched, statusCode: 422);
        }

        // 6–11. Transactional save.
        $this->db->begin();
        try {
            // Side-creates first so we have ids to link in the doc.
            $sideCreatedIds = $this->runSideCreates($plan, $resolved);

            // Transform canonical → internal $data.
            $data = $this->transform($canonical, $plan, $sideCreatedIds, $numberSeriesId);

            // Save doc head + rows + vat_recap through DocDocument.
            $result = $this->headsGateway->saveDocument($data);
            if (!$result->isSuccess()) {
                // DocumentResult::validationFailed() leaves errorMessage null
                // and carries field-level errors in ValidationResult — bubble
                // them up explicitly, otherwise the caller only sees the
                // generic "unknown error" placeholder.
                $msg = $result->getErrorMessage();
                if ($msg === null && $result->getValidation() !== null) {
                    $parts = array_map(
                        static fn($e) => ($e->column !== '' ? $e->column . ': ' : '') . $e->message,
                        $result->getValidation()->getErrors(),
                    );
                    $msg = $parts !== [] ? implode('; ', $parts) : null;
                }
                throw new \RuntimeException('Save failed: ' . ($msg ?? 'unknown error'));
            }
            $savedDocId = (int) $result->getData()['id'];

            // Per-partner item-code mapping learning — only after we know
            // which row.item ids ended up actually linked.
            $this->writeSupplierCodeMappings($canonical, $plan, $sideCreatedIds);

            // Lineage targets only — status / applied_at update lives in
            // AnalysisController::applyExtracted so the
            // ExtractedDocumentDocument hooks (incl. message 30→40
            // auto-transition) fire. See Phase 2 spec.
            $this->writeLineageTargets($canonical, $savedDocId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ApplyResult::error('internal_error', $e->getMessage(), $enriched, statusCode: 500);
        }

        // Mark canCreate references as matched (with newly-assigned ids).
        $resolved = $this->annotateSideCreated($resolved, $sideCreatedIds);
        $finalCanonical = $this->withResolve($canonical, $resolved, $validatorIssues);
        $finalCanonical['savedDocId'] = $savedDocId;

        return ApplyResult::ok($finalCanonical, $savedDocId);
    }

    // ── Resolve all references ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     *        Reference; resolvers append issues for missing references.
     * @return array<string, mixed>  `_resolve`-shaped resolve data.
     */
    private function resolveAll(array $canonical, array &$issues): array
    {
        // Účetní doklad (cmnbkp): nemá supplier/customer/selfParty — saldo
        // identita žije per řádek. Resolvujeme jen řádky (item + účet z čísla).
        $isAccountingDoc = $this->mapDocType($canonical) === 'cmnbkp';

        $supplierResult = null;
        $customerResult = null;
        $supplierBankResult = null;
        $supplierPersonId = null;

        if (!$isAccountingDoc) {
            $selfParty = $canonical['selfParty'] ?? null;
            $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
            $customer = is_array($canonical['customer'] ?? null) ? $canonical['customer'] : [];

            $supplierResult = $selfParty === 'supplier'
                ? $this->partyResolver->resolveSelfParty()
                : $this->partyResolver->resolve($supplier);
            $customerResult = $selfParty === 'customer'
                ? $this->partyResolver->resolveSelfParty()
                : $this->partyResolver->resolve($customer);

            if (is_array($supplier['bankAccount'] ?? null) && $supplier['bankAccount'] !== []) {
                $supplierBankResult = $this->bankAccountResolver->resolvePartnerBank(
                    $supplier['bankAccount'],
                    $supplierResult->status === ResolveStatus::Matched ? $supplierResult->matchedId : null,
                );
            }

            $supplierPersonId = $supplierResult->status === ResolveStatus::Matched
                ? $supplierResult->matchedId
                : null;
        }

        $rowsResolve = [];
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $vatCountry = strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        $taxPointDate = $canonical['dates']['taxPointDate'] ?? ($canonical['dates']['issueDate'] ?? null);

        foreach ($rows as $idx => $row) {
            $rowResolve = ['index' => $idx];
            if (is_array($row['item'] ?? null) && $row['item'] !== []) {
                $rowResolve['item'] = $this->itemResolver->resolve($row['item'], $supplierPersonId)->toArray();
            }

            // Účet z čísla (kontační řádek acc.record). Nenalezeno → warning,
            // řádek skončí jako chybový až při účtování — neblokovat import.
            if (isset($row['account']) && (string) $row['account'] !== '') {
                $accountId = $this->accountResolver->resolve((string) $row['account']);
                $rowResolve['account'] = [
                    'number'    => (string) $row['account'],
                    'matchedId' => $accountId,
                    'status'    => $accountId !== null ? 'matched' : 'notFound',
                ];
                if ($accountId === null) {
                    $issues[] = [
                        'severity' => 'warning',
                        'path'     => "rows.{$idx}.account",
                        'code'     => 'account_not_found',
                        'message'  => "Účet „{$row['account']}\" nebyl v rozvrhu nalezen; řádek bude bez účtu.",
                    ];
                }
            }
            if (!empty($row['unit'])) {
                $unitR = $this->unitResolver->resolve((string) $row['unit']);
                $rowResolve['unit'] = $unitR->toArray();
                if ($unitR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'warning',
                        'path'     => "rows.{$idx}.unit",
                        'code'     => 'unit_not_found',
                        'message'  => "Jednotka „{$row['unit']}\" nebyla rozpoznána; bude doplněna default.",
                    ];
                }
            }
            if (is_array($row['vat'] ?? null) && !empty($row['vat']['code'])) {
                $vatR = $this->vatCodeResolver->resolve(
                    (string) $row['vat']['code'],
                    $vatCountry !== '' ? $vatCountry : null,
                    is_string($taxPointDate) ? $taxPointDate : null,
                    isset($row['vat']['pct']) ? (float) $row['vat']['pct'] : null,
                );
                $rowResolve['vatCode'] = $vatR->toArray();
                if ($vatR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'error',
                        'path'     => "rows.{$idx}.vat.code",
                        'code'     => 'vat_code_unknown',
                        'message'  => "Neznámý kód DPH „{$row['vat']['code']}\".",
                    ];
                }
            }
            $rowsResolve[] = $rowResolve;
        }

        $resolved = ['rows' => $rowsResolve];
        // For accounting documents these stay unset → reconcile skips them
        // (no supplier/customer/bank to reconcile, no unresolved_required).
        if ($supplierResult !== null) {
            $resolved['supplier'] = $supplierResult->toArray();
        }
        if ($customerResult !== null) {
            $resolved['customer'] = $customerResult->toArray();
        }
        if ($supplierBankResult !== null) {
            $resolved['supplierBank'] = $supplierBankResult->toArray();
        }
        return $resolved;
    }

    // ── Reconcile: validate userAction → execution plan ─────────────────────

    /**
     * @param array<string, mixed> $resolved        Fresh resolve output.
     * @param array<string, mixed> $clientResolve   Client's _resolve with userAction set.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{
     *   errorCode: ?string, errorMessage: ?string,
     *   partyCreates: array<string, array<string, mixed>>,
     *   bankCreate: ?array<string, mixed>,
     *   rowItemCreates: array<int, array<string, mixed>>,
     *   rowSkips: list<int>,
     *   resolvedSupplier: ?int, resolvedCustomer: ?int,
     *   resolvedSupplierBank: ?int,
     *   resolvedRowItems: array<int, int|null>,
     *   resolvedRowUnits: array<int, int|null>,
     *   resolvedRowVatCodes: array<int, array<string, mixed>|null>
     * }
     */
    private function reconcile(array $resolved, array $clientResolve, array &$issues): array
    {
        $plan = [
            'errorCode'           => null,
            'errorMessage'        => null,
            'partyCreates'        => [],
            'bankCreate'          => null,
            'rowItemCreates'      => [],
            'rowSkips'            => [],
            'resolvedSupplier'    => null,
            'resolvedCustomer'    => null,
            'resolvedSupplierBank'=> null,
            'resolvedRowItems'    => [],
            'resolvedRowUnits'    => [],
            'resolvedRowVatCodes' => [],
            'resolvedRowAccounts' => [],
            'resolvedRowPartners' => [],
            'resolvedHeadPartner' => null,
        ];

        // Hlavičkový partner účetního dokladu — nepovinný, pin přes
        // _resolve.partner (useExisting:<id>). Bez pinu zůstává null.
        $plan['resolvedHeadPartner'] = $this->resolvePin(
            is_array($clientResolve['partner'] ?? null) ? ($clientResolve['partner']['userAction'] ?? null) : null,
        );

        foreach (['supplier', 'customer'] as $partyKey) {
            $fresh = $resolved[$partyKey] ?? null;
            $client = $clientResolve[$partyKey] ?? null;
            if ($fresh === null) {
                continue;
            }
            $userAction = is_array($client) ? ($client['userAction'] ?? null) : null;
            $res = $this->resolveOne($partyKey, $fresh, $userAction, 'base_persons_persons', $plan, $issues);
            $plan["resolved" . ucfirst($partyKey)] = $res['id'];
            if ($res['autoCreate']) {
                $plan['partyCreates'][$partyKey] = $fresh['createPayload'] ?? [];
            }
        }

        if (isset($resolved['supplierBank'])) {
            $fresh = $resolved['supplierBank'];
            $client = $clientResolve['supplierBank'] ?? null;
            $userAction = is_array($client) ? ($client['userAction'] ?? null) : null;
            $res = $this->resolveOne('supplierBank', $fresh, $userAction, 'base_persons_bank_accounts', $plan, $issues);
            $plan['resolvedSupplierBank'] = $res['id'];
            if ($res['autoCreate']) {
                $plan['bankCreate'] = $fresh['createPayload'] ?? [];
            }
        }

        $clientRows = is_array($clientResolve['rows'] ?? null) ? $clientResolve['rows'] : [];
        foreach ($resolved['rows'] ?? [] as $i => $rowResolve) {
            $clientRow = $clientRows[$i] ?? null;
            $rowUserAction = is_array($clientRow) ? ($clientRow['userAction'] ?? null) : null;
            if ($rowUserAction === 'skip') {
                $plan['rowSkips'][] = $i;
                continue;
            }

            $itemFresh = $rowResolve['item'] ?? null;
            if ($itemFresh !== null) {
                $itemClient = is_array($clientRow['item'] ?? null) ? $clientRow['item'] : null;
                $itemAction = $itemClient['userAction'] ?? null;
                $itemRes = $this->resolveOne("rows.{$i}.item", $itemFresh, $itemAction, 'economy_items', $plan, $issues);
                $plan['resolvedRowItems'][$i] = $itemRes['id'];
                if ($itemRes['autoCreate']) {
                    $plan['rowItemCreates'][$i] = $itemFresh['createPayload'] ?? [];
                }
            } else {
                $plan['resolvedRowItems'][$i] = null;
            }

            $unitFresh = $rowResolve['unit'] ?? null;
            $plan['resolvedRowUnits'][$i] = ($unitFresh['status'] ?? null) === 'matched'
                ? ($unitFresh['matchedId'] ?? null)
                : null;

            $plan['resolvedRowVatCodes'][$i] = $rowResolve['vatCode'] ?? null;

            // Účet z čísla (kontace) — passthrough, žádná userAction (číslo je
            // autoritativní; nenalezeno už dalo warning v resolveAll).
            $accountFresh = $rowResolve['account'] ?? null;
            $plan['resolvedRowAccounts'][$i] = ($accountFresh['status'] ?? null) === 'matched'
                ? ($accountFresh['matchedId'] ?? null)
                : null;

            // Per-řádkový partner — pin přes _resolve.rows[i].partner.
            $plan['resolvedRowPartners'][$i] = $this->resolvePin(
                is_array($clientRow['partner'] ?? null) ? ($clientRow['partner']['userAction'] ?? null) : null,
            );
        }

        return $plan;
    }

    /**
     * Resolve a `useExisting:<id>` pin against active persons. Returns the id
     * when valid + active, null otherwise (no pin / malformed / inactive).
     * Used for the optional accounting-document partners (head + per row),
     * which are pin-only in MVP — no fresh resolve, no side-create.
     */
    private function resolvePin(?string $userAction): ?int
    {
        if (!is_string($userAction) || !str_starts_with($userAction, 'useExisting:')) {
            return null;
        }
        $idStr = substr($userAction, strlen('useExisting:'));
        if (!ctype_digit($idStr) || (int) $idStr <= 0) {
            return null;
        }
        $id = (int) $idStr;
        return $this->entityActive('base_persons_persons', $id) ? $id : null;
    }

    /**
     * Generic per-reference reconcile.
     *
     * Returns {id: ?int, autoCreate: bool}:
     *   - `id`         — resolved DB id, or null when none (error or
     *                    pending side-create).
     *   - `autoCreate` — true when caller should schedule a side-create
     *                    from `$fresh['createPayload']`. Driven by
     *                    explicit `userAction = 'create'` OR by
     *                    `applyOptions.autoCreateMode` (liberal always;
     *                    safe when `safetyGuardOk()` passes).
     *
     * @param array<string, mixed> $fresh Fresh resolve output (toArray shape).
     * @param array<string, mixed> $plan  Modified in place on error.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{id: ?int, autoCreate: bool}
     */
    private function resolveOne(string $path, array $fresh, ?string $userAction, string $existsTable, array &$plan, array &$issues): array
    {
        $status = $fresh['status'] ?? null;

        if ($userAction === null) {
            if ($status === 'matched') {
                return ['id' => $fresh['matchedId'] ?? null, 'autoCreate' => false];
            }
            if ($status === 'canCreate' && $this->autoCreateAllowed($existsTable, $fresh)) {
                return ['id' => null, 'autoCreate' => true];
            }
            if ($status === 'canCreate' || $status === 'ambiguous' || $status === 'notFound') {
                $plan['errorCode'] = 'unresolved_required';
                $plan['errorMessage'] = "Reference „{$path}\" vyžaduje rozhodnutí (userAction).";
                $issues[] = [
                    'severity' => 'error',
                    'path'     => $path,
                    'code'     => 'unresolved_required',
                    'message'  => 'Nelze automaticky propojit; doplňte _resolve.userAction.',
                ];
            }
            return ['id' => null, 'autoCreate' => false];
        }

        if (str_starts_with($userAction, 'useExisting:')) {
            $idStr = substr($userAction, strlen('useExisting:'));
            if (!ctype_digit($idStr) || (int) $idStr <= 0) {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "Neplatné id v userAction pro „{$path}\".";
                return ['id' => null, 'autoCreate' => false];
            }
            $id = (int) $idStr;
            if (!$this->entityActive($existsTable, $id)) {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "Cílový záznam {$existsTable}#{$id} pro „{$path}\" už neexistuje.";
                return ['id' => null, 'autoCreate' => false];
            }
            return ['id' => $id, 'autoCreate' => false];
        }

        if ($userAction === 'create') {
            if ($status !== 'canCreate') {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "userAction=\"create\" pro „{$path}\", ale resolve status je „{$status}\".";
                return ['id' => null, 'autoCreate' => false];
            }
            return ['id' => null, 'autoCreate' => true];
        }

        if ($userAction === 'skip') {
            return ['id' => null, 'autoCreate' => false];
        }

        $plan['errorCode'] = 'conflict';
        $plan['errorMessage'] = "Neznámá userAction „{$userAction}\" pro „{$path}\".";
        return ['id' => null, 'autoCreate' => false];
    }

    /**
     * Decide whether `userAction = null` on a `canCreate` reference should
     * be promoted to autocreate based on applyOptions.autoCreateMode.
     *
     * - `strict` (default) — never.
     * - `liberal`          — always.
     * - `safe`             — only if `$fresh['createPayload']` has enough
     *                        identifiers per {@see safetyGuardOk()}.
     *
     * @param array<string, mixed> $fresh
     */
    private function autoCreateAllowed(string $existsTable, array $fresh): bool
    {
        $mode = $this->applyOptionsCache['autoCreateMode'] ?? 'strict';
        if ($mode === 'liberal') {
            return true;
        }
        if ($mode === 'safe') {
            return $this->safetyGuardOk($existsTable, $fresh);
        }
        return false;
    }

    /**
     * Per-table minimum identifiers required for "safe" autocreate.
     * See exchange-format-phase2.md §"autoCreateMode" guard table.
     *
     * @param array<string, mixed> $fresh
     */
    private function safetyGuardOk(string $existsTable, array $fresh): bool
    {
        $payload = $fresh['createPayload'] ?? [];
        if (!is_array($payload)) {
            return false;
        }
        return match ($existsTable) {
            'base_persons_persons' => !empty($payload['company_id']),
            'economy_items' => !empty($payload['name']),
            'base_persons_bank_accounts' =>
                !empty($payload['iban']) || !empty($payload['account_number']),
            default => false,
        };
    }

    private function entityActive(string $table, int $id): bool
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM %n WHERE [id] = %i AND [docState] IN (%i, %i, %i)',
            $table, $id,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null;
    }

    // ── Side-creates ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resolved
     * @return array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>}
     */
    private function runSideCreates(array $plan, array $resolved): array
    {
        $ids = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];

        foreach (['supplier', 'customer'] as $partyKey) {
            $payload = $plan['partyCreates'][$partyKey] ?? null;
            if (!is_array($payload) || $payload === []) {
                continue;
            }
            $payload['docState'] = 40;
            $payload['docStateMain'] = 3;
            if (!isset($payload['person_type'])) {
                $payload['person_type'] = PersonType::Company->value;
            }
            $result = $this->personsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Side-create person ({$partyKey}) failed: " . ($result->getErrorMessage() ?? ''));
            }
            $ids[$partyKey] = (int) $result->getData()['id'];
        }

        if (is_array($plan['bankCreate'] ?? null)) {
            $bankPayload = $plan['bankCreate'];
            // Re-link bank to a freshly-created supplier if needed.
            if (empty($bankPayload['person']) && $ids['supplier'] !== null) {
                $bankPayload['person'] = $ids['supplier'];
            }
            if (!empty($bankPayload['person'])) {
                // base_persons_bank_accounts has no docState — bypass.
                $this->db->insert('base_persons_bank_accounts', $bankPayload)->execute();
                $ids['supplierBank'] = (int) $this->db->getInsertId();
            }
        }

        foreach ($plan['rowItemCreates'] ?? [] as $rowIdx => $payload) {
            $payload = $this->prepareItemCreatePayload($payload, $plan['resolvedRowUnits'][$rowIdx] ?? null);
            $result = $this->itemsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Side-create item (row {$rowIdx}) failed: " . ($result->getErrorMessage() ?? ''));
            }
            $ids['rowItems'][$rowIdx] = (int) $result->getData()['id'];
        }

        return $ids;
    }

    /**
     * Thin wrapper around `Connection::query()` so subclasses (especially
     * testing doubles) can intercept. `Connection::query()` itself is
     * final and cannot be mocked directly. Pattern matches DocDocument.
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db->query(...$args);
    }

    /**
     * Per-partner item code learning — see docs/exchange-format.md §"Side-
     * creates a per-partner item mapping". Idempotent via unique index.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     */
    private function writeSupplierCodeMappings(array $canonical, array $plan, array $sideIds): void
    {
        $supplierId = $sideIds['supplier'] ?? $plan['resolvedSupplier'] ?? null;
        if ($supplierId === null) {
            return;
        }

        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            if (in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            $supplierCode = $row['item']['supplierCode'] ?? null;
            if (!is_string($supplierCode) || trim($supplierCode) === '') {
                continue;
            }
            $itemId = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
            if ($itemId === null) {
                continue;
            }
            $this->executeSql(
                'INSERT IGNORE INTO [economy_items_supplier_codes]
                 ([person], [item], [supplier_code], [supplier_name], [created])
                 VALUES (%i, %i, %s, %sN, NOW())',
                $supplierId, $itemId, $supplierCode,
                isset($row['item']['name']) ? (string) $row['item']['name'] : null,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareItemCreatePayload(array $payload, ?int $unitId): array
    {
        $payload['docState'] = 40;
        $payload['docStateMain'] = 3;
        if ($unitId !== null) {
            $payload['unit'] = $unitId;
        }
        // item_kind is required by ItemDocument::validate. Pick the first
        // active kind as a generic default — better than failing the save.
        if (empty($payload['item_kind'])) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [economy_items_kinds]
                 WHERE [docState] IN (%i, %i, %i) ORDER BY [id] LIMIT 1',
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row !== null) {
                $payload['item_kind'] = (int) $row['id'];
            }
        }
        // Default unit fallback if not resolved on the row.
        if (empty($payload['unit'])) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [core_units] WHERE [system_code] = %s LIMIT 1',
                'pcs',
            );
            if ($row !== null) {
                $payload['unit'] = (int) $row['id'];
            }
        }
        return $payload;
    }

    // ── Transform canonical → internal $data ───────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @param ?int $numberSeriesId Pre-resolved by apply() (honors numberSeriesCode).
     * @return array<string, mixed>
     */
    private function transform(array $canonical, array $plan, array $sideIds, ?int $numberSeriesId): array
    {
        $docType = $this->mapDocType($canonical);
        $selfParty = $canonical['selfParty'] ?? null;
        $targetDocState = (int) ($canonical['applyOptions']['targetDocState'] ?? 10);

        // Účetní doklad (cmnbkp): hlavičkový partner je nepovinný a žije per
        // řádek; bere se z volitelného pinu (resolvedHeadPartner), bez
        // selfParty resolution. Faktura: partner = ta druhá strana.
        $partnerId = $docType === 'cmnbkp'
            ? ($plan['resolvedHeadPartner'] ?? null)
            : match ($selfParty) {
                'customer' => $sideIds['supplier'] ?? $plan['resolvedSupplier'] ?? null,
                'supplier' => $sideIds['customer'] ?? $plan['resolvedCustomer'] ?? null,
                default    => $sideIds['supplier'] ?? $plan['resolvedSupplier']
                               ?? ($sideIds['customer'] ?? $plan['resolvedCustomer'] ?? null),
            };

        $vatRegistrationId = $this->resolveVatRegistrationFor($canonical);
        $vatMode = self::VAT_MODE_MAP[(string) ($canonical['vat']['mode'] ?? 'fromBase')] ?? 1;
        $vatPlace = self::VAT_PLACE_MAP[(string) ($canonical['vat']['place'] ?? 'domestic')] ?? 0;
        $paymentMethod = self::PAYMENT_METHOD_MAP[(string) ($canonical['payment']['method'] ?? 'bankTransfer')] ?? 1;

        // AI extractors sometimes omit accountingDate even when issueDate
        // is present. DocDocument::beforeSave (applyDateDefaults) would
        // backfill accounting_date from issue_date, but its validate() runs
        // first and rejects an empty accounting_date — so default it here,
        // matching the same Czech accounting practice as the beforeSave
        // hook. The form-based flow still requires accounting_date
        // explicitly (DocsHeadsForm marks it required); this fallback
        // applies only to the Exchange apply path.
        $issueDate = $canonical['dates']['issueDate'] ?? null;
        $accountingDate = $canonical['dates']['accountingDate'] ?? $issueDate;

        // Import mode (legacy migration): the client supplies the document's own
        // number + sequence and (for issued invoices) our own bank account.
        // Both are opt-in — without them apply() behaves exactly as before.
        $importNumber = $canonical['applyOptions']['importNumber'] ?? null;
        $importOwnBank = $canonical['applyOptions']['importOwnBankAccount'] ?? null;

        $data = [
            'doc_type'             => $docType,
            'number_series'        => $numberSeriesId,
            'doc_text'             => $canonical['docText'] ?? null,
            'partner_doc_number'   => $canonical['docNumber'] ?? null,
            'partner'              => $partnerId,
            // Import mode: virtual field consumed + removed by
            // DocDocument::beforeSave. Must NOT reach SQL. Null when not in
            // import mode → dropped by the array_filter below.
            '_importNumber'        => is_array($importNumber) ? [
                'docNumber'      => (string) ($importNumber['docNumber'] ?? ''),
                'sequenceNumber' => (int) ($importNumber['sequenceNumber'] ?? 0),
            ] : null,
            // Import mode: our own bank account (issued invoices need it at
            // state 20+; standard self-party flow can't carry it).
            'bank_account'         => $importOwnBank !== null ? (int) $importOwnBank : null,
            'partner_bank'         => $sideIds['supplierBank'] ?? $plan['resolvedSupplierBank'] ?? null,
            'partner_bank_account' => $canonical['supplier']['bankAccount']['accountNumber'] ?? null,
            'partner_bank_iban'    => $canonical['supplier']['bankAccount']['iban'] ?? null,
            'partner_bank_bic'     => $canonical['supplier']['bankAccount']['bic'] ?? null,
            'issue_date'           => $issueDate,
            'due_date'             => $canonical['dates']['dueDate'] ?? null,
            'accounting_date'      => $accountingDate,
            'vat_duzp'             => $canonical['dates']['taxPointDate'] ?? null,
            'vat_dppd'             => $canonical['dates']['vatObligationDate'] ?? null,
            'period_from'          => $canonical['dates']['periodFrom'] ?? null,
            'period_to'            => $canonical['dates']['periodTo'] ?? null,
            'vat_mode'             => $vatMode,
            'vat_place'            => $vatPlace,
            'vat_registration'    => $vatRegistrationId,
            'doc_currency'         => isset($canonical['currency'])
                                       ? strtolower((string) $canonical['currency'])
                                       : null,
            'exchange_rate'        => $canonical['exchangeRate'] ?? null,
            'payment_method'       => $paymentMethod,
            'payment_reference'    => $canonical['payment']['paymentReference'] ?? null,
            'specific_symbol'      => $canonical['payment']['specificSymbol'] ?? null,
            'constant_symbol'      => $canonical['payment']['constantSymbol'] ?? null,
            'notice'               => $canonical['notes']['internal'] ?? null,
            'doc_notice'           => $canonical['notes']['onDocument'] ?? null,
            'source_kind'          => $canonical['source']['kind'] ?? null,
            'source_extracted_doc' => $canonical['source']['extractedDoc'] ?? null,
            'source_extracted_at'  => $this->mapExtractedAt($canonical['source']['extractedAt'] ?? null),
            'docState'             => $targetDocState,
            'rows'                 => $this->transformRows($canonical['rows'] ?? [], $plan, $sideIds),
        ];

        return array_filter(
            $data,
            static fn($v, $k) => $v !== null || in_array($k, ['rows'], true),
            ARRAY_FILTER_USE_BOTH,
        ) + ['rows' => $data['rows']];
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @return array<int, array<string, mixed>>
     */
    private function transformRows(array $rows, array $plan, array $sideIds): array
    {
        $out = [];
        $orderPos = 0;
        foreach ($rows as $i => $row) {
            if (in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            if (!is_array($row)) continue;

            $orderPos++;
            $itemId = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
            $unitId = $plan['resolvedRowUnits'][$i] ?? null;
            $vat = $plan['resolvedRowVatCodes'][$i] ?? null;
            $vatPct = null;
            $vatCode = null;
            if (is_array($vat) && ($vat['status'] ?? null) === 'matched') {
                $vatPct = $vat['createPayload']['pct'] ?? null;
                $vatCode = $vat['createPayload']['code'] ?? null;
            }

            $out[] = array_filter([
                'row_kind'        => self::ROW_KIND_MAP[(string) ($row['rowKind'] ?? 'item')] ?? 1,
                // Row movement (docs.core.rowOperations). Verbatim passthrough —
                // required for item rows to reach state 40 (DocRowOperationRules).
                // Absent → null → row cannot be confirmed at 40 (caller's job).
                'operation'       => $row['operation'] ?? null,
                'order_pos'       => $orderPos,
                'item'            => $itemId,
                'unit'            => $unitId,
                'quantity'        => $row['quantity'] ?? null,
                'unit_price'      => $row['unitPrice'] ?? null,
                'total_price'     => $row['totalPrice'] ?? null,
                // Kontační řádek (má accSide) účtuje částku přímo → fromTotal,
                // jinak by calculateRowPrice přepsal total_price z qty×unit (0).
                'price_calc_mode' => isset($row['accSide'])
                                      ? 1
                                      : (self::PRICE_CALC_MODE_MAP[(string) ($row['priceCalcMode'] ?? 'fromUnitPrice')] ?? 0),
                'discount_pct'    => $row['discountPct'] ?? null,
                'discount_amount' => $row['discountAmount'] ?? null,
                'vat_code'        => $vatCode,
                'vat_pct'         => $vatPct,
                // Text řádku: faktury ho nesou přes item.description / item.name;
                // účetní doklad (acc.record) item fragment nemá → bere se z
                // řádkové úrovně. Top-level description má přednost.
                'description'     => $row['description']
                                      ?? (is_array($row['item'] ?? null)
                                          ? ($row['item']['description'] ?? $row['item']['name'] ?? null)
                                          : null),
                // Kontace (účetní doklad) — chybí u faktur → array_filter je
                // vynechá, takže faktury jsou beze změny.
                'account'           => $plan['resolvedRowAccounts'][$i] ?? null,
                'acc_side'          => isset($row['accSide'])
                                        ? (['debit' => 0, 'credit' => 1][$row['accSide']] ?? null)
                                        : null,
                'partner'           => $plan['resolvedRowPartners'][$i] ?? null,
                'payment_reference' => $row['paymentReference'] ?? null,
                'specific_symbol'   => $row['specificSymbol'] ?? null,
                'constant_symbol'   => $row['constantSymbol'] ?? null,
                'due_date'          => $row['dueDate'] ?? null,
            ], static fn($v) => $v !== null);
        }
        return $out;
    }

    /**
     * Resolve the target number series for a document.
     *
     *   - $seriesCode !== null → exact match on (doc_type, doc_number_code).
     *     No match throws {@see NumberSeriesNotFoundException}; the caller
     *     maps it to a clean apply-level error. NO silent fallback to the
     *     first active series.
     *   - $seriesCode === null → legacy behaviour: first active series for
     *     the doc_type (backward compatible for clients not sending a code).
     */
    private function resolveNumberSeriesFor(string $docType, ?string $seriesCode = null): ?int
    {
        if ($seriesCode !== null) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [docs_core_number_series]
                 WHERE [doc_type] = %s AND [doc_number_code] = %s AND [docState] IN (%i, %i, %i)
                 ORDER BY [id] LIMIT 1',
                $docType, $seriesCode,
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row === null) {
                throw new NumberSeriesNotFoundException($docType, $seriesCode);
            }
            return (int) $row['id'];
        }

        $row = $this->db->fetch(
            'SELECT [id] FROM [docs_core_number_series]
             WHERE [doc_type] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $docType,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Map canonical docType (descriptive name or short code) → the short code
     * stored in docs_core_heads.doc_type. Passthrough when no alias matches.
     *
     * @param array<string, mixed> $canonical
     */
    private function mapDocType(array $canonical): string
    {
        $raw = (string) ($canonical['docType'] ?? '');
        return self::DOC_TYPE_MAP[$raw] ?? $raw;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function resolveVatRegistrationFor(array $canonical): ?int
    {
        $country = strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        if ($country === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_vat_registrations]
             WHERE [country] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $country,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    private function mapExtractedAt(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Lineage update ─────────────────────────────────────────────────────

    /**
     * Stamp the extracted_document with target_table_id + target_row_ndx
     * (where the canonical went). Status and applied_at are intentionally
     * NOT touched here — those move through ExtractedDocumentDocument hooks
     * in AnalysisController so message auto-transition (30→40) runs.
     * See exchange-format-phase2.md §"Lineage targets vs status update split".
     *
     * @param array<string, mixed> $canonical
     */
    private function writeLineageTargets(array $canonical, int $savedDocId): void
    {
        $extractedDoc = $canonical['source']['extractedDoc'] ?? null;
        if (!is_int($extractedDoc) || $extractedDoc <= 0) {
            return;
        }
        $this->executeSql(
            'UPDATE [core_mail_extracted_documents]
             SET [target_table_id] = %s,
                 [target_row_ndx] = %i
             WHERE [id] = %i',
            'docs_core_heads', $savedDocId, $extractedDoc,
        );
    }

    /**
     * Idempotency pre-check for apply(). If the canonical's
     * source.extractedDoc already has target_row_ndx set AND status = 40
     * (Applied), return the existing savedDocId without re-saving. Saves
     * a duplicate INSERT on retries / double-clicks.
     *
     * @param array<string, mixed> $canonical
     */
    private function checkIdempotent(array $canonical): ?ApplyResult
    {
        $extractedNdx = $canonical['source']['extractedDoc'] ?? null;
        if (!is_int($extractedNdx) || $extractedNdx <= 0) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [target_row_ndx], [status]
             FROM [core_mail_extracted_documents]
             WHERE [id] = %i',
            $extractedNdx,
        );
        if ($row === null || empty($row['target_row_ndx']) || (int) $row['status'] !== 40) {
            return null;
        }
        $existingId = (int) $row['target_row_ndx'];

        $enriched = $canonical;
        $enriched['savedDocId'] = $existingId;
        $enriched['_resolve'] = [
            'summary' => [
                'status'          => 'alreadyApplied',
                'matchedCount'    => 0,
                'unresolvedCount' => 0,
                'ambiguousCount'  => 0,
                'errorCount'      => 0,
            ],
            'issues' => [],
        ];
        return ApplyResult::ok($enriched, $existingId);
    }

    // ── Output enrichment helpers ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolveIssues(array $canonical, array $issues): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['issues'] = array_merge(
            is_array($resolve['issues'] ?? null) ? $resolve['issues'] : [],
            $issues,
        );
        $resolve['summary'] = $this->buildSummary([], $resolve['issues']);
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolve(array $canonical, array $resolved, array $issues): array
    {
        $resolve = $resolved + ['issues' => $issues];
        $resolve['summary'] = $this->buildSummary($resolved, $issues);
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, int|string>
     */
    private function buildSummary(array $resolved, array $issues): array
    {
        $matched = 0;
        $unresolved = 0;
        $ambiguous = 0;
        foreach (['supplier', 'customer', 'supplierBank'] as $key) {
            $status = $resolved[$key]['status'] ?? null;
            if ($status === 'matched') $matched++;
            elseif ($status === 'ambiguous') $ambiguous++;
            elseif ($status === 'notFound' || $status === 'canCreate') $unresolved++;
        }
        foreach ($resolved['rows'] ?? [] as $rowR) {
            foreach (['item', 'unit', 'vatCode'] as $k) {
                $status = $rowR[$k]['status'] ?? null;
                if ($status === 'matched') $matched++;
                elseif ($status === 'ambiguous') $ambiguous++;
                elseif ($status === 'notFound' || $status === 'canCreate') $unresolved++;
            }
        }
        $errors = count(array_filter($issues, static fn($i) => ($i['severity'] ?? null) === 'error'));

        $status = match (true) {
            $errors > 0 || $unresolved > 0 || $ambiguous > 0 => 'needsAttention',
            default => 'ok',
        };
        return [
            'status'          => $status,
            'matchedCount'    => $matched,
            'unresolvedCount' => $unresolved,
            'ambiguousCount'  => $ambiguous,
            'errorCount'      => $errors,
        ];
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? null) === 'error') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @return array<string, mixed>
     */
    private function annotateSideCreated(array $resolved, array $sideIds): array
    {
        foreach (['supplier', 'customer', 'supplierBank'] as $key) {
            if (($resolved[$key]['status'] ?? null) === 'canCreate' && ($sideIds[$key] ?? null) !== null) {
                $resolved[$key]['status'] = 'matched';
                $resolved[$key]['matchedId'] = $sideIds[$key];
                $resolved[$key]['matchedBy'] = 'created';
            }
        }
        foreach ($resolved['rows'] ?? [] as $i => $rowR) {
            if (($rowR['item']['status'] ?? null) === 'canCreate' && isset($sideIds['rowItems'][$i])) {
                $resolved['rows'][$i]['item']['status'] = 'matched';
                $resolved['rows'][$i]['item']['matchedId'] = $sideIds['rowItems'][$i];
                $resolved['rows'][$i]['item']['matchedBy'] = 'created';
            }
        }
        return $resolved;
    }
}
