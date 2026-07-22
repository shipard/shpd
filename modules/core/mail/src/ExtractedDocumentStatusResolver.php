<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;

/**
 * Sdílená pravidla pro status extrahovaného dokumentu: mapování confidence
 * na status podle prahů profilu + strop D7 podle pokrytí řádků položkami.
 * Jediné místo s těmito pravidly — používá AnalysisController (/result)
 * i IsdocImportService (deterministický ISDOC import).
 */
class ExtractedDocumentStatusResolver
{
    /**
     * Fallback prahy, když profil neexistuje nebo nemá confidence_thresholds.
     * ISDOC import tak funguje i v DS bez nakonfigurované AI.
     */
    public const DEFAULT_THRESHOLDS = ['ready' => 0.9, 'review' => 0.6];

    private const PROFILES_TABLE = 'core_mail_ai_profiles';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * Prahy daného profilu; null nebo profil bez prahů → defaulty.
     *
     * @return array{ready: float, review: float}
     */
    public function thresholdsForProfile(?int $profileNdx): array
    {
        if ($profileNdx === null) {
            return self::DEFAULT_THRESHOLDS;
        }

        $row = $this->db->fetchRow(
            'SELECT confidence_thresholds FROM %n WHERE id = %i',
            self::PROFILES_TABLE,
            $profileNdx,
        );
        if ($row === null || empty($row['confidence_thresholds'])) {
            return self::DEFAULT_THRESHOLDS;
        }

        $decoded = json_decode((string) $row['confidence_thresholds'], true);
        if (!is_array($decoded)) {
            return self::DEFAULT_THRESHOLDS;
        }

        return [
            'ready' => isset($decoded['ready']) ? (float) $decoded['ready'] : self::DEFAULT_THRESHOLDS['ready'],
            'review' => isset($decoded['review']) ? (float) $decoded['review'] : self::DEFAULT_THRESHOLDS['review'],
        ];
    }

    /**
     * Prahy default aktivního profilu DS — zdroj pro importy, které neběží
     * pod konkrétním profilem (ISDOC). Bez profilu → defaulty.
     *
     * @return array{ready: float, review: float}
     */
    public function thresholdsForDefaultProfile(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            1,
            1,
        );
        return $this->thresholdsForProfile($row !== null ? (int) $row['id'] : null);
    }

    /**
     * @param array{ready: float, review: float} $thresholds
     */
    public function mapConfidenceToStatus(float $confidence, array $thresholds): int
    {
        if ($confidence >= $thresholds['ready']) {
            return ExtractedDocumentDocument::STATUS_READY_TO_APPLY;
        }
        if ($confidence >= $thresholds['review']) {
            return ExtractedDocumentDocument::STATUS_PENDING_REVIEW;
        }
        return ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE;
    }

    /**
     * Strop statusu podle pokrytí řádků (D7): ready_to_apply smí zůstat jen
     * když má každý item řádek položku — z extrakce, nebo návrhem
     * enrichmentu. Kontační řádky (acc.record) položku nemají validně,
     * strop se jich netýká.
     *
     * Řádek doplněný enrichmentem s confidence `low` (dominantní položka
     * partnera, bez textového signálu) se jako pokrytý nepočítá — dokument
     * zůstává pending_review a uživatel návrh potvrzuje
     * (`tasks/enrichment-dominant-item.md`, D5). Textové matche
     * (`high`/`medium`) beze změny; řádky s ourCode od AI mají
     * `confidence: null` → stropu se netýkají.
     *
     * @param array<string, mixed> $canonical
     */
    public function capStatusByRowCoverage(int $status, array $canonical): int
    {
        if ($status !== ExtractedDocumentDocument::STATUS_READY_TO_APPLY) {
            return $status;
        }
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $enrichments = $this->enrichmentsByRowIndex($canonical);
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || !RowHistoryEnricher::rowExpectsItem($row)) {
                continue;
            }
            if (trim((string) ($row['item']['ourCode'] ?? '')) === '') {
                return ExtractedDocumentDocument::STATUS_PENDING_REVIEW;
            }
            if (($enrichments[$idx]['confidence'] ?? null) === 'low') {
                return ExtractedDocumentDocument::STATUS_PENDING_REVIEW;
            }
        }
        return $status;
    }

    /**
     * Enrichment audit bloky z `_resolve.rows` mapované na index řádku
     * canonical (jen array entries s array `enrichment`).
     *
     * @param array<string, mixed> $canonical
     * @return array<int, array<string, mixed>>
     */
    private function enrichmentsByRowIndex(array $canonical): array
    {
        $resolveRows = $canonical['_resolve']['rows'] ?? null;
        if (!is_array($resolveRows)) {
            return [];
        }
        $map = [];
        foreach ($resolveRows as $entry) {
            if (!is_array($entry) || !is_array($entry['enrichment'] ?? null)) {
                continue;
            }
            $idx = $entry['index'] ?? null;
            if (is_int($idx)) {
                $map[$idx] = $entry['enrichment'];
            }
        }
        return $map;
    }
}
