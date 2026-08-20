<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Hlavička souboru účetní historie — první řádek JSONL
 * (`docs/booking-history-format.md` §3).
 *
 * Povinná jsou jen `format` a `version`; zbytek je informativní a jde do
 * reportu. Neznámá pole se ignorují (dopředná kompatibilita) — proto
 * fromArray() nekontroluje nic nad rámec známých polí.
 */
final readonly class BookingHistoryHeader
{
    public const FORMAT  = 'shpd.economy.booking-history';
    public const VERSION = 1;

    /** Varianty osnovy zdroje; `unknown` → zpracování použije `default`. */
    public const CHART_VARIANTS = ['default', 'npo', 'unknown'];

    /**
     * @param array{name: ?string, version: ?string} $sourceSystem
     * @param array{from: ?string, to: ?string} $period
     * @param list<string> $docTypes
     */
    public function __construct(
        public string $chartVariant,
        public string $currency,
        public array $sourceSystem,
        public ?string $sourceRef,
        public array $period,
        public array $docTypes,
        public ?string $exportedAt,
        public ?int $recordCount,
    ) {
        if (!in_array($chartVariant, self::CHART_VARIANTS, true)) {
            throw new \InvalidArgumentException("BookingHistoryHeader: unknown chartVariant \"{$chartVariant}\"");
        }
    }

    /**
     * Varianta osnovy pro reverzní mapování — `unknown` spadne na
     * podnikatelskou nabídku (report to poznamená, viz
     * {@see chartVariantIsGuessed()}).
     */
    public function effectiveChartVariant(): string
    {
        return $this->chartVariant === 'unknown' ? 'default' : $this->chartVariant;
    }

    public function chartVariantIsGuessed(): bool
    {
        return $this->chartVariant === 'unknown';
    }

    /** Popis zdroje pro report — „shipard-e10 1.2 (ds abcd-…)". */
    public function sourceLabel(): string
    {
        $parts = array_filter([
            (string) ($this->sourceSystem['name'] ?? ''),
            (string) ($this->sourceSystem['version'] ?? ''),
        ], static fn (string $s): bool => $s !== '');
        $label = implode(' ', $parts);
        if ($this->sourceRef !== null && $this->sourceRef !== '') {
            $label = $label !== '' ? "{$label} ({$this->sourceRef})" : $this->sourceRef;
        }
        return $label !== '' ? $label : 'neznámý zdroj';
    }

    /**
     * @param array<string, mixed> $raw
     * @throws BookingHistoryFormatException
     */
    public static function fromArray(array $raw, int $line = 1): self
    {
        $format = $raw['format'] ?? null;
        if (!is_string($format) || $format === '') {
            throw new BookingHistoryFormatException($line, 'hlavička nemá pole "format"');
        }
        if ($format !== self::FORMAT) {
            throw new BookingHistoryFormatException(
                $line,
                'neznámý formát "' . $format . '" (očekáván "' . self::FORMAT . '")',
            );
        }

        $version = $raw['version'] ?? null;
        if (!is_int($version)) {
            throw new BookingHistoryFormatException($line, 'hlavička nemá celočíselné pole "version"');
        }
        if ($version !== self::VERSION) {
            throw new BookingHistoryFormatException(
                $line,
                "verze formátu {$version} není podporovaná (podporovaná: " . self::VERSION . ')',
            );
        }

        $chartVariant = $raw['chartVariant'] ?? null;
        if ($chartVariant !== null && !in_array($chartVariant, self::CHART_VARIANTS, true)) {
            throw new BookingHistoryFormatException(
                $line,
                'neznámá varianta osnovy "' . (is_string($chartVariant) ? $chartVariant : gettype($chartVariant))
                . '" (očekáváno: ' . implode(' | ', self::CHART_VARIANTS) . ')',
            );
        }

        $system = is_array($raw['sourceSystem'] ?? null) ? $raw['sourceSystem'] : [];
        $period = is_array($raw['period'] ?? null) ? $raw['period'] : [];

        $docTypes = [];
        foreach ((array) ($raw['docTypes'] ?? []) as $docType) {
            if (is_string($docType) && $docType !== '') {
                $docTypes[] = $docType;
            }
        }

        return new self(
            chartVariant: is_string($chartVariant) ? $chartVariant : 'unknown',
            currency: self::nonEmptyString($raw['currency'] ?? null) ?? 'CZK',
            sourceSystem: [
                'name'    => self::nonEmptyString($system['name'] ?? null),
                'version' => self::nonEmptyString($system['version'] ?? null),
            ],
            sourceRef: self::nonEmptyString($raw['sourceRef'] ?? null),
            period: [
                'from' => self::nonEmptyString($period['from'] ?? null),
                'to'   => self::nonEmptyString($period['to'] ?? null),
            ],
            docTypes: $docTypes,
            exportedAt: self::nonEmptyString($raw['exportedAt'] ?? null),
            recordCount: is_int($raw['recordCount'] ?? null) ? $raw['recordCount'] : null,
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return is_int($value) || is_float($value) ? (string) $value : null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
