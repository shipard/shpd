<?php

declare(strict_types=1);

namespace Shipard\Core\Mail\Exception;

/** Zprávu nejde sestavit — prázdné tělo, chybějící příloha… Selhává pokus, ne tiché vynechání. */
class MailComposeException extends \RuntimeException
{
}
