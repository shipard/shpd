<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Matchování odesílatele proti pravidlům `core_mail_sender_rules`
 * (Fáze 3 Spisovny — pre-triage při ingestu).
 *
 * Matchují výhradně potvrzená pravidla (docState 40) s disposition
 * `archive` (D7 — auto-archivuje jen deterministika, které uživatel
 * věří). Precedence: přesný e-mail > doména; lowercase na obou stranách.
 */
class SenderRuleMatcher
{
    /** docState potvrzeného pravidla (core.system.docStatesArchive). */
    private const DOC_STATE_CONFIRMED = 40;

    public function __construct(
        private readonly \Dibi\Connection $db,
    ) {
    }

    /**
     * Vrátí první matchující pravidlo jako pole (id, pattern_kind, pattern,
     * disposition), nebo null. Jeden dotaz s prioritním řazením.
     *
     * @return array<string, mixed>|null
     */
    public function match(string $senderEmail): ?array
    {
        $email = strtolower(trim($senderEmail));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);

        $row = $this->db->fetch(
            'SELECT id, pattern_kind, pattern, disposition'
            . ' FROM core_mail_sender_rules'
            . ' WHERE docState = %i AND disposition = %s'
            . ' AND ((pattern_kind = %s AND pattern = %s) OR (pattern_kind = %s AND pattern = %s))'
            . ' ORDER BY pattern_kind = %s DESC, id ASC'
            . ' LIMIT 1',
            self::DOC_STATE_CONFIRMED,
            'archive',
            'email',
            $email,
            'domain',
            $domain,
            'email',
        );

        return $row !== null ? (array) $row : null;
    }
}
