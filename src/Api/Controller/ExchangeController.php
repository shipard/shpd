<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Exchange\Document\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/**
 * REST endpoints for the canonical document exchange format:
 *
 *   POST /api/v1/_exchange/docs/document/validate
 *   POST /api/v1/_exchange/docs/document/preview
 *   POST /api/v1/_exchange/docs/document/apply
 *
 * The controller is intentionally thin — body validation + delegate to
 * Applier + map ApplyResult to Response. Error shape follows
 * docs/exchange-format.md §"Error response shape" exactly:
 *
 *   { success: false, error: { code, message, details: <enriched canonical> } }
 */
final class ExchangeController
{
    public function __construct(
        private readonly DocumentApplier $applier,
    ) {}

    public function validate(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->validate($payload));
    }

    public function preview(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->preview($payload));
    }

    public function apply(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->apply($payload));
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function extractPayload(Request $request): array|Response
    {
        $body = $request->getBody();
        if (!is_array($body)) {
            return Response::error(
                'schema_invalid',
                'Tělo požadavku musí být JSON objekt.',
                400,
            );
        }
        return $body;
    }

    private function respond(ApplyResult $result): Response
    {
        if ($result->success) {
            return Response::success(
                [
                    'canonical'  => $result->canonical,
                    'savedDocId' => $result->savedDocId,
                ],
                $result->statusCode,
            );
        }
        return Response::error(
            $result->errorCode ?? 'internal_error',
            $result->errorMessage ?? 'Unknown error',
            $result->statusCode,
            $result->canonical !== [] ? ['canonical' => $result->canonical] : [],
        );
    }
}
