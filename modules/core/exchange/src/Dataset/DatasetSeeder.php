<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Orchestrace seedu: seedery v pořadí sekcí, na konci kontrola počtů vůči
 * manifestu (nesoulad = varování — `counts` jsou informativní).
 */
final class DatasetSeeder
{
    /**
     * @param list<SectionSeeder> $seeders
     */
    public function seed(SeedContext $ctx, array $seeders): SeedReport
    {
        $report = new SeedReport();
        try {
            foreach ($seeders as $seeder) {
                $report->touch($seeder->section());
                $seeder->seed($ctx, $report);
            }
        } finally {
            $ctx->cleanup();
        }

        foreach ($ctx->reader->getManifest()->counts as $section => $expected) {
            if (!isset($report->counts()[$section])) {
                if ($expected > 0) {
                    $report->warning("sekce '{$section}' ({$expected} záznamů) nemá na tomto DS aktivní modul — přeskočena");
                }
                continue;
            }
            $processed = $report->processed($section);
            if ($processed !== $expected) {
                $report->warning("manifest counts.{$section} = {$expected}, zpracováno {$processed}");
            }
        }

        return $report;
    }
}
