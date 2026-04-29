<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní seed systémových druhů položek do tabulky `economy_items_kinds`.
 * Stejný pattern jako `UnitsProvisioner` — `system_code` je marker systémového záznamu.
 */
class ItemKindsProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $seedFilePath,
    ) {}

    /**
     * @return array{kinds: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        $seed = JsoncParser::parseFile($this->seedFilePath);
        if (!is_array($seed)) {
            throw new \RuntimeException('Item kinds seed file must contain a JSON array: ' . $this->seedFilePath);
        }

        $created = 0;
        $existing = 0;

        foreach ($seed as $entry) {
            if (!is_array($entry) || empty($entry['system_code'])) {
                throw new \RuntimeException('Invalid seed entry — missing system_code');
            }

            $code = (string) $entry['system_code'];
            $row = $this->db->fetchRow(
                'SELECT id FROM economy_items_kinds WHERE system_code = %s',
                $code,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $this->db->insertRow('economy_items_kinds', [
                'name'         => (string) ($entry['name:cs'] ?? $entry['name'] ?? $code),
                'item_type'    => (int) ($entry['item_type'] ?? 3),
                'system_code'  => $code,
                'docState'     => 40,
                'docStateMain' => 3,
            ]);
            $created++;
        }

        return ['kinds' => ['created' => $created, 'existing' => $existing]];
    }
}
