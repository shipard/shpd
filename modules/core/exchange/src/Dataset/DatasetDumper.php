<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Shipard\Module\Core\Exchange\Export\SetupExporter;

/**
 * Orchestrace dumpu: exportery → soubory sady → manifest.
 *
 * Názvy souborů `NNNN-<slug>.jsonc` podle pořadí, v jakém exporter záznamy
 * vrátil; přílohy záznamu do sidecar složky `NNNN-<slug>.files/`. Sekce
 * `setup/` má jeden soubor per tabulka (`setup/<tabulka>.jsonc`). Chybějící
 * binárka na disku zdrojového DS je varování, ne pád — záznam se zapíše
 * a `attachments[]` ji dál uvádí (seed ji pak ohlásí jako chybějící).
 */
final class DatasetDumper
{
    public function __construct(
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * @param list<RecordExporter> $exporters v pořadí sekcí sady
     */
    public function dump(DatasetManifest $manifest, ?SetupExporter $setup, array $exporters): DumpResult
    {
        $counts = [];
        $warnings = [];

        if ($setup !== null) {
            $n = 0;
            foreach ($setup->exportAll() as $record) {
                $this->writer->writeJsonc('setup/' . $record->slug . '.jsonc', $record->data);
                $n++;
            }
            $counts['setup'] = $n;
            foreach ($setup->getWarnings() as $w) {
                $warnings[] = $w;
            }
        }

        foreach ($exporters as $exporter) {
            $section = $exporter->section();
            $ordinal = 0;
            foreach ($exporter->exportAll() as $record) {
                $ordinal++;
                $base = $section . '/' . DatasetWriter::fileName($ordinal, $record->slug, '');
                $this->writer->writeJsonc($base . '.jsonc', $record->data);
                foreach ($record->files as $file) {
                    if (!is_file($file->sourcePath)) {
                        $warnings[] = "{$section} {$record->slug}: příloha '{$file->name}' chybí na disku ({$file->sourcePath})";
                        continue;
                    }
                    $this->writer->copyFile($file->sourcePath, $base . '.files/' . $file->name);
                }
            }
            $counts[$section] = $ordinal;
            foreach ($exporter->getWarnings() as $w) {
                $warnings[] = $w;
            }
        }

        $final = $manifest->withCounts($counts);
        $this->writer->writeManifest($final);

        return new DumpResult($final, $counts, $warnings);
    }
}
