# Účtování dokladů — Fáze 3: UI

## Kontext

Fáze 2 je hotová: deník se generuje při přechodu dokladu do stavu 40,
chyby končí v `accounting_state`/`accounting_messages`, existuje endpoint
`POST /_accounting/reaccount` a alert check. Fáze 3 to zviditelňuje:
sekce Zaúčtování v detailu dokladu, akce Přeúčtovat a viewer účetního
deníku.

Tři pracovní balíčky:

- **W1** — tab Zaúčtování v detailu dokladu
- **W2** — akce Přeúčtovat v detailu dokladu
- **W3** — viewer účetního deníku

## Návaznost

- `docs/accounting.md` sekce 9 (Zobrazení) — záměr; tento task upřesňuje
  podle reálných schopností viewer frameworku.
- Vyžaduje hotové Fáze 1 + 2 (`tasks/accounting-phase1.md`,
  `tasks/accounting-phase2.md`) — splněno.
- Reporty (obratová předvaha, hlavní kniha) jsou samostatný budoucí úkol
  — nejsou součástí této fáze.

## Před implementací přečti

- `modules/docs/core/src/DocsHeadsViewer.php` — metoda `detail` (stavba
  tabs + content), `detailAttachmentGroups` (vzor podmíněného tabu/sekce)
- `modules/core/alerts/src/AlertsViewer.php` — `buildDetailActions` +
  obsluha akcí (server side) — **vzor pro W2**; více tabů v detailu
- `frontend/src/components/viewer/ViewerDetail.svelte` — podporované
  content typy (`properties`, `table`, `html`, `composite`, `document`,
  `heading`), rendering `actions` (confirm, dropdown, in-flight stav),
  `onAction` wiring
- `frontend/src/components/viewer/DocumentDetail.svelte` — jak se
  renderuje overview dokladu
- `src/Core/Viewer/TableViewer.php` — `selectRows($search, $filters,
  $page)`, definice sloupců/filtrů vieweru;
  `modules/economy/accounting/src/AccountsViewer.php` jako vzor vieweru
  s filtry
- `modules/economy/accounting/src/AccountingController.php` — endpoint
  reaccount (kontrakt request/response)
- `modules/economy/accounting/tables/economy_accounting_journal.jsonc` —
  sloupce deníku
- `docs/frontend.md`, `docs/design-system.md` — konvence frontend

## Scope

### V scope

- tab Zaúčtování v detailu dokladu (řádky deníku + chybový banner)
- akce Přeúčtovat (detail actions, volá `/_accounting/reaccount`)
- `JournalViewer` — seznam řádků deníku s filtry, navigace v modulu
  `economy.accounting`
- aktualizace dokumentace

### Mimo scope

- reporty (obratová předvaha, hlavní kniha, výsledovka) — budoucí úkol
- editace deníku (deník je derivát — vždy read-only)
- jakékoliv zásahy do enginu/předpisu (jen čtení dat)

---

## Co implementovat

### W1 — Tab Zaúčtování v detailu dokladu

