<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;

/**
 * Jeden agregovaný záznam účetní historie — řádek 2+ JSONL souboru
 * (`docs/booking-history-format.md` §4).
 *
 * Nese i dvě odvozené věci, které patří k záznamu, ne k jeho konzumentům:
 *
 *  - {@see rowTextNorm()} — normalizace agregačního klíče (D30): trim,
 *    collapse whitespace, lowercase. Obě strany ji počítají stejně, do
 *    souboru se neposílá.
 *  - {@see degeneracy()} — degenerovaný text (D33): prázdný / shodný
 *    s názvem položky / shodný s číslem účtu. Detekuje zpracování, ne
 *    export; degenerované texty nejdou do LLM klasifikace a jejich podíl
 *    je metrika kvality zdroje.
 */
final readonly class BookingHistoryRecord
{
    public const DEGENERACY_EMPTY     = 'empty';
    public const DEGENERACY_ITEM_NAME = 'itemName';
    public const DEGENERACY_ACCOUNT   = 'account';

    public function __construct(
        public ?string $companyId,
        public ?string $account,
        public ?string $itemCode,
        public ?string $itemName,
        public ?string $rowText,
        public int $docCount,
        public int $rowCount,
        public ?float $totalAmount,
        public ?string $firstDate,
        public ?string $lastDate,
    ) {
        if ($docCount < 0 || $rowCount < 0) {
            throw new \InvalidArgumentException('BookingHistoryRecord: docCount/rowCount must not be negative');
        }
    }

    /** Normalizovaný text řádku — klíč cache klasifikace i agregační klíč. */
    public function rowTextNorm(): string
    {
        return self::normalizeText($this->rowText);
    }

    /** trim + collapse whitespace + lowercase (UTF-8). */
    public static function normalizeText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Druh degenerace textu, nebo null když text nese obsah.
     *
     * @return self::DEGENERACY_*|null
     */
    public function degeneracy(): ?string
    {
        $norm = $this->rowTextNorm();
        if ($norm === '') {
            return self::DEGENERACY_EMPTY;
        }
        if ($this->itemName !== null && $norm === self::normalizeText($this->itemName)) {
            return self::DEGENERACY_ITEM_NAME;
        }
        if ($this->account !== null && $norm === self::normalizeText($this->account)) {
            return self::DEGENERACY_ACCOUNT;
        }
        return null;
    }

    /** Obsahonosný text = jediný legitimní vstup LLM klasifikace. */
    public function hasContentfulText(): bool
    {
        return $this->degeneracy() === null;
    }

    /**
     * @param array<string, mixed> $raw
     * @throws BookingHistoryFormatException
     */
    public static function fromArray(array $raw, int $line): self
    {
        $companyId = self::optionalString($raw['companyId'] ?? null, 'companyId', $line);
        if ($companyId !== null) {
            $companyId = ContentTagResolver::normalizeCompanyId($companyId);
            if ($companyId === '') {
                $companyId = null;
            }
        }

        return new self(
            companyId: $companyId,
            account: self::optionalString($raw['account'] ?? null, 'account', $line),
            itemCode: self::optionalString($raw['itemCode'] ?? null, 'itemCode', $line),
            itemName: self::optionalString($raw['itemName'] ?? null, 'itemName', $line),
            rowText: self::optionalString($raw['rowText'] ?? null, 'rowText', $line),
            docCount: self::count($raw['docCount'] ?? null, 'docCount', $line),
            rowCount: self::count($raw['rowCount'] ?? null, 'rowCount', $line),
            totalAmount: self::optionalFloat($raw['totalAmount'] ?? null, $line),
            firstDate: self::optionalString($raw['firstDate'] ?? null, 'firstDate', $line),
            lastDate: self::optionalString($raw['lastDate'] ?? null, 'lastDate', $line),
        );
    }

    /**
     * Čísla přicházejí jako string i number (tabulkové exporty) — obojí je
     * legální, jen struktury ne.
     */
    private static function optionalString(mixed $value, string $field, int $line): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new BookingHistoryFormatException(
                $line,
                "pole \"{$field}\" musí být string nebo null, přišlo " . gettype($value),
            );
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private static function count(mixed $value, string $field, int $line): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value)) {
            $count = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $count = (int) trim($value);
        } elseif (is_float($value) && $value === floor($value)) {
            $count = (int) $value;
        } else {
            throw new BookingHistoryFormatException(
                $line,
                "pole \"{$field}\" musí být celé číslo, přišlo " . gettype($value),
            );
        }
        if ($count < 0) {
            throw new BookingHistoryFormatException($line, "pole \"{$field}\" nesmí být negativní ({$count})");
        }
        return $count;
    }

    private static function optionalFloat(mixed $value, int $line): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        throw new BookingHistoryFormatException(
            $line,
            'pole "totalAmount" musí být číslo nebo null, přišlo ' . gettype($value),
        );
    }
}
