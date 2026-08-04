# Hosting — Task 0a: admin-only tabulky (`adminOnly` flag)

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/hosting.md`,
> rozhodnutí **D9**. Samostatné jaderné rozšíření bez závislosti na
> hosting modulu — commitovatelné nezávisle, hodnota i mimo hosting
> (nejhrubší stupeň budoucího RBAC).

## Kontext

`TableAccessGuard` dnes chrání tabulky **jen prefix matchem**
`core_system_` (`guardSystemTable`). Tabulky budoucího hosting modulu
(`hosting_core_*`) — a obecně jakékoli modulové tabulky s citlivým
obsahem — by byly přes generické CRUD/viewer/form cesty přístupné
každému přihlášenému uživateli. D9: definice tabulky může deklarovat
`"adminOnly": true` a guard to vynucuje plošně, stejně jako dnes prefix.

Zdroj pravdy je server; UI jen nezobrazuje mrtvé odkazy (server-side
filtr settings navigace — stejný princip jako Fáze 0a auth, viz
`docs/auth.md` §Admin model).

## Cíl

1. `TableDefinition` umí `adminOnly` (bool, default `false`) z JSONC.
2. `TableAccessGuard` vynucuje flag vedle stávajícího prefixu — nový
   chybový kód `FORBIDDEN_ADMIN_ONLY`; chování pro `core_system_*`
   se **nemění** (kód `FORBIDDEN_SYSTEM_TABLE` zůstává).
3. Všechny stávající call sites (CRUD, viewer, form, settings navigace)
   flag respektují.
4. Dokumentace v `docs/table-definitions.md`.

## Před implementací přečti

- `docs/hosting.md` §0 (D9), §7 — závazný rámec
- `src/Api/TableAccessGuard.php` — celý (krátký)
- `src/Core/Database/TableDefinition.php` — konstruktor + `fromArray()`
- `src/Api/Controller/CrudController.php` — `resolveTable()` (ř. ~41)
- `src/Api/Controller/FormController.php` — tři volání guardu
  (ř. ~51, ~159, ~331); `$def` je v okolí vždy k dispozici
- `src/Api/Controller/ViewerController.php` — `meta`/`rows`/`detail`;
  guard se volá nad `$def->table` (ViewerDefinition), TableDefinition
  tu dnes **není** — bude potřeba přidat parametr `$tables`
- `src/Api/Controller/SettingsController.php` — filtr navigace
  (ř. ~390–410, prefix match)
- `public/index.php` — `dispatchViewer` (ř. ~960) a `dispatchSettings`
  (ř. ~925); mapa `$tables` je ve scope (viz volání form meta ř. ~1013)
- `tests/Unit/Api/TableAccessGuardTest.php`,
  `tests/Unit/Api/Controller/ViewerControllerGuardTest.php`,
  `tests/Unit/Api/Controller/FormControllerGuardTest.php`,
  `tests/Unit/Core/Database/TableDefinitionTest.php`

## Změny po souborech

### `src/Core/Database/TableDefinition.php`

- Konstruktor: nový readonly parametr `public readonly bool $adminOnly = false`
  (za `stateTransitionsRunDocumentHooks`).
- `fromArray()`: `adminOnly: (bool) ($data['adminOnly'] ?? false)`.

### `src/Api/TableAccessGuard.php`

- `guardSystemTable(string $table, AuthContext $auth)` →
  **`guardTable(string $table, AuthContext $auth, ?TableDefinition $def = null)`**
  (přejmenování; starý název nenechávat — call sites je hrstka):
  - prefix `core_system_` + ne-admin → `FORBIDDEN_SYSTEM_TABLE`, 403
    (beze změny textu i kódu);
  - `$def?->adminOnly === true` + ne-admin → **`FORBIDDEN_ADMIN_ONLY`**,
    403, text `Table requires administrator rights`;
  - jinak `null`.
- Docblock: dvě větve, odkaz na `docs/hosting.md` D9.

### `src/Api/Controller/CrudController.php`

- `resolveTable()`: `TableAccessGuard::guardTable($table, $this->auth, $def) ?? $def`.

### `src/Api/Controller/FormController.php`

- Tři volání: doplnit `$def` třetím argumentem (lookup `$tables[$table]`
  už na místě existuje).

### `src/Api/Controller/ViewerController.php`

- `meta()`, `rows()`, `detail()`: nový parametr `array $tables`
  (mapa `name → TableDefinition`; umístění parametru dle vkusu okolní
  signatury). Guard: `guardTable($def->table, $auth, $tables[$def->table] ?? null)`.
- Viewer bez odpovídající TableDefinition (teoretický případ) → guard
  jen s prefixem, beze změny chování.

### `public/index.php`

- `dispatchViewer(...)`: přidat `array $tables`, předat do tří akcí;
  call site doplnit `$tables`.
- `dispatchSettings(...)`: dtto pro `navigation`/`accountNavigation`.

### `src/Api/Controller/SettingsController.php`

- `navigation()`: nový parametr `array $tables = []`. Filtr položek
  rozšířit: přeskočit i když
  `($tables[$itemTable] ?? null)?->adminOnly === true` a ne-admin.
  Komentář u filtru aktualizovat (už nejde jen o systémové tabulky).

### `docs/table-definitions.md`

- Nová podsekce u příznaků tabulky: `adminOnly` — co dělá (403 pro
  ne-adminy na CRUD/viewer/form + skrytí ze settings navigace), kdy ho
  použít, odkaz na `docs/hosting.md` D9 a `docs/auth.md` §Admin model.

## Testy

- `TableDefinitionTest`: parsování `adminOnly` (true / chybí → false).
- `TableAccessGuardTest`: matice — flagovaná tabulka × admin/ne-admin
  × s/bez `$def`; `core_system_*` větev beze změny (regresní případy
  ponechat zelené).
- `ViewerControllerGuardTest` + `FormControllerGuardTest`: nový případ
  s `adminOnly` tabulkou (403 `FORBIDDEN_ADMIN_ONLY` pro ne-admina,
  průchod pro admina).
- CRUD cesta: rozšířit stávající test CrudControlleru o adminOnly
  případ (najdi dle vzoru systémových tabulek).
- PHPUnit spouštět s úzkým `--filter` (např.
  `--filter 'TableAccessGuard'`), ne naširoko.

## Commit strategie

Jeden commit: `core: adminOnly table flag enforced by TableAccessGuard (hosting D9)`.

## Hotovo když

- [x] `"adminOnly": true` v JSONC definici → ne-admin dostane 403
      (`FORBIDDEN_ADMIN_ONLY`) na CRUD list/show/create/update/patch/delete,
      viewer meta/rows/detail i form meta/save
- [x] Admin má na tytéž cesty plný přístup
- [x] `core_system_*` chování beze změny (kód `FORBIDDEN_SYSTEM_TABLE`)
- [x] Settings navigace ne-adminovi položky nad adminOnly tabulkami
      vůbec nepošle
- [x] Tabulka bez flagu se chová přesně jako dřív (žádná regrese)
- [x] Testy zelené, `docs/table-definitions.md` aktualizován
