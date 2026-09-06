<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashRegister;

use Shipard\Module\Docs\Core\CashDeskDocumentBase;

/**
 * Prodejka (PRO) — `doc_type = 'cashreg'`.
 *
 * Logika žije v CashDeskDocumentBase (docs.core). Typ má pevný směr výstup
 * (`docTypes.cashreg.trade_dir = 1`), takže `cash_dir` musí zůstat 0 — hlídá
 * DocDocument::validateBindingAndDirection. Vratka = záporné řádky, žádná
 * validace je neodmítá (stejně jako u dobropisů).
 */
class CashRegisterDocument extends CashDeskDocumentBase
{
}
