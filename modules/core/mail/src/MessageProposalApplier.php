<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;

/**
 * Sdílené jádro message-centrických akcí nad dokumentovým návrhem — HTTP
 * agnostické. Operuje nad **poslední úspěšnou analýzou** zprávy
 * (MAX(analyzed_at), status=2); verdikt uživatele (apply/reject) zapisuje
 * na řádek analýzy (`resolution`), lineage na zprávu (`target_*`) a doklad
 * (`source_message`). Viz tasks/mail-message-centric.md (D2, D3, D6).
 *
 * HTTP endpoint i MCP nástroj `mail_draft_document` jedou jedním kódem;
 * controller je nad službou tenká slupka (parse body / auth / mapování na
 * Response).
 *
 * Target seam: podle `primaryTypes[proposed_type].target` se apply/unapply
 * větví — `docs` (default) jede přes exchange DocumentApplier (enrichment,
 * `_resolve`, applyOptions); ostatní targety deleguje na mapu
 * `$targetAppliers` ({@see ProposalTargetApplier}). Sdílený zápis verdiktu
 * (resolution + docState zprávy) je pro všechny targety společný.
 */
final class MessageProposalApplier
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';

    /** Verdikt návrhu (core.mail.analysisResolutions). */
    public const RESOLUTION_APPLIED = 40;
    public const RESOLUTION_REJECTED = 50;

    // Workflow stavy zprávy (core.mail.docStatesIncoming).
    private const MSG_STATE_NEW = 10;
    private const MSG_STATE_IN_PROGRESS = 20;
    private const MSG_STATE_DONE = 40;
    private const MSG_STATE_ARCHIVED = 80;
    private const MSG_STATE_TRASH = 90;
    private const MSG_STATE_MAIN_IN_PROGRESS = 2;
    private const MSG_STATE_MAIN_DONE = 3;

    /** analysis_state, ve kterém jsou akce nad návrhem povolené (Analyzováno). */
    private const ANALYSIS_ANALYZED = 30;

    /** Doc-state cíle docs apply (docs.core.docStates): Koncept / Koš. */
    private const DOC_STATE_DRAFT = 10;
    private const DOC_STATE_TRASH = 90;
    private const DOC_STATE_TRASH_MAIN = 5;

    /**
     * `$applier` je nullable kvůli konstrukci pro unapply-only / registry-only
     * použití (docs apply bez něj vrací INTERNAL_ERROR). `$headsGateway`
     * potřebuje jen docs větev unapply — staví ho controller (se všemi
     * závislostmi jako `FormController::applyStateTransitionViaDocument`).
     *
     * @param array<string, ProposalTargetApplier> $targetAppliers mapa target => applier (bez `docs`)
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?DocumentApplier $applier,
        private readonly ?RowEnrichmentPipeline $enricher = null,
        private readonly ?ConfigRuntime $config = null,
        private readonly array $targetAppliers = [],
        private readonly ?TableGateway $headsGateway = null,
    ) {}

    /**
     * Poslední úspěšná analýza zprávy (status=2) — nositel aktuálního
     * návrhu (D2: žádný flag, konvence MAX(analyzed_at)).
     *
     * @return array<string, mixed>|null
     */
    public function latestSuccessfulAnalysis(int $messageNdx): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE message = %i AND status = %i'
            . ' ORDER BY analyzed_at DESC, id DESC LIMIT 1',
            self::ANALYSES_TABLE, $messageNdx, 2,
        );
    }

    /**
     * Aplikuje dokumentový návrh poslední analýzy zprávy. Guardy:
     * zpráva mimo Archiv/Koš, analysis_state=30, otevřený návrh
     * (resolution IS NULL), validní canonical. `message.target_row`
     * obsazený → idempotent/recovery cesta.
     *
     * @param array<string, mixed>|null $clientResolveFlat  flat {path: userAction}, nebo null
     * @param array<string, mixed>      $applyOptionsOverride  autoCreateMode / targetDocState
     */
    public function apply(
        int $messageNdx,
        ?int $userId,
        ?array $clientResolveFlat,
        array $applyOptionsOverride = [],
    ): ProposalApplyOutcome {
        $message = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::MESSAGES_TABLE, $messageNdx,
        );
        if ($message === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'NOT_FOUND',
                "Message {$messageNdx} not found", 404,
            );
        }

        $docState = (int) ($message['docState'] ?? 0);
        if ($docState === self::MSG_STATE_ARCHIVED || $docState === self::MSG_STATE_TRASH) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'INVALID_STATE',
                'Message is archived or trashed', 409,
            );
        }

        $analysis = $this->latestSuccessfulAnalysis($messageNdx);
        if ($analysis === null || (int) ($message['analysis_state'] ?? 0) !== self::ANALYSIS_ANALYZED) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'INVALID_STATE',
                'Message has no completed analysis (analysis_state != 30)', 409,
            );
        }
        $analysisNdx = (int) $analysis['id'];

        // Recovery / idempotency — apply už dřív zapsal target na zprávu.
        $targetRow = isset($message['target_row']) ? (int) $message['target_row'] : 0;
        if ($targetRow > 0) {
            return $this->completeApplied($analysis, $messageNdx, $userId, $targetRow);
        }

        if ($analysis['resolution'] !== null) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INVALID_STATE',
                'Proposal is already resolved (applied or rejected)', 409,
            );
        }

        if ($analysis['canonical_json'] === null || $analysis['canonical_json'] === '') {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'NO_PROPOSAL',
                'Latest analysis produced no document proposal', 422,
            );
        }

        $canonical = json_decode((string) $analysis['canonical_json'], true);
        if (!is_array($canonical)) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'CORRUPTED_DATA',
                'canonical_json cannot be parsed', 500,
            );
        }

        // ai_failed wrapper → cannot apply. User must reanalyze.
        if (isset($canonical['_validationError'])) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'AI_OUTPUT_INVALID',
                'AI extrakce neproběhla úspěšně, použij reanalýzu.', 422,
            );
        }

        // Routing podle typu návrhu běhu — závazný je proposed_type, ne
        // aktuální message.primary_type (ten je mutable, nesoulad apply
        // neblokuje).
        $proposedType = (string) ($analysis['proposed_type'] ?? '');
        $target = PrimaryTypes::targetFor($this->config, $proposedType);
        if ($target !== PrimaryTypes::TARGET_DOCS) {
            return $this->applyViaTarget($target, $canonical, $message, $proposedType, $analysisNdx, $userId);
        }

        if ($this->applier === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                'Document applier unavailable', 500,
            );
        }

        // Server-controlled injection — never trust client-supplied source.
        $source = is_array($canonical['source'] ?? null) ? $canonical['source'] : [];
        $source['message'] = $messageNdx;
        if (empty($source['kind'])) {
            $source['kind'] = 'aiExtraction';
        }
        $canonical['source'] = $source;

        // Fresh obohacení řádků (historie + obsahové štítky, bez LLM) —
        // před merge userActions, takže klientovy piny mají v reconcile
        // fázi applieru přednost. Selhání enrichmentu apply nikdy neblokuje.
        if ($this->enricher !== null) {
            try {
                $canonical = $this->enricher->enrichFresh($canonical);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'MessageProposalApplier::apply row history enrichment failed');
            }
        }

        // Merge client userActions into canonical _resolve.
        if ($clientResolveFlat !== null) {
            $expanded = self::expandUserActions($clientResolveFlat);
            $canonical['_resolve'] = self::mergeUserActions(
                is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [],
                $expanded,
            );
        }

        // autoCreateMode: explicit override wins; else strict when client sent
        // a _resolve map, safe otherwise.
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
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx,
                $result->errorCode ?? 'INTERNAL_ERROR',
                $result->errorMessage ?? 'Apply failed',
                $result->statusCode,
                $result->canonical,
            );
        }

        $savedDocId = (int) ($result->savedId ?? 0);

        // Verdikt: resolution=40 na analýze + zpráva → Hotovo. Když selže,
        // doklad už existuje a zpráva má target_row (zapsal DocumentApplier
        // v transakci uložení) — opakovaný apply doběhne přes recovery cestu
        // výše, proto jen warn a hlásí se úspěch.
        if (!$this->writeApplyResolution($analysisNdx, $messageNdx, $userId)) {
            ErrorLogger::warn('MessageProposalApplier::apply resolution write failed after successful apply', [
                'messageNdx' => $messageNdx,
                'analysisNdx' => $analysisNdx,
                'savedDocId' => $savedDocId,
            ]);
        }

        return ProposalApplyOutcome::ok($messageNdx, $analysisNdx, $savedDocId, $result->canonical);
    }

    /**
     * Apply přes registrovaný target applier (ne-docs cesta). Target applier
     * založí cílový záznam včetně zápisu `target_*` na zprávu; verdikt jde
     * sdílenou mašinerií (stejné warn-only chování jako docs — recovery
     * cesta doběhne verdikt později).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $message
     */
    private function applyViaTarget(
        string $target,
        array $canonical,
        array $message,
        string $proposedType,
        int $analysisNdx,
        ?int $userId,
    ): ProposalApplyOutcome {
        $messageNdx = (int) $message['id'];
        $targetApplier = $this->targetAppliers[$target] ?? null;
        if ($targetApplier === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                "No applier wired for proposal target '{$target}'", 500,
            );
        }

        $result = $targetApplier->apply($canonical, $message, $proposedType, $userId);
        if (!$result->success) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx,
                $result->errorCode ?? 'INTERNAL_ERROR',
                $result->errorMessage ?? 'Apply failed',
                $result->statusCode,
            );
        }

        $savedId = (int) ($result->savedId ?? 0);
        if (!$this->writeApplyResolution($analysisNdx, $messageNdx, $userId)) {
            ErrorLogger::warn('MessageProposalApplier::apply resolution write failed after successful apply', [
                'messageNdx' => $messageNdx,
                'analysisNdx' => $analysisNdx,
                'target' => $target,
                'savedDocId' => $savedId,
            ]);
        }

        return ProposalApplyOutcome::ok($messageNdx, $analysisNdx, $savedId, $canonical);
    }

    /**
     * Re-entry pro už (částečně) aplikovaný návrh: resolution=40 →
     * idempotent; jinak doběhne zápis verdiktu (recovery po laggnutém
     * zápisu resolution).
     *
     * @param array<string, mixed> $analysis
     */
    private function completeApplied(
        array $analysis,
        int $messageNdx,
        ?int $userId,
        int $savedDocId,
    ): ProposalApplyOutcome {
        $analysisNdx = (int) $analysis['id'];
        if ((int) ($analysis['resolution'] ?? 0) === self::RESOLUTION_APPLIED) {
            return ProposalApplyOutcome::ok($messageNdx, $analysisNdx, $savedDocId, null, idempotent: true);
        }

        if (!$this->writeApplyResolution($analysisNdx, $messageNdx, $userId)) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                'Resolution write failed', 500,
            );
        }
        return ProposalApplyOutcome::ok($messageNdx, $analysisNdx, $savedDocId, null, recovered: true);
    }

    /**
     * Zamítne dokumentový návrh poslední analýzy: resolution=50 +
     * rejected_reason + resolved_at/by; zpráva → Hotovo (symetricky
     * s apply — uživatel může následně Koš/Archiv). Reanalýza po rejectu
     * zůstává možná (vznikne nový běh s resolution NULL).
     */
    public function reject(int $messageNdx, ?int $userId, string $reason): ProposalApplyOutcome
    {
        $message = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::MESSAGES_TABLE, $messageNdx,
        );
        if ($message === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'NOT_FOUND',
                "Message {$messageNdx} not found", 404,
            );
        }

        $docState = (int) ($message['docState'] ?? 0);
        if ($docState === self::MSG_STATE_ARCHIVED || $docState === self::MSG_STATE_TRASH) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'INVALID_STATE',
                'Message is archived or trashed', 409,
            );
        }

        $analysis = $this->latestSuccessfulAnalysis($messageNdx);
        if ($analysis === null || (int) ($message['analysis_state'] ?? 0) !== self::ANALYSIS_ANALYZED) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'INVALID_STATE',
                'Message has no completed analysis (analysis_state != 30)', 409,
            );
        }
        $analysisNdx = (int) $analysis['id'];

        if ($analysis['resolution'] !== null) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INVALID_STATE',
                'Proposal is already resolved (applied or rejected)', 409,
            );
        }

        if (!$this->writeRejectResolution($analysisNdx, $messageNdx, $userId, $reason)) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                'Resolution write failed', 500,
            );
        }

        return ProposalApplyOutcome::ok($messageNdx, $analysisNdx, null, null);
    }

    /**
     * Vrátí aplikovaný návrh (undo apply): cílovou entitu soft-deletne
     * (docs Koncept → Koš, registry dle target applieru), vynuluje
     * `message.target_*`, resolution analýzy → NULL a zprávu vrátí
     * 40 → 20 (K řešení). Vratná operace, nikdy tvrdě nemaže.
     *
     * Guard: poslední analýza `resolution=40` s `message.target_row > 0`;
     * docs cíl musí být **stále nedotčený Koncept** (`docState=10`), jinak
     * 409 `DOC_ADVANCED` (uživatel řeší ručně).
     */
    public function unapply(int $messageNdx, ?int $userId): ProposalApplyOutcome
    {
        $message = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::MESSAGES_TABLE, $messageNdx,
        );
        if ($message === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, null, 'NOT_FOUND',
                "Message {$messageNdx} not found", 404,
            );
        }

        $analysis = $this->latestSuccessfulAnalysis($messageNdx);
        if ($analysis === null
            || (int) ($analysis['resolution'] ?? 0) !== self::RESOLUTION_APPLIED
        ) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysis !== null ? (int) $analysis['id'] : null, 'INVALID_STATE',
                'Latest proposal is not applied (resolution != 40)', 409,
            );
        }
        $analysisNdx = (int) $analysis['id'];

        $targetDocId = isset($message['target_row']) ? (int) $message['target_row'] : 0;
        if ($targetDocId <= 0) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INVALID_STATE',
                'Applied proposal has no target record', 409,
            );
        }

        // Non-docs target → guard + trash deleguje target applier; sdílený
        // unapply přechod (resolution NULL, target_* NULL, zpráva reverz) níže.
        $proposedType = (string) ($analysis['proposed_type'] ?? '');
        $target = PrimaryTypes::targetFor($this->config, $proposedType);
        if ($target !== PrimaryTypes::TARGET_DOCS) {
            $targetApplier = $this->targetAppliers[$target] ?? null;
            if ($targetApplier === null) {
                return ProposalApplyOutcome::error(
                    $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                    "No applier wired for proposal target '{$target}'", 500,
                );
            }
            $result = $targetApplier->unapply($targetDocId, $analysis['resolved_at'] ?? null);
            if (!$result->success) {
                return ProposalApplyOutcome::error(
                    $messageNdx, $analysisNdx,
                    $result->errorCode ?? 'INTERNAL_ERROR',
                    $result->errorMessage ?? 'Unapply failed',
                    $result->statusCode,
                );
            }
            return $this->finishUnapply($analysisNdx, $messageNdx, (int) ($result->trashedId ?? $targetDocId));
        }

        if ($this->headsGateway === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                'Document gateway unavailable', 500,
            );
        }

        // Cíl musí být stále nedotčený Koncept — jinak řeší uživatel ručně.
        $doc = $this->headsGateway->loadDocument($targetDocId);
        if ($doc === null) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'DOC_ADVANCED',
                'Target document no longer exists', 409,
            );
        }
        if ((int) ($doc['docState'] ?? 0) !== self::DOC_STATE_DRAFT) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'DOC_ADVANCED',
                'Target document is no longer an untouched draft', 409,
            );
        }

        // 1. Doklad → Koš (soft-delete, vratné). Koncept nespotřeboval číslo
        //    (přiděluje se až 10→20), takže není co vracet.
        $doc['docState']     = self::DOC_STATE_TRASH;
        $doc['docStateMain'] = self::DOC_STATE_TRASH_MAIN;
        $result = $this->headsGateway->saveDocument($doc);
        if (!$result->isSuccess()) {
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                $result->getErrorMessage() ?? 'Failed to trash target document', 500,
            );
        }

        // 2. Resolution NULL, target_* NULL, zpráva 40→20.
        return $this->finishUnapply($analysisNdx, $messageNdx, $targetDocId);
    }

    /**
     * Sdílené dokončení unapply po úspěšném úklidu cíle (libovolný target).
     */
    private function finishUnapply(int $analysisNdx, int $messageNdx, int $targetDocId): ProposalApplyOutcome
    {
        if (!$this->writeUnapplyTransition($analysisNdx, $messageNdx)) {
            ErrorLogger::warn('MessageProposalApplier::unapply transition failed after trashing doc', [
                'messageNdx' => $messageNdx,
                'analysisNdx' => $analysisNdx,
                'targetDocId' => $targetDocId,
            ]);
            return ProposalApplyOutcome::error(
                $messageNdx, $analysisNdx, 'INTERNAL_ERROR',
                'Unapply transition failed', 500,
            );
        }

        return ProposalApplyOutcome::ok($messageNdx, $analysisNdx, $targetDocId, null);
    }

    // -------------------------------------------------------------------
    // Zápis verdiktu — jedna transakce: analysis resolution + docState zprávy
    // -------------------------------------------------------------------

    private function writeApplyResolution(int $analysisNdx, int $messageNdx, ?int $userId): bool
    {
        return $this->writeResolution($analysisNdx, $messageNdx, $userId, self::RESOLUTION_APPLIED, null);
    }

    private function writeRejectResolution(int $analysisNdx, int $messageNdx, ?int $userId, string $reason): bool
    {
        return $this->writeResolution($analysisNdx, $messageNdx, $userId, self::RESOLUTION_REJECTED, $reason);
    }

    /**
     * Verdikt + workflow zprávy atomicky: resolution/resolved_at/by na
     * analýze, zpráva → Hotovo (40) z Nové (10) i K řešení (20). Ruční
     * docState mimo {10,20} se nepřepisuje (WHERE guard).
     */
    private function writeResolution(
        int $analysisNdx,
        int $messageNdx,
        ?int $userId,
        int $resolution,
        ?string $rejectedReason,
    ): bool {
        $dibi = $this->db->getDibiConnection();
        $now = date('Y-m-d H:i:s');
        $dibi->begin();
        try {
            $update = [
                'resolution' => $resolution,
                'resolved_at' => $now,
                'resolved_by' => $userId,
            ];
            if ($rejectedReason !== null) {
                $update['rejected_reason'] = $rejectedReason;
            }
            $dibi->update(self::ANALYSES_TABLE, $update)
                ->where('id = %i', $analysisNdx)
                ->execute();

            $dibi->update(self::MESSAGES_TABLE, [
                'docState' => self::MSG_STATE_DONE,
                'docStateMain' => self::MSG_STATE_MAIN_DONE,
                'modified' => $now,
            ])
            ->where('id = %i', $messageNdx)
            ->where('docState IN %in', [self::MSG_STATE_NEW, self::MSG_STATE_IN_PROGRESS])
            ->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            ErrorLogger::warn('MessageProposalApplier::writeResolution failed', [
                'error' => $e->getMessage(),
                'analysisNdx' => $analysisNdx,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Reverz apply atomicky: resolution/resolved_* → NULL, `target_*` zprávy
     * → NULL, zpráva 40 → 20 (jen když je stále v Hotovo — ruční stav se
     * nepřepisuje).
     */
    private function writeUnapplyTransition(int $analysisNdx, int $messageNdx): bool
    {
        $dibi = $this->db->getDibiConnection();
        $now = date('Y-m-d H:i:s');
        $dibi->begin();
        try {
            $dibi->update(self::ANALYSES_TABLE, [
                'resolution' => null,
                'rejected_reason' => null,
                'resolved_at' => null,
                'resolved_by' => null,
            ])->where('id = %i', $analysisNdx)->execute();

            $dibi->update(self::MESSAGES_TABLE, [
                'target_table_id' => null,
                'target_row' => null,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            $dibi->update(self::MESSAGES_TABLE, [
                'docState' => self::MSG_STATE_IN_PROGRESS,
                'docStateMain' => self::MSG_STATE_MAIN_IN_PROGRESS,
            ])
            ->where('id = %i', $messageNdx)
            ->where('docState = %i', self::MSG_STATE_DONE)
            ->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            ErrorLogger::warn('MessageProposalApplier::writeUnapplyTransition failed', [
                'error' => $e->getMessage(),
                'analysisNdx' => $analysisNdx,
            ]);
            return false;
        }
        return true;
    }

    // -------------------------------------------------------------------
    // Client _resolve helpers
    // -------------------------------------------------------------------

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
