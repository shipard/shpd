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

        $rows = $db->fetchAll(
            'SELECT ds.`id`, ds.`ds_id`, ds.`name`, ds.`url_app`, du.`role`'
            . ' FROM `hosting_core_ds_users` AS du'
            . ' JOIN `hosting_core_data_sources` AS ds ON ds.`id` = du.`data_source`'
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
        // modul, lifecycle) sem nepatří.
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'      => (int) $row['id'],
                'ds_id'   => (string) $row['ds_id'],
                'name'    => (string) $row['name'],
                'url_app' => (string) $row['url_app'],
                'role'    => (string) $row['role'],
            ];
        }

        return Response::success(['items' => $items]);
    }
}
