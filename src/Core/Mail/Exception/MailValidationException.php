<?php

declare(strict_types=1);

namespace Shipard\Core\Mail\Exception;

/** Nevalidní vstup enqueue — chybějící from (a žádný default), špatný e-mail… */
class MailValidationException extends \RuntimeException
{
}
