# TODO — poznámky k budoucímu řešení

## AI extrakce: špatný `vat.mode` u faktur s cenami včetně DPH

**Zjištěno:** 07/2026 při diagnostice zaokrouhlování
(`tasks/mail-invoice-rounding.md`) na reálné faktuře z testovacího
prostředí.

**Problém:** U faktur, kde jsou položkové řádky uvedené v cenách
**s DPH** (koncové/maloobchodní ceny — typicky občerstvení, drobný
prodej), AI vrací `vat.mode: "fromBase"` a `rows[].totalPrice` s cenou
včetně daně. Applier mapuje `fromBase` → `vat_mode = 1`, takže
`DocDocument` při apply počítá DPH **zdola nad cenou, která už daň
obsahuje** → DPH je na dokladu dvakrát a celková částka je věcně
špatně (o celou sazbu vyšší než na faktuře).

**Jak vzor poznat na datech:** Σ `rows[].totalPrice` ≈
Σ `vatRecap[].total` (základ + daň), nikoli ≈ `totals.totalBase`;
jednotkové ceny řádků odpovídají koncovým cenám z faktury.

**Směr řešení (k rozmyšlení, až se to bude dělat):**

1. **Prompt** — pravidlo pro rozpoznání faktury s cenami s DPH: pokud
   součet řádků odpovídá částce s daní / faktura uvádí jednotkové
   ceny s DPH, vrátit `vat.mode: "fromTotal"` (počítá se „shora“).
   Ověřit, že `VAT_MODE_MAP` v `DocumentApplier` a větev fromTotal
   v `DocDocument` fungují end-to-end.
2. **Deterministický sanity check** (validator nebo applier) —
   nezávisle na promptu: když Σ řádků sedí na recap total (a ne na
   totalBase) a mode je `fromBase`, minimálně warning do
   `_resolve.issues` („řádky vypadají jako ceny s DPH, zkontroluj
   režim výpočtu“), případně automatická korekce modu.
3. Otestovat proti reálným případům na testovacím prostředí
   (najde se přes vzor v bodu „jak poznat na datech“).

**Priorita:** střední — u dodavatelů s koncovými cenami to bude bít
opakovaně a výsledný doklad je věcně špatně (ne jen kosmetický
warning).
