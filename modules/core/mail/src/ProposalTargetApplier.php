<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Cílový applier dokumentového návrhu pro jeden `target` z cfgItem
 * `core.mail.primaryTypes` (`docs` řeší MessageProposalApplier interně přes
 * exchange DocumentApplier; ostatní targety implementují tento interface).
 * Registrace napevno ve wiringu — mapa `target => ProposalTargetApplier`
 * v konstruktoru {@see MessageProposalApplier}, žádný plugin registr.
 *
 * Sdílenou verdikt mašinerii (resolution na řádku analýzy, docState zprávy,
 * reverz při unapply) drží MessageProposalApplier — implementace targetu
 * řeší jen vznik/úklid cílového záznamu včetně zápisu
 * `target_table_id`/`target_row` na zprávu (symetrie
 * s `DocumentApplier::writeLineageTargets` u docs cesty; recovery přes
 * `completeApplied` na tom stojí).
 */
interface ProposalTargetApplier
{
    /**
     * Vytvoří cílový záznam z canonical návrhu. Součástí úspěchu je zapsaný
     * `target_table_id`/`target_row` na zprávě (uvnitř vlastní transakce).
     *
     * @param array<string, mixed> $canonical    parsovaný `canonical_json`
     * @param array<string, mixed> $message      celý řádek `core_mail_incoming_messages`
     * @param string               $proposedType typ návrhu (klíč `core.mail.primaryTypes`)
     */
    public function apply(array $canonical, array $message, string $proposedType, ?int $userId): TargetApplyResult;

    /**
     * Undo apply: guard (cíl nedotčený od apply) + úklid cílového záznamu
     * (soft-delete). Vynulování `target_*` na zprávě a reset resolution
     * analýzy dělá sdílený `writeUnapplyTransition`, ne implementace.
     *
     * @param int   $targetDocId id cílového záznamu (message.target_row)
     * @param mixed $resolvedAt  čas apply verdiktu (analysis.resolved_at) pro guard změn
     */
    public function unapply(int $targetDocId, mixed $resolvedAt): TargetUnapplyResult;
}
