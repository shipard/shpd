<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Item;

/**
 * Policy for what `/apply` does when the canonical's header already
 * exists in DB (matched by ourCode / ean / sku / name).
 *
 * See docs/exchange-format-items.md §9 for the per-strategy behaviour
 * matrix (header vs. supplierCodes).
 */
enum MergeStrategy: string
{
    /** Reject with 409 item_exists if header is matched. */
    case CreateOnly = 'createOnly';

    /** Overwrite header columns; leave supplierCodes untouched. */
    case UpdateHeader = 'updateHeader';

    /**
     * Default. Header: fill empty columns only. supplierCodes: matched →
     * leave, missing → INSERT IGNORE, existing not in payload → leave.
     */
    case MergeAdd = 'mergeAdd';

    /**
     * Header: overwrite all columns (except code / is_closed / docState).
     * supplierCodes: matched → leave (no overwrite — mapping is immutable
     * audit), missing → INSERT IGNORE, existing not in payload → **leave**.
     * NO closing semantics — see spec §6.4.
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
