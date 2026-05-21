<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * Registry responded with HTTP 200 but the body cannot be parsed as
 * JSON or does not match the expected canonical shape. Likely a
 * registry-side bug or schema drift — log and alert; do not silently
 * fall back to "not found" because that would mask the real issue.
 */
final class RegistryInvalidResponseException extends RegistryException
{
}
