# Task: Řádek dokladu — živý výpočet ceny, základu, DPH a celkem (Issue #71)

**Stav:** naplánováno

## Status / cíl

V modalu řádku dokladu jsou dole tři readOnly pole *Základ DPH (vypočteno)*,
*Částka DPH (vypočteno)* a *Celkem (vypočteno)*. U nového řádku jsou prázdná,
u existujícího ukazují hodnoty z posledního uložení — počítá je až
`DocDocument::calculateRowPrice` / `calculateRowVat` při přepočtu hlavičky
(`DocRowsDocument::recomputeHeader`). Uživatel před přidáním řádku nevidí,
kolik řádek dělá s DPH, a po úpravě vidí staré hodnoty, dokud neuloží.
Navíc při `price_calc_mode = 0` nikdo nepočítá `total_price` — pole Cena
celkem zůstává prázdné a do DB jde prázdné/zastaralé.

Cíl: **při každé změně vstupu se cena, základ, DPH a celkem přepočítají
hned v modalu stejným kódem jako při uložení** a součty jsou vidět bez
scrollování v živém pruhu nad obsahem formuláře. Hlavička modalu
(`header_info`) zůstává „uložený stav" — nemění se.

GitHub Issue: shipard/shpd#71. Navazuje na #60 (tam vědomě odloženo)
a #24 B (hotovo, `cd04f84e` — `NumberInput` propaguje `onchange`, takže
`triggers: 'reload'` na číselných polích funguje).

## Potvrzená designová rozhodnutí (Anna, 2026-09-08)

1. **Výpočet se vytáhne do sdílené čisté třídy `DocRowCalculator`**;
   `DocDocument::calculateRowPrice/Vat` se stanou tenkými obálkami se
   stejným chováním. Formulář i save počítají jedním kódem.
2. **Zobrazení = varianta (b) z issue: samostatný živý pruh** nad taby
   formuláře, oddělený od `header_info`. Technicky: `FormDefinition`
   dostane volitelné `live_summary` (seznam `{label, value}`), které
   `DocRowsForm::buildFormDefinition` sestaví z `$data`. Protože
   `buildFormDefinition` běží při loadu i při každém recalculate, je pruh
   živý automaticky; princip „hlavička = uložený stav" se nemění.
3. **Tři readOnly pole `vat_base`/`vat_amount`/`vat_total` se z formuláře
   odeberou** (duplicita s pruhem). Sloupce v DB zůstávají, hodnoty
   se dál počítají a ukládají.
4. **Způsob výpočtu řídí, které cenové pole je read-only:**
   - `price_calc_mode = 0` (z ceny za jednotku): **Cena celkem** je
     read-only, počítá se `quantity × unit_price`; uživatel edituje
     Množství a Cena/jednotka.
   - `price_calc_mode = 1` (z celkové ceny): **Cena/jednotka** je
     read-only, počítá se `total_price / quantity`; uživatel edituje
     Množství a Cena celkem.
   - `price_calc_mode` má `triggers: 'reload'` — přepnutí přestaví
     formulář (prohodí read-only) a dopočte z aktuálních hodnot.
5. **Sleva se do cenových polí nepromítá.** `unit_price` i `total_price`
   ve formuláři a v DB jsou ceny **před slevou**; sleva se uplatní až do
   `vat_base` (pruh). Důvod: past P1 níže — jinak by save slevu odečetl
   podruhé. Věcně: sleva je samostatná informace na řádku, ne přepis ceny.
6. **Jeden task file, dvě fáze** — fáze 1 (výpočet, read-only pole,
   triggery) je použitelná sama; fáze 2 (pruh, odebrání polí) navazuje.
   Po fázi 1 commit.

## Před implementací přečti

- `modules/docs/core/src/DocDocument.php` ř. 243–320 (`beforeSave` —
  kdy a v jakém pořadí se volá `calculateRowPrice`/`calculateRowVat`,
  co je `$vatMode`, `$vatCodes`), ř. 581–662 (obě metody — **toto je kód,
  který se přesouvá**), ř. 425–449 (`persistRowComputedColumns` — do DB
  se zapisují **jen `vat_*`**, nikdy `total_price`/`unit_price`),
  ř. 826–847 (`resolveVatCodesForDoc` — přesné volání
  `getVatCodes($country, direction: null, place: null, includeHidden: true)`,
  které musí formulář zopakovat).
