<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accbal\BalanceMatcher;
use Shipard\Module\Economy\Accbal\MatchSummary;

/**
 * Endpoints:
 *   POST /_accbal/match — dávkové párování úhrad saldokonta ({@see BalanceMatcher::matchAll})
 *
 * Body (všechna pole volitelná): {"scope": "all", "partner": <int>,
 * "fiscalYear": <int>, "dryRun": <bool>}. Vyžaduje `scope: "all"` nebo aspoň
 * jeden filtr — validace zrcadlí CLI `accbal-match` (--all / --partner /
 * --fiscal-year). Response nese jen agregát z MatchSummary; per-result řádky
 * (mohou být tisíce) se neserializují. Destruktivní cesty matcheru (unmatch,
 * rematch-partner) zůstávají jen v CLI.
 */
class AccbalController
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
        private readonly JournalEventDispatcher $journalEvents,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {}

    /** POST /_accbal/match */
    public function match(Request $request): Response
    {
        // Běh nad velkým DS trvá nízké desítky sekund — nesmí ho utnout max_execution_time.
        set_time_limit(0);

        $body = $request->getBody() ?? [];

        $scope = $body['scope'] ?? null;
        if ($scope !== null && $scope !== 'all') {
            return Response::error('VALIDATION', 'Unsupported scope; only "all" is allowed', 400);
        }

        $filters = [];
        foreach (['partner', 'fiscalYear'] as $key) {
            if (!isset($body[$key])) {
                continue;
            }
            if (!is_int($body[$key]) || $body[$key] <= 0) {
                return Response::error('VALIDATION', "{$key} must be a positive integer", 400);
            }
            $filters[$key] = $body[$key];
        }

        if ($scope !== 'all' && $filters === []) {
            return Response::error(
                'VALIDATION',
                'Requires "scope": "all" or at least one filter (partner / fiscalYear)',
                400,
            );
        }

        $dryRun = $body['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return Response::error('VALIDATION', 'dryRun must be a boolean', 400);
        }

        $summary = $this->runMatch($filters, $dryRun);

        return Response::success([
            'dryRun'            => $dryRun,
            'candidates'        => $summary->candidates(),
            'allocated'         => $summary->allocated,
            'planned'           => $summary->planned,
            'routedUnallocated' => $summary->routedUnallocated,
            'skipped'           => $summary->skipped,
            'matchedAmount'     => $summary->matchedAmount,
        ]);
    }

    /**
     * Seam pro testy (subclassing) — BalanceMatcher je final, stubuje se
     * až celý běh dávky.
     *
     * @param array{partner?: int, fiscalYear?: int} $filters
     */
    protected function runMatch(array $filters, bool $dryRun): MatchSummary
    {
        $matcher = new BalanceMatcher(
            $this->db->getDibiConnection(),
            $this->config,
            $this->journalEvents,
            $this->dsConfig,
        );
        return $matcher->matchAll($filters, $dryRun);
    }
}
