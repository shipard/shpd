<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Thrown by DocumentApplier::resolveNumberSeriesFor() when the canonical
 * carries an explicit applyOptions.numberSeriesCode but no active number
 * series matches (doc_type, doc_number_code). The applier maps it to a
 * clean apply-level error (number_series_not_found, 422) — no silent
 * fallback to the first active series.
 */
final class NumberSeriesNotFoundException extends \RuntimeException
{
    public function __construct(
        public readonly string $docType,
        public readonly string $seriesCode,
    ) {
        parent::__construct(
            "Číselná řada doc_type={$docType} kód={$seriesCode} neexistuje.",
        );
    }
}
