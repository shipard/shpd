<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting\Exception;

/**
 * Klíč na disku existuje, ale nejde přečíst (typicky špatný vlastník —
 * zapsán pod rootem) nebo je prázdný. Na rozdíl od Missing (gateway
 * nezřízená → 404) je tohle chyba konfigurace → error log + 500.
 */
class AiGwKeyUnreadableException extends AiGwKeyException
{
}
