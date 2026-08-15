<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konzistence `docs.core.applyRowOperations` (doplnění pohybu při apply
 * z AI analýzy) s `docs.core.rowOperations` a `economy.items.itemTypes`:
 * každý kód v mapě musí existovat a být povolený pro svůj docType, klíče
 * byItemType musí být známé typy položek. DocumentApplier neznámý kód
 * nedoplní (warning row_operation_config_invalid) — tenhle test drží
 * konfiguraci opravenou, aby na to za běhu nedošlo.
 */
class ApplyRowOperationsParityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $applyRowOperations;

    /** @var array<string, mixed> */
    private array $rowOperations;

    /** @var array<string, mixed> */
    private array $itemTypes;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 5);
        $this->applyRowOperations = JsoncParser::parseFile(
            $root . '/modules/docs/core/config/applyRowOperations.jsonc',
        );
        $this->rowOperations = JsoncParser::parseFile(
            $root . '/modules/docs/core/config/rowOperations.jsonc',
        );
        $this->itemTypes = JsoncParser::parseFile(
            $root . '/modules/economy/items/config/itemTypes.jsonc',
        );
    }

    public function testEveryCodeExistsAndIsAllowedForItsDocType(): void
    {
        foreach ($this->applyRowOperations as $docType => $entry) {
            $codes = array_values(is_array($entry['byItemType'] ?? null) ? $entry['byItemType'] : []);
            if (isset($entry['default'])) {
                $codes[] = $entry['default'];
            }
            $this->assertNotEmpty($codes, "applyRowOperations[{$docType}] je prázdný záznam");

            foreach ($codes as $code) {
                $this->assertArrayHasKey(
                    $code,
                    $this->rowOperations,
                    "Pohyb „{$code}“ ({$docType}) v docs.core.rowOperations neexistuje",
                );
                $this->assertArrayHasKey(
                    $docType,
                    $this->rowOperations[$code]['docTypes'] ?? [],
                    "Pohyb „{$code}“ není povolen pro docType „{$docType}“",
                );
            }
        }
    }

    public function testByItemTypeKeysAreKnownItemTypes(): void
    {
        foreach ($this->applyRowOperations as $docType => $entry) {
            foreach (array_keys(is_array($entry['byItemType'] ?? null) ? $entry['byItemType'] : []) as $type) {
                $this->assertArrayHasKey(
                    (string) $type,
                    $this->itemTypes,
                    "Typ položky „{$type}“ ({$docType}) v economy.items.itemTypes neexistuje",
                );
            }
        }
    }
}