`DocsHeadsViewer::detail` — přidat druhý tab `accounting` (label
„Zaúčtování"), **podmíněně**: zobrazí se, pokud `accounting_state != 0`
nebo existují řádky deníku pro doklad.

Content `composite`, části:

1. **Stavový blok** — `accounting_state` jako badge/text (Zaúčtováno /
   Chyba účtování, názvy z cfgItem `economy.accounting.accountingStates`).
   Při `accounting_state = 2` vypsat `accounting_messages` (message,
   případně odkaz na řádek dokladu přes `rowId` — stačí textově „řádek N").
   Použij `html` část composite, ať není potřeba nový content typ; styl
   chybového bloku konzistentní s design systémem (viz jak banner řeší
   jiné části UI — pokud nic hotového není, prostý `<div class="...">`
   s existující error/warning třídou).
2. **Tabulka řádků deníku** — content typ `table`. Sloupce: Účet
   (`account_number`), Text, MD, DAL (domácí měna, formát částek shodný
   se zbytkem detailu). U cizoměnového dokladu (`doc_currency !=
   home_currency`) navíc sloupce MD/DAL v měně dokladu + kód měny.
   Chybové řádky (`is_error = 1`): zvýraznění — ověř, zda `table` typ
   umí styl/klasifikaci řádku; pokud ne, prefixuj číslo účtu symbolem ⚠
   a nepřidávej kvůli tomu novou frontend funkcionalitu.
3. **Součty** — pod tabulkou řádek Σ MD / Σ DAL (dom; u cizí měny i cur).

Řádky deníku načítej řazené podle `id`. Dotaz patří do
`DocsHeadsViewer`? Ne — docs.core nesmí záviset na účetnictví. Řešení:
poskytovatele tabu udělej v `economy.accounting` a do `DocsHeadsViewer`
ho zapoj stejným mechanismem, jakým Fáze 2 řešila závislosti — pokud
žádný hotový mechanismus pro „cizí" taby detailu není, je přijatelné
pro teď načíst data v `DocsHeadsViewer` podmíněně (`tableExists` /
try-catch na chybějící tabulku, jako to dělají jiné volitelné integrace
— ověř, jak detail řeší přílohy z `core.attachments`, což je obdobná
mezimodulová vazba) a do Otevřených bodů zapsat, že čistý extension
point pro detail taby je dluh.

### W2 — Akce Přeúčtovat

`DocsHeadsViewer::detail` vrací top-level `actions` (vzor
`AlertsViewer::buildDetailActions` + frontend `onAction`):

- akce `reaccount`, label „Přeúčtovat", viditelná jen pro doklad ve
  stavu 40 (libovolný `accounting_state` — přeúčtovat lze i OK doklad)
- `confirm` netřeba (operace je idempotentní a bezpečná)
- obsluha akce volá `POST /_accounting/reaccount` (`{docId}`); po
  odpovědi refresh detailu (tab Zaúčtování + badge stavu) — použij
  stejný refresh mechanismus jako akce v alertech
- chybové odpovědi endpointu zobraz toast/hlášku konzistentně s tím, jak
  frontend hlásí chyby akcí dnes

Server-side ověř, kudy viewer akce routuje (AlertsViewer má vlastní
action handling) — pokud viewer akce volají generický viewer-action
endpoint, přidej do `DocsHeadsViewer` handler, který interně zavolá
totéž co `AccountingController` (sdílená service metoda, ne duplicitní
logika).

### W3 — Viewer účetního deníku

`modules/economy/accounting/src/JournalViewer.php` + registrace
v `module.jsonc` + položka navigace (sekce Ekonomika, vedle Účtového
rozvrhu; název „Účetní deník").

- **Sloupce seznamu**: Datum (accounting_date), Doklad (`doc_number`),
  Účet (`account_number`), Text, MD, DAL (dom), Partner (jméno přes
  join), u cizí měny indikace měny. Řazení default `accounting_date`
  desc, `id` desc.
- **Filtry** (dle schopností `TableViewer`): fiskální rok, fiskální
  měsíc (vazba na economy_codebooks číselníky — vzor jiného vieweru
  s reference filtrem, pokud existuje; jinak select z číselníku),
  účet (prefix match na `account_number`), partner. Chybové řádky:
  filtr „Jen chyby" (`is_error = 1`).
- **Fulltext** (`$search`): `text`, `doc_number`, `account_number`.
- **Detail řádku**: jednoduchý `properties` content — všechny sloupce
  vč. obou měn + odkaz na zdrojový doklad (jak se dělá odkaz na záznam
  jiného vieweru zjisti z existujícího kódu; pokud pattern není, stačí
  zobrazit číslo dokladu textově a poznamenat do Otevřených bodů).
- Viewer je **read-only**: žádné new/edit/delete akce, žádné docState
  taby (deník docStates nemá — ověř, že viewer framework zvládne tabulku
  bez docStates; AccountsViewer je vzor, rozvrh docStates má, takže
  případné předpoklady frameworku odhal a vyřeš minimálně).

### W4 — Dokumentace

- `docs/accounting.md`: sekce 9 přepsat podle reality (co přesně UI
  umí), stav implementace: Fáze 1–3 hotové, dál reporty + saldo + další
  docTypes
- `docs/frontend.md` jen pokud vznikl nový obecný pattern (extension
  point detail tabů, row styling v table content typu)

---

## Hotovo když

1. Detail faktury ve stavu 40: tab Zaúčtování zobrazuje řádky deníku
   (účet, text, MD, DAL + Σ), stav Zaúčtováno. U konceptu (state 0,
   žádné řádky) tab není.
2. Chybový doklad (`accounting_state = 2`): tab ukazuje banner
   s messages a zvýrazněné/označené chybové řádky (`504???`).
3. Akce Přeúčtovat: viditelná jen ve stavu 40; po opravě rozvrhu a
   kliknutí se detail obnoví — state 1, banner zmizí, řádky správně.
4. Cizoměnový doklad: tab i viewer ukazují dom i cur částky.
5. Viewer Účetní deník: v navigaci, seznam s filtry (rok, měsíc, účet,
   partner, jen chyby) a fulltextem; detail řádku s oběma měnami;
   žádná možnost editace.
6. PHP testy: detail payload (tab přítomen/nepřítomen, struktura table,
   actions dle stavu) + JournalViewer selectRows s filtry — úzký filtr
   (`--filter 'DocsHeadsViewer|JournalViewer|Accounting'`) zelený;
   existující testy neporušené.

## Doporučené pořadí

1. W1 (tab) — nejdřív payload + test, pak frontend doladění
2. W2 (akce) → ruční end-to-end s chybovým dokladem
3. W3 (viewer) → commit zvlášť
4. W4 dokumentace

## Rozhodnutí ✓

- Tab Zaúčtování je podmíněný (`accounting_state != 0` nebo existují
  řádky deníku); obsah composite (stavový blok + table + součty),
  bez nového content typu, pokud to není nezbytné.
- Přeúčtovat: bez confirm, jen ve stavu 40, sdílená logika s
  `AccountingController` (žádná duplicita).
- Viewer deníku read-only, bez docState tabů, default řazení datum desc.
- Chybové řádky: minimální vizuální řešení (styl pokud table umí, jinak
  ⚠ prefix) — žádný velký frontend vývoj kvůli zvýraznění.

## Otevřené body

- Mezimodulová vazba detail tabu (docs.core × economy.accounting):
  pokud nevznikne čistý extension point, zapsat dluh (kandidát: obecný
  mechanismus `viewerDetailExtensions` v module.jsonc, obdoba
  `documentEventHandlers`) — rozhodnutí nechávám na zjištění z kódu,
  preferuj nejmenší funkční řešení.
  - **W1 rozhodnuto:** přímý dotaz v `DocsHeadsViewer::buildAccountingTab`
    (precedent: přílohy z core.attachments). Guard bez `tableExists`:
    extension sloupec `accounting_state` je v `SELECT h.*` jen
    s nainstalovaným modulem — bez něj se na deník nesahá. Extension
    point `viewerDetailExtensions` zůstává dluh.
- Odkaz ze záznamu deníku na doklad (cross-viewer navigace) — pokud
  pattern neexistuje, jen textové číslo dokladu + poznámka sem.
  - Pattern existuje: detail akce `kind: 'open_viewer'`
    (`Viewer.svelte::handleDetailAction` → `navigationStore.navigateToViewer`).
    Použít ve W3.
- Row styling v `table` content typu — pokud chybí a šlo by o triviální
  doplněk (class per row), je OK ho přidat a zdokumentovat; jinak ⚠.
  - **W1 přidáno:** `row._class` (`error` | `total`) a `columns[].align:
    'right'` v `ViewerDetail.svelte`. Zdokumentovat ve W4 (frontend.md).
