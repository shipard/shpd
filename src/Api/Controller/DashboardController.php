<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

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

    public function dashboard(
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
    ): Response {
        $lang = $language ?? 'en';

        [$cards, $truncated] = $this->collectCards($db, $config, $lang, $alertRegistry);
        if ($truncated) {
            $cards[] = $this->andMoreCard($lang);
        }

        return Response::success([
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary'     => ['aiText' => null, 'counts' => $this->countByKind($cards)],
            'cards'       => $cards,
        ]);
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
    ): Response {
        $lang = $language ?? 'en';

        [$cards] = $this->collectCards($db, $config, $lang, $alertRegistry);

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
     * @return array{0: list<array<string,mixed>>, 1: bool}  [karty, zda došlo k ořezu]
     */
    private function collectCards(
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
        ?AlertCheckRegistry $alertRegistry = null,
    ): array {
        $ctx = new FeedContext($db, $config, $lang, self::MAX_CARDS);

        /** @var list<FeedSource> $sources — napevno registrované (D10). */
        $sources = [
            new MailSuggestionsSource(),
            new MailDigestSource(),
            new AlertsSource($alertRegistry),
        ];

        $cards = [];
        foreach ($sources as $src) {
            foreach ($src->collectCards($ctx) as $card) {
                $cards[] = $card;
            }
        }

        return $this->sortAndCap($cards, self::MAX_CARDS);
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
