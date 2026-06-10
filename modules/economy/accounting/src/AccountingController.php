<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * REST endpointy účetnictví (`/_accounting/*`).
 *
 * POST /_accounting/reaccount, body {"docId": N} — přeúčtuje doklad ve
 * stavu 40 (po opravě rozvrhu / položky). Vrací {accountingState,
 * messages}. UI tlačítko přijde ve Fázi 3, endpoint existuje už teď
 * (akce z alertu / API).
 */
final class AccountingController
{
    private const DOC_STATE_OK = 40;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {}

    public function reaccount(Request $request): Response
    {
        $body = $request->getBody();
        $docId = is_array($body) ? (int) ($body['docId'] ?? 0) : 0;
        if ($docId <= 0) {
            return Response::error('BAD_REQUEST', 'Body must contain a positive docId', 400);
        }

        $head = $this->db->fetchRow(
            'SELECT id, docState FROM docs_core_heads WHERE id = %i',
            $docId,
        );
        if ($head === null) {
            return Response::error('NOT_FOUND', "Document {$docId} not found", 404);
        }
        if ((int) $head['docState'] !== self::DOC_STATE_OK) {
            return Response::error(
                'INVALID_DOC_STATE',
                'Only documents in state 40 (OK) can be re-accounted',
                422,
            );
        }

        $engine = new AccountingEngine($this->db->getDibiConnection(), $this->config);
        $result = $engine->accountDocument($docId);

        return Response::success([
            'accountingState' => $result['state'],
            'messages'        => $result['messages'],
        ]);
    }
}
