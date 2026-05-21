<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * Registry is unreachable or returned a transient server error
 * (timeout, network failure, DNS, HTTP 5xx). Callers may retry later.
 */
final class RegistryUnavailableException extends RegistryException
{
}
