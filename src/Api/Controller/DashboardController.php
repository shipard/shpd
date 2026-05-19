<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\ViewerRegistry;

/**
 * Dashboard — agregovaný pohled na alerts / mail / tasks pro home obrazovku.
 *
 * MVP: hardcoded sada tří widgetů. Položky se získávají re-use existujících
 * viewerů přes `selectRows()` + `renderRow()`, počty otevřených záznamů
 * doplňuje samostatný COUNT (může být > items.length, viz `count` v API).
 *
 * Modularita (widgety per modul přes `module.jsonc`) je out of scope pro
 * fázi 1 — viz tasks/dashboard-phase1.md.
 */
class DashboardController
{
    private const int ITEMS_PER_WIDGET = 7;

    /** alert_state Active — viz core.alerts.alertStates + AlertReconciler::STATE_ACTIVE. */
    private const int ALERT_STATE_ACTIVE = 10;

    public function dashboard(
        ViewerRegistry $registry,
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
    ): Response {
        $lang = $language ?? 'en';

        $alerts = $this->buildAlertsWidget($registry, $db, $config, $lang);
        $mail   = $this->buildMailWidget($registry, $db, $config, $lang);
        $tasks  = $this->buildTasksWidget($registry, $db, $config, $lang);

        return Response::success([
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary' => [
                'alertsCount'       => $alerts['count'],
                'incomingMailCount' => $mail['count'],
                'tasksCount'        => $tasks['count'],
            ],
            'widgets' => [$alerts, $mail, $tasks],
        ]);
    }

    private function buildAlertsWidget(
        ViewerRegistry $registry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
    ): array {
        $viewerId = 'core.alerts.alerts';
        $items = $this->fetchWidgetItems(
            $registry, $db, $config, $lang, $viewerId,
            [['id' => 'alert_state', 'value' => 'active']],
            'alert',
            ['kind' => 'open_viewer', 'viewerId' => $viewerId],
        );

        $def = $registry->get($viewerId);
        $count = 0;
        if ($def !== null) {
            $val = $db->fetchSingle(
                'SELECT COUNT(*) FROM `' . $def->table . '` WHERE `alert_state` = %i',
                self::ALERT_STATE_ACTIVE,
            );
            $count = (int) ($val ?? 0);
        }

        return [
            'id'            => 'alerts',
            'type'          => 'alerts',
            'title'         => $lang === 'cs' ? 'Upozornění' : 'Alerts',
            'icon'          => 'alert',
            'count'         => $count,
            'items'         => $items,
            'openAllAction' => ['viewerId' => $viewerId],
        ];
    }

    private function buildMailWidget(
        ViewerRegistry $registry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
    ): array {
        $viewerId = 'core.mail.incoming';
        $items = $this->fetchWidgetItems(
            $registry, $db, $config, $lang, $viewerId,
            [['id' => 'viewGroup', 'value' => 'active']],
            'mail',
            ['kind' => 'open_viewer', 'viewerId' => $viewerId],
        );

        $count = $this->countActiveByDocState(
            $db, $registry, $config, $viewerId, 'core.mail.docStatesIncoming',
        );

        return [
            'id'            => 'incoming_mail',
            'type'          => 'mail',
            'title'         => $lang === 'cs' ? 'Aktuální došlá pošta' : 'Recent incoming mail',
            'icon'          => 'mail',
            'count'         => $count,
            'items'         => $items,
            'openAllAction' => ['viewerId' => $viewerId],
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
