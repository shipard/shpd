<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Ai\Exception\LlmException;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;
use Shipard\Module\Core\Mail\Feed\MailDigestSource;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Dashboard — prioritizovaný feed akčních karet pro home obrazovku (fáze 2).
 *
 * Alerty a návrhy z došlé pošty se agregují do jednotného `cards[]` přes lehké
 * `FeedSource` zdroje (napevno registrované, D10). Úkoly zůstávají jako
 * sekundární widget `tasks` pod feedem (re-use fáze 1: `buildTasksWidget` +
 * `fetchWidgetItems` + `renderRowToWidgetItem`).
 *
 * Řazení a strop řeší server (`sortAndCap` dle `KIND_ORDER` + `timestamp`),
 * frontend jen renderuje. `summary.aiText` je v této fázi `null` (naplní 2b).
 *
 * Detaily: `docs/dashboard.md`.
 */
class DashboardController
{
    private const int ITEMS_PER_WIDGET = 7;

    /** Strop počtu karet feedu; při ořezu se přidá info karta „a další…". */
    private const int MAX_CARDS = 30;

    /** Prioritní žebříček pásem karet (nižší = výše). Sekundárně timestamp DESC. */
    private const array KIND_ORDER = ['urgent' => 0, 'review' => 1, 'ready' => 2, 'info' => 3];

    public function dashboard(
        ViewerRegistry $registry,
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

        $tasks = $this->buildTasksWidget($registry, $db, $config, $lang);

        return Response::success([
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary'     => ['aiText' => null, 'counts' => $this->countByKind($cards)],
            'cards'       => $cards,
            'tasks'       => $tasks,
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
        ViewerRegistry $registry,
        DataSourceConnection $db,
        DashboardSummaryService $service,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
    ): Response {
        $lang = $language ?? 'en';

        [$cards] = $this->collectCards($db, $config, $lang, $alertRegistry);
        $tasksCount = $this->countActiveByDocState(
            $db, $registry, $config, 'tasks.core', 'tasks.core.docStatesTasks',
        );

        return Response::stream(
            function () use ($service, $cards, $tasksCount, $lang): void {
                try {
                    $result = $service->stream($cards, $tasksCount, $lang, function (string $delta): void {
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

    private function buildTasksWidget(
        ViewerRegistry $registry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
    ): array {
        $viewerId = 'tasks.core';
        $items = $this->fetchWidgetItems(
            $registry, $db, $config, $lang, $viewerId,
            [['id' => 'viewGroup', 'value' => 'active']],
            'list-check',
            ['kind' => 'open_form', 'table' => 'tasks_core_tasks'],
        );

        $count = $this->countActiveByDocState(
            $db, $registry, $config, $viewerId, 'tasks.core.docStatesTasks',
        );

        return [
            'id'            => 'tasks',
            'type'          => 'tasks',
            'title'         => $lang === 'cs' ? 'Aktivní úkoly' : 'Active tasks',
            'icon'          => 'list-check',
            'count'         => $count,
            'items'         => $items,
            'openAllAction' => ['viewerId' => $viewerId],
        ];
    }

    /**
     * Re-use vieweru přes `selectRows()` + `renderRow()` a transformace na
     * widget-row tvar (viz API kontrakt v tasks/dashboard-phase1.md).
     *
     * Viewer může chybět (modul vypnutý) → vrátíme prázdný seznam. Chyby
     * při čtení (typicky compiled config nedoběhl) tichne — frontend pak
     * jen ukáže `count=0` empty state, ne celkový crash.
     */
    private function fetchWidgetItems(
        ViewerRegistry $registry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
        string $viewerId,
        array $filters,
        string $widgetIcon,
        array $actionTemplate,
    ): array {
        $viewer = $registry->createViewer($viewerId, $db, $config, $lang);
        if ($viewer === null) {
            return [];
        }

        try {
            $rawRows = $viewer->selectRows(null, $filters, 0);
        } catch (\Throwable) {
            return [];
        }

        $rawRows = array_slice($rawRows, 0, self::ITEMS_PER_WIDGET);

        $items = [];
        foreach ($rawRows as $rawRow) {
            $rendered = $viewer->renderRow($rawRow);
            $items[] = $this->renderRowToWidgetItem($rendered, $actionTemplate, $widgetIcon);
        }
        return $items;
    }

    /**
     * COUNT(*) pro mail/tasks: stavy z cfgItem.viewGroup=active. Pokud cfgItem
     * není dostupný (compiled config nedoběhl), vrátíme 0 — viz graceful
     * fallback v public/index.php.
     */
    private function countActiveByDocState(
        DataSourceConnection $db,
        ViewerRegistry $registry,
        ?ConfigRuntime $config,
        string $viewerId,
        string $cfgItemId,
    ): int {
        $def = $registry->get($viewerId);
        if ($def === null || $config === null) {
            return 0;
        }
        $cfg    = DocStateConfig::fromCfgItem($config->cfgItem($cfgItemId));
        $states = $cfg->getViewGroupStates('active');
        if ($states === []) {
            return 0;
        }
        $val = $db->fetchSingle(
            'SELECT COUNT(*) FROM `' . $def->table . '` WHERE `docState` IN %in',
            $states,
        );
        return (int) ($val ?? 0);
    }

    /**
     * Mapuje renderRow() výstup vieweru na widget-row tvar pro Dashboard
     * (kompaktnější než plný viewer řádek, neobsahuje i1/i2/t3).
     *
     * @param  array{kind:'open_viewer',viewerId:string}|array{kind:'open_form',table:string}  $actionTemplate
     *         Šablona action; recordId se vyplní z řádku (rendered['id']).
     * @internal Public pro účely testů — bez business logiky, čistá transformace.
     */
    public function renderRowToWidgetItem(
        array $rendered,
        array $actionTemplate,
        ?string $widgetIcon,
    ): array {
        $id = (int) ($rendered['id'] ?? 0);
        return [
            'id'         => $id,
            'stateStyle' => $rendered['stateStyle'] ?? null,
            'title'      => $this->flattenTextField($rendered['t1'] ?? null, ' ')
                            ?: ('#' . $id),
            'subtitle'   => $this->flattenTextField($rendered['t2'] ?? null, ' · '),
            'icon'       => $rendered['icon'] ?? $widgetIcon,
            'action'     => array_merge($actionTemplate, ['recordId' => $id]),
        ];
    }

    /**
     * Sploští `t1`/`t2` z renderRow() do jediného stringu.
     * Akceptuje: null, string, `{text, class?}`, list<string|{text, class?}>.
     *
     * @internal Public pro účely testů.
     */
    public function flattenTextField(mixed $value, string $separator): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && isset($value['text'])) {
            return (string) $value['text'];
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                    continue;
                }
                if (is_array($item) && isset($item['text'])) {
                    $parts[] = (string) $item['text'];
                }
            }
            return $parts !== [] ? implode($separator, $parts) : null;
        }
        return null;
    }
}
