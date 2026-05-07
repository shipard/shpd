<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for issued invoices (doc_type = 'invno').
 *
 * Inherits everything from DocsHeadsViewer and adds a fixed type filter.
 */
class IssuedInvoicesViewer extends DocsHeadsViewer
{
    private const DOC_TYPE = 'invno';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $filters[] = ['id' => '_doc_type', 'value' => self::DOC_TYPE];
        return parent::selectRows($search, $filters, $pageNumber);
    }

    public function getNewRecordDefaults(): array
    {
        return ['doc_type' => self::DOC_TYPE];
    }
}
