<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Feed;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;

/**
 * Feed zdroj došlé pošty — emituje kartu **per vytěžený doklad** (D5) plus
 * chybové karty per zpráva, u které selhala AI.
 *
 * Návrhové karty: `core_mail_extracted_documents.status ∈ {10,20,30}` join na
 * `core_mail_incoming_messages` (kontext subject/sender/received_at). Mapování:
 *   - 10 → kind=ready  (jednoklik apply + review + reject)
 *   - 20/30 → kind=review (review primary + reject; jednoklik se u nízké
 *     jistoty záměrně nenabízí)
 * Chybové karty: zpráva `analysis_state=70` (Analýza selhala) mimo Archiv/Koš
 * → kind=urgent, akce reanalyze + open_viewer na došlou poštu.
 *
 * Titulek: `doc_type` → label z cfgItem `core.mail.extractedDocTypes`.
 * Podtitulek: partner + částka z `extracted_json` (kanonický doklad) + jistota
 * + zdrojový e-mail. Feed je stropovaný (maxCards), takže N `json_decode` je
 * únosné; denormalizace headline polí do sloupců je možná optimalizace později.
 *
 * Akce se emitují bez `label` — frontend je lokalizuje podle `action.id`
 * (i18n klíče `dashboard.card.action.*`). Podtitulek a titulek jsou naopak
 * složené na serveru (data-driven), lokalizované dle `ctx->language`.
 */
final class MailSuggestionsSource implements FeedSource
{
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';
    private const MESSAGES_TABLE  = 'core_mail_incoming_messages';

    /** analysis_state zprávy = permanentní selhání AI (core.mail.analysisStates). */
    private const ANALYSIS_FAILED = 70;

    /** Workflow stavy Archiv/Koš — chybové karty se pro ně neemitují. */
    private const DOC_STATE_ARCHIVED = 80;
    private const DOC_STATE_TRASH = 90;

    private const DOC_TYPES_CFG_ITEM = 'core.mail.extractedDocTypes';

    /** Viewer pro navigaci „Otevřít e-mail". */
    private const INCOMING_VIEWER = 'core.mail.incoming';

    public function collectCards(FeedContext $ctx): array
    {
        return [
            ...$this->suggestionCards($ctx),
            ...$this->errorCards($ctx),
        ];
    }

