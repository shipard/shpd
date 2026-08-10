<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting\Exception;

/**
 * Klíč na disku existuje, ale nejde přečíst (typicky špatný vlastník —
 * zapsán pod rootem). Na rozdíl od Missing je tohle chyba konfigurace,
 * ne „nezřízeno".
 */
class OpKeyUnreadableException extends OpKeyException
{
}
