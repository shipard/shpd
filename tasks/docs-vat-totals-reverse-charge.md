# Oprava DPH okrajů dokladů — nulové součty, samovyměření, noPayTax řádky

**Stav:** naplánováno — opravy Z1–Z3 v `DocDocument` (nulové součty, samovyměření, noPayTax)

> **Status:** navrženo · **Modul:** docs.core, world.vat · **Typ:** oprava chyb
> **Návaznost:** `world-vat-cz.md` (DPH konfigurace), nálezy z dev DS
> aaaa-bbbb-cccc-dddd (DS A) a eeee-ffff-gggg-hhhh (DS B)

## Cíl

Opravit tři spolu související chyby výpočtu DPH a součtů v `DocDocument`,
které na importovaných datech způsobují nevyrovnaný nebo prázdný účetní
deník (alerty `economy.accounting.accounting_errors`: 1 830 na DS A,
409 na DS B) — a které **kazí i nově pořízené doklady** (doklady bez
DPH, tuzemská PDP, EU pořízení, osvobozená plnění na výstupu).

## Diagnóza (ověřeno na dev DS)

**Z1 — Doklady bez DPH mají nulové součty hlavičky.**
`DocDocument::sumTotals()` sčítá výhradně z DPH rekapitulace.
`buildVatRecapitulation()` ale přeskakuje řádky bez `vat_code` a při
nedohledané zemi z `vat_registration` vrací rovnou `[]`. Doklad z období
neplátcovství (řádky s `vat_code NULL`) tak má `total_* = 0` → účetní
deník nedostane stranu 321 (částka závazku jde z hlavičky) → deník jen
s MD stranou. Stejný kontrakt „hlavička jen z rekapitulace" má i
`applyDomesticAmounts()` (bod 2 v jeho docblocku) — `_dom` součty jsou
nulové ze stejného důvodu.
*Důkaz:* doklad A (DS A): řádek `acc.entry`
vat_base 9 490 / vat_code NULL, hlavička 0/0/0, deník jen MD 518001.
Rozsah: 918 dokladů DS A, 279 DS B.

**Z2 — Samovyměření (reverse charge) se tiše ztrácí.**
`buildVatRecapitulation()` generuje k samovyměřovacím kódům párový řádek
(`is_reverse_pair = 1`) přes `resolveVatPct(country, reverseVatCode, duzp)`.
Žádný z 19 reverse kódů (`cz-203` … `cz-493`, vše `hidden: 1`) ale nemá
záznam v `vatPercents` ve `vat-cz.jsonc` → `resolveVatPct()` vyhodí
`LogicException` → `catch { continue; }` pár **tiše zahodí**. Deník pak má
MD 343xxx (odpočet z primárního řádku), ale chybí DAL protistrana →
nevyrovnáno přesně o daň (poměr MD/DAL = 1,21 u 21 %).
Downstream je připraveno: `AccountingEngine` účtuje `is_reverse_pair`
na opačnou stranu kroku (řádek ~283) a `accountingRules.cz.jsonc` má
masky pro všechny reverse kódy (343203, 343204, …).
*Důkaz:* doklad B: recap jen primární cz-115
(tax 1 355,73, sum_tax 0), deník MD 511001 + MD 343115 / DAL 321001,
chybí DAL 343203. Rozsah: 447 dokladů DS A, 67 DS B.

**Z3 — `calculateRowVat()` ignoruje sémantiku `noPayTax`.**
Řádková DPH se počítá jen podle `vat_mode`, bez znalosti definice kódu.
U PDP/osvobozených kódů tak řádek dostane `vat_amount`/`vat_total`, jako
by šlo o běžnou daň — na vydané PDP faktuře (DS A fakturuje stavební
práce dle §92e běžně) pak řádek tvrdí `vat_total = základ × 1,21`,
zatímco hlavička i deník správně nesou jen základ. Lže tisk faktury,
řádková data i kontrolní sestavy.
*Důkaz:* doklad C: řádek cz-150 vat_amount 399 /
vat_total 2 299, hlavička správně 1 900; starý Shipard měl na řádku
tax 0,00 / priceTotal 1 900. U vstupní PDP (cz-115) starý Shipard nechává
tax spočtenou (nárok na odpočet), ale priceTotal = základ.
Rozsah: 649 invno DS A + řádky všech PDP invni.

