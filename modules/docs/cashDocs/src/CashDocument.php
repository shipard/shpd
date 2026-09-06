<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashDocs;

use Shipard\Module\Docs\Core\CashDeskDocumentBase;

/**
 * Pokladní doklad (PD) — `doc_type = 'cash'`.
 *
 * Veškerá logika žije v CashDeskDocumentBase (docs.core): partner nepovinný,
 * úhrada jen hotově / kartou, měna pokladny, splatnost = vystavení. Směr
 * (`cash_dir` 1 příjem / 2 výdej) hlídá DocDocument::validateBindingAndDirection
 * podle `docTypes.cash.trade_dir_column`; povolené pohyby řádků podle směru
 * hlídá DocRowOperationRules (`cashDir`).
 */
class CashDocument extends CashDeskDocumentBase
{
}
