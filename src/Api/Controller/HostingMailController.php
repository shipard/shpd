<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\HostingApiKeyAuthenticator;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Lookup API pro mail-routery (D4) — protistrana procesu `lookup-sync`
 * v repu `mail_router`. Router si periodicky stahuje lookup a atomicky
 * přepisuje lokální `lookup.json`; hosting nikdy nevolá router.
 *
 * Endpoint (exempt v AuthMiddleware — auth si dělá kontroler):
 *   GET /_hosting/mail/lookup   celý lookup ve formátu lookup.json
 *       ({hosts, data_sources}); ETag/If-None-Match → 304 bez body
 *
 * Autentizace: `Authorization: Bearer shpd_hk_…` klíčem routeru
 * (hosting_core_mail_routers) přes sdílený HostingApiKeyAuthenticator.
 * Klíč plní CLI `hosting-router-key`.
 *
 * mail_token opouští hosting jedině tímto endpointem (https,
 * dešifrovaný do api_token) — druhé místo po queue payloadu, kde
 * secret opouští hosting.
 *
 * Spec: tasks/hosting-04-mail-router.md, docs/hosting.md §5.3.
 */
class HostingMailController
{
    public function __construct(
        private readonly DataSourceConfig $config,
        private readonly ?DsSecretCipher $cipher = null,
    ) {}

    /**
     * GET /_hosting/mail/lookup
     *
     * Response — přesně formát lookup.json mail-routeru:
     *   {"hosts": [...], "data_sources": {"<ds_id|web_id>":
     *    {"api_url": ..., "api_token": ...}}}
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function lookup(Request $request, DataSourceConnection $db, array $tables): Response
    {
        if (!isset($tables['hosting_core_mail_routers'])
            || !isset($tables['hosting_core_data_sources'])
        ) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $authenticator = new HostingApiKeyAuthenticator(
            'hosting_core_mail_routers',
            errorMessage: 'Router key required',
            invalidMessage: 'Invalid router key',
        );
        $router = $authenticator->authenticate($request, $db);
        if ($router instanceof Response) {
            return $router;
        }

        $hosts = array_values(array_filter(array_map(
            static fn (string $h): string => mb_strtolower(trim($h)),
            explode(',', (string) ($router['domains'] ?? '')),
        ), static fn (string $h): bool => $h !== ''));
        sort($hosts);

        $rows = $db->fetchAll(
            'SELECT * FROM hosting_core_data_sources'
            . ' WHERE lifecycle = %s AND docState IN %in AND mail_token IS NOT NULL'
            . ' ORDER BY id ASC',
            'active',
            HostingApiKeyAuthenticator::ACTIVE_DOC_STATES,
        );

        $cipher = $this->cipher ?? DsSecretCipher::forConfig($this->config);
        $dataSources = [];
        foreach ($rows as $row) {
            $encrypted = (string) ($row['mail_token'] ?? '');
            if ($encrypted === '') {
                continue;
            }
            try {
                $token = $cipher->decrypt($encrypted);
            } catch (\Throwable $e) {
                // Jeden nedešifrovatelný token nesmí shodit lookup pro
                // ostatní DS — přeskočit a zalogovat.
                ErrorLogger::warn('hosting mail lookup: mail_token nejde dešifrovat — DS přeskočen', [
                    'dsId' => (string) $row['ds_id'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $entry = [
                'api_url' => (string) $row['url_app'],
                'api_token' => $token,
            ];
            $dataSources[(string) $row['ds_id']] = $entry;
            $webId = trim((string) ($row['web_id'] ?? ''));
            if ($webId !== '') {
                $dataSources[$webId] = $entry;
            }
        }
        ksort($dataSources, SORT_STRING);

        // Body je PŘESNĚ formát lookup.json (žádný success envelope) —
        // lookup-sync ho po validaci zapisuje beze změny. Objektový cast
        // drží `{}` i pro prázdnou mapu; deterministické řazení výše →
        // stabilní ETag (sha256 kanonizovaného obsahu).
        $payload = [
            'hosts' => $hosts,
            'data_sources' => (object) $dataSources,
        ];
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('sha256', (string) $canonical) . '"';

        $ifNoneMatch = trim((string) ($request->getHeader('if-none-match') ?? ''));
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            return Response::raw(null, 304)->withHeader('ETag', $etag);
        }

        return Response::raw($payload)->withHeader('ETag', $etag);
    }

    /**
     * If-None-Match může nést víc hodnot, wildcard a weak prefix (W/).
     */
    private function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '*') {
            return true;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }
}
