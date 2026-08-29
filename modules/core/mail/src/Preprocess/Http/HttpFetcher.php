<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess\Http;

/**
 * Minimální HTTP klient pro akce předzpracování. Jeden GET, **bez**
 * následování redirectů — řetězec hopů řídí volající, aby mohl každý hop
 * zkontrolovat (SSRF, allowlist). `pinnedIp` je adresa, na kterou volající
 * host už přeložil a ověřil; klient se na ni musí připojit (žádný druhý
 * DNS lookup = žádný DNS rebinding).
 */
interface HttpFetcher
{
    public function get(string $url, string $pinnedIp, int $timeoutSeconds, int $maxBytes): HttpResponse;
}
