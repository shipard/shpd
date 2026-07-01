<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/**
 * Sdílené jádro apply flow extrahovaných dokladů — HTTP-agnostické. Vytvoří
 * doklad v cílové tabulce (default Koncept) z kanonického `extracted_json`
 * a označí extracted jako `applied` (spustí auto-transition zprávy 30→40).
 *
 * Vytaženo z `AnalysisController::applyExtracted`, aby HTTP endpoint i MCP
 * nástroj `mail_draft_document` jely jedním kódem. Controller je nad službou
 * tenká slupka (parse body / auth / mapování na Response); nástroj nad ní
 * staví LLM obálku. Chování HTTP endpointu se refaktorem nemění.
 */
final class ExtractedDocumentApplier
{
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';

    /** Statusy, ze kterých lze apply/reject (10/20/30). */
    private const PENDING_STATES = [
        ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
        ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
        ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
    ];

    private const HEADS_TABLE = 'docs_core_heads';

    /** Doc-state cíle apply (docs.core.docStates): Koncept / Koš. */
    private const DOC_STATE_DRAFT = 10;
    private const DOC_STATE_TRASH = 90;
    private const DOC_STATE_TRASH_MAIN = 5;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DocumentApplier $applier,
    ) {}

    /**
     * Aplikuje jeden extrahovaný doklad. Zachovává všechny větve dřívějšího
     * `applyExtracted`: ai_failed → `AI_OUTPUT_INVALID`; `target_row_ndx>0` →
     * idempotent/recovery; ne-pending status → `INVALID_STATE`; jinak parse
     * canonical, inject `source`, merge `_resolve`, autoCreateMode/targetDocState,
     * `applier->apply()`, při úspěchu status→applied (afterPersist 30→40).
     *
     * @param array<string, mixed>|null $clientResolveFlat  flat {path: userAction}, nebo null
     * @param array<string, mixed>      $applyOptionsOverride  autoCreateMode / targetDocState
     */
    public function apply(
        int $extractedNdx,
        ?int $userId,
        ?array $clientResolveFlat,
        array $applyOptionsOverride = [],
    ): ExtractedApplyOutcome {
        $existing = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::EXTRACTED_TABLE, $extractedNdx,
        );
        if ($existing === null) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, 0, 'NOT_FOUND',
                "Extracted document {$extractedNdx} not found", 404,
            );
        }

        $messageNdx = (int) ($existing['message'] ?? 0);
        $currentStatus = (int) $existing['status'];

        // ai_failed → cannot apply. User must reanalyze.
        if ($currentStatus === ExtractedDocumentDocument::STATUS_AI_FAILED) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'AI_OUTPUT_INVALID',
                'AI extrakce neproběhla úspěšně, použij reanalýzu.', 422,
            );
        }

        // Recovery / idempotency — apply ran earlier (target_row_ndx set).
        $targetRowNdx = isset($existing['target_row_ndx']) ? (int) $existing['target_row_ndx'] : 0;
        if ($targetRowNdx > 0) {
            return $this->completeApplied($existing, $extractedNdx, $messageNdx, $userId, $targetRowNdx);
        }

        if (!in_array($currentStatus, self::PENDING_STATES, true)) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'INVALID_STATE',
                'Document is not in a pending state (10/20/30)', 409,
            );
        }

        $canonical = json_decode((string) ($existing['extracted_json'] ?? ''), true);
        if (!is_array($canonical)) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'CORRUPTED_DATA',
                'extracted_json cannot be parsed', 500,
            );
        }

        // Server-controlled injection — never trust client-supplied source.
        $source = is_array($canonical['source'] ?? null) ? $canonical['source'] : [];
        $source['extractedDoc'] = $extractedNdx;
        if (empty($source['kind'])) {
            $source['kind'] = 'aiExtraction';
        }
        $canonical['source'] = $source;

        // Merge client userActions into canonical _resolve.
        if ($clientResolveFlat !== null) {
            $expanded = self::expandUserActions($clientResolveFlat);
            $canonical['_resolve'] = self::mergeUserActions(
                is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [],
                $expanded,
            );
        }

        // autoCreateMode: explicit override wins; else strict when client sent
        // a _resolve map, safe otherwise (= dřívější $hasResolveKey logika).
        $autoCreateMode = $applyOptionsOverride['autoCreateMode']
            ?? ($clientResolveFlat !== null ? 'strict' : 'safe');
        $targetDocState = isset($applyOptionsOverride['targetDocState'])
            ? (int) $applyOptionsOverride['targetDocState']
            : 10;
        $canonical['applyOptions'] = [
            'autoCreateMode' => $autoCreateMode,
            'targetDocState' => $targetDocState,
        ];

        $result = $this->applier->apply($canonical);
        if (!$result->success) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx,
                $result->errorCode ?? 'INTERNAL_ERROR',
                $result->errorMessage ?? 'Apply failed',
                $result->statusCode,
                $result->canonical,
            );
        }

        $savedDocId = (int) ($result->savedId ?? 0);

        // Mark applied via the Document flow → message 30→40 auto-transition.
        // If it fails, the doc is already saved (target_row_ndx set) and the
        // user can retry through the recovery path above — so we log and still
        // report success, matching the prior controller behaviour.
        $write = self::writeStatusTransition(
            $this->db, $extractedNdx, $userId,
            ExtractedDocumentDocument::STATUS_APPLIED, null,
        );
        if (!$write->ok) {
            ErrorLogger::warn('ExtractedDocumentApplier::apply status update failed after successful apply', [
                'extractedNdx' => $extractedNdx,
                'savedDocId' => $savedDocId,
            ]);
        }

        return ExtractedApplyOutcome::ok($extractedNdx, $messageNdx, $savedDocId, $result->canonical);
    }

    /**
     * Re-entry pro už (částečně) aplikovaný doklad: status 40 → idempotent;
     * jinak doběhne status update (recovery po laggnutém zápisu statusu).
     *
     * @param array<string, mixed> $existing
     */
    private function completeApplied(
        array $existing,
        int $extractedNdx,
        int $messageNdx,
        ?int $userId,
        int $savedDocId,
    ): ExtractedApplyOutcome {
        if ((int) $existing['status'] === ExtractedDocumentDocument::STATUS_APPLIED) {
            return ExtractedApplyOutcome::ok($extractedNdx, $messageNdx, $savedDocId, null, idempotent: true);
        }

        $write = self::writeStatusTransition(
            $this->db, $extractedNdx, $userId,
            ExtractedDocumentDocument::STATUS_APPLIED, null,
        );
        if (!$write->ok) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx,
                $write->errorCode ?? 'INTERNAL_ERROR',
                $write->errorMessage, $write->statusCode,
            );
        }
        return ExtractedApplyOutcome::ok($extractedNdx, $messageNdx, $savedDocId, null, recovered: true);
    }

    /**
     * Vrátí aplikovaný extracted doklad (undo apply): cílový Koncept přesune do
     * Koše a extracted vrátí na `pending_review`. Vratná operace, nikdy tvrdě
     * nemaže doklad.
     *
     * Guard (dle `docs/dashboard.md`):
     *   - extracted musí být `applied` (40) s `target_row_ndx > 0`, jinak 409
     *     `INVALID_STATE`;
     *   - cílový doklad musí být **stále nedotčený Koncept** (`docState=10`),
     *     jinak 409 `DOC_ADVANCED` (uživatel řeší ručně).
     *
     * Doklad → Koš (`docState=90`) přes Document flow (`saveDocument`), extracted
     * → 20 + vynulování `target_row_ndx`/`applied_*` a zpráva 40→30 přes reverzní
     * reconcile ({@see writeUnapplyTransition}). Zrcadlí neatomicitu apply:
     * doc-save a status-write jsou dvě transakce; při selhání druhé je doklad
     * už v koši (vratné ručně) a chyba se reportuje.
     *
     * `$headsGateway` je předaný zvenčí (controller ho staví se všemi
     * závislostmi jako `FormController::applyStateTransitionViaDocument`), takže
     * unapply nezávisí na `DocumentApplier` — proto static.
     */
    public static function unapply(
        DataSourceConnection $db,
        int $extractedNdx,
        ?int $userId,
        TableGateway $headsGateway,
    ): ExtractedApplyOutcome {
        $existing = $db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::EXTRACTED_TABLE, $extractedNdx,
        );
        if ($existing === null) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, 0, 'NOT_FOUND',
                "Extracted document {$extractedNdx} not found", 404,
            );
        }

        $messageNdx = (int) ($existing['message'] ?? 0);

        if ((int) $existing['status'] !== ExtractedDocumentDocument::STATUS_APPLIED) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'INVALID_STATE',
                'Extracted document is not applied (status 40)', 409,
            );
        }
        $targetDocId = isset($existing['target_row_ndx']) ? (int) $existing['target_row_ndx'] : 0;
        if ($targetDocId <= 0) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'INVALID_STATE',
                'Applied document has no target record', 409,
            );
        }

        // Cíl musí být stále nedotčený Koncept — jinak řeší uživatel ručně.
        $doc = $headsGateway->loadDocument($targetDocId);
        if ($doc === null) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'DOC_ADVANCED',
                'Target document no longer exists', 409,
            );
        }
        if ((int) ($doc['docState'] ?? 0) !== self::DOC_STATE_DRAFT) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'DOC_ADVANCED',
                'Target document is no longer an untouched draft', 409,
            );
        }

        // 1. Doklad → Koš (soft-delete, vratné). Koncept nespotřeboval číslo
        //    (přiděluje se až 10→20), takže není co vracet.
        $doc['docState']     = self::DOC_STATE_TRASH;
        $doc['docStateMain'] = self::DOC_STATE_TRASH_MAIN;
        $result = $headsGateway->saveDocument($doc);
        if (!$result->isSuccess()) {
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx, 'INTERNAL_ERROR',
                $result->getErrorMessage() ?? 'Failed to trash target document', 500,
            );
        }

        // 2. Extracted → pending_review, vynulovat target/applied_*, zpráva 40→30.
        $write = self::writeUnapplyTransition($db, $extractedNdx);
        if (!$write->ok) {
            ErrorLogger::warn('ExtractedDocumentApplier::unapply status update failed after trashing doc', [
                'extractedNdx' => $extractedNdx,
                'targetDocId'  => $targetDocId,
            ]);
            return ExtractedApplyOutcome::error(
                $extractedNdx, $messageNdx,
                $write->errorCode ?? 'INTERNAL_ERROR',
                $write->errorMessage, $write->statusCode,
            );
        }

        return ExtractedApplyOutcome::ok($extractedNdx, $messageNdx, $targetDocId, null);
    }

    /**
     * Zapíše undo přechod extracted dokladu 40 → 20 (pending_review) přes
     * Document hooky v jedné transakci: vynuluje `target_row_ndx`/`applied_*`
     * a reverzně reconciluje zprávu (40→30, opak apply). Oddělená od
     * {@see writeStatusTransition}, protože ta cíleně povoluje jen přechody
     * z pending stavů (10/20/30) — unapply je jediná legitimní cesta z
     * `applied` (40) zpět.
     */
    public static function writeUnapplyTransition(
        DataSourceConnection $db,
        int $extractedNdx,
    ): StatusWriteResult {
        $existing = $db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::EXTRACTED_TABLE, $extractedNdx,
        );
        if ($existing === null) {
            return StatusWriteResult::notFound();
        }

        $messageNdx = (int) ($existing['message'] ?? 0);
        if ((int) $existing['status'] !== ExtractedDocumentDocument::STATUS_APPLIED) {
            return StatusWriteResult::invalidState($messageNdx);
        }

        $dibi = $db->getDibiConnection();
        $doc = new ExtractedDocumentDocument();
        $doc->setDb($dibi);

        $data = $existing;
        $data['status']         = ExtractedDocumentDocument::STATUS_PENDING_REVIEW;
        $data['target_row_ndx'] = null;
        $data['applied_at']     = null;
        $data['applied_by']     = null;

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $errors = array_map(
                static fn($e) => ['field' => $e->column, 'message' => $e->message, 'code' => $e->code],
                $validation->getErrors(),
            );
            return StatusWriteResult::validationFailed($errors, $messageNdx);
        }

        $dibi->begin();
        try {
            $doc->beforeSave($data);

            $writableData = $data;
            unset($writableData['id']);
            $dibi->update(self::EXTRACTED_TABLE, $writableData)
                ->where('id = %i', $extractedNdx)
                ->execute();

            // Reverzní reconcile zprávy (40→30) uvnitř transakce.
            $doc->reconcileMessageAfterUnapply($messageNdx);

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            ErrorLogger::warn('ExtractedDocumentApplier::writeUnapplyTransition failed', [
                'error' => $e->getMessage(),
            ]);
            return StatusWriteResult::internalError($messageNdx);
        }

        return StatusWriteResult::ok($messageNdx, ExtractedDocumentDocument::STATUS_PENDING_REVIEW);
    }

    /**
     * Sdílená primitiva: zapíše nový status extracted dokladu přes Document
     * hooky (validate / beforeSave / DB update / afterPersist) v jedné
     * transakci. Response-free → použitelná i bez DocumentApplier (reject,
     * no-applier fallback).
     */
    public static function writeStatusTransition(
        DataSourceConnection $db,
        int $extractedNdx,
        ?int $userId,
        int $newStatus,
        ?string $rejectedReason,
    ): StatusWriteResult {
        $existing = $db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::EXTRACTED_TABLE, $extractedNdx,
        );
        if ($existing === null) {
            return StatusWriteResult::notFound();
        }

        $messageNdx = (int) ($existing['message'] ?? 0);
        if (!in_array((int) $existing['status'], self::PENDING_STATES, true)) {
            return StatusWriteResult::invalidState($messageNdx);
        }

        $dibi = $db->getDibiConnection();
        $doc = new ExtractedDocumentDocument();
        $doc->setDb($dibi);

        $data = $existing;
        $data['status'] = $newStatus;
        if ($rejectedReason !== null) {
            $data['rejected_reason'] = $rejectedReason;
        }
        if ($newStatus === ExtractedDocumentDocument::STATUS_APPLIED) {
            $data['applied_by'] = $userId;
            // applied_at nastaví beforeSave
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $errors = array_map(
                static fn($e) => ['field' => $e->column, 'message' => $e->message, 'code' => $e->code],
                $validation->getErrors(),
            );
            return StatusWriteResult::validationFailed($errors, $messageNdx);
        }

        $dibi->begin();
        try {
            $doc->beforeSave($data);

            $writableData = $data;
            unset($writableData['id']);
            $dibi->update(self::EXTRACTED_TABLE, $writableData)
                ->where('id = %i', $extractedNdx)
                ->execute();

            // afterPersist běží uvnitř transakce — auto-transition zprávy 30→40
            $doc->afterPersist($data);

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            ErrorLogger::warn('ExtractedDocumentApplier::writeStatusTransition failed', [
                'error' => $e->getMessage(),
            ]);
            return StatusWriteResult::internalError($messageNdx);
        }

        return StatusWriteResult::ok($messageNdx, $newStatus);
    }

    /**
     * Expand flat client-side userActions into nested `_resolve` shape
     * expected by DocumentApplier.
     *
     *   {"supplier": "useExisting:42", "rows[0].item": "create"}
     *   → {"supplier": {"userAction": "useExisting:42"},
     *      "rows": [{"item": {"userAction": "create"}}]}
     *
     * Unknown paths are silently ignored — applier handles unresolved refs
     * via its normal reconcile flow.
     *
     * @param array<string, mixed> $flat
     * @return array<string, mixed>
     */
    public static function expandUserActions(array $flat): array
    {
        $expanded = [];
        foreach ($flat as $path => $action) {
            if (!is_string($action)) {
                continue;
            }
            if (preg_match('/^rows\[(\d+)\]\.(item|unit|vatCode)$/', (string) $path, $m)) {
                $idx = (int) $m[1];
                $field = $m[2];
                $expanded['rows'][$idx][$field]['userAction'] = $action;
                continue;
            }
            if (in_array($path, ['supplier', 'customer', 'supplierBank', 'customerBank'], true)) {
                $expanded[$path]['userAction'] = $action;
            }
        }
        return $expanded;
    }

    /**
     * Deep-merge userAction overrides into existing canonical `_resolve`.
     * Only `userAction` keys are touched; status/candidates/createPayload
     * stay as-is (the applier re-resolves and overwrites them anyway).
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function mergeUserActions(array $existing, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($key === 'rows' && is_array($value)) {
                $existing['rows'] = is_array($existing['rows'] ?? null) ? $existing['rows'] : [];
                foreach ($value as $idx => $rowOverride) {
                    if (!is_array($rowOverride)) continue;
                    $existing['rows'][$idx] = is_array($existing['rows'][$idx] ?? null)
                        ? $existing['rows'][$idx]
                        : [];
                    foreach ($rowOverride as $field => $fieldOverride) {
                        if (!is_array($fieldOverride) || !isset($fieldOverride['userAction'])) {
                            continue;
                        }
                        $existing['rows'][$idx][$field] = is_array($existing['rows'][$idx][$field] ?? null)
                            ? $existing['rows'][$idx][$field]
                            : [];
                        $existing['rows'][$idx][$field]['userAction'] = $fieldOverride['userAction'];
                    }
                }
                continue;
            }
            if (is_array($value) && isset($value['userAction'])) {
                $existing[$key] = is_array($existing[$key] ?? null) ? $existing[$key] : [];
                $existing[$key]['userAction'] = $value['userAction'];
            }
        }
        return $existing;
    }
}
