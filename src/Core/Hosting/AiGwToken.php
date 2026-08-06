<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting;

/**
 * Formát gateway tokenu AI gateway (D5): `shpd_gw_` + 43 url-safe znaků.
 * Na řádek hosting_core_ai_tokens se ukládá prefix (prvních 12 znaků
 * náhodné části, lookup) + SHA-256 hash CELÉHO tokenu včetně prefixu
 * (konvence shpd_hk_) + plaintext šifrovaně (token_encrypted) pro
 * opakované servírování v queue payloadu.
 *
 * Sdílí CLI `hosting-ai-token`, lazy mint v queue payloadu
 * a HostingAiGatewayTokenAuthenticator.
 */
final class AiGwToken
{
    public const string TOKEN_PREFIX = 'shpd_gw_';
    public const int KEY_PREFIX_LENGTH = 12;

    private function __construct()
    {
    }

    /**
     * @return array{token: string, prefix: string, hash: string}
     */
    public static function mint(): array
    {
        $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = self::TOKEN_PREFIX . $random;

        return [
            'token'  => $token,
            'prefix' => substr($random, 0, self::KEY_PREFIX_LENGTH),
            'hash'   => hash('sha256', $token),
        ];
    }
}
