<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\Document;

/**
 * Document třída pro `hosting_core_ai_analyzers` (hosting-10, D1).
 *
 * Oproti mail-routerům žádná normalizace — analyzer nemá `domains`,
 * obsluhuje všechny DS. Drží jen audit sloupce created/modified.
 */
class AiAnalyzerDocument extends Document
{
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        if (empty($data['id']) && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }
}
