<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Feed;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Module\Core\Mail\ExtractedDocTypes;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Module\Core\Mail\IncomingMessageDocument;

/**
 * Feed zdroj došlé pošty — emituje kartu **per vytěžený doklad** (D5),
 * chybové karty per zpráva, u které selhala AI, a info karty „Není faktura"
 * pro AI-klasifikované ne-faktury.
 *
 * Návrhové karty: `core_mail_extracted_documents.status ∈ {10,20,30}` join na
 * `core_mail_incoming_messages` (kontext subject/sender/received_at). Mapování:
 *   - 10 → kind=ready (vizuální odlišení „AI si je jistá“)
 *   - 20/30 → kind=review
 *   Akce jsou pro všechny stavy shodné: review (primary) + reject —
 *   vystavení jde vždy přes review modal, jednoklikové apply z karty
 *   bylo odstraněno.
 * Doklady s `doc_type='other'` se ignorují (pojistka — prompt v2.2.0 je
 * zakazuje, starší analýzy je mohly vytvořit).
 * Chybové karty: zpráva `analysis_state=70` (Analýza selhala) mimo Archiv/Koš
 * → kind=urgent, akce reanalyze + open_form (editační formulář zprávy). Když už
 * klasifikace stihla určit `primary_type='other'` (např. při reanalyze),
 * karta degraduje na kind=review — ne-faktura není urgentní.
 * Karty „Není faktura": zpráva `analysis_state=30`, `docState=10` (Nová),
 * `primary_type='other'` a žádný akční extracted doc → kind=info s akcemi
 * Koš (primary) / Archiv / otevřít editační formulář zprávy.
 *
 * Návrhové karty s partnerem nesou strukturovanou hlavičku `headline`
 * ({partnerName, typeLabel, amountText?}) + volitelná pole `confidencePct`
 * (int 0–100) a `details` ({label, value} — číslo dokladu / splatnost /
 * variabilní symbol; u registry „Platí do"); `subtitle` se u nich neposílá.
 * Bez partnera karta padá na dnešní složený `title`/`subtitle` fallback.
 * Všechny tři druhy mail karet nesou `emailSubject` (holý předmět zprávy).
 * a volitelné `receivedDateText` (lokalizované datum doručení z `received_at`).
 * Data jdou z `extracted_json` (kanonický doklad) — feed je stropovaný
 * (maxCards), takže N `json_decode` je únosné; denormalizace headline polí
 * do sloupců je možná optimalizace později.
 *
 * Akce se emitují bez `label` — frontend je lokalizuje podle `action.id`
 * (i18n klíče `dashboard.card.action.*`). Podtitulek a titulek jsou naopak
 * složené na serveru (data-driven), lokalizované dle `ctx->language`.
 *
 * Přílohy: každá karta s ≥1 obsahovou přílohou zprávy nese volitelná pole
 * `attachments` (max MAX_CARD_ATTACHMENTS položek `{id, name, mime_type,
 * file_size}` — struktura shodná s fetchContentAttachments() ve vieweru)
 * + `attachmentsTotal` (počet před stropem). Návrhové karty filtrují na
 * `source_attachments` extracted dokladu (fallback všechny obsahové přílohy),
 * chybové a „Není faktura" karty nesou všechny obsahové přílohy. Raw `.eml`
 * (`raw_source_attachment`) se vylučuje vždy. Jeden batch dotaz na collect.
 */
final class MailSuggestionsSource implements FeedSource
{
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';
    private const MESSAGES_TABLE  = 'core_mail_incoming_messages';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';

    /**
     * tableId tabulky `core_mail_incoming_messages` — viewer používá literál
     * 303 přímo (IncomingMessagesViewer::fetchContentAttachments), nesjednocovat teď.
     */
    private const MESSAGES_TABLE_ID = 303;

    /** Strop počtu příloh na kartě; nad strop frontend kreslí „+N". */
    private const MAX_CARD_ATTACHMENTS = 3;

    private const PRIMARY_TYPES_CFG_ITEM = 'core.mail.primaryTypes';

    private const DOC_TYPES_CFG_ITEM = 'core.mail.extractedDocTypes';

