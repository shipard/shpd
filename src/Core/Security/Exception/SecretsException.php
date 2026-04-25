<?php

declare(strict_types=1);

namespace Shipard\Core\Security\Exception;

/**
 * Root for all secrets-related exceptions.
 *
 * @internal Use one of the concrete subclasses for throwing.
 *           Catch this base class only for logging / generic handling.
 */
class SecretsException extends \RuntimeException
{
}
