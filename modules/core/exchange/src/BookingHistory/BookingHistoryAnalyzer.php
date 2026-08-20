<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Jediný průchod souborem účetní historie: naplní metriky kvality,
 * akumulátor seed kandidátů a tabulku distinct textů (clusterů).
 *
 * Proč jeden průchod a ne tři: soubor je streamovaný a reverz účtu se počítá
 * pro každý záznam jednou — trojí čtení by ho počítalo třikrát. Konzumenti
 * jsou samostatné testovatelné jednotky, orchestruje je tenhle analyzer,
 * ne CLI (kolektivní analýza napříč DS ho použije beze změny).
 *
 * LLM sem nevstupuje — klasifikace distinct textů běží nad výsledkem
 * ({@see BookingHistoryAnalysis::applyLlmTags()}).
 */
final class BookingHistoryAnalyzer
{
    public function __construct(
        private readonly float $minShare = BookingHistorySeedBuilder::DEFAULT_MIN_SHARE,
        private readonly int $minDocCount = BookingHistorySeedBuilder::DEFAULT_MIN_DOC_COUNT,
    ) {}

    public function analyze(BookingHistoryFile $file, AccountTagMap $accountTags): BookingHistoryAnalysis
    {
        $quality  = new BookingHistoryQuality();
        $seed     = new BookingHistorySeedBuilder($this->minShare, $this->minDocCount);
        $clusters = [];
        $matchKindRows = [];
        $recordCount = 0;

        foreach ($file->records() as $record) {
            $recordCount++;
            $match = $accountTags->resolve($record->account);

            $quality->add($record);
            $seed->add($record, $match);
            $matchKindRows[$match->kind] = ($matchKindRows[$match->kind] ?? 0) + $record->rowCount;

            // Clustery jen z obsahonosných textů — degenerovaný text nemá co
            // klasifikovat a v matici konzistence by dělal šum (D33).
            if (!$record->hasContentfulText()) {
                continue;
            }
            $norm = $record->rowTextNorm();
            $cluster = $clusters[$norm] ??= new TextCluster($norm, (string) $record->rowText);
            $cluster->observe($record, $match);
        }

        return new BookingHistoryAnalysis(
            header: $file->header,
            quality: $quality,
            seed: $seed,
            accountTags: $accountTags,
            clusters: $clusters,
            recordCount: $recordCount,
            matchKindRows: $matchKindRows,
        );
    }
}
