<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Najde expirované rezervace v `core_mail_analysis_claims` (released=false,
 * expires_at < now), označí je `released=true` s reason `expired` a vrátí
 * jejich zprávy z `docState=20` zpět na `docState=10`.
 *
 * Volá se 1×/min z cronu (CLI `mail-analysis-reap`). Recovery, když analyzer
 * mezi `claim` a `result` spadne. Spec: tasks/mail-phase3a.md §3.7.
 */
class AnalysisClaimReaper
{
    public const RELEASE_REASON_EXPIRED = 'expired';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const DOC_STATE_NEW = 10;
    private const DOC_STATE_MAIN_NEW = 1;
    private const DOC_STATE_ANALYZING = 20;

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return list<array{
     *     claim_id: int,
     *     message_id: int,
     *     analyzer_id: string,
     *     duration_seconds: int
     * }>
     */
    public function reapExpired(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $expired = $this->db->fetchAll(
            'SELECT id, message, analyzer_id, claimed_at FROM %n WHERE %n = %i AND %n < %s',
            self::CLAIMS_TABLE,
            'released',
            0,
            'expires_at',
            $nowStr,
        );

        if ($expired === []) {
            return [];
        }

        $reaped = [];
        $this->db->begin();
        try {
            foreach ($expired as $claim) {
                $claimId = (int) $claim['id'];
                $messageId = (int) $claim['message'];
                $analyzerId = (string) $claim['analyzer_id'];
                $claimedAt = (string) $claim['claimed_at'];

                $this->db->updateWhere(
                    self::CLAIMS_TABLE,
                    [
                        'released' => 1,
                        'released_at' => $nowStr,
                        'release_reason' => self::RELEASE_REASON_EXPIRED,
                    ],
                    '%n = %i',
                    'id',
                    $claimId,
                );

                // Zpráva se vrací do queue jen pokud je stále v "Analyzuje se" — admin
                // mohl mezitím manuálně přepnout, nepřepisujeme jeho stav.
                $this->db->execute(
                    'UPDATE %n SET %n = %i, %n = %i, %n = %s WHERE %n = %i AND %n = %i',
                    self::MESSAGES_TABLE,
                    'docState',
                    self::DOC_STATE_NEW,
                    'docStateMain',
                    self::DOC_STATE_MAIN_NEW,
                    'modified',
                    $nowStr,
                    'id',
                    $messageId,
                    'docState',
                    self::DOC_STATE_ANALYZING,
                );

                $reaped[] = [
                    'claim_id' => $claimId,
                    'message_id' => $messageId,
                    'analyzer_id' => $analyzerId,
                    'duration_seconds' => $this->durationSeconds($claimedAt, $nowStr),
                ];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $reaped;
    }

    private function durationSeconds(string $from, string $to): int
    {
        $a = strtotime($from);
        $b = strtotime($to);
        if ($a === false || $b === false) {
            return 0;
        }
        return max(0, $b - $a);
    }
}
