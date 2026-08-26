<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Prostředí seedu sdílené všemi `SectionSeeder`y: čtečka sady, DB,
 * konfigurace DS, registr Document tříd, definice tabulek, přílohy.
 *
 * `merge = true` (`dataset-seed --no-reset`): obsah se doplňuje do
 * existujícího DS — applieři jedou v `mergeAdd`, duplicity čísel dokladů /
 * kódů zpráv jsou chyba záznamu, ne pád seedu.
 */
final class SeedContext
{
    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        public readonly DatasetReader $reader,
        public readonly Connection $db,
        public readonly DataSourceConnection $dsConnection,
        public readonly ConfigRuntime $config,
        public readonly DataSourceConfig $dsConfig,
        public readonly DocumentRegistry $registry,
        public readonly array $tables,
        public readonly string $dsDir,
        public readonly AttachmentService $attachments,
        public readonly ?DocumentEventDispatcher $dispatcher,
        public readonly bool $merge,
    ) {}

    /**
     * `docStateMain` pro daný stav z cfgItemu; bez compiled hodnoty pevná mapa.
     *
     * @param array<int, int> $fallback
     */
    public function mainState(string $cfgItemId, int $docState, array $fallback): int
    {
        $cfg = $this->config->cfgItem($cfgItemId);
        if (is_array($cfg) && $cfg !== []) {
            return DocStateConfig::fromCfgItem($cfg)->getMainState($docState);
        }
        return $fallback[$docState] ?? 1;
    }

    /**
     * Archivní stavový model (`core.system.docStatesArchive`) — osoby,
     * položky, spisovna. Applieři umí cílit jen 10/40; stav 70/80 se
     * po uložení dorovná přímým UPDATE.
     */
    public function restoreArchiveState(string $table, int $id, ?int $docState): void
    {
        if ($docState === null || !in_array($docState, [70, 80], true)) {
            return;
        }
        $main = $this->mainState('core.system.docStatesArchive', $docState, [10 => 1, 80 => 2, 40 => 3, 70 => 4, 90 => 5]);
        $this->db->query(
            'UPDATE %n SET [docState] = %i, [docStateMain] = %i WHERE [id] = %i',
            $table, $docState, $main, $id,
        );
    }

    /**
     * `AttachmentService::upload()` zdrojový soubor přesouvá — sada musí
     * zůstat netknutá, proto kopie do tempu. Uklidí `cleanup()`.
     */
    public function tempCopy(string $absPath): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'shpd_seed_');
        if (!copy($absPath, $tmp)) {
            @unlink($tmp);
            throw new DatasetException("Cannot copy '{$absPath}' to temp");
        }
        $this->tempFiles[] = $tmp;
        return $tmp;
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tempFiles = [];
    }

    /** Sidecar složka záznamu: `persons/0001-x.jsonc` → `persons/0001-x.files`. */
    public static function sidecarDir(string $relPath): string
    {
        return str_ends_with($relPath, '.jsonc') ? substr($relPath, 0, -6) . '.files' : $relPath . '.files';
    }

    /** ISO `Y-m-d\TH:i:s` (sada) → DB `Y-m-d H:i:s`. */
    public static function dbDateTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        return str_replace('T', ' ', $iso);
    }
}
