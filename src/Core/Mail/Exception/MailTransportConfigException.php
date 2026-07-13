<?php

declare(strict_types=1);

namespace Shipard\Core\Mail\Exception;

/** Chybějící transport — žádný sender pro from adresu a žádný relay v konfiguraci. */
class MailTransportConfigException extends \RuntimeException
{
}