## Scope

### 1. `modules/docs/core/src/DocDocument.php`

**a) Sdílené rozlišení DPH kódů (podklad pro Z2/Z3).**
V `beforeSave()` se země + `$vatCodes` aktuálně řeší až uvnitř
`buildVatRecapitulation()`. Vytáhnout do jedné privátní metody
(např. `resolveVatCodesForDoc(array $data): ?array` — vrací
`['country' => ..., 'codes' => [...]]` nebo `null`), zavolat před smyčkou
`calculateRowVat` a výsledek předat jak řádkovému výpočtu, tak rekapitulaci.
Chování při nedohledané zemi se nemění (řádky se počítají bez definic
kódů, rekapitulace je prázdná) — součty pak řeší Z1 fallback.

**b) Z3 — `calculateRowVat()` respektuje `noPayTax`.**
Nový parametr s definicemi kódů. Pravidla pro `row_kind = 1` s kódem,
jehož definice má `noPayTax`:

- `vat_base = total_price` **vždy** (i pro `vat_mode = 2` — cena bez daně
  je celá základ, zpětný rozpočet by byl chybný),
- `vat_total = vat_base` (daň nevstupuje do placené částky),
- `vat_amount`:
  - kód **se** `reverseVatCode` (vstupní samovyměření — PDP, EU pořízení):
    spočtená daň (informativní nárok na odpočet; zrcadlí starý Shipard,
    EUCZ115 → tax vyplněná),
  - kód **bez** `reverseVatCode` (výstupní PDP, osvobozená plnění): `0.0`
    (zrcadlí EUCZ150 → tax 0).

Kódy bez `noPayTax`, řádky bez kódu a případ bez definic (nedohledaná
země) beze změny.

**c) Z2 — párový řádek dědí sazbu z primární skupiny.**
V `buildVatRecapitulation()` nahradit
`resolveVatPct($countryCode, $reverseCodeKey, $duzp)` přímo
`$entry['vat_pct']` — samovyměření je z definice ve stejné sazbě jako
nárok na odpočet, resolver ani try/catch nejsou potřeba. Celý
`try { … } catch (\LogicException) { continue; }` blok odstranit.

**d) Z2 — neznámý kód nesmí tiše vypadnout.**
Větev `if (!isset($vatCodes[$code])) { continue; }` v rekapitulaci
nahradit `throw new \DomainException("Neznámý DPH kód '{$code}' …")` —
řádek s neexistujícím kódem je datová chyba, kterou musí uživatel opravit,
ne tichá ztráta skupiny ze součtů. (Stejný vzor jako DomainException
u vrácení čísla dokladu.) Migrace kódy mapuje přes `mapVatCode`, legitimní
data to nezasáhne.

**e) Z1 — součty zahrnou řádky mimo rekapitulaci.**
`sumTotals()`: po sečtení z rekapitulace projít `$rows` a každý řádek
`row_kind = 1`, jehož skupina (`vatGroupKey(vat_code, vat_pct)`) v recapu
není — tj. řádky bez kódu, nebo celá množina při prázdné rekapitulaci —
přičíst z řádkových hodnot: `base += vat_base`, `vat += vat_amount`,
`total += vat_total`. (Po Z3 mají bezkódové řádky amount 0 a
total = base, takže fallback je konzistentní.)
`applyDomesticAmounts()`: krok 2 (head z recap sum) rozšířit stejně —
`_dom` hodnoty řádků mimo recap přičíst k `total_base_dom`/`total_vat_dom`;
invariant `base + vat + rounding == amount` musí platit dál.
Pozor: `cmnbkp` má vlastní override součtů — nesmí se změnit jeho chování
(pokrýt testem, že se base-class fallback u zápočtů neaplikuje dvakrát).

### 2. `modules/world/vat/config/vat-cz.jsonc`

