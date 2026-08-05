# Lookup — TableAccessGuard na lookup endpointech

**Stav:** hotovo

> Mini-task, jedna Claude Code session. Bezpečnostní oprava nalezená
> při Fázi 2 hostingu (`tasks/hosting-03-provisioning-agent.md`,
> poznámka 3): `LookupController` je jediná datová cesta bez
> `TableAccessGuard`. Nezávisí na hosting modulu — chrání i
> `core_system_*` (díra existuje od Fáze 0a auth hardeningu).

## Kontext

`GET /_ui/lookup/{table}/search|resolve` vrací display hodnoty řádků
(a přes `filter[...]` umí i cílené dotazy) pro kteroukoli tabulku
s registrovaným lookupem. CRUD/viewer/form cesty prochází
`TableAccessGuard::guardTable` (prefix `core_system_` +
`adminOnly` flag, viz `docs/hosting.md` D9 a task
`hosting-00-admin-only-tables.md`) — lookup ne. `dispatchLookup`
v `public/index.php` dokonce nedostává `$auth`. Důsledek: ne-admin
umí lookupem číst display hodnoty systémových i `adminOnly` tabulek
(např. jména/e-maily z `core_system_users`, evidenci
`hosting_core_*`).

## Cíl

Obě lookup akce vynucují `guardTable` stejně jako ostatní datové
cesty. Žádná změna chování pro neguardované tabulky.

## Před implementací přečti

- `src/Api/Controller/LookupController.php` — celý (krátký)
- `src/Api/TableAccessGuard.php` — `guardTable` signatura
- `public/index.php` — `dispatchLookup` (ř. ~1094) a match větev
  `'lookup'` (ř. ~286); `$auth` je ve scope
- `src/Api/Controller/ViewerController.php` — vzor umístění guardu
  (po nalezení `$def`, před prací s daty)
- `tests/Unit/Api/Controller/ViewerControllerGuardTest.php` — vzor
  guard testů
- `docs/table-definitions.md` — sekce `adminOnly` (výčet cest)

## Změny po souborech

### `src/Api/Controller/LookupController.php`

`search()` i `resolve()`: nový parametr `AuthContext $auth`;
po nalezení `$def` (za větví `TABLE_NOT_FOUND`, před
`LOOKUP_NOT_REGISTERED`):
`TableAccessGuard::guardTable($table, $auth, $def)` → případný 403
vrátit. Pořadí: neexistující tabulka → 404; guardovaná → 403
(registrace lookupu se pro guardovanou tabulku nezjišťuje).

### `public/index.php`

`dispatchLookup(...)`: parametr `AuthContext $auth`, předat do obou
akcí; call site v match doplnit `$auth`.

### `docs/table-definitions.md`

V sekci `adminOnly` doplnit lookup do výčtu vynucovaných cest
(CRUD/viewer/form/**lookup**). Totéž zkontrolovat v `docs/auth.md`
(pokud vyjmenovává guardované cesty) a v `docs/hosting.md` §7
(věta o bariérách — doplnit lookup).

## Testy

- `tests/Unit/Api/Controller/LookupControllerGuardTest.php` (nový,
  vzor ViewerControllerGuardTest): matice pro search i resolve —
  `adminOnly` tabulka × admin/ne-admin (403 `FORBIDDEN_ADMIN_ONLY` /
  průchod), `core_system_*` × ne-admin (403 `FORBIDDEN_SYSTEM_TABLE`),
  běžná tabulka ne-admin → beze změny (průchod až na
  `LOOKUP_NOT_REGISTERED`/data).
- Stávající lookup testy (pokud existují — najdi dle
  `--filter 'Lookup'`) doplnit o `$auth` a nechat zelené.
- PHPUnit `--filter 'Lookup'`.

## Commit strategie

Jeden commit:
`fix(security): TableAccessGuard on lookup endpoints (search/resolve)`.

## Hotovo když

- [x] Ne-admin dostane 403 na lookup search i resolve nad `adminOnly`
      a `core_system_*` tabulkami; admin projde
- [x] Běžné tabulky: chování beze změny (formuláře s lookupy fungují)
- [x] Dokumentace výčtu guardovaných cest aktualizovaná
- [x] Testy zelené
