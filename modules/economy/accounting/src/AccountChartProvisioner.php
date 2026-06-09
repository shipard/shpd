<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní seed standardní účtové osnovy do `economy_accounting_accounts`.
 *
 * Pro každý záznam v seed souboru:
 *   - existuje-li v DB záznam se stejným `number` (libovolný stav, včetně
 *     `V archívu` / `Smazáno`), nic neděláme — uživatel si účet mohl
 *     zarchivovat / upravit a nechceme mu ho přepisovat
 *   - jinak INSERT s is_system = 1, docState = 40 (V pořádku), docStateMain = 3
 *
 * Provisioner vkládá jen int hodnoty z enumů — nečte compiled config, takže
 * nemá závislost na pořadí kompilace. `account_level`/`g1`/`g2`/`g3` se
 * dopočítají z `number` přes `AccountDocument::deriveStructure()`.
 *
 * Volá se z `DsUpgradeCommand`. Vzor: `ItemKindsProvisioner`.
 */
class AccountChartProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $seedFilePath,
    ) {}

    /**
     * @return array{accountChart: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        $seed = JsoncParser::parseFile($this->seedFilePath);
        if (!is_array($seed)) {
            throw new \RuntimeException('Account chart seed file must contain a JSON array: ' . $this->seedFilePath);
        }

        $created = 0;
        $existing = 0;

        foreach ($seed as $entry) {
            if (!is_array($entry) || !isset($entry['number']) || trim((string) $entry['number']) === '') {
                throw new \RuntimeException('Invalid seed entry — missing number');
            }

            $number = trim((string) $entry['number']);
            $row = $this->db->fetchRow(
                'SELECT id FROM economy_accounting_accounts WHERE number = %s',
                $number,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $structure = AccountDocument::deriveStructure($number);

            $values = [
                'number'        => $number,
                'name'          => (string) ($entry['name'] ?? $number),
                'short_name'    => isset($entry['short_name']) ? (string) $entry['short_name'] : null,
                'account_level' => $structure['account_level'],
                'g1'            => $structure['g1'],
                'g2'            => $structure['g2'],
                'g3'            => $structure['g3'],
                'is_system'     => 1,
                'docState'      => 40,
                'docStateMain'  => 3,
            ];

            // Enumy se vkládají jen pokud je klíč v seed záznamu přítomen.
            // account_kind MŮŽE být 0 (= Aktiva) a v tom případě se 0 vkládá;
            // costs_type / results_type se hodnotou 0 (= ---) do seedu nepíšou,
            // takže chybějící klíč → sloupec zůstane NULL.
            if (array_key_exists('account_kind', $entry)) {
                $values['account_kind'] = (int) $entry['account_kind'];
            }
            if (array_key_exists('costs_type', $entry)) {
                $values['costs_type'] = (int) $entry['costs_type'];
            }
            if (array_key_exists('results_type', $entry)) {
                $values['results_type'] = (int) $entry['results_type'];
            }

            $this->db->insertRow('economy_accounting_accounts', $values);
            $created++;
        }

        return ['accountChart' => ['created' => $created, 'existing' => $existing]];
    }
}