    private const DOC_KINDS_CFG_ITEM = 'base.registry.docKinds';

    public function collectCards(FeedContext $ctx): array
    {
        $suggestionRows = $this->fetchSuggestionRows($ctx);
        $errorRows      = $this->fetchErrorRows($ctx);
        $notInvoiceRows = $this->fetchNotInvoiceRows($ctx);

        $attachmentsByMessage = $this->fetchAttachmentsByMessage(
            $ctx,
            [...$suggestionRows, ...$errorRows, ...$notInvoiceRows],
        );

        $cards = [];
        foreach ($suggestionRows as $row) {
            $messageAttachments = $attachmentsByMessage[(int) $row['message_ndx']] ?? [];
            $cards[] = $this->withAttachments(
                $this->buildSuggestionCard($ctx, $row),
                $this->suggestionAttachments($row, $messageAttachments),
            );
        }
        foreach ($errorRows as $row) {
            $cards[] = $this->withAttachments(
                $this->buildErrorCard($ctx, $row),
                $attachmentsByMessage[(int) $row['message_ndx']] ?? [],
            );
        }
        foreach ($notInvoiceRows as $row) {
            $cards[] = $this->withAttachments(
                $this->buildNotInvoiceCard($ctx, $row),
                $attachmentsByMessage[(int) $row['message_ndx']] ?? [],
            );
        }
        return $cards;
    }

