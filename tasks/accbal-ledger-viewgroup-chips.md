# Task: Saldo pohyby — chip bar saldokont místo roletky

**Stav:** hotovo

## Status / cíl

Prohlížeč Saldo pohybů (`economy.accbal.ledger`) dnes vybírá saldokonto
roletkou ve filtrech — je schovaná a uživatel na ni musí myslet. Cíl:
saldokonta se stanou **viewGroups** vieweru, tedy chip/tab lištou nahoře
v těle prohlížeče (dnes u tohoto vieweru prázdný pruh), vizuálně obdoba
filtračních chipů na Dashboardu (`FeedFilter.svelte`). Roletka „Saldokonto"
z filtrů zmizí.

Klíčový poznatek: mechanismus viewGroups **není** zadrátovaný na docStates.
`getViewGroups()` je metoda na vieweru, frontend jen vykreslí taby a posílá
`filter[viewGroup]=<id>`. LedgerViewer může vracet skupiny odvozené z dat
(`economy_accbal_balances`) — celá tab infrastruktura (render, klik, fetch)
se použije beze změny.

## Závislosti

- Žádné. Task `accbal-nav-items.md` (saldokonta v sidebaru) na tomto tasku
  **staví** — dělej tento první.

## Potvrzená designová rozhodnutí (Anna)

1. **Výchozí chip = první saldokonto** dle `sort_order` (ne „Vše").
2. **Tab „Vše" zůstává, na konci** lišty (frontend ho tam už dnes přidává).
3. Roletka „Saldokonto" z filtrů zmizí; ostatní filtry (Partner, VS,
   Jen otevřené) zůstávají.

### Rozhodnutí Claude (v duchu potvrzených)

- **Label chipu = `short_name` s fallbackem na `name`** — konzistentní
  s rozhodnutím pro sidebar (accbal-nav-items) a šetří místo v liště
  („Náklady příštích období" by lištu rozbily).
- **Identita viewGroup = `code`** saldokonta (`receivables`, `payables`…),
  ne `id` — stabilní napříč DS, čitelné v URL/logu, bez kolize s rezervovanými
  hodnotami `active`/`archive`/`trash`/`all`.

## Rozsah

### V rozsahu

**Backend**

1. `ViewerController::meta()` — nový klíč `defaultViewGroup`:
   - `TableViewer::getDefaultViewGroup(): string` → vrací `'active'`
     (dnešní chování, žádná změna pro ostatní viewery).
   - meta: `'defaultViewGroup' => $viewer->getDefaultViewGroup()`.

2. **Tvar viewGroups rozšířit o objektovou variantu.** Dnes
   `getViewGroups()` vrací pole stringů (`['active','archive']`), frontend
   mapuje string → i18n klíč. Nově smí položka být i objekt
   `{id: string, label: string}` (label už lokalizovaný z backendu).
   Stávající viewery se nemění — string varianta zůstává.

3. `LedgerViewer`:
   - `getViewGroups()`: `SELECT id, code, name, short_name FROM
     economy_accbal_balances WHERE docState != 90 ORDER BY sort_order ASC,
     name ASC` → `[{id: code, label: short_name ?: name}, …]`.
     Výsledek per-request nacachovat do property — volá se z meta
     i z `getDefaultViewGroup()`.
   - `getDefaultViewGroup()`: `code` prvního saldokonta; když žádné
     neexistuje, `'all'`.
   - `selectRows()`: zpracovat filtr `viewGroup`:
     - `'all'` (a defenzivně `'active'` — stale frontend) → bez podmínky,
     - jinak podmínka `b.\`code\` = %s` (alias `b` na balances už v SQL je).
   - `getFilters()`: odstranit filtr `balance` (select) — a s ním i jeho
     větev v `selectRows()`.

**Frontend (`Viewer.svelte`)**

4. `viewTabs` derived: položka string → dnešní i18n mapování; objekt →
   `{id: vg.id, label: vg.label}`. Tab „Vše" se dál přidává na konec.

5. **Odstranit hardcoded `'active'`** — dvě místa:
   - init `$effect` (reset `activeViewGroup = 'active'` + volání
     `fetchRowsExplicit(…, pendingViewGroup ?? 'active', …)`): výchozí
     viewGroup se smí určit až **po** `fetchMeta()` —
     `pendingViewGroup ?? meta.defaultViewGroup ?? 'active'` a stejnou
     hodnotou nastavit `activeViewGroup` (jinak se nezvýrazní správný chip).
     POZOR na disciplínu efektu (nesmí trackovat jiný $state než
     `tab.viewerId`) — čtení meta v `.then()` už netrackuje, ale drž vzor
     `untrack()` jako okolní kód.
   - zkontrolovat ostatní výskyty literálu `'active'` v souboru (grep) —
     každý, který znamená „výchozí viewGroup", nahradit hodnotou z meta.

6. **Chování lišty při ~9 chipech** (seed má 9 skupin): `.shpd-viewer__tabs`
   musí zvládnout přetečení v úzkém list panelu — horizontální scroll bez
   zalomení, vzor `FeedFilter.svelte` z dashboardu (skryté scrollbary,
   `overflow-x: auto`). Jen CSS úprava, žádná změna markup.

### Mimo rozsah

- Saldokonta v sidebaru (`accbal-nav-items.md` — navazuje).
- Vizuální redesign tab lišty do 1:1 podoby dashboard chipů (počty
  v chipech ap.) — stávající styl tabů stačí.
- Součty/agregace per saldokonto v liště.

## Pasti / na co pozor

- **`getViewGroups()` nyní sahá do DB** — u ostatních viewerů ne. Meta
  endpoint DB connection má (`createViewer` ji dostává), jen ověř, že se
  metoda nevolá v kontextu bez DB.
- **`viewGroup=active` od starého klienta**: po nasazení může přijít
  z otevřené session. Řešeno defenzivní větví v `selectRows()` (bod 3).
- **Řazení tabů** musí odpovídat `sort_order` — stejné pořadí jako
  v Nastavení saldokont a (později) v sidebaru.
- `HAVING residual <> 0` (Jen otevřené) je nezávislé na WHERE — kombinace
  chip + filtry musí dál fungovat, přidávej podmínku do `$conditions`.

## Ověření

1. `php -l` na dotčené PHP soubory; `vendor/bin/phpunit --filter
   'LedgerViewer|ViewerController'` (existují-li testy; jinak smoke přes
   ostatní viewer testy — meta tvar se rozšiřuje, nemění).
2. `cd frontend && timeout 90 npm run build 2>&1 | tail -10`.
3. Ručně na dev DS: Saldo pohyby se otevřou na prvním saldokontu
   (zvýrazněný chip), přepínání chipů filtruje, „Vše" na konci ukazuje
   všechno, filtry Partner/VS/Jen otevřené fungují v kombinaci s chipem,
   roletka Saldokonto už ve filtrech není. Regresně: viewer s docState
   taby (např. Saldokonta v Nastavení, Došlá pošta) — taby Aktivní/Archiv/
   Vše se chovají jako dřív.
