<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;

/**
 * REST endpoints for the canonical exchange formats. Two parallel
 * flavours sharing the same response shape:
 *
 *   POST /api/v1/_exchange/docs/document/{validate|preview|apply}
 *   POST /api/v1/_exchange/persons/person/{validate|preview|apply}
 *
 * The controller is intentionally thin — body validation + delegate to
 * the relevant Applier + map ApplyResult to Response. Error shape
 * follows docs/exchange-format.md §"Error response shape" exactly:
 *
 *   { success: false, error: { code, message, details: <enriched canonical> } }
 *
 * `PersonApplier` is injected optionally so document-only deployments
 * and existing unit tests can stub the controller without wiring the
 * person flow. Calling a /persons/* endpoint without a configured
 * PersonApplier returns 500 INTERNAL_ERROR.
 */
final class ExchangeController
{
    public function __construct(
        private readonly DocumentApplier $applier,
        private readonly ?PersonApplier $personApplier = null,
    ) {}

    // ── Document flow ──────────────────────────────────────────────────

    public function validate(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->validate($payload), 'savedDocId');
    }

    public function preview(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->preview($payload), 'savedDocId');
    }

    public function apply(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->apply($payload), 'savedDocId');
    }

    // ── Person flow ────────────────────────────────────────────────────

    public function validatePerson(Request $request): Response
    {
        if ($this->personApplier === null) {
            return $this->personFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->personApplier->validate($payload), 'savedPersonId');
    }

    public function previewPerson(Request $request): Response
    {
        if ($this->personApplier === null) {
            return $this->personFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->personApplier->preview($payload), 'savedPersonId');
    }

    public function applyPerson(Request $request): Response
    {
        if ($this->personApplier === null) {
            return $this->personFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->personApplier->apply($payload), 'savedPersonId');
    }

    // ── Shared plumbing ────────────────────────────────────────────────

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

    private function respond(ApplyResult $result, string $savedKey): Response
    {
        if ($result->success) {
            return Response::success(
                [
                    'canonical' => $result->canonical,
                    $savedKey   => $result->savedId,
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

    private function personFlowUnavailable(): Response
    {
        return Response::error(
            'INTERNAL_ERROR',
            'Person exchange flow is not wired in this dispatcher.',
            500,
        );
    }
}
