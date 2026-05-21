<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * Registry confirms the requested entity does not exist (HTTP 404 or
 * `{status: 0}` body for a fetch). Retrying will not help.
 */
final class RegistryNotFoundException extends RegistryException
{
}
