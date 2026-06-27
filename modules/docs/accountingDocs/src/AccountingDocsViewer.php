<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\AccountingDocs;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for accounting documents (doc_type = 'cmnbkp').
 *
 * All behavior — doc_type filter in selectRows, number-series bottom tabs,
 * newRecordDefaults for the create form — is derived in DocsHeadsViewer
 * from $scopedDocType.
 */
class AccountingDocsViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'cmnbkp';
}
