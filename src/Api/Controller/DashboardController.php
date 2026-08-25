<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Ai\Exception\LlmException;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedCollector;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Dashboard — prezentační vrstva feedu akčních karet (fáze 2).
 *
 * Sběr, řazení a strop karet řeší `FeedCollector` (sdílený s badge stavů
 * sekcí, UI shells Fáze 3); controller nad kartami staví odpovědi —
 * dashboard feed, AI shrnutí (SSE) a `readySummary`.
 *
 * Detaily: `docs/dashboard.md`.
 */
class DashboardController
{
    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     *        Runtime definice tabulek — řídí registraci zdrojů (D8)
     *        a capabilities (D9). Prázdná mapa = fail-closed.
     */
    public function dashboard(
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
        ?AuthContext $auth = null,
    ): Response {
        $lang = $language ?? 'en';

        $collector = new FeedCollector();
        [$cards, $truncated] = $collector->collect($db, $config, $lang, $alertRegistry, $tables);
        // readySummary se počítá nad kartami po stropu (Issue #32/2) a interní
        // pole `amount`/`currency` se hned poté odstraní — do kontraktu nepatří.
        $readySummary = $this->buildReadySummary($cards);
        $cards        = $collector->stripInternalFields($cards);
        if ($truncated) {
            $cards[] = $this->andMoreCard($lang);
        }

        $data = [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary'     => ['aiText' => null, 'counts' => $collector->countByKind($cards)],
            'cards'       => $cards,
        ];
        // Souhrn ready pásma pro sbalený pruh (Issue #32/2, D8) — jen když
        // je aspoň jedna ready karta; jinak se pole vynechá.
        if ($readySummary !== null) {
            $data['readySummary'] = $readySummary;
        }
        // Capabilities (D9) — frontend podle nich skrývá upload a
        // ChatLauncher. `chat` musí zůstat identický s podmínkou Chat
        // root leafu v NavigationController (D5 + D10).
        $data['capabilities'] = [
            'mailUpload' => isset($tables['core_mail_incoming_messages']),
            'chat'       => isset($tables['core_chat_conversations'])
                && (($auth?->isAdmin ?? false) || !isset($tables['hosting_core_data_sources'])),
        ];

        return Response::success($data);
    }

    /**
     * GET /_ui/dashboard/summary — generované AI shrnutí feedu (SSE, fáze 2b).
     *
     * Sdílí `FeedCollector::collect()` s `dashboard()`, takže shrnutí vzniká nad
     * přesně týmiž kartami, jaké vidí uživatel. Události: `text {delta}` (jen při
     * cache miss), `done {text, cached}` (`text=null` = prázdný feed nebo
     * degradace — frontend ponechá statické county), `error {message}`.
     * Vzor streamu: ChatController. Detaily docs/dashboard.md §AI shrnutí.
     */
    public function summary(
        DataSourceConnection $db,
        DashboardSummaryService $service,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): Response {
        $lang = $language ?? 'en';

        $collector = new FeedCollector();
        [$cards] = $collector->collect($db, $config, $lang, $alertRegistry, $tables);
        // Čisté karty i pro AI shrnutí — digest cache se interními poli nemění.
        $cards = $collector->stripInternalFields($cards);

        return Response::stream(
            function () use ($service, $cards, $lang): void {
                try {
                    $result = $service->stream($cards, $lang, function (string $delta): void {
                        $this->sse('text', ['delta' => $delta]);
                    });
                    $this->sse('done', ['text' => $result['text'], 'cached' => $result['cached']]);
                } catch (LlmException $e) {
                    $this->sse('error', ['message' => $e->getMessage()]);
                } catch (\Throwable $e) {
                    ErrorLogger::warn('DashboardController: summary stream failed', ['error' => $e->getMessage()]);
                    $this->sse('error', ['message' => 'Internal error during summary']);
                }
            },
            200,
            'text/event-stream; charset=utf-8',
        );
    }

