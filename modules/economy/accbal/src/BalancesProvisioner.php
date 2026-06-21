<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní seed standardních saldokont (skupiny + jejich účty) do
 * economy_accbal_balances / economy_accbal_balance_accounts.
 *
 * Idempotence dle `code` skupiny: existuje-li balance se stejným `code`
 * (libovolný stav, vč. archivu/koše), **přeskočíme celou skupinu** — uživatel
 * si ji mohl upravit a nechceme mu to přepsat. Jinak INSERT skupiny
 * (docState 40 / docStateMain 3) + INSERT jejích účtů.
 *
 * Volá se z DsUpgradeCommand. Vzor: AccountChartProvisioner.
 */
class BalancesProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $seedFilePath,
    ) {}

    /**
     * @return array{balances: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        $seed = JsoncParser::parseFile($this->seedFilePath);
        if (!is_array($seed)) {
            throw new \RuntimeException('Balances seed file must contain a JSON array: ' . $this->seedFilePath);
        }

        $created = 0;
        $existing = 0;

        foreach ($seed as $group) {
            if (!is_array($group) || !isset($group['code']) || trim((string) $group['code']) === '') {
                throw new \RuntimeException('Invalid balances seed entry — missing code');
            }

            $code = trim((string) $group['code']);
            $row = $this->db->fetchRow(
                'SELECT id FROM economy_accbal_balances WHERE code = %s',
                $code,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $balanceId = $this->db->insertRow('economy_accbal_balances', [
                'code'         => $code,
                'name'         => (string) ($group['name'] ?? $code),
                'short_name'   => isset($group['short_name']) ? (string) $group['short_name'] : null,
                'sort_order'   => (int) ($group['sort_order'] ?? 0),
                'docState'     => 40,
                'docStateMain' => 3,
            ]);

            $accounts = is_array($group['accounts'] ?? null) ? $group['accounts'] : [];
            $accSort = 0;
            foreach ($accounts as $acc) {
                if (!is_array($acc) || trim((string) ($acc['account_number'] ?? '')) === '') {
                    throw new \RuntimeException("Invalid account entry in balance '{$code}' — missing account_number");
                }
                $accSort += 10;
                $this->db->insertRow('economy_accbal_balance_accounts', [
                    'balance'        => $balanceId,
                    'account_number' => trim((string) $acc['account_number']),
                    'acc_side'       => (int) ($acc['acc_side'] ?? 0),
                    'amounts_sign'   => (int) ($acc['amounts_sign'] ?? 0),
                    'bal_side'       => (int) ($acc['bal_side'] ?? 0),
                    'modify_sign'    => !empty($acc['modify_sign']) ? 1 : 0,
                    'note'           => isset($acc['note']) ? (string) $acc['note'] : null,
                    'sort_order'     => (int) ($acc['sort_order'] ?? $accSort),
                    'docState'       => 40,
                    'docStateMain'   => 3,
                ]);
            }

            $created++;
        }

        return ['balances' => ['created' => $created, 'existing' => $existing]];
    }
}
