<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Portálové endpointy hostingu (D10) — data scopovaná na session uživatele.
 * Hosting tabulky jsou adminOnly (D9), portáloví ne-admini se k evidenci
 * dostanou výhradně tudy; server vrací jen řádky přihlášeného uživatele
 * a nikdy serverové detaily.
 *
 * Endpoints:
 *   GET /_hosting/portal/my-datasources  Seznam „moje DS" pro portál
 */
class HostingPortalController
{
    /** Ne-archivní stavy modelu core.system.docStatesArchive. */
    private const ACTIVE_DOC_STATES = [10, 40, 80];

    /**
     * GET /_hosting/portal/my-datasources
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function myDatasources(AuthContext $auth, DataSourceConnection $db, array $tables): Response
    {
        // Modul hosting.core není na DS aktivní → endpoint neexistuje.
        if (!isset($tables['hosting_core_data_sources']) || !isset($tables['hosting_core_ds_users'])) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        // AuthMiddleware nepřihlášené nepustí (endpoint není exempt) —
        // přesto ověřit, ať kontroler nestojí jen na wiringu middlewaru.
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        // Snapshoty (D7) přišly až s Fází 5 — hosting bez ds-upgrade jede
        // dál bez nich (stats: null).
        $hasStats = isset($tables['hosting_core_ds_stats']);
        $select = 'SELECT ds.`id`, ds.`ds_id`, ds.`name`, ds.`url_app`, du.`role`';
        $statsJoin = '';
        if ($hasStats) {
            $select .= ', st.`alerts_count`, st.`mail_count`, st.`collected_at`';
            $statsJoin = ' LEFT JOIN `hosting_core_ds_stats` AS st ON st.`data_source` = ds.`id`';
        }

        $rows = $db->fetchAll(
            $select
            . ' FROM `hosting_core_ds_users` AS du'
            . ' JOIN `hosting_core_data_sources` AS ds ON ds.`id` = du.`data_source`'
            . $statsJoin
            . ' WHERE du.`user` = %i'
            . ' AND ds.`lifecycle` = %s'
            . ' AND du.`docState` IN %in'
            . ' AND ds.`docState` IN %in'
            . ' ORDER BY ds.`name` ASC, ds.`id` ASC',
            $auth->userId,
            'active',
            self::ACTIVE_DOC_STATES,
            self::ACTIVE_DOC_STATES,
        );

        // Jen portálový kontrakt — žádné serverové detaily (server, install
        // modul, lifecycle) sem nepatří; stats jsou záměrně jen počty (§7).
        $items = [];
        foreach ($rows as $row) {
            $stats = null;
            if ($hasStats && ($row['collected_at'] ?? null) !== null) {
                $stats = [
                    'alerts' => $row['alerts_count'] !== null ? (int) $row['alerts_count'] : null,
                    'mail' => $row['mail_count'] !== null ? (int) $row['mail_count'] : null,
                    'collected_at' => $this->toAtom($row['collected_at']),
                ];
            }
            $items[] = [
                'id'      => (int) $row['id'],
                'ds_id'   => (string) $row['ds_id'],
                'name'    => (string) $row['name'],
                'url_app' => (string) $row['url_app'],
                'role'    => (string) $row['role'],
                'stats'   => $stats,
            ];
        }

        return Response::success(['items' => $items]);
    }

    /** DB datetime (DibiDateTime i string) → ISO 8601 pro klientský freshness check. */
    private function toAtom(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
