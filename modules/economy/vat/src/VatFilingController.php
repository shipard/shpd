<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * REST endpointy podání DPH (`/_vat/*`).
 *
 * POST /_vat/filing-compose, body {"filingId": N} — přepočítá snapshot
 * podání ve stavu Sestaveno z aktuálních dokladů instance (akce
 * „Přepočítat" v detailu podání). Podané ani zrušené podání composer
 * odmítne — je to záznam o tom, co odešlo.
 */
final class VatFilingController
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {}

    public function compose(Request $request): Response
    {
        $body     = $request->getBody();
        $filingId = is_array($body) ? (int) ($body['filingId'] ?? 0) : 0;
        if ($filingId <= 0) {
            return Response::error('BAD_REQUEST', 'Body must contain a positive filingId', 400);
        }

        $filing = $this->db->fetchRow(
            'SELECT id, docState FROM ' . FilingDocument::TABLE . ' WHERE id = %i',
            $filingId,
        );
        if ($filing === null) {
            return Response::error('NOT_FOUND', "Filing {$filingId} not found", 404);
        }
        if ((int) $filing['docState'] !== FilingDocument::DOC_STATE_COMPOSED) {
            return Response::error(
                'INVALID_DOC_STATE',
                'Only filings in state 10 (composed) can be recomposed',
                422,
            );
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $summary = (new FilingComposer($dibi, $this->config))->compose($filingId);
            $dibi->commit();
        } catch (\DomainException | \RuntimeException $e) {
            $dibi->rollback();
            // Doménová chyba sestavení (kód DPH bez mapování, chybějící
            // config) je výsledek, který uživatel musí vidět, ne 500.
            return Response::error('FILING_COMPOSE_FAILED', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $dibi->rollback();
            throw $e;
        }

        return Response::success([
            'filingId' => $filingId,
            'items'    => $summary['items'],
            'rows'     => $summary['rows'],
            'isEmpty'  => $summary['isEmpty'],
        ]);
    }
}
