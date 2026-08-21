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
use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;
use Shipard\Module\Core\Exchange\Dashboard\ContentTagSuggestionsSource;
use Shipard\Module\Core\Mail\Feed\MailDigestSource;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Dashboard — prioritizovaný feed akčních karet pro home obrazovku (fáze 2).
 *
 * Alerty a návrhy z došlé pošty se agregují do jednotného `cards[]` přes lehké
 * `FeedSource` zdroje (napevno registrované, D10).
 *
 * Řazení a strop řeší server (`sortAndCap` dle `KIND_ORDER` + `timestamp`),
 * frontend jen renderuje. `summary.aiText` je v této fázi `null` (naplní 2b).
 *
 * Detaily: `docs/dashboard.md`.
 */
class DashboardController
{
    /** Strop počtu karet feedu; při ořezu se přidá info karta „a další…". */
    private const int MAX_CARDS = 30;

    /** Prioritní žebříček pásem karet (nižší = výše). Sekundárně timestamp DESC. */
    private const array KIND_ORDER = ['urgent' => 0, 'review' => 1, 'ready' => 2, 'info' => 3];

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

        [$cards, $truncated, $readySummary] = $this->collectCards($db, $config, $lang, $alertRegistry, $tables);
        if ($truncated) {
            $cards[] = $this->andMoreCard($lang);
        }

        $data = [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary'     => ['aiText' => null, 'counts' => $this->countByKind($cards)],
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
     * Sdílí `collectCards()` s `dashboard()`, takže shrnutí vzniká nad přesně
     * týmiž kartami, jaké vidí uživatel. Události: `text {delta}` (jen při
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

        [$cards] = $this->collectCards($db, $config, $lang, $alertRegistry, $tables);

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
     * Posbírá karty ze zdrojů feedu, seřadí a stropuje (`sortAndCap`). Sdílené
     * mezi `dashboard()` a `summary()` — obě odpovědi stojí nad týmiž kartami.
     * Info karta „…a další" se přidává až v `dashboard()` (do shrnutí nepatří).
     *
     * `readySummary` se počítá tady (nad kartami po stropu) a interní pole
     * `amount`/`currency` se hned odstraní — čisté karty tak dostane i AI
     * shrnutí (`summary()`), digest cache se interními poli nemění.
     *
     * @return array{0: list<array<string,mixed>>, 1: bool, 2: array<string,mixed>|null}
     *         [karty, zda došlo k ořezu, souhrn ready pásma]
     */
    private function collectCards(
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): array {
        $ctx = new FeedContext($db, $config, $lang, self::MAX_CARDS);

        // Zdroje se registrují podle přítomnosti klíčové tabulky na DS (D8) —
        // dashboard nesmí padat na DS bez core.mail / core.alerts (hosting DS).
        // Mapování tabulka → zdroj drží controller; zdroje zůstávají napevno
        // registrované (dashboard.md D10), žádné nové rozhraní na FeedSource.
        /** @var list<FeedSource> $sources */
        $sources = [];
        if (isset($tables['core_mail_incoming_messages'])) {
            $sources[] = new MailSuggestionsSource();
            $sources[] = new MailDigestSource();
        }
        if (isset($tables['core_alerts_alerts'])) {
            $sources[] = new AlertsSource($alertRegistry);
        }
        // Karta „Nová kategorie" (content-tag-ui D25) — potřebuje analýzy
        // (štítky návrhů), položky (pokrytí štítků) i osnovu (volba účtu
        // goods.stock + materializace).
        if (isset($tables['core_mail_message_analyses'], $tables['economy_items'], $tables['economy_accounting_accounts'])) {
            $sources[] = new ContentTagSuggestionsSource();
        }

        $cards = [];
        foreach ($sources as $src) {
            // Per-source izolace (D8): výjimka jednoho zdroje se zaloguje
            // a feed pokračuje ostatními zdroji.
            try {
                foreach ($src->collectCards($ctx) as $card) {
                    $cards[] = $card;
                }
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'Dashboard feed source failed: ' . $src::class);
            }
        }

        [$cards, $truncated] = $this->sortAndCap($cards, self::MAX_CARDS);
        $readySummary = $this->buildReadySummary($cards);

        return [$this->stripInternalFields($cards), $truncated, $readySummary];
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

    /**
     * Odstraní interní pole `amount`/`currency` (podklad pro readySummary)
     * ze všech karet — do kartového kontraktu (docs/dashboard.md §4) nepatří.
     *
     * @param  list<array<string,mixed>> $cards
     * @return list<array<string,mixed>>
     * @internal Public pro účely testů.
     */
    public function stripInternalFields(array $cards): array
    {
        foreach ($cards as &$card) {
            unset($card['amount'], $card['currency']);
        }
        return $cards;
    }

    /** Writes one SSE event frame and flushes it to the client. */
    private function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }

    /**
     * Seřadí karty dle prioritního žebříčku (`KIND_ORDER`), uvnitř pásma dle
     * `timestamp` sestupně (nejnovější první; karty bez timestampu naspod), a
     * ořízne na `$max`.
     *
     * @param  list<array<string,mixed>> $cards
     * @return array{0: list<array<string,mixed>>, 1: bool}  [seřazené+oříznuté, zda došlo k ořezu]
     * @internal Public pro účely testů — čistá transformace bez business logiky.
     */
    public function sortAndCap(array $cards, int $max): array
    {
        usort($cards, static function (array $a, array $b): int {
            $oa = self::KIND_ORDER[$a['kind'] ?? ''] ?? 99;
            $ob = self::KIND_ORDER[$b['kind'] ?? ''] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $ta = (string) ($a['timestamp'] ?? '');
            $tb = (string) ($b['timestamp'] ?? '');
            if ($ta === $tb) {
                return 0;
            }
            if ($ta === '') {
                return 1;
            }
            if ($tb === '') {
                return -1;
            }
            return strcmp($tb, $ta); // ATOM formát řadí lexikálně = chronologicky
        });

        $truncated = count($cards) > $max;
        if ($truncated) {
            $cards = array_slice($cards, 0, $max);
        }
        return [$cards, $truncated];
    }

    /**
     * Počty karet dle kind (jen actionable pásma — urgent/review/ready).
     * Info karty (vč. „a další…") se nezapočítávají.
     *
     * @param  list<array<string,mixed>> $cards
     * @return array{urgent:int, review:int, ready:int}
     * @internal Public pro účely testů.
     */
    public function countByKind(array $cards): array
    {
        $counts = ['urgent' => 0, 'review' => 0, 'ready' => 0];
        foreach ($cards as $card) {
            $kind = (string) ($card['kind'] ?? '');
            if (isset($counts[$kind])) {
                $counts[$kind]++;
            }
        }
        return $counts;
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
