# Task: Saldokonta v sidebaru — datově řízené položky navigace

## Status / cíl

Vybraná saldokonta (typicky Pohledávky a Závazky) dostanou **vlastní položku
v sidebaru** vedle Saldo pohybů. Klik otevře tentýž prohlížeč
(`economy.accbal.ledger`), ale **napevno filtrovaný** za dané saldokonto —
bez chip lišty. Které saldokonto se v sidebaru objeví, řídí checkbox
v Nastavení saldokont (nový sloupec `show_in_navigation`).

Hlavní nová věc: navigace je dnes čistě statická (skládá se z `module.jsonc`,
`NavigationController` nemá DB). Tento task zavádí **obecný mechanismus
navigation providerů** — modul zaregistruje třídu, která vrátí dynamické
položky z dat. Accbal je první konzument; příště (pokladny, sklady…) je
mechanismus zadarmo.

## Závislosti

- **`accbal-ledger-viewgroup-chips.md` musí být hotový** — sidebar položka
  jede na viewGroups per saldokonto (fixuje jeden `code`), bez nich nemá
  jak filtrovat.

## Potvrzená designová rozhodnutí (Anna)

1. **Obecný mechanismus providerů**, ne accbal natvrdo v NavigationControlleru.
2. **Label = `short_name` s fallbackem na `name`.**
3. **Ikona zatím jedna společná: `calculator`** (stejná jako Saldo pohyby);
   per-saldokonto ikony případně později.
4. **Výchozí zapnutí**: v seedu `show_in_navigation: 1` u `receivables`
   a `payables`. Migrované DS (alpha) se v tomto tasku **neřeší**.
5. Výchozí stav sidebaru po seedu: Saldo pohyby + Pohledávky + Závazky.

## Rozsah

### V rozsahu

**Data (modul economy.accbal)**

1. `tables/economy_accbal_balances.jsonc` — nový sloupec:
   `{"id": "show_in_navigation", "name:cs": "Zobrazit v navigaci",
   "name:en": "Show in navigation", "type": "bool" (dle konvence bool
   sloupců v projektu — okoukej existující, např. `modify_sign` na
   balance_accounts: tinyint default 0), "default": 0, "group": "settings"}`.
   Additivní ADD COLUMN přes `ds-upgrade`.
2. `forms/economy_accbal_balances.jsonc` — checkbox do sekce „Nastavení"
   (za `sort_order`).
3. `config/balancesDefault.cz.jsonc` — `"show_in_navigation": 1` u skupin
   `receivables` a `payables`. Ověř, že `BalancesProvisioner` pole ze seedu
   propisuje (pokud mapuje sloupce explicitně, doplnit).

**Backend — obecný mechanismus**

4. Interface `Shipard\Core\Navigation\NavigationItemsProvider`:
   ```php
   /** @return array<int, array<string, mixed>> položky ve tvaru collectItems() */
   public function items(DataSourceConnection $db, string $language): array;
   ```
   Položka nese: `id`, `label`, `type: 'viewer'`, `viewerId`, `icon`,
   volitelně `fixedViewGroup`, interní `_section`, `_order` (stejný kontrakt
   jako `collectItems()`, interní klíče maže `cleanItem()`).
5. `module.jsonc` — nový klíč `navigationProviders`:
   ```jsonc
   "navigationProviders": [
       { "class": "Shipard\\Module\\Economy\\Accbal\\BalancesNavigationProvider" }
   ]
   ```
   Parsování v `ModuleDefinition` — vzor `journalEventHandlers`.
6. `NavigationController::navigation()` — nový parametr
   `?DataSourceConnection $db`; po `collectItems()` projde
   `navigationProviders` resolvnutých modulů, instancuje a přimerguje
   položky do stejného bucketování (`_section`/`_order`). **Degradace:**
   `$db === null` → providery se přeskočí; výjimka z provideru → catch +
   `ErrorLogger`, navigace nesmí spadnout.