    /**
     * Karty z vytěžených dokladů ve stavech 10/20/30.
     *
     * @return list<array<string,mixed>>
     */
    private function suggestionCards(FeedContext $ctx): array
    {
        $rows = $ctx->db->fetchAll(
            'SELECT `e`.`id` AS `extracted_ndx`, `e`.`message` AS `message_ndx`,'
            . ' `e`.`doc_type`, `e`.`extracted_json`, `e`.`confidence`, `e`.`status`,'
            . ' `m`.`subject`, `m`.`sender_name`, `m`.`received_at`'
            . ' FROM `' . self::EXTRACTED_TABLE . '` `e`'
            . ' JOIN `' . self::MESSAGES_TABLE . '` `m` ON `m`.`id` = `e`.`message`'
            . ' WHERE `e`.`status` IN %in'
            . ' ORDER BY `m`.`received_at` DESC, `e`.`id` DESC'
            . ' LIMIT %i',
            [
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
                ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
            ],
            $ctx->maxCards,
        );

        $cards = [];
        foreach ($rows as $row) {
            $cards[] = $this->buildSuggestionCard($ctx, $row);
        }
        return $cards;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildSuggestionCard(FeedContext $ctx, array $row): array
    {
        $extractedNdx = (int) $row['extracted_ndx'];
        $messageNdx   = (int) $row['message_ndx'];
        $status       = (int) $row['status'];
        $confidence   = isset($row['confidence']) ? (float) $row['confidence'] : null;

        $canonical = json_decode((string) ($row['extracted_json'] ?? ''), true);
        $canonical = is_array($canonical) ? $canonical : [];

        [$kind, $stateStyle, $icon] = match ($status) {
            ExtractedDocumentDocument::STATUS_READY_TO_APPLY => ['ready', 'done', 'check'],
            ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE => ['review', 'edit', 'warning'],
            default                                          => ['review', 'confirmed', 'question'],
        };

        $target = ['extractedNdx' => $extractedNdx];
        if ($status === ExtractedDocumentDocument::STATUS_READY_TO_APPLY) {
            $actions = [
                ['id' => 'apply',  'kind' => 'apply_extracted',  'target' => $target, 'primary' => true],
                ['id' => 'review', 'kind' => 'review_extracted', 'target' => $target],
                ['id' => 'reject', 'kind' => 'reject_extracted', 'target' => $target],
            ];
        } else {
            $actions = [
                ['id' => 'review', 'kind' => 'review_extracted', 'target' => $target, 'primary' => true],
                ['id' => 'reject', 'kind' => 'reject_extracted', 'target' => $target],
            ];
        }

        return [
            'id'         => 'mail_extracted:' . $extractedNdx,
            'source'     => 'mail',
            'kind'       => $kind,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'title'      => $this->cardTitle($ctx, (string) ($row['doc_type'] ?? ''), $canonical),
            'subtitle'   => $this->cardSubtitle($ctx, $canonical, $confidence, (string) ($row['subject'] ?? '')),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => [
                'messageNdx'   => $messageNdx,
                'extractedNdx' => $extractedNdx,
                'confidence'   => $confidence,
            ],
            'actions'    => $actions,
        ];
    }

    /**
     * Chybové karty — zprávy, u kterých permanentně selhala AI
     * (analysis_state=70), mimo Archiv/Koš.
     *
     * @return list<array<string,mixed>>
     */
    private function errorCards(FeedContext $ctx): array
    {
        $rows = $ctx->db->fetchAll(
            'SELECT `id` AS `message_ndx`, `subject`, `sender_name`, `received_at`'
            . ' FROM `' . self::MESSAGES_TABLE . '`'
            . ' WHERE `analysis_state` = %i AND `docState` NOT IN %in'
            . ' ORDER BY `received_at` DESC, `id` DESC'
            . ' LIMIT %i',
            self::ANALYSIS_FAILED,
            [self::DOC_STATE_ARCHIVED, self::DOC_STATE_TRASH],
            $ctx->maxCards,
        );

        $cards = [];
        foreach ($rows as $row) {
            $messageNdx = (int) $row['message_ndx'];
            $subject    = trim((string) ($row['subject'] ?? ''));
            $cards[] = [
                'id'         => 'mail_message:' . $messageNdx,
                'source'     => 'mail',
                'kind'       => 'urgent',
                'icon'       => 'warning',
                'stateStyle' => 'error',
                'title'      => $ctx->language === 'cs' ? 'Chyba analýzy e-mailu' : 'E-mail analysis failed',
                'subtitle'   => $subject !== '' ? $this->emailSubjectLabel($ctx, $subject) : (string) ($row['sender_name'] ?? ''),
                'timestamp'  => $this->toAtom($row['received_at'] ?? null),
                'context'    => ['messageNdx' => $messageNdx],
                'actions'    => [
                    ['id' => 'reanalyze', 'kind' => 'reanalyze', 'target' => ['messageNdx' => $messageNdx], 'primary' => true],
                    ['id' => 'openMail',  'kind' => 'open_viewer', 'target' => ['viewerId' => self::INCOMING_VIEWER, 'recordId' => $messageNdx]],
                ],
            ];
        }
        return $cards;
    }

    /**
     * Titulek karty: „{typ dokladu} — {partner}" (partner odvozen z kanonického
     * dokladu podle self-party). Bez partnera jen typ dokladu.
     *
     * @param array<string,mixed> $canonical
     */
    private function cardTitle(FeedContext $ctx, string $docType, array $canonical): string
    {
        $typeLabel = $this->docTypeLabel($ctx, $docType);
        $partner   = $this->counterpartyName($canonical);
        return $partner !== null ? ($typeLabel . ' — ' . $partner) : $typeLabel;
    }

    /**
     * Podtitulek: částka · jistota · zdrojový e-mail (jen neprázdné části).
     *
     * @param array<string,mixed> $canonical
     */
    private function cardSubtitle(FeedContext $ctx, array $canonical, ?float $confidence, string $subject): string
    {
        $parts = [];

        $amount = $this->formatAmount($canonical);
        if ($amount !== null) {
            $parts[] = $amount;
        }
        if ($confidence !== null) {
            $pct = (int) round($confidence * 100);
            $parts[] = $ctx->language === 'cs' ? "jistota {$pct} %" : "confidence {$pct} %";
        }
        $subject = trim($subject);
        if ($subject !== '') {
            $parts[] = $this->emailSubjectLabel($ctx, $subject);
        }

        return implode(' · ', $parts);
    }

    private function emailSubjectLabel(FeedContext $ctx, string $subject): string
    {
        return ($ctx->language === 'cs' ? 'e-mail' : 'email') . ' „' . $subject . '"';
    }

    /** Lokalizovaný label typu dokladu z cfgItem; fallback na holý key. */
    private function docTypeLabel(FeedContext $ctx, string $docType): string
    {
        if ($docType === '') {
            return $ctx->language === 'cs' ? 'Doklad' : 'Document';
        }
        $cfg = $ctx->config?->cfgItem(self::DOC_TYPES_CFG_ITEM);
        if (is_array($cfg) && isset($cfg[$docType]['name']) && is_string($cfg[$docType]['name'])) {
            return $cfg[$docType]['name'];
        }
        return $docType;
    }

    /**
     * Jméno protistrany z kanonického dokladu. Protistrana = strana, kterou
     * nejsme my (`selfParty`); default supplier (přijatá faktura).
     *
     * @param array<string,mixed> $canonical
     */
    private function counterpartyName(array $canonical): ?string
    {
        $selfParty = $canonical['selfParty'] ?? null;
        $key = $selfParty === 'supplier' ? 'customer' : 'supplier';
        $name = $canonical[$key]['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
        // Fallback — zkus druhou stranu, kdyby self-party chybělo.
        $other = $key === 'supplier' ? 'customer' : 'supplier';
        $name = $canonical[$other]['name'] ?? null;
        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Naformátuje celkovou částku „{amount} {currency}" z canonical totals.
     *
     * @param array<string,mixed> $canonical
     */
    private function formatAmount(array $canonical): ?string
    {
        $total = $canonical['totals']['totalAmount'] ?? null;
        if (!is_int($total) && !is_float($total) && !(is_string($total) && is_numeric($total))) {
            return null;
        }
        $amount   = (float) $total;
        $currency = is_string($canonical['currency'] ?? null) ? (string) $canonical['currency'] : '';
        $formatted = number_format($amount, 2, ',', ' ');
        return $currency !== '' ? ($formatted . ' ' . $currency) : $formatted;
    }

    /** DB datetime → ATOM; null/prázdné → null. */
    private function toAtom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
