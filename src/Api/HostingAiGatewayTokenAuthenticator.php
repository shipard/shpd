<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Hosting\AiGwToken;

/**
 * Validace gateway tokenů `shpd_gw_…` pro AI gateway (D5) — sibling
 * HostingApiKeyAuthenticatoru: hlavička `x-api-key` (žádný Bearer —
 * klienti Anthropicu ji posílají nativně), tabulka hosting_core_ai_tokens
 * (sloupce token_prefix/token_hash/active), navíc kontrola lifecycle
 * zdroje dat. Selhání vrací 401 v Anthropic error formátu — oba klienti
 * (AnthropicLlmClient, Python SDK) mu rozumí.
 *
 * Prefix lookup + `hash_equals` nad sha256 celého tokenu; hash je nad
 * tokenem VČETNĚ prefixu `shpd_gw_`, sloupec prefix drží prvních
 * 12 znaků náhodné části (konvence shpd_hk_). `last_used` se aktualizuje
 * throttled (max 1× za minutu), ne každý request.
 */
final class HostingAiGatewayTokenAuthenticator
{
    /** Ne-archivní stavy modelu core.system.docStatesArchive. */
    private const ACTIVE_DOC_STATES = [10, 40, 80];
    private const LAST_USED_THROTTLE_S = 60;

    /**
     * x-api-key shpd_gw_… → řádek hosting_core_ai_tokens (vč. data_source),
     * jinak 401 v Anthropic formátu. Update last_used (throttled).
     *
     * @return array<string, mixed>|Response
     */
    public function authenticate(Request $request, DataSourceConnection $db): array|Response
    {
        $token = trim((string) ($request->getHeader('x-api-key') ?? ''));
        if ($token === '' || !str_starts_with($token, AiGwToken::TOKEN_PREFIX)) {
            return self::anthropicError('authentication_error', 'x-api-key required', 401);
        }

        $keyPart = substr($token, strlen(AiGwToken::TOKEN_PREFIX));
        $prefix = substr($keyPart, 0, AiGwToken::KEY_PREFIX_LENGTH);
        $row = $db->fetchRow(
            'SELECT * FROM hosting_core_ai_tokens WHERE token_prefix = %s',
            $prefix,
        );
        if ($row === null
            || ($row['token_hash'] ?? null) === null
            || !hash_equals((string) $row['token_hash'], hash('sha256', $token))
            || (int) ($row['active'] ?? 0) !== 1
            || !in_array((int) $row['docState'], self::ACTIVE_DOC_STATES, true)
        ) {
            return self::anthropicError('authentication_error', 'invalid x-api-key', 401);
        }

        $ds = $db->fetchRow(
            'SELECT id, lifecycle, docState FROM hosting_core_data_sources WHERE id = %i',
            (int) $row['data_source'],
        );
        if ($ds === null
            || (string) ($ds['lifecycle'] ?? '') !== 'active'
            || !in_array((int) $ds['docState'], self::ACTIVE_DOC_STATES, true)
        ) {
            return self::anthropicError('authentication_error', 'invalid x-api-key', 401);
        }

        $lastUsed = $row['last_used'] ?? null;
        $lastUsedTs = $lastUsed instanceof \DateTimeInterface
            ? $lastUsed->getTimestamp()
            : (is_string($lastUsed) && $lastUsed !== '' ? (int) strtotime($lastUsed) : 0);
        if (time() - $lastUsedTs >= self::LAST_USED_THROTTLE_S) {
            $db->updateWhere(
                'hosting_core_ai_tokens',
                ['last_used' => date('Y-m-d H:i:s')],
                'id = %i',
                (int) $row['id'],
            );
        }

        return $row;
    }

    /**
     * Chybová odpověď v Anthropic error formátu
     * {"type":"error","error":{"type":…,"message":…}} — klienti Anthropicu
     * z ní umí přečíst typ i hlášku (AnthropicLlmClient, Python SDK).
     */
    public static function anthropicError(string $type, string $message, int $status): Response
    {
        return Response::raw(
            ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]],
            $status,
        );
    }
}