7. `public/index.php` — `dispatchUi()` protáhnout `$db` (v routeru je
   k dispozici, viz `dispatchDashboard`).

**Backend — accbal provider**

8. `BalancesNavigationProvider`: `SELECT code, name, short_name FROM
   economy_accbal_balances WHERE show_in_navigation = 1 AND docState != 90
   ORDER BY sort_order ASC, name ASC` →
   ```
   id:             'accbal-balance:' . code
   label:          short_name ?: name
   type:           'viewer'
   viewerId:       'economy.accbal.ledger'
   icon:           'calculator'
   fixedViewGroup: code
   _section:       'accounting'
   _order:         31 + index    // Saldo pohyby mají navOrder 30
   ```
   Zkontroluj kolize `_order` s ostatními položkami sekce accounting
   (deník ap.) — případně posuň jejich navOrder, řazení je stabilní.
   Defenzivně: neexistuje-li sloupec (DS před upgrade), catch → prázdný
   seznam.

**Frontend**

9. `navigation.svelte.js` — `navigate()` normalizace: doplnit
   `fixedViewGroup: item.fixedViewGroup ?? null`.
10. `Viewer.svelte` — podpora `tab.fixedViewGroup`:
    - je-li nastaven: **chip lišta se nerenderuje** (`hasViewGroups`
      force false) a všechny fetche posílají `viewGroup = fixedViewGroup`
      (přebíjí `meta.defaultViewGroup` i `pendingViewGroup`),
    - **KLÍČOVÁ PAST:** init `$effect` dnes trackuje jen `tab.viewerId`.
      Pohledávky a Závazky sdílejí `viewerId` → přepnutí mezi nimi by
      viewer **nereinicializovalo**. Efekt musí trackovat identitu položky
      (`tab.id`, případně `tab.viewerId + tab.fixedViewGroup`) — pozor na
      komentovanou disciplínu efektu, uprav i komentář.
11. `Sidebar.svelte` — ověřit, že položky projdou beze změny (jsou to
    normální viewer leaves v sekci; `fixedViewGroup` jen musí přežít cestu
    item → `navigate()`). Zkontrolovat keying (`item.id` je unikátní).

### Mimo rozsah

- Zapnutí Pohledávek/Závazků na **migrovaných DS** (rozhodnutí Anna:
  neřešit teď).
- Per-saldokonto ikony, počty/badge u položek.
- Živý refresh sidebaru po změně checkboxu (navigace se načítá při startu
  aplikace; reload je přijatelný).

## Pasti / na co pozor

- `navigation()` má už dnes víc call-sites (`dispatchUi`,
  `SettingsController::navigation` pro settings/account módy) — nový
  parametr přidej zpětně kompatibilně (nullable, default null); providery
  patří jen do **app** navigace, ne do settings/account.
- Provider běží při každém načtení navigace → dotaz drž triviální
  (indexovaná malá tabulka, v pořádku).
- `fixedViewGroup` s kódem saldokonta, které mezitím zaniklo (smazaná
  skupina, stale sidebar): `selectRows` s neexistujícím `code` vrátí
  prázdný seznam — přijatelné, nesmí to shodit viewer.

## Ověření

1. `php -l`; `vendor/bin/phpunit --filter 'Navigation'` + testy ModuleDefinition
   (existují-li), jinak celý běh unit testů.
2. `cd frontend && timeout 90 npm run build 2>&1 | tail -10`.
3. Ručně na dev DS (po `ds-upgrade`): v sekci Účtárna jsou Saldo pohyby,
   Pohledávky, Závazky; klik na Pohledávky otevře ledger bez chip lišty,
   jen s pohyby pohledávek; přepnutí Pohledávky ↔ Závazky reinicializuje
   data (past bodu 10); Saldo pohyby dál mají chip lištu; vypnutí checkboxu
   v Nastavení saldokont + reload položku odebere; DS bez zapnutých
   saldokont má v sidebaru jen Saldo pohyby.
