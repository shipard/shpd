<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Lookup/store pro `core_mail_incoming_idempotency`.
 *
 * TTL je 7 dní. Starší klíče ignorujeme při lookup a mažeme periodicky přes
 * CLI `mail-idempotency-prune` (cron 1×/den).
 */
class IdempotencyStore
{
    public const TTL_DAYS = 7;

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * Najde existující idempotency záznam (pokud je v TTL).
     *
     * @return array{message_id: int, response_body: string}|null
     */
    public function lookup(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $cutoff = date('Y-m-d H:i:s', time() - self::TTL_DAYS * 86400);

        $row = $this->db->fetchRow(
            'SELECT message, response_body FROM core_mail_incoming_idempotency '
            . 'WHERE idempotency_key = %s AND created >= %s',
            $key,
            $cutoff,
        );

        if ($row === null) {
            return null;
        }

        return [
            'message_id' => (int) $row['message'],
            'response_body' => (string) $row['response_body'],
        ];
    }

    public function store(string $key, int $messageId, string $responseBody): void
    {
        $this->db->insertRow('core_mail_incoming_idempotency', [
            'idempotency_key' => $key,
            'message' => $messageId,
            'response_body' => $responseBody,
            'created' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Vymaže záznamy starší než `$days` dní. Vrací počet smazaných řádků.
     */
    public function prune(int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        $this->db->execute(
            'DELETE FROM core_mail_incoming_idempotency WHERE created < %s',
            $cutoff,
        );

        return $this->db->getAffectedRows();
    }
}