    /**
     * Řádky vytěžených dokladů ve stavech 10/20/30 pro návrhové karty.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchSuggestionRows(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `e`.`id` AS `extracted_ndx`, `e`.`message` AS `message_ndx`,'
            . ' `e`.`doc_type`, `e`.`extracted_json`, `e`.`confidence`, `e`.`status`,'
            . ' `e`.`source_attachments`,'
            . ' `m`.`subject`, `m`.`sender_name`, `m`.`received_at`, `m`.`raw_source_attachment`'
            . ' FROM `' . self::EXTRACTED_TABLE . '` `e`'
            . ' JOIN `' . self::MESSAGES_TABLE . '` `m` ON `m`.`id` = `e`.`message`'
            . ' WHERE `e`.`status` IN %in'
            . ' AND `e`.`doc_type` != \'other\''
            . ' ORDER BY `m`.`received_at` DESC, `e`.`id` DESC'
            . ' LIMIT %i',
            [
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
                ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
            ],
            $ctx->maxCards,
        );
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
        $docType      = (string) ($row['doc_type'] ?? '');
        $subject      = trim((string) ($row['subject'] ?? ''));

        $canonical = json_decode((string) ($row['extracted_json'] ?? ''), true);
        $canonical = is_array($canonical) ? $canonical : [];

        // Target typu řídí prezentaci karty (titulek/podtitulek/label apply
        // akce) — action kinds i endpointy jsou pro oba targety shodné.
        $extractionTarget = ExtractedDocTypes::targetFor($ctx->config, $docType);
        $isRegistry = $extractionTarget === ExtractedDocTypes::TARGET_REGISTRY;

        [$kind, $stateStyle, $icon] = match ($status) {
            ExtractedDocumentDocument::STATUS_READY_TO_APPLY => ['ready', 'done', 'check'],
            ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE => ['review', 'edit', 'warning'],
            default                                          => ['review', 'confirmed', 'question'],
        };

        $target = ['extractedNdx' => $extractedNdx];
        // Vystavení jde vždy přes review modal (kontrola náhledu před
        // potvrzením) — jednoklikové apply z karty bylo odstraněno, stav 10
        // se liší jen vizuálně (kind/ikona).
        $actions = [
            ['id' => 'review', 'kind' => 'review_extracted', 'target' => $target, 'primary' => true],
            ['id' => 'reject', 'kind' => 'reject_extracted', 'target' => $target],
        ];

        $card = [
            'id'         => 'mail_extracted:' . $extractedNdx,
            'source'     => 'mail',
            'kind'       => $kind,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'category'   => $isRegistry ? FeedSource::CATEGORY_REGISTRY : FeedSource::CATEGORY_INVOICES,
            'title'      => $isRegistry
                ? $this->registryCardTitle($ctx, $docType, $canonical)
                : $this->cardTitle($ctx, $docType, $canonical),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => [
                'messageNdx'   => $messageNdx,
                'extractedNdx' => $extractedNdx,
                'confidence'   => $confidence,
                'target'       => $extractionTarget,
            ],
            'actions'    => $actions,
        ];

        // Strukturovaná hlavička jen když známe partnera — bez něj karta
        // padá na dnešní složený title/subtitle fallback (bez headline se
        // subtitle posílá dál, u headline karet už ne — data jsou v ní).
        $partnerName = $isRegistry
            ? $this->registryPartyName($canonical)
            : $this->counterpartyName($canonical);
        if ($partnerName !== null) {
            $headline = [
                'partnerName' => $partnerName,
                'typeLabel'   => $isRegistry
                    ? $this->docKindLabel($ctx, $docType)
                    : $this->docTypeLabel($ctx, $docType),
            ];
            $amountText = $isRegistry ? null : $this->formatAmount($canonical);
            if ($amountText !== null) {
                $headline['amountText'] = $amountText;
            }
            $card['headline'] = $headline;
        } else {
            $card['subtitle'] = $isRegistry
                ? $this->registryCardSubtitle($ctx, $docType, $canonical, $confidence, $subject)
                : $this->cardSubtitle($ctx, $canonical, $confidence, $subject);
        }

        if ($confidence !== null) {
            $card['confidencePct'] = (int) round($confidence * 100);
        }
        if ($subject !== '') {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        $details = $isRegistry
            ? $this->registryDetails($ctx, $docType, $canonical)
            : $this->docsDetails($ctx, $canonical);
        if ($details !== []) {
            $card['details'] = $details;
        }

        return $card;
    }

    /**
     * Řádky zpráv, u kterých permanentně selhala AI (analysis_state=70),
     * mimo Archiv/Koš — pro chybové karty.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchErrorRows(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `id` AS `message_ndx`, `subject`, `sender_name`, `received_at`, `primary_type`,'
            . ' `raw_source_attachment`'
            . ' FROM `' . self::MESSAGES_TABLE . '`'
            . ' WHERE `analysis_state` = %i AND `docState` NOT IN %in'
            . ' ORDER BY `received_at` DESC, `id` DESC'
            . ' LIMIT %i',
            IncomingMessageDocument::ANALYSIS_FAILED,
            [IncomingMessageDocument::DOC_STATE_ARCHIVED, IncomingMessageDocument::DOC_STATE_TRASH],
            $ctx->maxCards,
        );
    }

    /**
     * Chybová karta — AI selhala; kind=urgent, degradace na review, když
     * dřívější klasifikace už určila `primary_type='other'`.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildErrorCard(FeedContext $ctx, array $row): array
    {
        $messageNdx = (int) $row['message_ndx'];
        $subject    = trim((string) ($row['subject'] ?? ''));
        $isOther    = (string) ($row['primary_type'] ?? '') === 'other';
        $card = [
            'id'         => 'mail_message:' . $messageNdx,
            'source'     => 'mail',
            'kind'       => $isOther ? 'review' : 'urgent',
            'icon'       => 'warning',
            'stateStyle' => 'error',
            'category'   => FeedSource::CATEGORY_OTHER,
            'title'      => $ctx->language === 'cs' ? 'Chyba analýzy e-mailu' : 'E-mail analysis failed',
            'subtitle'   => trim((string) ($row['sender_name'] ?? '')),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => ['messageNdx' => $messageNdx],
            'actions'    => [
                ['id' => 'reanalyze', 'kind' => 'reanalyze', 'target' => ['messageNdx' => $messageNdx], 'primary' => true],
                ['id' => 'openMail',  'kind' => 'open_form', 'target' => ['table' => self::MESSAGES_TABLE, 'recordId' => $messageNdx]],
            ],
        ];
        if ($subject !== '') {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        return $card;
    }

    /**
     * Řádky zpráv pro karty „Není faktura" — AI klasifikovala zprávu jako
     * `other`, zpráva zůstala v Nové a nemá žádný akční extracted doc.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchNotInvoiceRows(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `m`.`id` AS `message_ndx`, `m`.`subject`, `m`.`sender_name`,'
            . ' `m`.`sender_email`, `m`.`received_at`, `m`.`primary_type`, `m`.`raw_source_attachment`'
            . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
            . ' WHERE `m`.`analysis_state` = %i'
            . ' AND `m`.`docState` = %i'
            . ' AND `m`.`primary_type` = \'other\''
            . ' AND NOT EXISTS ('
            . '     SELECT 1 FROM `' . self::EXTRACTED_TABLE . '` `e`'
            . '     WHERE `e`.`message` = `m`.`id` AND `e`.`status` IN %in'
            . ' )'
            . ' ORDER BY `m`.`received_at` DESC, `m`.`id` DESC'
            . ' LIMIT %i',
            IncomingMessageDocument::ANALYSIS_ANALYZED,
            IncomingMessageDocument::DOC_STATE_NEW,
            [
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
                ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
            ],
            $ctx->maxCards,
        );
    }

    /**
     * Karta „Není faktura" — jednoklikový úklid: Koš (primary) / Archiv /
     * otevřít editační formulář zprávy.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildNotInvoiceCard(FeedContext $ctx, array $row): array
    {
        $messageNdx = (int) $row['message_ndx'];
        $target = ['messageNdx' => $messageNdx];

        $subject = trim((string) ($row['subject'] ?? ''));
        $sender = trim((string) ($row['sender_name'] ?? '')) !== ''
            ? trim((string) $row['sender_name'])
            : trim((string) ($row['sender_email'] ?? ''));

        $card = [
            'id'         => 'mail_notinvoice:' . $messageNdx,
            'source'     => 'mail',
            'kind'       => 'info',
            'icon'       => 'info',
            'stateStyle' => 'archive',
            'category'   => FeedSource::CATEGORY_OTHER,
            'title'      => ($ctx->language === 'cs' ? 'Není faktura — ' : 'Not an invoice — ')
                . $this->primaryTypeLabel($ctx, (string) ($row['primary_type'] ?? 'other')),
            'subtitle'   => $sender,
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => ['messageNdx' => $messageNdx],
            'actions'    => [
                ['id' => 'trash',    'kind' => 'trash_message',   'target' => $target, 'primary' => true],
                ['id' => 'archive',  'kind' => 'archive_message', 'target' => $target],
                ['id' => 'openMail', 'kind' => 'open_form',       'target' => ['table' => self::MESSAGES_TABLE, 'recordId' => $messageNdx]],
            ],
        ];
        if ($subject !== '') {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        return $card;
    }

    /**
     * Batch obsahových příloh pro všechny karty — jeden dotaz na celý collect.
     * Vrací mapu messageNdx → seznam příloh (bez raw `.eml`, řazení
     * `att_order ASC, name ASC`); struktura položky zrcadlí
     * IncomingMessagesViewer::fetchContentAttachments().
     *
     * @param list<array<string,mixed>> $rows řádky s `message_ndx` + `raw_source_attachment`
     * @return array<int, list<array{id: int, name: string, mime_type: string, file_size: int}>>
     */
    private function fetchAttachmentsByMessage(FeedContext $ctx, array $rows): array
    {
        $rawByMessage = [];
        foreach ($rows as $row) {
            $messageNdx = (int) $row['message_ndx'];
            $rawByMessage[$messageNdx] = isset($row['raw_source_attachment']) && $row['raw_source_attachment'] !== null
                ? (int) $row['raw_source_attachment']
                : null;
        }
        if ($rawByMessage === []) {
            return [];
        }

        $files = $ctx->db->fetchAll(
            'SELECT `id`, `record_id`, `name`, `file_name`, `mime_type`, `file_size`'
            . ' FROM `' . self::ATTACHMENTS_TABLE . '`'
            . ' WHERE `table_id` = %i AND `record_id` IN %in AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            self::MESSAGES_TABLE_ID,
            array_keys($rawByMessage),
        );

        $byMessage = [];
        foreach ($files as $f) {
            $messageNdx = (int) $f['record_id'];
            $id = (int) $f['id'];
            if (($rawByMessage[$messageNdx] ?? null) === $id) {
                continue; // raw .eml není obsahová příloha
            }
            $byMessage[$messageNdx][] = [
                'id'        => $id,
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
            ];
        }
        return $byMessage;
    }

