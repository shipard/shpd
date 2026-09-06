<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\AbstractDocumentEventHandler;

/**
 * afterSave handler na `economy_codebooks_cash_desks`: pokladna uložená
 * ve stavu 40 (V pořádku) dostane hned řady všech typů dokladu se
 * `series_binding: cash_desk` — bez čekání na `ds-upgrade`.
 *
 * economy.codebooks na docs.core nezávisí, proto handler registrovaný
 * v module.jsonc docs.core, ne hook v CashDeskDocument. Idempotentní
 * (BoundNumberSeriesProvisioner), výjimku polyká dispatcher.
 */
final class CashDeskSeriesEventHandler extends AbstractDocumentEventHandler
{
    public function onAfterSave(string $tableId, array $data, ?array $originalData): void
    {
        if ($this->db === null || $this->config === null) {
            return;
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || (int) ($data['docState'] ?? 10) !== 40) {
            return;
        }
        (new BoundNumberSeriesProvisioner(new DataSourceConnection($this->db), $this->config))
            ->provisionForCashDesk($id);
    }
}
