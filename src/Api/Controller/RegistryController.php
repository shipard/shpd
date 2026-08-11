<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Shipard\Module\Base\Registry\FileFromMessageService;
use Shipard\Module\Base\Registry\RegistryImportService;

/**
 * Endpointy Spisovny (`/_registry/*`).
 *
 * Tenká slupka nad službami modulu base.registry — auth + mapování
 * výsledku služby na Response (vzor AnalysisController::applyMessage
 * nad MessageProposalApplier).
 *
 * Pozn.: PersonsRegistryController je ARES (obchodní rejstřík) a s tímto
 * controllerem nesouvisí.
 */
class RegistryController
{
    public function __construct(
        private readonly FileFromMessageService $fileFromMessage,
        private readonly ExtractedTextFiller $textFiller,
        private readonly DataSourceConnection $db,
        private readonly ?RegistryImportService $importService = null,
    ) {}

    /**
     * POST /api/v1/_registry/import
     *
     * Programové založení jednoho dokumentu Spisovny z migračního runneru
     * (`wkf.docs`, design §10) — zachovává historické `created`, zapisuje
     * cílový `docState` a je idempotentní podle `legacy.ndx`. Auth shodná
     * s `/_mail/import`: libovolný api_key (typicky `_legacy_importer`).
     * Odpovědi: 201 {id}, 200 {id, existed} (dedupe), oboje + `warning?`
     * (`BINDER_NOT_FOUND`), 400 chybějící tělo, 422 validace.
     *
     * Viz `tasks/registry-import-endpoint.md`.
     */
    public function import(AuthContext $auth, Request $request): Response
    {
        if (!$auth->isAuthenticated || $auth->tokenType !== 'api_key') {
            return Response::error('UNAUTHORIZED', 'API key required', 401);
        }
        if ($this->importService === null) {
            return Response::error('INTERNAL_ERROR', 'Import service not available', 500);
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        $result = $this->importService->import($body);

        if (!$result['ok']) {
            return Response::error(
                $result['errorCode'] ?? 'INTERNAL_ERROR',
                $result['errorMessage'] ?? 'Import failed',
                $result['statusCode'] ?? 500,
                $result['details'] ?? [],
            );
        }

        $payload = ['id' => (int) $result['id']];
        if (!empty($result['existed'])) {
            $payload['existed'] = true;
        }
        if (isset($result['warning'])) {
            $payload['warning'] = $result['warning'];
        }
        return Response::success($payload, (int) ($result['statusCode'] ?? 201));
    }

    /**
     * POST /api/v1/_registry/from-message/{ndx}
     *
     * Ruční zařazení došlé zprávy do Spisovny: vznikne Koncept dokumentu
     * s kopiemi obsahových příloh, zpráva přejde do Hotovo (40) s vazbou
     * target_*. Odpovědi: 200 {id, warning?}, 404 NOT_FOUND (zpráva
     * neexistuje), 409 INVALID_STATE (Koš), 500 INTERNAL_ERROR.
     */
    public function fromMessage(AuthContext $auth, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $result = $this->fileFromMessage->fileFromMessage($messageNdx, $auth->userId);

        if (!$result['ok']) {
            return Response::error(
                $result['errorCode'] ?? 'INTERNAL_ERROR',
                $result['errorMessage'] ?? 'Filing failed',
                $result['statusCode'] ?? 500,
            );
        }

        $payload = ['id' => (int) $result['id']];
        if (isset($result['warning'])) {
            $payload['warning'] = $result['warning'];
        }
        return Response::success($payload);
    }

    /**
     * POST /api/v1/_registry/documents/{id}/extract-text
     *
     * Přegeneruje `extracted_text` dokumentu z aktuálních příloh
     * (ExtractedTextFiller — stejná skladba jako při zařazení; bez příloh
     * text vyčistí). Idempotentní. Odpovědi: 200 {chars, attachments},
     * 404 NOT_FOUND (neexistuje / Koš).
     */
    public function extractText(AuthContext $auth, int $documentId): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $row = $this->db->fetchRow(
            'SELECT `id`, `docState` FROM `base_registry_documents` WHERE `id` = %i',
            $documentId,
        );
        if ($row === null || (int) $row['docState'] === 90) {
            return Response::error('NOT_FOUND', 'Document not found', 404);
        }

        $result = $this->textFiller->fill($documentId, clearWhenNoAttachments: true);
        return Response::success($result);
    }
}
