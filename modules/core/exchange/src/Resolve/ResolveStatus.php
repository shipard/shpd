<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

/**
 * Outcome of a single reference resolve (Party, Item, Unit, …).
 *
 * `Matched`    — one unambiguous DB row, resolver fills `matchedId` + `matchedBy`.
 * `Ambiguous`  — several candidates, UI picks via userAction = useExisting:<id>.
 * `NotFound`   — no match and the resolver cannot construct a payload to create
 *                one (e.g. unknown VAT code from an unknown country).
 * `CanCreate`  — no match, but the canonical payload carries enough info to
 *                build a new entity. UI picks via userAction = create.
 */
enum ResolveStatus: string
{
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case NotFound = 'notFound';
    case CanCreate = 'canCreate';
}