    /**
     * Přílohy návrhové karty: `source_attachments` (JSON pole ndx) filtruje
     * obsahové přílohy zprávy — uživatel vidí přímo přílohu, ze které doklad
     * vznikl. Prázdné/nevalidní pole nebo žádný průnik → fallback na všechny
     * obsahové přílohy. Pořadí se zachovává dle batch dotazu (att_order).
     *
     * @param array<string,mixed> $row
     * @param list<array{id: int, name: string, mime_type: string, file_size: int}> $messageAttachments
     * @return list<array{id: int, name: string, mime_type: string, file_size: int}>
     */
    private function suggestionAttachments(array $row, array $messageAttachments): array
    {
        $sourceNdx = json_decode((string) ($row['source_attachments'] ?? ''), true);
        if (is_array($sourceNdx) && $sourceNdx !== []) {
            $wanted = array_map('intval', array_filter($sourceNdx, 'is_numeric'));
            $filtered = array_values(array_filter(
                $messageAttachments,
                static fn(array $att): bool => in_array($att['id'], $wanted, true),
            ));
            if ($filtered !== []) {
                return $filtered;
            }
        }
        return $messageAttachments;
    }

    /**
     * Doplní do karty volitelná pole `attachments` (strop MAX_CARD_ATTACHMENTS)
     * + `attachmentsTotal` (počet před stropem). Karta bez příloh pole nemá.
     *
     * @param array<string,mixed> $card
     * @param list<array{id: int, name: string, mime_type: string, file_size: int}> $attachments
     * @return array<string,mixed>
     */
    private function withAttachments(array $card, array $attachments): array
    {
        if ($attachments === []) {
            return $card;
        }
        $card['attachments']      = array_slice($attachments, 0, self::MAX_CARD_ATTACHMENTS);
        $card['attachmentsTotal'] = count($attachments);
        return $card;
    }