Doplnit `vatPercents` záznamy pro všech 19 reverse kódů zrcadlením časové
osy kódů, které na ně odkazují (`cz-203` = osa `cz-115`/`cz-117`:
2010–2011 → 20, 2012 → 20, 2013+ → 21; `cz-204` = osa `cz-116`;
`cz-370` = osa `cz-340`; obdobně cz-2xx/36x/4xx dle jejich primárních
protějšků). Po Z2c to výpočet nepotřebuje, ale konfigurace nemá mít
definované kódy bez sazeb — je to nášlapná mina pro jakékoli další použití
resolveru.

### 3. Testy

- `tests/Unit/Module/Docs/Core/DocDocumentRowCalcTest.php` — Z3:
  vstupní samovyměřovací kód (mode 1 i 2): base = total_price,
  amount spočtená, total = base; výstupní `noPayTax` kód: amount 0;
  běžný kód beze změny; bez definic kódů beze změny.
- `tests/Unit/Module/Docs/Core/DocDocumentTotalsTest.php` — Z1:
  doklad jen s bezkódovými řádky → totals z řádků (ne 0); smíšený doklad
  (kódové + bezkódové řádky) → recap + fallback; prázdná rekapitulace
  kvůli chybějící `vat_registration` → totals z řádků; `_dom` varianty
  a invariant zaokrouhlení.
- Rekapitulace (tamtéž nebo nový `DocDocumentVatRecapTest.php`) — Z2:
  samovyměřovací kód generuje pár s `vat_pct` primární skupiny a bez
  volání rate resolveru pro reverse kód; neznámý kód → DomainException.
- `tests/Unit/Module/Economy/Accounting/…` — end-to-end předpis invni
  s PDP: MD 5xx + MD 343115 / DAL 321 + DAL 343203, deník vyrovnaný.
- PHPUnit vždy s úzkým `--filter` (např.
  `--filter 'DocDocumentTotalsTest'`), široké filtry timeoutují.

### 4. Ověření na dev DS (read-only, provedu po nasazení)

Jednotlivý doklad z každé skupiny přepnout v UI 80 → 40 (přepočet +
přeúčtování) a zkontrolovat: 21310028 (totals + DAL 321),
22610152 (DAL 343203, deník vyrovnaný), 11310045 (řádek vat_amount 0,
tisková data). Plošnou opravu dat řeší re-import (viz Mimo scope).

## Mimo scope

- **Hromadné přeúčtování existujících dokladů** — dev DS se opraví
  re-importem po dokončení migračních oprav (banka, zálohy/majetek);
  pro alfu/produkci případný `docs-recompute` CLI příkaz = samostatný task.
- Migrace zálohových/majetkových řádků bez item (samostatná diskuse).
- Migrace bankovních transakcí se záporným credit (samostatný task
  ve starém Shipardu).

## Commit strategie

1. `docs: calculateRowVat respektuje noPayTax kódy` — 1a + 1b + testy.
2. `docs: DPH rekapitulace — pár dědí sazbu, neznámý kód je chyba` —
   1c + 1d + testy.
3. `docs: součty hlavičky zahrnují řádky mimo DPH rekapitulaci` —
   1e + testy.
4. `world.vat: sazby pro reverse kódy cz` — bod 2 + případný
   completeness test po vzoru `VatAnalyticsCompletenessTest`.

## Hotovo když

- [ ] Doklad jen s bezkódovými řádky má po uložení nenulové
      `total_base/total_amount` (= Σ řádků) a vyrovnaný deník s DAL 321.
- [ ] Doklad s `cz-115` řádkem má v rekapitulaci pár `cz-203`
      (`is_reverse_pair = 1`, stejná sazba) a vyrovnaný deník
      s DAL 343203.
- [ ] Řádek s `noPayTax` kódem má `vat_total = vat_base`; výstupní kódy
      mají `vat_amount = 0`, vstupní samovyměřovací spočtenou.
- [ ] Neznámý `vat_code` při uložení vyhodí DomainException (neztratí se
      ze součtů).
- [ ] Všechny reverse kódy mají sazby ve `vat-cz.jsonc`.
- [ ] `cmnbkp` zápočty beze změny chování (test).
- [ ] Nové i stávající unit testy zelené (úzké filtry),
      `npm run check:i18n` netřeba (bez frontend změn).