- `modules/docs/core/src/DocRowsForm.php` — `buildFormDefinition` ř. 22–147
  (položkový layout; komentář ř. 98–100 o odstraněných triggerech),
  `buildContationDefinition` ř. 167–223 (kontační layout — **nedotýkat se**,
  `price_calc_mode` je tam skryté a fixní 1), `recalculate` ř. 380–431,
  `loadHeadContext` ř. 445–496 (co už z hlavičky máme: `vat_mode`,
  `country`, `vat_duzp`; chybí `doc_currency`).
- `modules/docs/core/src/DocRowsDocument.php` ř. 105–180 —
  `recomputeHeader` po uložení řádku; potvrzuje, že save-cesta počítá
  z DB a `total_price` nepřepisuje.
- `src/Core/Form/FormDefinition.php` (celý, 53 ř.) — kam přidat
  `live_summary`; vzor `withHeaderInfo`.
- `src/Core/Form/FormHeaderInfo.php` — tvar položek `{label, value}`,
  který pruh převezme.
- `src/Core/Form/TabBuilder.php` ř. 216–226 — `number()` má `readOnly`
  i `triggers`.
- `frontend/src/components/form/FormElement.svelte` ř. 26 —
  `elDisabled = disabled || element.read_only === true` (read-only číselné
  pole se renderuje jako disabled, tj. šedé — přesně to, co D4 chce);
  ř. 109 — `NumberInput` s `onchange={handleChange}`.
- `frontend/src/components/form/FormEditor.svelte` ř. 199–228
  (`handleTrigger` — `formData` se po recalculate nahradí celý z response,
  takže hodnoty vrácené serverem dorazí i do read-only polí), ř. 542–600
  (šablona: tab-bar → validační banner → content; pruh patří mezi tab-bar
  a banner), CSS `.shpd-form-editor__tab-bar` ř. 648.
