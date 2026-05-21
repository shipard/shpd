<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * One row from {@see PersonsRegistryClient::search()} — a flat summary
 * of a registry person, designed for picker UI (the "Přidat firmu z
 * registru" wizard). NOT a canonical person; clients render this and,
 * once the user picks one, call {@see PersonsRegistryClient::fetchPerson()}
 * to get the full canonical payload for apply.
 *
 * The shape is taken from the legacy search response (`formatMode=ns`)
 * — see {@see PersonsRegistryClient} class doc for the raw fields this
 * maps from.
 *
 * `validFrom`/`validTo` are kept as ISO date strings rather than
 * \DateTimeImmutable because (a) the search response is purely
 * informational and rendered to the user verbatim, and (b) JSON
 * serialization back out to the wizard frontend wants strings.
 */
final readonly class SearchResultRow
{
    public function __construct(
        public string $country,
        public string $companyId,
        public string $fullName,
        public ?string $vatId,
        public bool $isValid,
        public ?string $validFrom,
        public ?string $validTo,
        public ?string $primaryAddressText,
    ) {
        if ($country === '') {
            throw new \InvalidArgumentException('SearchResultRow: country must not be empty');
        }
        if ($companyId === '') {
            throw new \InvalidArgumentException('SearchResultRow: companyId must not be empty');
        }
        if ($fullName === '') {
            throw new \InvalidArgumentException('SearchResultRow: fullName must not be empty');
        }
    }

    /**
     * Build from one entry of the registry search response (legacy
     * `services_persons_persons` row shape, polished by
     * `DataViewPersons::loadData_searchResults()`).
     *
     * Returns null for entries missing required fields (country, oid,
     * fullName) — callers filter these out rather than throwing, because
     * one malformed row should not poison the whole result set.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRegistryResponse(array $row): ?self
    {
        $country   = self::stringOrNull($row['country']  ?? null);
        $companyId = self::stringOrNull($row['oid']      ?? null);
        $fullName  = self::stringOrNull($row['fullName'] ?? null);
        if ($country === null || $companyId === null || $fullName === null) {
            return null;
        }

        return new self(
            country:            strtolower($country),
            companyId:          $companyId,
            fullName:           $fullName,
            vatId:              self::stringOrNull($row['vatID'] ?? null),
            isValid:            ((int) ($row['valid'] ?? 0)) === 1,
            validFrom:          self::stringOrNull($row['validFrom'] ?? null),
            validTo:            self::stringOrNull($row['validTo']   ?? null),
            primaryAddressText: self::stringOrNull($row['primaryAddressText'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'country'            => $this->country,
            'companyId'          => $this->companyId,
            'fullName'           => $this->fullName,
            'vatId'              => $this->vatId,
            'isValid'            => $this->isValid,
            'validFrom'          => $this->validFrom,
            'validTo'            => $this->validTo,
            'primaryAddressText' => $this->primaryAddressText,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
