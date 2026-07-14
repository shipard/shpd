<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Isdoc\IsdocParseException;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Deterministický import ISDOC příloh došlé zprávy místo AI analýzy
 * (tasks/mail-isdoc-import.md). Volá MailController::receiveIncoming po
 * commitu intake transakce; úspěch = zpráva přeskočí AI frontu
 * (analysis_state → 30), v tabu Analýzy vznikne záznam `model_name='isdoc'`
 * a extracted dokument s confidence 1.0.
 *
 * Invarianty:
 *   - nikdy nesmí shodit příjem pošty — tryImport polyká všechny výjimky,
 *     návrat false = zpráva zůstává v AI frontě,
 *   - parse + validace + enrichment běží před otevřením zápisové tx,
 *   - FOR UPDATE guard řeší závod s analyzerem (okno mezi commitem intake
 *     a začátkem importu): analysis_state mimo {0, 10} = claim vyhrál,
 *     import se vzdá,
 *   - všechny ISDOC přílohy zprávy musí projít (all-or-nothing) — jediný
 *     vadný ISDOC / nepodporovaný DocumentType pošle celou zprávu do AI.
 */
class IsdocImportService
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';

    /** analysis_state hodnoty (core.mail.analysisStates). */
    private const ANALYSIS_IMPORTABLE_STATES = [0, 10];
    private const ANALYSIS_ANALYZED = 30;

    /** docState hodnoty (core.mail.docStatesIncoming). */
    private const DOC_STATE_NEW = 10;
    private const DOC_STATE_IN_PROGRESS = 20;
    private const DOC_STATE_IN_PROGRESS_MAIN = 2;

    private const XML_MIME_TYPES = ['application/xml', 'text/xml'];

    private readonly IsdocReader $reader;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly SchemaValidator $schemaValidator,
        private readonly ?RowHistoryEnricher $enricher,
        private readonly ExtractedDocumentStatusResolver $statusResolver,
        private readonly string $dsPath,
        ?IsdocReader $reader = null,
    ) {
        $this->reader = $reader ?? new IsdocReader();
    }

    /**
     * Rychlá detekce kandidáta bez instance service — MailController podle
     * ní rozhoduje, zda vůbec service (lazy) postavit. Přípona
     * .isdoc/.isdocx (case-insensitive), nebo XML mime (root element pak
     * ověří až plný parse v tryImport).
     *
     * @param array<string, mixed> $uploadedFile Návrat AttachmentService::upload.
     */
    public static function isPotentialIsdocAttachment(array $uploadedFile): bool
    {
        $extension = self::extensionOf($uploadedFile);
        if ($extension === 'isdoc' || $extension === 'isdocx') {
            return true;
        }
        $mime = strtolower(trim((string) ($uploadedFile['mime_type'] ?? '')));
        return in_array($mime, self::XML_MIME_TYPES, true);
    }

    /**
     * Zkusí deterministický import ISDOC příloh zprávy. Vrací true, když
     * import proběhl (zpráva je Analyzována); false = žádný ISDOC, vadný
     * ISDOC nebo prohraný závod s analyzerem — zpráva zůstává, jak byla.
     *
     * @param list<array<string, mixed>> $uploadedFiles Návraty
     *        AttachmentService::upload z intake (id, name, file_name,
     *        file_path, mime_type, …); bez raw .eml souboru.
     */
    public function tryImport(int $messageNdx, array $uploadedFiles): bool
    {
        try {
            return $this->doImport($messageNdx, $uploadedFiles);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'IsdocImportService::tryImport failed — message stays in AI queue');
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $uploadedFiles
     */
    private function doImport(int $messageNdx, array $uploadedFiles): bool
    {
        $startedAt = microtime(true);

        // ── 1. Parse + schema validace + enrichment (mimo tx) ──────────────
        $documents = [];
        $modelVersion = null;
        foreach ($uploadedFiles as $file) {
            if (!is_array($file) || !self::isPotentialIsdocAttachment($file)) {
                continue;
            }

            $canonical = $this->readCandidate($messageNdx, $file);
            if ($canonical === null) {
                continue; // XML příloha, která není ISDOC — není kandidát
            }
            if ($canonical === false) {
                return false; // vadný ISDOC → celá větev končí, AI fronta
            }

            $canonical['source']['mailMessage'] = $messageNdx;
            $canonical['attachments'] = [self::attachmentEntry($file)];

            $issues = $this->schemaValidator->validate(
                $canonical,
                DocumentApplier::FORMAT_ID,
                DocumentApplier::FORMAT_VERSION,
            );
            if ($issues !== []) {
                ErrorLogger::warn('ISDOC import: canonical failed schema validation — message stays in AI queue', [
                    'message' => $messageNdx,
                    'attachment' => (int) ($file['id'] ?? 0),
                    'issues' => array_slice($issues, 0, 5),
                ]);
                return false;
            }

            if ($this->enricher !== null) {
                // Obohacení řádků z historie (persist, jako /result) —
                // selhání enrichmentu není fatální, pokračuje se neobohaceně.
                try {
                    $canonical = $this->enricher->enrich($canonical);
                } catch (\Throwable $e) {
                    ErrorLogger::logException($e, 'IsdocImportService row history enrichment failed');
                }
            }

            $documents[] = [
                'canonical' => $canonical,
                'attachmentId' => (int) ($file['id'] ?? 0),
                'docType' => (string) $canonical['docType'],
            ];
            $modelVersion ??= isset($canonical['source']['raw']['version'])
                ? (string) $canonical['source']['raw']['version']
                : null;
        }

        if ($documents === []) {
            return false;
        }

        $thresholds = $this->statusResolver->thresholdsForDefaultProfile();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $now = date('Y-m-d H:i:s');

        // ── 2. Zápisová tx s guardem ────────────────────────────────────────
        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $msg = $dibi->fetch(
                'SELECT analysis_state, docState, primary_type_source, created_by
                   FROM %n WHERE id = %i FOR UPDATE',
                self::MESSAGES_TABLE,
                $messageNdx,
            );
            if ($msg === null) {
                $dibi->rollback();
                return false;
            }
            if (!in_array((int) $msg['analysis_state'], self::ANALYSIS_IMPORTABLE_STATES, true)) {
                // Mezitím si zprávu stihl claimnout analyzer — nechat mu ji.
                $dibi->rollback();
                return false;
            }
            $createdBy = $msg['created_by'] !== null ? (int) $msg['created_by'] : null;

            $dibi->insert(self::ANALYSES_TABLE, [
                'message' => $messageNdx,
                'profile' => null,
                'backend' => null,
                'analyzed_at' => $now,
                'status' => 2, // success
                'model_name' => 'isdoc',
                'model_version' => $modelVersion,
                'prompt_version' => 'isdoc',
                'confidence' => 1.0,
                'duration_ms' => $durationMs,
                'extracted_document_count' => count($documents),
                'created' => $now,
                'created_by' => $createdBy,
            ])->execute();
            $analysisNdx = (int) $dibi->getInsertId();

            foreach ($documents as $doc) {
                $status = $this->statusResolver->capStatusByRowCoverage(
                    $this->statusResolver->mapConfidenceToStatus(1.0, $thresholds),
                    $doc['canonical'],
                );
                $dibi->insert(self::EXTRACTED_TABLE, [
                    'message' => $messageNdx,
                    'analysis' => $analysisNdx,
                    'doc_type' => $doc['docType'],
                    'source_attachments' => json_encode([$doc['attachmentId']], JSON_UNESCAPED_UNICODE),
                    'extracted_json' => (string) json_encode(
                        $doc['canonical'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'confidence' => 1.0,
                    'status' => $status,
                    'created' => $now,
                    'created_by' => $createdBy,
                ])->execute();
            }

            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_ANALYZED,
                'needs_reanalysis' => 0,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            // Ruční volba typu má vždy přednost (stejné pravidlo jako AI).
            if ((string) $msg['primary_type_source'] !== 'user') {
                $dibi->update(self::MESSAGES_TABLE, [
                    'primary_type' => 'invoiceReceived',
                    'primary_type_source' => 'isdoc',
                ])
                ->where('id = %i', $messageNdx)
                ->where('primary_type_source != %s', 'user')
                ->execute();
            }

            // Workflow: Nová → K řešení, jen když stav mezitím nikdo nezměnil.
            if ((int) $msg['docState'] === self::DOC_STATE_NEW) {
                $dibi->update(self::MESSAGES_TABLE, [
                    'docState' => self::DOC_STATE_IN_PROGRESS,
                    'docStateMain' => self::DOC_STATE_IN_PROGRESS_MAIN,
                ])
                ->where('id = %i', $messageNdx)
                ->where('docState = %i', self::DOC_STATE_NEW)
                ->execute();
            }

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * Přečte jednoho kandidáta. Návraty: canonical array = OK; null =
     * příloha není ISDOC (XML bez ISDOC přípony s cizím rootem / nevalidní
     * XML — tichý skip); false = je to ISDOC, ale nejde zpracovat (vadný
     * obsah, nepodporovaný DocumentType, vadný ZIP) → celá větev se vzdává.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>|false|null
     */
    private function readCandidate(int $messageNdx, array $file): array|false|null
    {
        $extension = self::extensionOf($file);
        $isHardCandidate = $extension === 'isdoc' || $extension === 'isdocx';
        $displayName = (string) ($file['name'] ?? '');

        try {
            return $this->reader->fromFile($this->attachmentPath($file), $displayName);
        } catch (IsdocParseException $e) {
            $softMiss = in_array($e->reason, [
                IsdocParseException::REASON_FOREIGN_ROOT,
                IsdocParseException::REASON_INVALID_XML,
            ], true);
            if (!$isHardCandidate && $softMiss) {
                return null;
            }

            ErrorLogger::warn('ISDOC import: parse failed — message stays in AI queue', [
                'message' => $messageNdx,
                'attachment' => (int) ($file['id'] ?? 0),
                'filename' => $displayName,
                'reason' => $e->reason,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed> Canonical `attachments[]` entry.
     */
    private static function attachmentEntry(array $file): array
    {
        $entry = [
            'filename' => (string) ($file['name'] ?? ''),
            // ISDOC je strojově čitelný formát — 'structured', ne 'original'
            // (viz docs/exchange-format.md §5, enum attachments[].kind).
            'kind' => 'structured',
            'ref' => 'att:' . (int) ($file['id'] ?? 0),
        ];
        $mime = trim((string) ($file['mime_type'] ?? ''));
        if ($mime !== '') {
            $entry['mimeType'] = $mime;
        }
        return $entry;
    }

    /**
     * Cesta k uloženému souboru na disku — stejná konvence jako
     * AttachmentService::getFilePath / MailController::cleanupOrphanedFiles.
     *
     * @param array<string, mixed> $file
     */
    private function attachmentPath(array $file): string
    {
        return $this->dsPath . '/att/' . (string) ($file['file_path'] ?? '')
            . '/' . (string) ($file['file_name'] ?? '');
    }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    private static function extensionOf(array $uploadedFile): string
    {
        return strtolower(pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));
    }
}
