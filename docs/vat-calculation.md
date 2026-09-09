# Jak se počítá DPH na dokladech

Design dokument (spec) pro `docs.core`. Popisuje pravidla, ne aktuální stav kódu —
kde se kód liší, platí tento dokument a rozdíl je chyba (#75). Implementace:
`DocDocument::beforeSave` → `calculateRowPrice` / `calculateRowVat` →
`buildVatRecapitulation` → `sumTotals` / `applyTotalRounding` → `applyDomesticAmounts`.

## 1. Tři úrovně, jedna autorita

| úroveň | tabulka | role |
|---|---|---|
| řádek | `docs_core_rows` | cena, množství, sazba, DPH kód; `vat_base` / `vat_amount` / `vat_total` jsou **informativní** (tisk řádku) |
| rekapitulace | `docs_core_vat_recap` | **autoritativní** DPH dokladu per (kód, sazba): `base`, `tax`, `total` + `_dom` |
| hlavička | `docs_core_heads` | součty z rekapitulace (`total_base`, `total_vat`, `total_amount`, `total_rounding`) |

Z rekapitulace čte účtování (343), saldo, DPH výstupy (DP3/KH/SH) a podání.
Řádkové hodnoty DPH do žádného výkazu nevstupují.

## 2. Režim cen — `vat_mode`

Vlastnost dokladu (`docs.core.vatModes`), určuje, co znamená cena na řádku:

- `0 Bez DPH` — nedaňový doklad; základ = cena, daň 0.
- `1 Ze základu` — ceny na řádcích jsou bez DPH.
- `2 Z ceny celkem` — ceny na řádcích jsou s DPH.

## 3. Metoda výpočtu — `vat_calc_source`

Platí jen pro **přepočítanou** rekapitulaci (§ 5). Default vždy `0`.

**`0 Z hlavičky`** (norma, § 37 odst. 1 ZDPH): řádky se seskupí per (DPH kód, sazba),
sečtou se **řádkové ceny** a daň se počítá jednou, ze součtu:

| `vat_mode` | výpočet skupiny |
|---|---|
| 1 ze základu | `base = Σ cena`; `tax = round(base × pct / 100)`; `total = base + tax` |
| 2 z ceny celkem | `total = Σ cena`; `base = round(total / (1 + pct / 100))`; `tax = total − base` |

Výsledek: celková částka dokladu je vždy přesně Σ řádkových cen; daň je spočtená
jednou, z celého plnění v dané sazbě. Řádkové `vat_base`/`vat_amount` se
z rekapitulace odchylují o haléře — to je v pořádku, jsou informativní.

Příklad (`mode 2`, 2 řádky à 55,00 s DPH 21 %): `total = 110,00`,
`base = round(110 / 1,21) = 90,91`, `tax = 19,09`. Součet řádkových rozpočtů by dal
2 × (45,45 + 9,55) = 90,90 + 19,10 — jiné rozdělení, stejná částka.

**`1 Z řádků`**: rekapitulace = součet **řádkových** `vat_base`/`vat_amount`/`vat_total`.
Historický režim (řádek = samostatná účtenka v jednom pokladním lístku). Ponechán
pro doklady, kde protistrana počítala po řádcích a my doklad počítáme sami; u
vydaných faktur a prodejek nedává smysl a časem se z nich odstraní.

`round()` = `vat_rounding_mode` dokladu (`docs.core.roundingModes`, default na
haléře), aplikuje se na dokladové úrovni: mode 1 na daň, mode 2 na základ.

Koeficientová metoda (do 2019, `k = round(pct / (100 + pct), 4)`) se **neimplementuje**;
historické doklady kryje převzatá rekapitulace.

## 4. Speciální kódy

- **Reverse charge (samovyměření):** primární řádek nese spočtenou daň jako nárok na
  odpočet (DP3 ř. 43/44), párový řádek `is_reverse_pair` oddanění; do `total`
  vstupuje jen daň placená dodavateli (`noPayTax` → jen základ).
- **`noPayTax` / 0 % / osvobozené:** daň 0 nebo informativní, `total = base`.
- **`sum_base` / `sum_tax` / `sum_total`** z definice kódu říkají, co z řádku
  rekapitulace vstupuje do součtů hlavičky.

## 5. Autorita rekapitulace — `vat_recap_source`

Vlastnost **celého dokladu**:

- `0 Přepočítaná` — rekapitulace vzniká z řádků podle § 2–4 při každém uložení.
  Doklady, které vystavuje Nový Shipard (FVB, prodejky, pokladní doklady, opravné
  doklady).
- `1 Převzatá` — rekapitulace je **vstup a fakt**: to, co je na existujícím dokladu.
  `beforeSave` ji nepřepočítá; spočítá z ní `_dom`, součty hlavičky a `sum_*`.
  Použití: import ze starého Shipardu, přijaté doklady (rekapitulace dodavatele je
  závazná i když je haléřově „špatně" — nárok na odpočet je částka z faktury),
  ruční oprava účetní.

Výchozí hodnota podle původu: vystavený doklad → přepočítaná; import → převzatá
(exchange `vat.recapSource: "declared"`, `vatRecap` z dat zdroje); přijatý doklad
z AI extrakce → převzatá, když `vatRecap` je vnitřně konzistentní (`base + tax =
total`); jinak přepočítaná.

Editace: při `převzatá` je rekapitulace editovatelná. Změna částek řádků ji
**nepřepíše** — uložení vydá warning `rows_recap_mismatch`. Oprava: upravit
rekapitulaci, nebo přepnout na `přepočítaná` (přegeneruje se z řádků) a případně
zpět. Změny textů a jiných neobnosových polí rekapitulaci nedotknou.

Kontroly (warning, neblokují): `vat_recap_inconsistent` (`tax ≠ base × pct`
v toleranci `max(0,05; |base| × 0,001)`, `base + tax ≠ total` ± 0,02) — u převzaté
zviditelní nesrovnalost dodavatele; `rows_recap_mismatch` (Σ řádků ≠ rekapitulace
dle režimu) — signál neúplných nebo špatně zadaných řádků.

## 6. Zaokrouhlení celkové částky — `total_rounding_mode`

Až po rekapitulaci: `total_amount` se zaokrouhlí (na celé jednotky, nahoru, dolů,
na haléře), rozdíl jde do `total_rounding`; rekapitulace zůstává. Hotovostní úhrada
faktury na celé Kč = #73.

## 7. Domácí měna

`applyDomesticAmounts`: `base_dom`/`tax_dom` rekapitulace = `round(cur × kurz)`;
hlavička se sčítá z rekapitulace; řádky se **dorovnávají** na rekapitulaci
(top-down), `total_rounding_dom` absorbuje haléřový zbytek. Platí i pro převzatou
rekapitulaci. Invarianty viz docblock metody.

## 8. Co se tiskne

Rekapitulace (základ / daň / celkem per sazba) a součty hlavičky. Řádkové DPH
hodnoty jsou volitelný komfort tiskové šablony, nikdy zdroj pro součet. Otevřené:
u dokladů v cenách s DPH řádkové DPH raději netisknout.

## 9. Odkazy

`docs/docs-mvp.md` §7–8 (historie návrhu; kde se liší, platí tento dokument),
`docs/exchange-format.md` (`vat`, `vatRecap`, `totals`), `docs/accounting.md`
(343 z rekapitulace), `modules/economy/vat/docs/README.md` (výstupy z recapu),
issue #75, zákon č. 235/2004 Sb. § 37.
