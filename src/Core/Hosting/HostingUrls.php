<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting;

/**
 * Odvozování veřejných URL hostingu z issuer settingu (D12) — issuer
 * `hosting.oidc.issuer` je jediná explicitně konfigurovaná base adresa
 * portálu (nikdy se neodvozuje z requestu); ostatní endpointy hostingu
 * žijí na stejném hostu pod /_hosting/*.
 */
final class HostingUrls
{
    private function __construct()
    {
    }

    /**
     * Base URL AI gateway (D5) z issueru — stejný host, cesta
     * /_hosting/oidc → /_hosting/ai-gw. Klienti k ní appendují /v1/messages.
     */
    public static function aiGwBaseUrl(string $issuer): string
    {
        return str_replace('/_hosting/oidc', '/_hosting/ai-gw', rtrim($issuer, '/'));
    }
}