    /** Lokalizovaný label primárního typu z cfgItem; fallback na holý key. */
    private function primaryTypeLabel(FeedContext $ctx, string $primaryType): string
    {
        $cfg = $ctx->config?->cfgItem(self::PRIMARY_TYPES_CFG_ITEM);
        if (is_array($cfg) && isset($cfg[$primaryType]['name']) && is_string($cfg[$primaryType]['name'])) {
            return $cfg[$primaryType]['name'];
        }
        return $primaryType === 'other' ? ($ctx->language === 'cs' ? 'Ostatní' : 'Other') : $primaryType;
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

    /**
     * Titulek registry karty: „{druh dokumentu} — {protistrana}" (druh
     * z `base.registry.docKinds`, protistrana z `party.name` canonicalu).
     * Bez protistrany jen label druhu.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryCardTitle(FeedContext $ctx, string $docType, array $canonical): string
    {
        $label = $this->docKindLabel($ctx, $docType);
        $party = $this->registryPartyName($canonical);
        return $party !== null ? ($label . ' — ' . $party) : $label;
    }

    /**
     * Jméno protistrany registry karty z `party.name` canonicalu; null pro
     * chybějící/prázdné.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryPartyName(array $canonical): ?string
    {
        $party = $canonical['party']['name'] ?? null;
        return is_string($party) && trim($party) !== '' ? trim($party) : null;
    }

    /**
     * Podtitulek registry karty: „platí do {datum}" · jistota · zdrojový
     * e-mail. Klíč kindFields nesoucí konec platnosti se hledá **inverzí**
     * `docKinds[docKind].promote` (hodnota `valid_to`) — jediné místo pravdy
     * pro mapování polí, žádná duplikace výčtu.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryCardSubtitle(
        FeedContext $ctx,
        string $docType,
        array $canonical,
        ?float $confidence,
        string $subject,
    ): string {
        $parts = [];

        $validTo = $this->registryValidTo($ctx, $docType, $canonical);
        if ($validTo !== null) {
            $parts[] = ($ctx->language === 'cs' ? 'platí do ' : 'valid until ') . $validTo;
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

    /** Lokalizovaný label druhu dokumentu z `base.registry.docKinds`; fallback docTypeLabel. */
    private function docKindLabel(FeedContext $ctx, string $docType): string
    {
        $docKind = ExtractedDocTypes::docKindFor($ctx->config, $docType);
        if ($docKind !== null) {
            $kinds = $ctx->config?->cfgItem(self::DOC_KINDS_CFG_ITEM);
            if (is_array($kinds) && isset($kinds[$docKind]['name']) && is_string($kinds[$docKind]['name'])) {
                return $kinds[$docKind]['name'];
            }
        }
        return $this->docTypeLabel($ctx, $docType);
    }

    /**
     * Konec platnosti z kindFields: klíč = inverze promote mapy druhu
     * (metaKey → 'valid_to'). Vrací lokalizované datum, nebo null.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryValidTo(FeedContext $ctx, string $docType, array $canonical): ?string
    {
        $docKind = ExtractedDocTypes::docKindFor($ctx->config, $docType);
        if ($docKind === null) {
            return null;
        }
        $kinds = $ctx->config?->cfgItem(self::DOC_KINDS_CFG_ITEM);
        $promote = is_array($kinds) ? ($kinds[$docKind]['promote'] ?? null) : null;
        if (!is_array($promote)) {
            return null;
        }
        $metaKey = array_search('valid_to', $promote, true);
        if (!is_string($metaKey)) {
            return null;
        }

        $value = $canonical['kindFields'][$metaKey] ?? null;
        if (!is_string($value)) {
            return null;
        }
        return $this->formatDate($ctx, $value);
    }

    /** Lokalizované datum (cs `j. n. Y`, en `Y-m-d`); prázdný/nevalidní vstup → null. */
    private function formatDate(FeedContext $ctx, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        return $ctx->language === 'cs' ? $date->format('j. n. Y') : $date->format('Y-m-d');
    }

    /**
     * Řádky expanderu návrhové karty (docs target): číslo dokladu, splatnost,
     * variabilní symbol — jen neprázdné hodnoty, fixní pořadí. Labely
     * lokalizuje server (konzistentní s lokalizací titulků karet).
     *
     * @param array<string,mixed> $canonical
     * @return list<array{label: string, value: string}>
     */
    private function docsDetails(FeedContext $ctx, array $canonical): array
    {
        $rows = [];

        $docNumber = $canonical['docNumber'] ?? null;
        if (is_string($docNumber) && trim($docNumber) !== '') {
            $rows[] = [
                'label' => $ctx->language === 'cs' ? 'Číslo dokladu' : 'Document number',
                'value' => trim($docNumber),
            ];
        }

        $dueDate = $canonical['dates']['dueDate'] ?? null;
        $dueDate = is_string($dueDate) ? $this->formatDate($ctx, $dueDate) : null;
        if ($dueDate !== null) {
            $rows[] = [
                'label' => $ctx->language === 'cs' ? 'Splatnost' : 'Due date',
                'value' => $dueDate,
            ];
        }

        $reference = $canonical['payment']['paymentReference'] ?? null;
        if (is_int($reference)) {
            $reference = (string) $reference;
        }
        if (is_string($reference) && trim($reference) !== '') {
            $rows[] = [
                'label' => $ctx->language === 'cs' ? 'Variabilní symbol' : 'Payment reference',
                'value' => trim($reference),
            ];
        }

        return $rows;
    }

    /**
     * Expander registry karty: jediný řádek „Platí do" z konce platnosti
     * (`registryValidTo`); bez něj se `details` neposílá.
     *
     * @param array<string,mixed> $canonical
     * @return list<array{label: string, value: string}>
     */
    private function registryDetails(FeedContext $ctx, string $docType, array $canonical): array
    {
        $validTo = $this->registryValidTo($ctx, $docType, $canonical);
        if ($validTo === null) {
            return [];
        }
        return [[
            'label' => $ctx->language === 'cs' ? 'Platí do' : 'Valid until',
            'value' => $validTo,
        ]];
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
