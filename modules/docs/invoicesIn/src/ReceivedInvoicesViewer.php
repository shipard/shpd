<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for received invoices (doc_type = 'invni').
 *
 * All behavior — doc_type filter in selectRows, number-series bottom tabs,
 * newRecordDefaults for the create form — is derived in DocsHeadsViewer
 * from $scopedDocType.
 */
class ReceivedInvoicesViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'invni';
}
