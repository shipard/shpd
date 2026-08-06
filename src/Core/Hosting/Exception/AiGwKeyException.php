<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting\Exception;

/**
 * Root for AI gateway org-key exceptions.
 *
 * @internal Use one of the concrete subclasses for throwing.
 *           Catch this base class only for logging / generic handling.
 */
class AiGwKeyException extends \RuntimeException
{
}
