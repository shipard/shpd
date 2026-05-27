<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Common;

/**
 * Maps a canonical Party fragment (as it appears inside a document or item
 * payload) into a full Person canonical accepted by {@see \Shipard\Module\Core\Exchange\Person\PersonApplier::apply()}.
 *
 * Used by ItemApplier when `_resolve.supplierCodes[i].supplier.userAction`
 * is `create` — the supplier does not exist in the DS yet, so we project
 * the inline Party onto a Person canonical and delegate to PersonApplier
 * for the actual save (single create path with full PersonDocument
 * validation).
 *
 * NOT used by DocumentApplier. Doc flow has its own shorter path via
 * {@see \Shipard\Module\Core\Exchange\Resolve\PartyResolver::buildPersonCreatePayload()}
 * which writes directly into `base_persons_persons` through the
 * persons gateway, bypassing PersonApplier. Unifying the two paths is a
 * Phase 2+ follow-up (see docs/exchange-format-items.md §15).
 *
 * Default `personType` is `company` — supplier-side Party in items is
 * always a company in Phase 1 (SupplierCodesResolver filters on company).
 * Override is supported for future use cases (e.g. natural-person
 * suppliers in an OSVČ catalog).
 */
final class PartyToPersonCanonical
{
    public const FORMAT_ID = 'shpd.persons.person';
    public const FORMAT_VERSION = '1.0';

    /**
     * @param array<string, mixed> $party
     * @return array<string, mixed>
     */
    public static function toPersonCanonical(array $party, string $personType = 'company'): array
    {
        $name = self::normalize($party['name'] ?? null);
        $country = self::normalizeLower($party['country'] ?? null);

        $canonical = [
            'format'        => self::FORMAT_ID,
            'formatVersion' => self::FORMAT_VERSION,
            'personType'    => $personType,
            'country'       => $country ?? 'cz',
            'name'          => [
                'fullName' => $name,
            ],
        ];

        foreach (['companyId', 'taxId', 'vatId', 'courtRegistration', 'govEBoxId'] as $key) {
            $value = self::normalize($party[$key] ?? null);
            if ($value !== null) {
                $canonical[$key] = $value;
            }
        }

        return $canonical;
    }

    private static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeLower(mixed $value): ?string
    {
        $v = self::normalize($value);
        return $v !== null ? mb_strtolower($v) : null;
    }
}
