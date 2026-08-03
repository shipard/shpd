# Auth Fáze 0a — is_admin a ochrana systémových tabulek

**Stav:** naplánováno — rate limiting a evidence neúspěšných přihlášení chybí

## Kontext

nov_shipard nemá žádný koncept oprávnění. `core_system_users` je v settings
navigaci a jde přes generický CRUD — **kterýkoli přihlášený uživatel může
číst `password_hash` všech uživatelů, přepsat komukoli heslo, deaktivovat
účty nebo manipulovat `core_system_api_keys`**. Před nasazením správy
uživatelů (Fáze 0b) je nutné tohle zavřít.

**Potvrzená rozhodnutí:**

- **D16** — minimální admin model: `is_admin` boolean na users + plošný
  guard: CRUD nad tabulkami `core_system_*` vyžaduje admina. Plné RBAC mimo
  scope, tento model je s ním dopředně kompatibilní.
- **D18** — mechanismus citlivých sloupců: `"sensitive": true` v definici
  tabulky → sloupec nikdy neopustí server (čtení) a nejde zapsat přes
  generický CRUD. Zápis citlivých hodnot vždy jen dedikovaným endpointem
  (vzor: API klíče; v mail-outbound tasku takto heslo senderu).

## Návaznost

- **Prerekvizita pro Fázi 0b** (`tasks/auth-phase0b-account-flows.md`) —
  invite akce a správa uživatelů v UI stojí na is_admin.
- **Prerekvizita pro mail-outbound** (`tasks/mail-outbound.md`) —
  `core_mail_senders.password_enc` je sensitive sloupec.
- Bez závislosti na odchozí poště — může jít hned.
- **Rollout pozor:** po nasazení na existující DS (ns-alpha!) nemá nikdo
  `is_admin` → systémové tabulky v UI zmizí všem do doby, než se spustí
  `user-set-admin`. Součást deploy postupu, viz Hotovo když.

## Před implementací přečti

- `src/Api/AuthContext.php` — readonly DTO, přibude `isAdmin`.
- `src/Api/Middleware/AuthMiddleware.php` — `handleSession()` /
  `handleApiKey()`; session lookup je dnes `SELECT * FROM
  core_system_sessions` bez joinu na users.
- `src/Api/Controller/CrudController.php` — akce `list/show/create/update/
  patch/delete/docStateOptions`; wiring v `public/index.php` (dispatch).
- `src/Core/Database/TableDefinition.php` + `src/Api/TableLoader.php` —
  parsování definic sloupců (přibude flag `sensitive`).
- `src/Core/Form/AutoFormBuilder.php` — generování formulářů.
- `src/Command/DataSource/UserCreateCommand.php`.
- `frontend/src/components/settings/` — navigace settings sekcí (filtr
  `other.system` pro ne-adminy).

## Scope

1. `is_admin` sloupec, propsání do `AuthContext`, session join.
2. Plošný guard `core_system_*` v CRUD.
3. Sensitive sloupce: parsování, filtrace čtení, odmítnutí zápisu,
   vyřazení z formulářů a z metadat pro frontend.
4. CLI: `user-create --admin`, nový `user-set-admin`.
5. Frontend: skrytí systémové sekce settings pro ne-adminy.

**Non-goals:** RBAC/role/per-tabulka oprávnění; audit log; správa uživatelů
v UI nad rámec dnešního generického CRUD (to je 0b); jakékoli mail flow.

## Změny po souborech

### Commit 1 — is_admin

**`modules/core/system/tables/core_system_users.jsonc`** — nový sloupec
`is_admin` boolean, default 0, skupina `credentials`.

**`src/Api/AuthContext.php`** — `public bool $isAdmin = false`.

**`src/Api/Middleware/AuthMiddleware.php`** — `handleSession()`: lookup
rozšířit o JOIN na `core_system_users` (`u.is_admin`, `u.is_active`);
neaktivní uživatel → 401 (session existuje, ale účet je vypnutý — dnes se
to mezi loginy nekontroluje, oprava zdarma). `handleApiKey()`: API klíč
není vázán na uživatele → `isAdmin: false` (integrace nemají co dělat
v systémových tabulkách; provisioning jde přes CLI).

