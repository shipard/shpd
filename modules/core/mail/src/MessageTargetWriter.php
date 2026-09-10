<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Partner a titulek zprávy z **cílového záznamu**
 * (tasks/mail-import-partner-title.md D1–D4, D7, D8) — třetí cesta vedle
 * `/result` (AI) a `IsdocImportService`.
 *
 * Zprávu navázanou na doklad (`target_table_id` = `docs_core_heads`)
 * `MailRunner` posílá s `analysis_state = 0`, takže do AI fronty nikdy
 * nevstoupí a `/result` ji nikdy nepotká. Fakta si proto server dopočítá
 * sám z cíle, který v payloadu už má:
 *
 *   - `partner_person` = `docs_core_heads.partner` (D2 — protistrana
 *     z definice, pravidlo sedí i na `invno` a `cmnbkp`),
 *   - `partner_name`   = `full_name` Osoby (D3 — pojistka pro případ
 *     smazané Osoby, ne snapshot dokladu),
 *   - `ai_title`       = {@see MessageTitleComposer::fromDocument()} (D4).
 *
 * Dvě použití:
 *   - {@see factsFor()} při `POST /_mail/import` — cíl je autorita, přebíjí
 *     hodnoty z payloadu (vrstva 2 nad vrstvou 1, D8),
 *   - {@see backfillRow()} v `shpd-ds mail-target-backfill` — zapisuje **jen
 *     do NULL sloupců**, aby nepřepsal ručně vybraného partnera ani titulek,
 *     který zprávě dala AI (D8, P7).
 *
 * Fakta jsou best-effort: výjimka se polkne do `ErrorLogger::warn` a vrátí
 * se samé `null` — import zprávy kvůli nim nesmí spadnout (symetrie
 * s {@see MessagePartnerWriter}).
 */
final class MessageTargetWriter
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const DOCS_TABLE = 'docs_core_heads';
    private const PERSONS_TABLE = 'base_persons_persons';

    /** Sloupce zprávy, které tahle třída plní. */
    private const FACT_COLUMNS = ['partner_person', 'partner_name', 'ai_title'];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly MessageTitleComposer $titleComposer,
    ) {}

    /**
     * Produkční wiring: composer v jazyce výchozího aktivního AI profilu DS,
     * ne v jazyce requestu (P2 — intake od runneru `Accept-Language` nenese).
     */
    public static function forDataSource(DataSourceConnection $db, DataSourceConfig $dsConfig): self
    {
        return new self($db, MessageTitleComposer::forDataSource($db, $dsConfig));
    }

    /**
     * Fakta z cílového záznamu. Trojice samých `null` pro prázdný cíl, cíl
     * mimo `docs_core_heads` (Spisovna — import ji nikdy nevytváří),
     * `$targetRow <= 0`, neexistující doklad a jakoukoli chybu.
     *
     * @return array{partner_person: ?int, partner_name: ?string, ai_title: ?string}
     */
    public function factsFor(?string $targetTableId, ?int $targetRow): array
    {
        $doc = $this->fetchDocument($targetTableId, $targetRow);
        if ($doc === null) {
            return self::emptyFacts();
        }

        $partner = (int) ($doc['partner'] ?? 0);

        return [
            'partner_person' => $partner > 0 ? $partner : null,
            'partner_name'   => MessagePartnerWriter::normalizeName($doc['partner_full_name'] ?? null),
            'ai_title'       => $this->titleComposer->fromDocument($doc),
        ];
    }

    /**
     * Backfill jedné zprávy podle jejího id (tenký wrapper — příkaz, který
     * řádek už načetl, volá {@see backfillRow()}).
     */
    public function backfill(int $messageNdx): bool
    {
        try {
            $message = $this->db->fetchRow(
                'SELECT id, target_table_id, target_row, partner_person, partner_name, ai_title'
                . ' FROM %n WHERE id = %i',
                self::MESSAGES_TABLE, $messageNdx,
            );
        } catch (\Throwable $e) {
            ErrorLogger::warn('MessageTargetWriter: message lookup failed', [
                'messageNdx' => $messageNdx,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return $message !== null && $this->backfillRow($message);
    }

    /**
     * Doplní zprávě jen ty ze sloupců {@see FACT_COLUMNS}, které jsou na ní
     * NULL a cíl pro ně má hodnotu (D8/P7). Prázdný `$set` → žádný dotaz.
     *
     * @param array<string, mixed> $message řádek zprávy včetně `target_*`
     *        a stávajících hodnot plněných sloupců
     * @return bool true = něco se zapsalo
     */
    public function backfillRow(array $message): bool
    {
        $messageNdx = (int) ($message['id'] ?? 0);
        if ($messageNdx <= 0) {
            return false;
        }

        $facts = $this->factsFor(
            isset($message['target_table_id']) ? (string) $message['target_table_id'] : null,
            isset($message['target_row']) ? (int) $message['target_row'] : null,
        );

        $set = [];
        foreach (self::FACT_COLUMNS as $column) {
            if ($facts[$column] !== null && ($message[$column] ?? null) === null) {
                $set[$column] = $facts[$column];
            }
        }
        if ($set === []) {
            return false;
        }

        try {
            $this->db->execute('UPDATE %n SET %a WHERE id = %i', self::MESSAGES_TABLE, $set, $messageNdx);
        } catch (\Throwable $e) {
            ErrorLogger::warn('MessageTargetWriter: backfill update failed', [
                'messageNdx' => $messageNdx,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Hlavička dokladu + jméno Osoby partnera. `target_table_id` je vstup
     * zvenčí, takže do dotazu jde jen po regex validaci — i když se dnes
     * porovnává na konstantu (P4; vzor
     * `MessageProposalApplier::targetPartnerId()`, který si partnera z cíle
     * čte sám, protože Použít má jinou sémantiku — přepisuje bez guardu).
     *
     * @return array<string, mixed>|null
     */
    private function fetchDocument(?string $targetTableId, ?int $targetRow): ?array
    {
        $table = trim((string) $targetTableId);
        if ($targetRow === null || $targetRow <= 0 || preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            return null;
        }
        if ($table !== self::DOCS_TABLE) {
            ErrorLogger::debug('MessageTargetWriter: target table without facts, skipped', [
                'targetTableId' => $table,
                'targetRow' => $targetRow,
            ]);
            return null;
        }

        try {
            return $this->db->fetchRow(
                'SELECT h.doc_type, h.doc_number, h.total_amount, h.doc_currency, h.partner,'
                . ' p.full_name AS partner_full_name'
                . ' FROM %n h LEFT JOIN %n p ON p.id = h.partner'
                . ' WHERE h.id = %i',
                self::DOCS_TABLE, self::PERSONS_TABLE, $targetRow,
            );
        } catch (\Throwable $e) {
            ErrorLogger::warn('MessageTargetWriter: target document lookup failed', [
                'targetTableId' => $table,
                'targetRow' => $targetRow,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** @return array{partner_person: ?int, partner_name: ?string, ai_title: ?string} */
    private static function emptyFacts(): array
    {
        return ['partner_person' => null, 'partner_name' => null, 'ai_title' => null];
    }
}
