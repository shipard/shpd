<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Units;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní seed systémových jednotek do tabulky `core_units`.
 *
 * Pro každý záznam v `unitsSeed.jsonc`:
 *   - existuje-li v DB záznam se stejným `system_code` (libovolný stav,
 *     včetně `V archívu` / `Smazáno`), nic neděláme — uživatel si jednotku
 *     mohl zarchivovat a nechceme mu ji znovu obnovovat
 *   - jinak INSERT s docState = 40 (V pořádku), docStateMain = 3
 *
 * Volá se z `DsUpgradeCommand`. Vzor: `MailRouterProvisioner`.
 */
class UnitsProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $seedFilePath,
    ) {}

    /**
     * @return array{units: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        $seed = JsoncParser::parseFile($this->seedFilePath);
        if (!is_array($seed)) {
            throw new \RuntimeException('Units seed file must contain a JSON array: ' . $this->seedFilePath);
        }

        $created = 0;
        $existing = 0;

        foreach ($seed as $entry) {
            if (!is_array($entry) || empty($entry['system_code'])) {
                throw new \RuntimeException('Invalid seed entry — missing system_code');
            }

            $code = (string) $entry['system_code'];
            $row = $this->db->fetchRow(
                'SELECT id FROM core_units WHERE system_code = %s',
                $code,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $this->db->insertRow('core_units', [
                'name'         => (string) ($entry['name:cs'] ?? $entry['name'] ?? $code),
                'shortcut'     => (string) ($entry['shortcut'] ?? ''),
                'system_code'  => $code,
                'quantity'     => (string) ($entry['quantity'] ?? 'other'),
                'coefficient'  => $entry['coefficient'] ?? null,
                'is_base'      => !empty($entry['is_base']) ? 1 : 0,
                'docState'     => 40,
                'docStateMain' => 3,
            ]);
            $created++;
        }

        return ['units' => ['created' => $created, 'existing' => $existing]];
    }
}