**`src/Api/Controller/AuthController.php`** — odpověď `login` (a `me`,
pokud existuje) rozšířit o `is_admin`, ať frontend ví, co zobrazit.

**`src/Command/DataSource/UserCreateCommand.php`** — option `--admin`.

**`src/Command/DataSource/UserSetAdminCommand.php`** (nový,
`user-set-admin --login xy [--revoke]`) — nastaví/odebere `is_admin`;
při `--revoke` odmítne odebrat poslednímu aktivnímu adminovi (pojistka
proti zamčení DS).

### Commit 2 — guard a sensitive sloupce

**`src/Api/Controller/CrudController.php`** — (a) do konstruktoru přibude
`AuthContext` (wiring v `public/index.php`); (b) privátní
`guardSystemTable(string $table): ?Response` volaný na začátku **všech**
akcí: `str_starts_with($table, 'core_system_') && !$this->auth->isAdmin`
→ 403 `FORBIDDEN_SYSTEM_TABLE`; (c) sensitive filtrace: čtecí akce
vyřadí sensitive sloupce ze SELECT i z odpovědi; `create/update/patch`
při výskytu sensitive sloupce ve vstupu → 400 `SENSITIVE_COLUMN` (žádné
tiché zahazování — volající se má dozvědět, že tudy cesta nevede).

**`src/Core/Database/TableDefinition.php`** — parsovat `"sensitive": true`
z definice sloupce; accessor `getSensitiveColumns(): array`.

**`src/Api/TableLoader.php`** (příp. místo, kde se skládají metadata
tabulek pro frontend/compiled cfg) — sensitive sloupce vyřadit z metadat
posílaných klientovi (nesmí se objevit v gridech ani formulářích).

**`src/Core/Form/AutoFormBuilder.php`** — sensitive sloupce přeskočit.

**`modules/core/system/tables/core_system_users.jsonc`** —
`password_hash`: `"sensitive": true`.
**`modules/core/system/tables/core_system_api_keys.jsonc`** — hash klíče
(`key_hash`): `"sensitive": true`.

**Frontend** — settings navigace: subsekce `other.system` (příp. celé
položky systémových tabulek) skrýt pro ne-adminy (`is_admin` z login/me
v authStore). Server je zdroj pravdy, UI jen nezobrazuje mrtvé odkazy.

**Docs** — `docs/table-definitions.md`: odstavec o `sensitive`;
`docs/rest-api.md`: chybové kódy `FORBIDDEN_SYSTEM_TABLE`,
`SENSITIVE_COLUMN`; `docs/cli.md`: `user-set-admin`, `user-create --admin`.

## Testy

- `AuthMiddlewareTest` — session join: is_admin propsané do kontextu;
  neaktivní uživatel s platnou session → 401; API klíč → isAdmin false.
- `CrudControllerTest` — ne-admin na `core_system_users`: list/show/
  create/update/patch/delete → 403; admin → prochází; běžná tabulka bez
  omezení; sensitive sloupec chybí v list/show odpovědi; create/patch se
  sensitive sloupcem → 400.
- `TableDefinitionTest` — parsování `sensitive`.
- `UserSetAdminCommandTest` — nastavení, revoke, odmítnutí posledního
  admina.
- Frontend: settings nav filtr dle is_admin.

## Commit strategie

1. `auth: is_admin flag, session join, user-set-admin CLI`
2. `crud: system table guard, sensitive column mechanism`

Po commitu 1: rebuild compiled cfg + `ds-upgrade` (nový sloupec).

## Hotovo když

- [ ] Ne-admin nedostane přes API žádný obsah `core_system_*` tabulek
      (403) a v UI sekci nevidí; admin pracuje jako dosud.
- [ ] `password_hash` a `key_hash` se nikdy neobjeví v žádné API odpovědi
      ani ve formuláři; pokus o zápis přes CRUD → 400.
- [ ] Session neaktivního uživatele je odmítnuta (401).
- [ ] `user-set-admin` funguje vč. pojistky posledního admina.
- [ ] Rollout na ns-alpha: `ds-upgrade` + `user-set-admin` pro adminské
      účty proveden a zdokumentován v deploy poznámkách.
- [ ] PHPUnit zelené (úzké filtry), frontend testy zelené, docs
      aktualizované.