- `modules/docs/core/src/DocsHeadsFormBase.php` ř. 340–358
  (`buildHeaderSummary` — vzor labelů „Bez DPH / DPH / Celkem CZK"),
  ř. 1358 (`formatMoney`: `number_format(…, 2, ',', ' ')`).
- `modules/docs/core/config/priceCalcModes.jsonc` — 0 = z ceny za jednotku,
  1 = z celkové ceny.
- `modules/docs/core/tables/docs_core_rows.jsonc` ř. 94–131 — `quantity`
  scale 4, `unit_price` scale 4, `total_price` scale 2.
- Testy: `tests/Unit/Module/Docs/Core/DocDocumentRowCalcTest.php`
  (chování, které refaktor nesmí změnit), `DocRowsFormTest.php`
  (`testCalculatedVatColumnsAreReadOnly` ř. 100 — po fázi 2 se změní),
  `DocDocumentTotalsTest.php`, `DocDocumentVatRecapTest.php`,
  `DocDocumentDomesticAmountsTest.php`.
- `docs/edit-forms.md` ř. 486–500 (recalculate), ř. 628 (header),
  ř. 683 (recalculate a dirty), ř. 1479 (`header_info` u nového záznamu) —
  doplnit `live_summary`.

## Rozsah

### Fáze 1 — výpočet

#### 1.1 `modules/docs/core/src/DocRowCalculator.php` (nový)

`final class DocRowCalculator` bez stavu, bez DB, jen statické metody:

- `computePrice(array $row): array` — vrací
  `['unit_price' => ?float, 'total_price' => ?float, 'net_total' => float]`:
  - `row_kind !== 1` → vše `null` / `0.0`.
  - mode 0: `total_price = round(qty × unit_price, 2)`, `unit_price`
    beze změny.
  - mode 1: `unit_price = qty > 0 ? round(total / qty, 4) : 0.0`,
    `total_price` beze změny.
  - `net_total` = `total_price` po slevě (`discount_pct` má přednost před
    `discount_amount`, stejně jako dnes; zaokrouhlení na 2 zachovat
    včetně dvoustupňového `round` u procent).
- `computeVat(float $netTotal, array $row, int $vatMode, ?array $vatCodes): array`
  — vrací `['vat_base', 'vat_amount', 'vat_total']`; logika 1:1 z dnešního
  `calculateRowVat` (vatMode 0 / bez kódu / bez pct → základ = celkem,
  daň 0; `noPayTax` + `reverseVatCode`; mode 1 zezdola, mode 2 shora).
  Pro `row_kind !== 1` vrací trojici `null`.
- Doc komentář: proč `total_price` vrácené z `computePrice` je **před
  slevou** a `net_total` po slevě (P1).

#### 1.2 `DocDocument::calculateRowPrice` / `calculateRowVat` → obálky

- `calculateRowPrice(array &$row)`: `$p = DocRowCalculator::computePrice($row)`;
  zapíše `unit_price`, `total_price` **a `total_price = net_total`** —
  tj. zachová dnešní dočasnou mutaci (po slevě), na které stojí
  `calculateRowVat`, `buildVatRecapitulation` i `sumTotals`. Chování save
  cesty se **nemění o jediný halíř**.
- `calculateRowVat(array &$row, int $vatMode, ?array $vatCodes)`:
  `computeVat((float) $row['total_price'], $row, $vatMode, $vatCodes)`
  a zápis trojice.
- Existující testy `DocDocument*Test` musí projít beze změny.

#### 1.3 `DocRowsForm` — formulář

- `loadHeadContext`: přidat `doc_currency` do SELECTu i do návratového
  pole (pro label „Celkem CZK" v pruhu ve fázi 2; ve fázi 1 zatím jen
  připravit).
- Nová privátní metoda `resolveVatCodesForRow(?array $headContext): ?array`
  — `VatRateResolver::getVatCodes($country, direction: null, place: null,
  includeHidden: true)`, `null` bez země/configu nebo při `LogicException`.
  **Stejné parametry jako `DocDocument::resolveVatCodesForDoc`** — jiná
  volba (`includeHidden: false`, filtr směru) by změnila sémantiku
  `noPayTax` kódů oproti save.
- Nová privátní metoda `applyLiveCalculation(array &$data, ?array $headContext): void`:
  - jen `row_kind === 1` a jen položkový layout (`!hasRowSideLayout`) —
    kontační řádek má `price_calc_mode = 1`, `total_price` = částka
    zadaná ručně, žádné DPH; nesahat.
  - `$p = computePrice($data)`; zapsat `unit_price` a `total_price`
    (**před slevou**, tj. ne `net_total`).
  - `$vatMode = (int) ($headContext['vat_mode'] ?? 0)` — bez kontextu
    hlavičky se počítá jako bez DPH (základ = celkem), stejně degradovaně
    jako save bez země.
  - `computeVat($p['net_total'], $data, $vatMode, $vatCodes)` → zapsat
    `vat_base`, `vat_amount`, `vat_total`.
- `recalculate`: po stávajících větvích (`item` doplní `unit_price`,
  `vat_code` doplní `vat_pct`, `operation`/`row_kind` kontační default)
  zavolat `applyLiveCalculation($data, $headContext)` **vždy** — ne per
  sloupec. Přepočet je idempotentní a levný; větvení podle sloupce by
  přineslo jen chyby z opomenutí.
- `buildFormDefinition` (položkový layout):
  - `$calcMode = (int) ($data['price_calc_mode'] ?? 0)`.
  - `->number('quantity', hidden: $isText, triggers: 'reload')`
  - `->number('unit_price', hidden: $isText, readOnly: $calcMode === 1, triggers: 'reload')`
  - `->number('total_price', hidden: $isText, readOnly: $calcMode === 0, triggers: 'reload')`
  - `->select('price_calc_mode', …, triggers: 'reload')`
  - `->number('discount_pct', …, triggers: 'reload')`,
    `->number('discount_amount', …, triggers: 'reload')`
  - `->number('vat_pct', …, triggers: 'reload')`
  - Smazat komentář ř. 98–100 („Bez triggers…") — už neplatí.
  - Tři readOnly `vat_*` pole ve fázi 1 **zůstávají** (živě se
    aktualizují); odeberou se ve fázi 2.
- `applyNewRecordDefaults`: na konci (po `vat_code`/`vat_pct` defaultu)
  zavolat `applyLiveCalculation` — nový řádek s prefillem pak má
  konzistentní `vat_*` od prvního zobrazení (typicky nuly).

#### 1.4 Testy fáze 1

- `tests/Unit/Module/Docs/Core/DocRowCalculatorTest.php` (nový):
  mode 0 (qty × unit, zaokrouhlení na 2), mode 1 (total / qty na 4;
  qty 0 → unit 0), sleva % vs. částka (přednost %, `total_price` v návratu
  **před** slevou, `net_total` po), `row_kind` 0 → null, vat mode 0/1/2,
  bez kódu, bez pct, `noPayTax` s/bez `reverseVatCode`.
- `DocDocumentRowCalcTest` a ostatní `DocDocument*Test` **beze změny
  a zelené** — to je důkaz, že obálky zachovaly chování.
- `DocRowsFormTest`: nové testy — (a) recalculate('quantity') s hlavičkou
  `vat_mode 1`, `vat_pct 21`, mode 0: `total_price`, `vat_base`,
  `vat_amount`, `vat_total` v návratových datech; (b) recalculate se
  slevou: `total_price` před slevou, `vat_base` po; (c) mode 0 → `total_price`
  má `read_only: true` a `unit_price` ne; mode 1 opačně; (d) kontační
  layout (rowSide) — `total_price` bez `read_only`, `vat_*` nedotčené;
  (e) bez `doc_head` — recalculate neshodí, `vat_amount = 0`.
  Vzor mockování hlavičky vzít z existujících testů souboru
  (`testWithoutHeadContextVatFieldsAreHidden`, `DocRowsFormOperationsTest`).
- `php -l` na dotčených souborech,
  `vendor/bin/phpunit --filter 'DocRowCalculator|DocRowsForm|DocDocument'`.

**→ Commit fáze 1** (`feat(docs): živý výpočet ceny a DPH na řádku dokladu, sdílený DocRowCalculator (#71 F1)`).

### Fáze 2 — živý pruh součtů

#### 2.1 `FormDefinition` — `live_summary`

- Konstruktor: `public array $liveSummary = []` (list `{label, value}`),
  pojmenovaný parametr za `headerInfo`; `toArray()` přidá
  `'live_summary' => $this->liveSummary` **jen když neprázdné** (jako
  `doc_states`), ať se nemění výstup ostatních formulářů a
  `FormDefinitionTest`.
- Doc komentář: „živé součty nad obsahem formuláře; na rozdíl od
  `header_info` se sestavují z aktuálních `$data` při každém
  buildFormDefinition (load i recalculate), takže odrážejí neuložený stav".

#### 2.2 `DocRowsForm` — sestavení pruhu

- Nová privátní `buildLiveSummary(array $data, ?array $headContext): array`
  (jen položkový layout, `row_kind === 1`): položky
  `Základ` / `DPH` / `Celkem <měna>` z `vat_base`/`vat_amount`/`vat_total`,
  formát `number_format(…, 2, ',', ' ')` — buď vytáhnout `formatMoney`
  z `DocsHeadsFormBase` do sdíleného helperu, nebo malou lokální kopii
  (jedna řádka); nezavádět novou závislost formuláře řádku na formuláři
  hlavičky. Bez DPH na hlavičce (`vat_mode 0`) jen `Celkem`. Prázdné pole
  → prázdný pruh (`[]`, klient nic nerenderuje).
- Předat do `new FormDefinition(…, liveSummary: …)`.
- **Odebrat** `->number('vat_base'/'vat_amount'/'vat_total', readOnly…)`
  z položkového layoutu. Hodnoty dál žijí v `$data` (recalculate je
  vrací, `formData` je nese, save je pošle) — ověřit, že
  `sanitizeFormData` ve `FormEditor` neořezává klíče mimo definici; pokud
  ano, `vat_*` se prostě neuloží z formuláře, což je v pořádku —
  autoritativně je zapisuje `persistRowComputedColumns` po
  `recomputeHeader`.
- `DocRowsFormTest::testCalculatedVatColumnsAreReadOnly` → přepsat na
  „`vat_*` nejsou ve formuláři, `live_summary` má 3 položky při
  `vat_mode 1` / 1 položku při `vat_mode 0`".

#### 2.3 `FormEditor.svelte` — render pruhu

- Mezi tab-bar a validační banner:
  ```svelte
  {#if formDef?.live_summary?.length}
    <div class="shpd-form-editor__live-summary" data-testid="form-live-summary">
      {#each formDef.live_summary as item (item.label)}
        <span class="shpd-form-editor__live-summary-label">{item.label}</span>
        <span class="shpd-form-editor__live-summary-value">{item.value}</span>
      {/each}
    </div>
  {/if}
  ```
- Nic dalšího ve skriptu: `formDef` se po recalculate nahrazuje celý
  (`handleTrigger`), pruh se překreslí sám. `savedHeaderInfo` nesahat.
- CSS: jednořádkový pruh, zarovnaný vpravo (jako `summary` v hlavičce),
  `border-bottom` jako tab-bar, labely tlumené (`--shpd-color-text-muted`
  nebo co používá `Modal` pro summary), hodnoty tabulární číslice
  (`font-variant-numeric: tabular-nums`). Během `recalculating` lehce
  ztlumit (`opacity`), aby bylo vidět, že hodnoty jsou v přepočtu.
- `cd frontend && timeout 90 npm run build 2>&1 | tail -4`.

#### 2.4 Dokumentace

- `docs/edit-forms.md`: k `header_info` (ř. ~628 a ~1479) doplnit
  odstavec o `live_summary` — rozdíl uložený vs. živý stav, kdy se
  sestavuje, kdo ho plní (`DocRowsForm`), že je volitelné a generické.
  Do sekce recalculate (ř. ~486) větu, že `live_summary` je součást
  vrácené FormDefinition.
- `help/` — pokud existuje stránka o řádcích dokladu / vystavení faktury
  popisující, že součty řádku jsou vidět až po uložení, upravit
  (`grep -rn "vypočteno\|po uložení" help/`).

**→ Commit fáze 2** (`feat(docs): živý pruh Základ · DPH · Celkem v modalu řádku dokladu (#71 F2)`).

### Mimo rozsah

- Živé součty na **hlavičce dokladu** (`summary` v `header_info` zůstává
  uložený stav) — `live_summary` je na to připravené, ale není to cíl #71.
- Změna zaokrouhlení, `_dom` částek, rekapitulace — save cesta se nemění.
- `DateInput`/`NumberInput` chování `onchange` (fires on blur = jeden
  roundtrip na opuštění pole) — přijatelné, neladit debounce.
- Význam roletek Zaokrouhlení (#63).
- Kontační layout účetního dokladu.

## Pasti

- **P1 — dvojité odečtení slevy.** `calculateRowPrice` dnes zapisuje
  `total_price` **po slevě**, ale jen dočasně pro výpočet; do DB jde
  z řádku jen `vat_*` (`persistRowComputedColumns`). Kdyby živý přepočet
  vrátil do pole `total_price` hodnotu po slevě, uloží se zlevněná a při
  příštím `recomputeHeader` se sleva odečte znovu. Proto `computePrice`
  vrací `total_price` před slevou a `net_total` po ní, a **formulář
  zapisuje jen to první**. Test (b) ve fázi 1 to hlídá.
- **Obálky v `DocDocument` musí zachovat dočasnou mutaci** `total_price
  = net_total` — na ní stojí `calculateRowVat` (dostává `$row`,
  ne `net_total`), `buildVatRecapitulation` (ř. 711 fallback na
  `total_price`) i `sumTotals`. Kdo tuhle mutaci „opraví", rozbije součty
  a rekapitulaci; hlídají to `DocDocument*Test`.
- **`getVatCodes` musí být voláno stejně jako v `resolveVatCodesForDoc`**
  (bez směru a místa, `includeHidden: true`). `buildVatCodeOptions`
  ve stejném souboru volá resolver s filtrem — nepoužívat pro výpočet.
- **Kontační layout** (`hasRowSideLayout`) — `total_price` je tam ručně
  zadaná částka, `price_calc_mode` skryté a fixní 1. `applyLiveCalculation`
  ho musí přeskočit, jinak by `computePrice` v módu 1 přepsal `unit_price`
  a `computeVat` zapsal `vat_*` do řádku bez DPH bloku.
- **Data z klienta jsou stringy** (`"21"`, `"1500.5"`, `""`). Casty
  `(float)`/`(int)` jako v dnešním kódu; `empty("0")` je `true` — chování
  `empty($row['vat_pct'])` (0 % → bez daně) záměrně zachovat.
- **`readOnly` pole a save.** Read-only `NumberInput` je `disabled`; ověřit,
  že `sanitizeFormData` posílá i disabled pole (pravděpodobně ano — čte
  `formData`, ne DOM). Kdyby ne, mode 0 by neuložil `total_price` —
  test E2E bod 4.
- **Přepnutí `price_calc_mode` z 0 na 1** — do té chvíle read-only
  `total_price` má hodnotu `qty × unit`; po přepnutí je editovatelná
  a `unit_price` se dopočte z ní. Přepnutí 1 → 0: `unit_price`
  (dopočtený, 4 des.) se stane vstupem a `total_price` se přepočte —
  může se lišit o zaokrouhlení (např. 1000 / 3 → 333.3333 × 3 = 999.9999
  → 1000.00 OK, ale 100 / 7 → 14.2857 × 7 = 99.9999 → 100.00 OK;
  100 / 3 → 33.3333 × 3 = 99.9999 → 100.00 OK). Není to chyba, je to
  vlastnost módu; do help nepsat, ale vědět o tom při E2E.
- **`live_summary` v `toArray()` jen když neprázdné** — jinak spadne
  `FormDefinitionTest` a změní se snapshoty JSONC formulářů.
- **`patch_file` a diakritika** — PHP komentáře, testy, `docs/edit-forms.md`,
  `help/` a tento soubor obsahují češtinu; editovat přes Python
  `io.open(..., encoding='utf-8')` s `assert s.count(old) == 1`.
- **Frontend má vlastní git root** (`frontend/`) — změny ve
  `FormEditor.svelte` commitovat z `frontend/`, PHP z rootu.
- **`triggers: 'reload'` na `quantity`/`unit_price`/`total_price` byly
  vědomě odstraněny** v `48b57574` (prázdný roundtrip). Vrací se **jen**
  spolu s `applyLiveCalculation` — ne dřív.

## Hotovo když

1. `vendor/bin/phpunit --filter 'DocRowCalculator|DocRowsForm|DocDocument'`
   zelené; `DocDocument*Test` beze změny obsahu.
2. `cd frontend && timeout 90 npm run build` bez chyb.
3. E2E na dev DS, **nová faktura vydaná** s DPH, nový řádek, položka
   s cenou: po výběru položky se Cena/jednotka doplní, Cena celkem je
   šedá a rovná `1 × cena`; pruh nad formulářem ukazuje Základ · DPH ·
   Celkem CZK odpovídající sazbě; změna Množství na 3 → vše se po opuštění
   pole přepočte; zadání slevy 10 % → Cena celkem se **nezmění**, Základ
   klesne o 10 %.
4. Přepnutí Způsobu výpočtu na „Z celkové ceny" → Cena celkem editovatelná,
   Cena/jednotka šedá a dopočtená; uložit řádek → v sub-tabulce a po
   znovuotevření řádku jsou `unit_price`/`total_price` uložené tak, jak
   byly v polích (před slevou), `vat_*` shodné s tím, co ukazoval pruh.
5. Uložit doklad → součty hlavičky (`Bez DPH / DPH / Celkem`) odpovídají
   součtu pruhů řádků (v rámci zaokrouhlení rekapitulace).
6. Nový řádek na dokladu **bez DPH** (`vat_mode 0`): pruh ukazuje jen
   Celkem; DPH blok skrytý; žádná chyba.
7. Účetní doklad, kontační řádek: modal beze změny — Částka editovatelná,
   žádný pruh.
8. `python3 scripts/tasks-index.py --check` a `python3 scripts/check-sensitive.py`
   projdou.
