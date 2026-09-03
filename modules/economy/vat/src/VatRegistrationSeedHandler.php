<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\AbstractDocumentEventHandler;

/**
 * afterSave handler na `economy_codebooks_vat_registrations`: po uložení
 * registrace hned zajistí instance tvrzení pokrývající dnešek a zítřek
 * (D9 — seed běžného období; setup wizard ukládá přes tentýž Document).
 * economy.codebooks na economy.vat nezávisí, proto handler, ne hook
 * v VatRegistrationDocument. Idempotentní; výjimku polyká dispatcher.
 */
final class VatRegistrationSeedHandler extends AbstractDocumentEventHandler
{
    public function onAfterSave(string $tableId, array $data, ?array $originalData): void
    {
        if ($this->db === null) {
            return;
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || (int) ($data['docState'] ?? 10) === 90) {
            return;
        }
        (new ReportPeriodsProvisioner(new DataSourceConnection($this->db)))->ensureForRegistration($id);
    }
}