    /**
     * GET /_ui/section-badges — badge stavů sekcí navigace (UI shells Fáze 3).
     *
     * Stejný sběr karet jako dashboard (plný FeedContext), jiná prezentace:
     * agregace per `navSection` (`FeedCollector::sectionBadges`, D2–D4).
     * Odpověď: `{sections: {"<sectionId>": {count, severity}}}` — jen
     * neprázdné sekce, `_top` je platný klíč.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function sectionBadges(
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): Response {
        $lang = $language ?? 'en';

        $collector = new FeedCollector();
        [$cards] = $collector->collect($db, $config, $lang, $alertRegistry, $tables);

        // (object) — prázdná mapa musí být v JSON `{}`, ne `[]`.
        return Response::success(['sections' => (object) $collector->sectionBadges($cards)]);
    }

    /**
     * Souhrn ready pásma pro sbalené pruhy feedu (Issue #32/2, D8 + D11).
     * Počítá se z karet PO `sortAndCap` — souhrn odpovídá tomu, co uživatel
     * vidí — a dělí se **per kategorie**: `invoices` (přijaté faktury,
     * defenzivní default) a `registry` (Spisovna) mají každá vlastní pruh.
     * Částky se agregují per měna, nikdy napříč měnami; karta bez
     * `amount`/`currency` se do `amounts` nezapočítá, do `count` ano
     * (registry karty částky nenesou → jejich `amounts` je vždy prázdné).
     * `confidencePct` u ready karet vždy existuje (pásmo se bez jistoty
     * nespočítá), kód je přesto defenzivní — bez hodnot zůstane min/max null.
     *
     * @param  list<array<string,mixed>> $cards
     * @return array<string, array{count:int,
     *               amounts:list<array{currency:string,total:float}>,
     *               confidenceMin:int|null, confidenceMax:int|null}>|null
     *         klíče `invoices`/`registry`, jen neprázdné skupiny;
     *         null = žádná ready karta (pole se v odpovědi vynechá)
     * @internal Public pro účely testů — čistá transformace bez business logiky.
     */
    public function buildReadySummary(array $cards): ?array
    {
        $groups = [];
        foreach ($cards as $card) {
            if (($card['kind'] ?? '') !== 'ready') {
                continue;
            }
            $key = ($card['category'] ?? '') === 'registry' ? 'registry' : 'invoices';
            $g = $groups[$key] ?? ['count' => 0, 'totals' => [], 'confMin' => null, 'confMax' => null];
            $g['count']++;
            $amount   = $card['amount'] ?? null;
            $currency = $card['currency'] ?? null;
            if ((is_int($amount) || is_float($amount)) && is_string($currency) && $currency !== '') {
                $g['totals'][$currency] = ($g['totals'][$currency] ?? 0.0) + (float) $amount;
            }
            $conf = $card['confidencePct'] ?? null;
            if (is_int($conf)) {
                $g['confMin'] = $g['confMin'] === null ? $conf : min($g['confMin'], $conf);
                $g['confMax'] = $g['confMax'] === null ? $conf : max($g['confMax'], $conf);
            }
            $groups[$key] = $g;
        }
        if ($groups === []) {
            return null;
        }

        $summary = [];
        foreach (['invoices', 'registry'] as $key) {
            if (!isset($groups[$key])) {
                continue;
            }
            $amounts = [];
            foreach ($groups[$key]['totals'] as $currency => $total) {
                $amounts[] = ['currency' => (string) $currency, 'total' => round($total, 2)];
            }
            $summary[$key] = [
                'count'         => $groups[$key]['count'],
                'amounts'       => $amounts,
                'confidenceMin' => $groups[$key]['confMin'],
                'confidenceMax' => $groups[$key]['confMax'],
            ];
        }
        return $summary;
    }

    /** Writes one SSE event frame and flushes it to the client. */
    private function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }

    /**
     * Závěrečná info karta při ořezu feedu — navigace na došlou poštu.
     *
     * Záměrně bez `category` — karty bez kategorie frontend zobrazuje jen
     * v záložce Vše (bezpečný default, docs/dashboard.md §4).
     */
    private function andMoreCard(string $lang): array
    {
        return [
            'id'         => 'mail_more',
            'source'     => 'mail',
            'kind'       => 'info',
            'icon'       => 'mail',
            'stateStyle' => 'concept',
            'title'      => $lang === 'cs' ? '…a další nezpracovaná pošta' : '…and more unprocessed mail',
            'subtitle'   => '',
            'timestamp'  => null,
            'context'    => [],
            'actions'    => [
                ['id' => 'openMail', 'kind' => 'open_viewer', 'target' => ['viewerId' => 'core.mail.incoming']],
            ],
        ];
    }
}
