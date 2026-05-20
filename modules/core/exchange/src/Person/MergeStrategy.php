<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Person;

/**
 * Policy for what `/apply` does when the canonical's header already
 * exists in DB (matched by companyId / vatId / taxId).
 *
 * See docs/exchange-format-persons.md §9 for the per-strategy behaviour
 * matrix (header vs. sub-collections).
 */
enum MergeStrategy: string
{
    /** Reject with 409 person_exists if header is matched. */
    case CreateOnly = 'createOnly';

    /** Overwrite header columns; leave sub-collections untouched. */
    case UpdateHeader = 'updateHeader';

    /**
     * Default. Header: fill empty columns only. Sub-collections: matched →
     * leave, missing → add, existing not in payload → leave. Provozovna /
     * Zařízení matched by `place_reg_id` is the exception — it always
     * receives an authoritative refresh (registry is source of truth).
     */
    case MergeAdd = 'mergeAdd';

    /**
     * Header: overwrite all columns (except is_closed / closed_date /
     * docState). Sub-collections: matched → update, missing → add,
     * existing not in payload → `valid_to = today`.
     *
     * Address closing is partitioned per `address_type` — payload with
     * only Sídla closes existing Sídla but leaves doručovací addresses
     * untouched. See spec §9.
     */
    case FullSync = 'fullSync';

    /** Default when canonical does not specify a strategy. */
    public static function default(): self
    {
        return self::MergeAdd;
    }

    /**
     * Read from `$canonical['applyOptions']['mergeStrategy']`. Returns the
     * default when missing or invalid (schema validation accepts null;
     * resolver translates that to default).
     */
    public static function fromCanonical(mixed $value): self
    {
        if (!is_string($value)) {
            return self::default();
        }
        return self::tryFrom($value) ?? self::default();
    }
}
