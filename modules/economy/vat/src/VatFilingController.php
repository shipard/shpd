<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\Xml\FilingFile;
use Shipard\Module\Economy\Vat\Xml\FilingFilesFactory;
use Shipard\Module\Economy\Vat\Xml\FilingXmlValidationException;

/**
 * REST endpointy podání DPH (`/_vat/*`).
 *
 * POST /_vat/filing-files, body {"filingId": N} — vyrobí XML (a PDF) pro
 * daňový portál a uloží je jako přílohy podání.
 *
 * POST /_vat/filing-compose, body {"filingId": N} — přepočítá snapshot
 * podání ve stavu Sestaveno z aktuálních dokladů instance (akce
 * „Přepočítat" v detailu podání). Podané ani zrušené podání composer
 * odmítne — je to záznam o tom, co odešlo.
 *
 * POST /_vat/filing-header-from-profile, body {"filingId": N} — přepíše
 * hlavičku konceptu předvyplněním z profilu podatele (akce „Načíst
 * hlavičku z profilu", #55 F3-5); přepočet hlavičku schválně nechává být.
 */
final class VatFilingController
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        /** Bez konfigurace zdroje dat se soubory nemají kam uložit. */
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?int $userId = null,
    ) {}

    public function compose(Request $request): Response
    {
        $filing = $this->resolveFiling($request);
        if ($filing instanceof Response) {
            return $filing;
        }
        $filingId = (int) $filing['id'];
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

    /**
     * POST /_vat/filing-files, body {"filingId": N} — vyrobí soubory pro
     * daňový portál a uloží je jako přílohy podání (#55 X6).
     *
     * Ve stavu Sestaveno lze opakovat (starší sada se nahradí), u podaného
     * podání se jen doplní, co chybí — hotové soubory jsou doklad o tom, co
     * odešlo, a guard je chrání.
     */
    public function files(Request $request): Response
    {
        $filing = $this->resolveFiling($request);
        if ($filing instanceof Response) {
            return $filing;
        }
        $filingId = (int) $filing['id'];
        if ((int) $filing['docState'] === FilingDocument::DOC_STATE_CANCELLED) {
            return Response::error(
                'INVALID_DOC_STATE',
                'Zrušené podání soubory pro daňový portál nemá.',
                422,
            );
        }

        try {
            $result = FilingFilesFactory::create(
                $this->db->getDibiConnection(),
                $this->config,
                $this->dsConfig,
            )->generate($filingId, userId: $this->userId);
        } catch (FilingXmlValidationException $e) {
            // Nedovyplněná hlavička — chyby jdou na pole formuláře podání.
            return Response::error('FILING_XML_INVALID', $e->getMessage(), 422, $e->toArray());
        } catch (\DomainException | \RuntimeException $e) {
            return Response::error('FILING_FILES_FAILED', $e->getMessage(), 422);
        }

        return Response::success([
            'filingId' => $filingId,
            'files'    => array_map(
                static fn (FilingFile $file): array => ['kind' => $file->kind, 'name' => $file->name],
                $result->files,
            ),
            'warnings' => $result->warnings,
        ]);
    }

    /**
     * POST /_vat/filing-header-from-profile — hlavička konceptu znovu
     * z profilu podatele (#55 F3-5). Ruční úpravy hlavičky zaniknou, proto
     * se UI ptá před voláním; podané a zrušené podání composer odmítne.
     */
    public function headerFromProfile(Request $request): Response
    {
        $filing = $this->resolveFiling($request);
        if ($filing instanceof Response) {
            return $filing;
        }
        $filingId = (int) $filing['id'];
        if ((int) $filing['docState'] !== FilingDocument::DOC_STATE_COMPOSED) {
            return Response::error(
                'INVALID_DOC_STATE',
                'Only filings in state 10 (composed) can reload the header',
                422,
            );
        }

        try {
            (new FilingComposer($this->db->getDibiConnection(), $this->config))->resetHeader($filingId);
        } catch (\DomainException | \RuntimeException $e) {
            return Response::error('FILING_HEADER_RESET_FAILED', $e->getMessage(), 422);
        }

        return Response::success(['filingId' => $filingId]);
    }

    /**
     * Podání z těla požadavku (`{"filingId": N}`), nebo hotová chybová
     * odpověď — všechny endpointy začínají stejně.
     *
     * @return array<string, mixed>|Response
     */
    private function resolveFiling(Request $request): array|Response
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
        return $filing;
    }
}
