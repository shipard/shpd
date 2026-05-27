<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for issued invoices (doc_type = 'invno').
 *
 * All behavior — doc_type filter in selectRows, number-series bottom tabs,
 * newRecordDefaults for the create form — is derived in DocsHeadsViewer
 * from $scopedDocType.
 */
class IssuedInvoicesViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'invno';
}
