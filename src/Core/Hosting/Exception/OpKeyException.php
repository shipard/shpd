<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting\Exception;

/**
 * Root for OIDC OP signing-key exceptions.
 *
 * @internal Use one of the concrete subclasses for throwing.
 *           Catch this base class only for logging / generic handling.
 */
class OpKeyException extends \RuntimeException
{
}
