<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Najde expirované rezervace v `core_mail_analysis_claims` (released=false,
 * expires_at < now), označí je `released=true` s reason `expired` a vrátí
 * jejich zprávy z `analysis_state=20` (Analyzuje se) zpět do fronty
 * (`analysis_state=10`). docState (workflow) se nemění.
 *
 * Volá se 1×/min z cronu (CLI `mail-analysis-reap`). Recovery, když analyzer
 * mezi `claim` a `result` spadne. Spec: tasks/mail-phase3a.md §3.7.
 */
class AnalysisClaimReaper
{
    public const RELEASE_REASON_EXPIRED = 'expired';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSIS_QUEUED = 10;
    private const ANALYSIS_ANALYZING = 20;

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

                // Analýza se vrací do fronty jen pokud je stále "Analyzuje se" —
                // result/failed mohl mezitím doběhnout, nepřepisujeme jeho stav.
                $this->db->execute(
                    'UPDATE %n SET %n = %i, %n = %s WHERE %n = %i AND %n = %i',
                    self::MESSAGES_TABLE,
                    'analysis_state',
                    self::ANALYSIS_QUEUED,
                    'modified',
                    $nowStr,
                    'id',
                    $messageId,
                    'analysis_state',
                    self::ANALYSIS_ANALYZING,
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
