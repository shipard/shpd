<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Zdraví fronty odchozí pošty (D25 — viditelnost selhání):
 *
 * 1. `failed_24h` — terminálně selhané zprávy s pokusem za posledních
 *    24 h (agregovaně: počet + nejstarší). Schéma nemá failed_at,
 *    odvozuje se z časů pokusů v core_mail_outbox_log.
 * 2. `stuck_pending` — nejstarší due `pending` čeká přes 30 minut =
 *    worker neběží nebo leží relay. Měří se od `next_attempt`, ne od
 *    `created` — zprávy v řádném backoffu nejsou false positive.
 *
 * Registrace: modules/core/mail/module.jsonc → alertChecks
 * (id core.mail.outbox_health).
 */
class MailOutboxAlertCheck extends AlertCheck
{
    private const FAILED_WINDOW_SEC = 86400;
    private const STUCK_PENDING_SEC = 1800;

    public function run(): array
    {
        $now = $this->now();
        $findings = [];

        $failed = $this->db->fetchRow(
            'SELECT COUNT(*) AS cnt, MIN(created) AS oldest FROM core_mail_outbox'
            . " WHERE state = 'failed'"
            . ' AND id IN (SELECT outbox_id FROM core_mail_outbox_log WHERE ts >= %s)',
            $now->modify('-' . self::FAILED_WINDOW_SEC . ' seconds')->format('Y-m-d H:i:s'),
        );
        $failedCount = (int) ($failed['cnt'] ?? 0);
        if ($failedCount > 0) {
            $findings[] = $this->failedFinding($failedCount, (string) ($failed['oldest'] ?? ''));
        }

        $stuck = $this->db->fetchRow(
            'SELECT COUNT(*) AS cnt, MIN(next_attempt) AS oldest FROM core_mail_outbox'
            . " WHERE state = 'pending' AND next_attempt <= %s",
            $now->modify('-' . self::STUCK_PENDING_SEC . ' seconds')->format('Y-m-d H:i:s'),
        );
        $stuckCount = (int) ($stuck['cnt'] ?? 0);
        if ($stuckCount > 0) {
            $findings[] = $this->stuckFinding($stuckCount, (string) ($stuck['oldest'] ?? ''));
        }

        return $findings;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    private function failedFinding(int $count, string $oldest): AlertFinding
    {
        $isCs = $this->language === 'cs';

        return new AlertFinding(
            findingKey: 'failed_24h',
            title: $isCs
                ? 'Odchozí pošta: selhané zprávy'
                : 'Outbound mail: failed messages',
            message: $isCs
                ? "Za posledních 24 hodin terminálně selhalo {$count} zpráv"
                . " (nejstarší z {$oldest}). Po opravě transportu je vrať do"
                . ' fronty přes `shpd-ds mail-outbox-retry --id N`.'
                : "{$count} messages failed terminally in the last 24 hours"
                . " (oldest from {$oldest}). After fixing the transport,"
                . ' re-queue them via `shpd-ds mail-outbox-retry --id N`.',
            severity: 'warning',
            context: ['count' => $count, 'oldest' => $oldest],
        );
    }

    private function stuckFinding(int $count, string $oldest): AlertFinding
    {
        $isCs = $this->language === 'cs';

        return new AlertFinding(
            findingKey: 'stuck_pending',
            title: $isCs
                ? 'Odchozí pošta: fronta se nehýbe'
                : 'Outbound mail: queue is stuck',
            message: $isCs
                ? "{$count} zpráv čeká na odeslání déle než 30 minut po termínu"
                . " (nejstarší mělo odejít {$oldest}). Worker mail-outbox-run"
                . ' nejspíš neběží (cron), nebo leží relay — ověř přes'
                . ' `shpd-ds mail-send-test`.'
                : "{$count} messages have been due for over 30 minutes"
                . " (oldest was due at {$oldest}). The mail-outbox-run worker"
                . ' is probably not running (cron), or the relay is down —'
                . ' verify via `shpd-ds mail-send-test`.',
            severity: 'warning',
            context: ['count' => $count, 'oldest' => $oldest],
        );
    }
}
