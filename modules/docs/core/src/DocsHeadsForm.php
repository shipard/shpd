<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Generický formulář nad `docs_core_heads` — slouží jako `defaultClass`
 * v polymorfní registraci pro doklady, pro něž není zaregistrovaná per-typ
 * subclass (např. záznam s prázdným / neznámým `doc_type`).
 *
 * Backuje generický viewer `docs.core.heads` („Doklady" napříč všemi typy).
 * Per-typ formuláře (`IssuedInvoiceForm`, `ReceivedInvoiceForm`) žijí v
 * příslušných modulech `docs.invoicesOut` / `docs.invoicesIn`.
 *
 * Nepřepisuje žádné metody — dědí default titulky („Doklad" / „Nový doklad")
 * a kompletní logiku z `DocsHeadsFormBase`.
 */
class DocsHeadsForm extends DocsHeadsFormBase
{
}
