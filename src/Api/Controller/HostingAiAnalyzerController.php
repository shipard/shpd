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
 * Lookup API pro AI analyzery (hosting-10 D4) — protistrana procesu
 * `sources-sync` v repu `ai_analyzer`. Analyzer si periodicky stahuje
 * lookup a atomicky přepisuje spravovaný soubor `sources.d/hosting.json`;
 * hosting nikdy nevolá analyzer.
 *
 * Endpoint (exempt v AuthMiddleware — auth si dělá kontroler):
 *   GET /_hosting/ai-analyzer/lookup   JSON pole položek sources.d
 *       souboru [{id, base_url, api_token}]; ETag/If-None-Match → 304
 *
 * Autentizace: `Authorization: Bearer shpd_hk_…` klíčem analyzeru
 * (hosting_core_ai_analyzers) přes sdílený HostingApiKeyAuthenticator.
 * Klíč plní CLI `hosting-analyzer-key`.
 *
 * analyzer_token opouští hosting jedině tímto endpointem (https,
 * dešifrovaný do api_token).
 *
 * Spec: tasks/hosting-10-ai-analyzer.md, docs/hosting.md.
 */
class HostingAiAnalyzerController
{
    public function __construct(
        private readonly DataSourceConfig $config,
        private readonly ?DsSecretCipher $cipher = null,
    ) {}

    /**
     * GET /_hosting/ai-analyzer/lookup
     *
     * Response — přesně obsah jednoho sources.d souboru (JSON pole,
     * žádný success envelope): [{"id": ds_id, "base_url": url_app,
     * "api_token": token}], řazeno dle id. Žádné web_id aliasy — loader
     * analyzeru duplicitní id odmítá; ds_id vyhovuje regexu SourceConfig
     * `[a-z0-9_-]{1,64}`. Bez timeout_seconds (default 60 v loaderu).
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function lookup(Request $request, DataSourceConnection $db, array $tables): Response
    {
        if (!isset($tables['hosting_core_ai_analyzers'])
            || !isset($tables['hosting_core_data_sources'])
        ) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $authenticator = new HostingApiKeyAuthenticator(
            'hosting_core_ai_analyzers',
            errorMessage: 'Analyzer key required',
            invalidMessage: 'Invalid analyzer key',
        );
        $analyzer = $authenticator->authenticate($request, $db);
        if ($analyzer instanceof Response) {
            return $analyzer;
        }

        $rows = $db->fetchAll(
            'SELECT * FROM hosting_core_data_sources'
            . ' WHERE lifecycle = %s AND docState IN %in AND analyzer_token IS NOT NULL'
            . ' ORDER BY ds_id ASC',
            'active',
            HostingApiKeyAuthenticator::ACTIVE_DOC_STATES,
        );

        $cipher = $this->cipher ?? DsSecretCipher::forConfig($this->config);
        $sources = [];
        foreach ($rows as $row) {
            $encrypted = (string) ($row['analyzer_token'] ?? '');
            if ($encrypted === '') {
                continue;
            }
            try {
                $token = $cipher->decrypt($encrypted);
            } catch (\Throwable $e) {
                // Jeden nedešifrovatelný token nesmí shodit lookup pro
                // ostatní DS — přeskočit a zalogovat.
                ErrorLogger::warn('hosting ai-analyzer lookup: analyzer_token nejde dešifrovat — DS přeskočen', [
                    'dsId' => (string) $row['ds_id'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $sources[] = [
                'id' => (string) $row['ds_id'],
                'base_url' => (string) $row['url_app'],
                'api_token' => $token,
            ];
        }

        // Body je PŘESNĚ formát sources.d souboru (JSON pole, žádný
        // success envelope) — sources-sync ho po validaci zapisuje beze
        // změny. Deterministické řazení (ORDER BY ds_id) → stabilní ETag
        // (sha256 kanonizovaného obsahu).
        $canonical = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('sha256', (string) $canonical) . '"';

        $ifNoneMatch = trim((string) ($request->getHeader('if-none-match') ?? ''));
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            return Response::raw(null, 304)->withHeader('ETag', $etag);
        }

        return Response::raw($sources)->withHeader('ETag', $etag);
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
