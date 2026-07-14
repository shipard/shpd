<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Module\Base\Registry\FileFromMessageService;

/**
 * Endpointy Spisovny (`/_registry/*`).
 *
 * Tenká slupka nad službami modulu base.registry — auth + mapování
 * výsledku služby na Response (vzor AnalysisController::applyExtracted
 * nad ExtractedDocumentApplier).
 *
 * Pozn.: PersonsRegistryController je ARES (obchodní rejstřík) a s tímto
 * controllerem nesouvisí.
 */
class RegistryController
{
    public function __construct(
        private readonly FileFromMessageService $fileFromMessage,
    ) {}

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
}
