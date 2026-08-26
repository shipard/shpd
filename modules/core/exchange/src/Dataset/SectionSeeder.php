<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Import jedné sekce sady do DS. Seedery běží v pořadí
 * `DatasetManifest::SECTIONS` (setup → persons → items → docs → registry →
 * mail); každý záznam hlásí výsledek do `SeedReport`, výjimka jednoho
 * záznamu nesmí shodit zbytek sekce.
 */
interface SectionSeeder
{
    public function section(): string;

    public function seed(SeedContext $ctx, SeedReport $report): void;
}
