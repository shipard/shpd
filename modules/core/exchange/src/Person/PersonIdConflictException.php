<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Person;

/**
 * Raised inside the apply transaction when the canonical's `personId`
 * collides with an existing row's `person_id` (different person, same
 * code). Caught by PersonApplier::apply() which rolls back and maps
 * the exception to a `409 person_id_conflict` ApplyResult.
 *
 * Kept as a dedicated exception (not an in-band error code) so the
 * transactional save path can short-circuit out of nested method
 * calls cleanly — header save + sub-record save + lineage all run in
 * one try/catch.
 */
final class PersonIdConflictException extends \RuntimeException
{
}
