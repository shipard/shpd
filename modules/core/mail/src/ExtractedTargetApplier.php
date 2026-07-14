<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Cílový applier extrahovaného dokumentu pro jeden `target` z cfgItem
 * `core.mail.extractedDocTypes` (`docs` řeší ExtractedDocumentApplier
 * interně přes exchange DocumentApplier; ostatní targety implementují
 * tento interface). Registrace napevno ve wiringu — mapa
 * `target => ExtractedTargetApplier` v konstruktoru
 * {@see ExtractedDocumentApplier}, žádný plugin registr.
 *
 * Sdílenou status mašinerii (writeStatusTransition, writeUnapplyTransition,
 * auto-transition zprávy) drží ExtractedDocumentApplier — implementace
 * targetu řeší jen vznik/úklid cílového záznamu včetně zápisu
 * `target_table_id`/`target_row_ndx` na extracted řádek (symetrie
 * s `DocumentApplier::writeLineageTargets` u docs cesty; recovery přes
 * `completeApplied` na tom stojí).
 */
interface ExtractedTargetApplier
{
    /**
     * Vytvoří cílový záznam z canonical extrakce. Součástí úspěchu je
     * zapsaný `target_table_id`/`target_row_ndx` na extracted řádku.
     *
     * @param array<string, mixed> $canonical    parsovaný `extracted_json`
     * @param array<string, mixed> $extractedRow celý řádek `core_mail_extracted_documents`
     */
    public function apply(array $canonical, array $extractedRow, ?int $userId): TargetApplyResult;

    /**
     * Undo apply: guard (cíl nedotčený od apply) + úklid cílového záznamu
     * (soft-delete). Vynulování `target_row_ndx`/`applied_*` na extracted
     * řádku dělá sdílený `writeUnapplyTransition`, ne implementace.
     *
     * @param array<string, mixed> $extractedRow celý řádek `core_mail_extracted_documents`
     */
    public function unapply(array $extractedRow): TargetUnapplyResult;
}
