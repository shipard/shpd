<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryClient;
use Shipard\Module\Base\Persons\Registry\RegistryImportException;
use Shipard\Module\Base\Persons\Registry\RegistryInvalidResponseException;
use Shipard\Module\Base\Persons\Registry\RegistryNotFoundException;
use Shipard\Module\Base\Persons\Registry\RegistryPersonImporter;
use Shipard\Module\Base\Persons\Registry\RegistryUnavailableException;

/**
 * Endpoints (all under `/api/v1/persons/registry`):
 *
 *   GET  /                              — search registry by `?q=<query>`
 *   GET  /{country}/{companyId}         — fetch canonical person payload
 *   POST /import                        — headless import (createOnly) by (country, companyId)
 *
 * Thin REST adapter on top of {@see PersonsRegistryClient} and
 * {@see RegistryPersonImporter}. All business logic (HTTP transport,
 * canonical validation, apply policy) lives in those services — this
 * controller is responsible only for mapping request/response shapes
 * and translating registry exceptions to HTTP errors.
 */
final class PersonsRegistryController
{
    public function __construct(
        private readonly PersonsRegistryClient $registry,
        private readonly RegistryPersonImporter $importer,
        private readonly DataSourceConnection $db,
    ) {}

    /** GET /api/v1/persons/registry?q=<query> */
    public function search(Request $request): Response
    {
        $query = $request->getQueryParams()['q'] ?? '';
        if (!is_string($query)) {
            $query = '';
        }
        $query = trim($query);

        if ($query === '') {
            return Response::success(['results' => []]);
        }

        try {
            $rows = $this->registry->search($query);
        } catch (RegistryUnavailableException $e) {
            return Response::error('REGISTRY_UNAVAILABLE', $e->getMessage(), 503);
        } catch (RegistryInvalidResponseException $e) {
            return Response::error('REGISTRY_INVALID_RESPONSE', $e->getMessage(), 502);
        }

        $results = [];
        foreach ($rows as $row) {
            $arr = $row->toArray();
            $arr['existsInDb'] = $this->personExistsInDb($row->companyId);
            $results[] = $arr;
        }

        return Response::success(['results' => $results]);
    }

    /** GET /api/v1/persons/registry/{country}/{companyId} */
    public function fetchPerson(string $country, string $companyId): Response
    {
        try {
            $canonical = $this->registry->fetchPerson($country, $companyId);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        } catch (RegistryNotFoundException $e) {
            return Response::error('REGISTRY_NOT_FOUND', $e->getMessage(), 404);
        } catch (RegistryUnavailableException $e) {
            return Response::error('REGISTRY_UNAVAILABLE', $e->getMessage(), 503);
        } catch (RegistryInvalidResponseException $e) {
            return Response::error('REGISTRY_INVALID_RESPONSE', $e->getMessage(), 502);
        }

        return Response::success($canonical);
    }

    /** POST /api/v1/persons/registry/import */
    public function import(Request $request): Response
    {
        $body = $request->getBody() ?? [];
        $country   = is_string($body['country']   ?? null) ? trim($body['country'])   : '';
        $companyId = is_string($body['companyId'] ?? null) ? trim($body['companyId']) : '';

        if ($country === '' || $companyId === '') {
            return Response::error(
                'BAD_REQUEST',
                'Missing required fields: country and companyId',
                400,
            );
        }

        try {
            $result = $this->importer->ensureImported($country, $companyId);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        } catch (RegistryNotFoundException $e) {
            return Response::error('REGISTRY_NOT_FOUND', $e->getMessage(), 404);
        } catch (RegistryUnavailableException $e) {
            return Response::error('REGISTRY_UNAVAILABLE', $e->getMessage(), 503);
        } catch (RegistryInvalidResponseException $e) {
            return Response::error('REGISTRY_INVALID_RESPONSE', $e->getMessage(), 502);
        } catch (RegistryImportException $e) {
            return Response::error(
                'REGISTRY_IMPORT_FAILED',
                $e->getMessage(),
                422,
                [
                    [
                        'applierErrorCode' => $e->applierErrorCode,
                        'canonical'        => $e->canonical,
                    ],
                ],
            );
        }

        return Response::success([
            'personId' => $result->personId,
            'created'  => $result->created,
        ]);
    }

    /**
     * `base_persons_persons` has no `country` column, so the existence
     * check matches on `company_id` alone — consistent with how
     * `PartyResolver` resolves header matches. Active rows only
     * (docState IN 10/40/80).
     */
    private function personExistsInDb(string $companyId): bool
    {
        $id = $this->db->fetchSingle(
            'SELECT id FROM %n WHERE company_id = %s AND docState IN %in LIMIT 1',
            'base_persons_persons',
            $companyId,
            [10, 40, 80],
        );
        return $id !== null;
    }
}
