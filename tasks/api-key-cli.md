# Task: CLI příkazy pro správu API klíčů

## Kontext

V novém Shipardu už existují role-specifické příkazy pro generování API
klíčů — `mail-router-setup` a `ai-analyzer-setup`. Oba jsou vázané na
konkrétního systémového uživatele (`_mail_router`, `_ai_analyzer`) a
mají hard one-active-key-per-role semantiku.

**Cíl tohoto tasku:** Doplnit **generické CLI příkazy** pro správu API
klíčů libovolného uživatele — `api-key-create`, `api-key-list`,
`api-key-revoke`. Bezprostřední motivace: importer ze starého Shipardu
(samostatný projekt) potřebuje vytvořit dedikovaný API klíč pro
integraci, který není svázán s rolí mail-router ani ai-analyzer. Obecně
ale chceme tento toolset i pro budoucí integrace, custom skripty,
DevOps automatizaci, atd.

**Vztah k existujícím příkazům.** `mail-router-setup` a `ai-analyzer-setup`
zůstávají beze změny — jsou to bootstrapy svých subsystémů (vytváří
systémového uživatele + klíč v jedné transakci) a one-active-key-per-role
politika dává smysl pro jejich use-case. Tento task **přidává** generic
toolset paralelně, **nepřepisuje** existující příkazy.

**Mimo scope:**

- UI pro správu API klíčů ve webu (Settings page) — Phase 2 follow-up,
  až bude potřeba.
- Rotace klíčů přes CLI (`api-key-rotate`) — řeší se kombinací
  `api-key-revoke` + `api-key-create`. Pokud by se ukázalo, že je to
  častá operace, dodá se zvlášť.
- Refactor `MailRouterSetupCommand` / `AiAnalyzerSetupCommand` aby
  používaly nový `ApiKeyService` — viz "Otevřené body" sekce 1.
  Doporučeno, ale není required pro tento task.

## Před implementací přečti

Klíčové existující soubory:

- **`src/Command/DataSource/MailRouterSetupCommand.php`** — kompletní
  vzor generování API klíče. Z něj je `generateToken()` statická metoda
  (`shpd_ak_` + 32 hex chars), SHA-256 hashing, key_prefix 12 znaků,
  insert do `core_system_api_keys`. Tato logika se přesune do
  `ApiKeyService` (viz 4.1), MailRouterSetupCommand zůstává hot-loaded
  na něj nebo zůstává s vlastní kopií logiky (viz Otevřené body 1).
- **`src/Command/DataSource/AiAnalyzerSetupCommand.php`** — paralela
  k mail-router-setup; už používá `MailRouterSetupCommand::generateToken()`
  z předchozího příkazu. Tj. ten je už dnes sdílený statický helper.
- **`src/Command/DataSource/UserCreateCommand.php`** — vzor pro CRUD-style
  CLI příkaz na `core_system_users`. Pattern resolve user, validate input,
  insert. Strukturu sleduj.
- **`src/Api/Middleware/AuthMiddleware.php`** — `handleApiKey()` ukazuje,
  jak se API klíč ověřuje proti DB (SHA-256 hash lookup, key_prefix
  index, IP allowlist, expires_at, is_active). Generátor klíče musí
  produkovat klíče, které AuthMiddleware umí přijmout.
- **`modules/core/system/tables/core_system_api_keys.jsonc`** — DB
  schéma cílové tabulky. Bez změn — Phase 1 neukládá nic navíc.
- **`bin/shpd-ds`** — registrace existujících příkazů Symfony Console.
  Sem se zaregistrují tři nové.

Sekundární kontext:

- **`modules/core/mail/src/MailRouterProvisioner.php`** — ukazuje
  `ensureRouterUser()` pattern. Pro generic `api-key-create` se tento
  pattern nevyužije (user už existuje, neresolvujeme).

## Co implementovat

### 1. `ApiKeyService` — sdílená logika

Nový soubor **`src/Service/ApiKeyService.php`** (nebo
`src/Core/Security/ApiKeyService.php` — viz Otevřené body 2 pro umístění).
Centralizuje generování, ukládání, vyhledávání a deaktivaci klíčů.

```php
final class ApiKeyService
{
    public function __construct(private readonly DataSourceConnection $db) {}

    /**
     * Vygeneruje nový API klíč pro daného uživatele. Vrací plaintext
     * (zobrazí se uživateli jednou) — v DB se ukládá jen SHA-256 hash.
     *
     * @param int                $userId       FK na core_system_users.id
     * @param string             $name         Popisek (např. "import-from-old-shipard")
     * @param string[]           $allowedIps   IPv4/IPv6 adresy, prázdné pole = bez restrikce
     * @param \DateTimeImmutable|null $expiresAt null = bez expirace
     *
     * @return array{plaintext: string, id: int, keyPrefix: string}
     */
    public function createKey(
        int $userId,
        string $name,
        array $allowedIps = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): array;

    /**
     * Vyhledá klíče per filtr.
     *
     * @param int|null  $userId           null = všechny userace
     * @param bool      $includeInactive  default false = jen aktivní
     * @return array<int, array<string, mixed>>  rows joinované s core_system_users (login)
     */
    public function listKeys(?int $userId = null, bool $includeInactive = false): array;

    /**
     * Vyhledá uživatele podle login/email/ID. Vrací row z core_system_users,
     * nebo null pokud nenalezen / ambiguous.
     */
    public function findUser(string $loginOrEmail): ?array;

    /**
     * Deaktivuje klíč (is_active = 0). Idempotentní — když je už neaktivní,
     * vrátí false; když existuje a deaktivuje se, vrátí true.
     */
    public function revokeKey(int $keyId): bool;

    /**
     * Vyhledá klíč podle key_prefix. Vrací row nebo null. Pokud víc klíčů
     * sdílí prefix (nepravděpodobné, ale teoreticky možné), vrací první
     * podle id ASC — caller musí být toho vědom (viz Otevřené body 3).
     */
    public function findKeyByPrefix(string $keyPrefix): ?array;

    /**
     * Generuje plaintext token ve formátu `shpd_ak_` + 32 hex chars.
     * Statický helper, přesunutý z MailRouterSetupCommand::generateToken().
     */
    public static function generateToken(): string;
}
```

**Implementační poznámky:**

- `createKey` insertuje row s `is_active = 1`, `last_used_at = NULL`,
  `created` / `modified` = `NOW()`. Pokud `allowedIps` je prázdné pole,
  ukládá `NULL` (ne `"[]"`); pokud má prvky, JSON encode jako array.
- `findUser` — exact match nejprve na `login`, pak na `email`. Pokud
  `loginOrEmail` je čistě numerický řetězec, zkusit i match na `id`.
  Ambiguous match (víc shod různými cestami) vrátí `null` a caller
  reportuje chybu.
- `revokeKey` — UPDATE `is_active = 0`, `modified = NOW()`. Nikdy
  nemaže row — jen deaktivuje.
- **Nesazí** `expires_at` při kontrole. Pokud `expiresAt` je v minulosti,
  klíč se vytvoří jako neexpirovaný a v DB skončí s `expires_at < NOW()`
  — AuthMiddleware ho při použití odmítne, ale CLI to nezakazuje (může
  to být úmyslné pro tests / debug). Pouze validate validity formátu
  data v CLI (`api-key-create`), ne v service.

**Constants:**

```php
private const TOKEN_PREFIX = 'shpd_ak_';
private const KEY_PREFIX_LENGTH = 12;
private const TOKEN_RANDOM_BYTES = 16;  // → 32 hex chars
```

### 2. `api-key-create` Command

Nový soubor **`src/Command/DataSource/ApiKeyCreateCommand.php`**.

Příkaz vytvoří nový API klíč pro existujícího uživatele.

**Opce:**

| Opce | Vyžadováno | Popis |
|---|---|---|
| `--user` | ano | Login, email nebo numerické ID uživatele. Pokud ambiguous, command selže s chybou. |
| `--name` | ano | Lidsky čitelný popisek (např. "import-from-old-shipard"). Max 100 znaků. Není unique per user. |
| `--ip` | ne | Povolená IP. Multi-value — lze opakovat (`--ip=1.2.3.4 --ip=5.6.7.8`) nebo dát comma-separated (`--ip=1.2.3.4,5.6.7.8`). Validace přes `filter_var(..., FILTER_VALIDATE_IP)`. Bez opce = bez IP restrikce. |
| `--expires` | ne | Datum expirace. Akceptuje `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS`, nebo relative `+30d` / `+1y` (resolveno přes `DateTimeImmutable::modify`). Bez opce = bez expirace. |

**Argumenty:** žádné.

**Výstup (success):**

```
API Key created for data source <ds_id>:

    shpd_ak_aabbccdd1122334455667788aabbccdd

IMPORTANT: This is the only time this key will be displayed.

User:         alice (id=5)
Key name:     import-from-old-shipard
Key ID:       42
Key prefix:   aabbccdd1122
Allowed IPs:  1.2.3.4, 5.6.7.8
Expires:      2026-12-31 23:59:59
Created:      2026-05-27 14:30:00
```

Pokud `--ip` / `--expires` nebyly zadány, vypsat `(none)` / `(never)`.

**Výstup (failure):**

- Neexistuje DS adresář (`config/main.json` missing) → exit 1 s zprávou
  jako u existujících commandů.
- `--user` / `--name` chybí → exit 1 s "Option --X is required".
- User nenalezen → exit 1 s `Error: User '<loginOrEmail>' not found.`
- User ambiguous (např. login a email se prolnuly) → exit 1 s
  `Error: User '<value>' is ambiguous — use --user=<id> to disambiguate.`
- `--ip` invalid format → exit 1 s `Error: '<value>' is not a valid IP address.`
- `--expires` invalid format → exit 1 s informativní zprávou jaké formáty
  akceptuje.

**Implementace:** thin wrapper nad `ApiKeyService`. Resolve user, validate
opce, zavolat `createKey()`, render output. Sleduj strukturu
`MailRouterSetupCommand` (validace → service call → output rendering).

### 3. `api-key-list` Command

Nový soubor **`src/Command/DataSource/ApiKeyListCommand.php`**.

Listuje API klíče v DS.

**Opce:**

| Opce | Vyžadováno | Popis |
|---|---|---|
| `--user` | ne | Login, email nebo ID uživatele. Filtruje jen klíče tohoto usera. Bez opce = klíče všech userů. |
| `--include-inactive` | ne | Boolean flag. Bez něj se listují jen aktivní klíče. |

**Výstup:** tabulka s sloupci v tomto pořadí:

```
ID   USER         NAME                     PREFIX        ACTIVE  EXPIRES              LAST USED            CREATED
---  -----------  -----------------------  ------------  ------  -------------------  -------------------  -------------------
42   alice        import-from-old-shipard  aabbccdd1122  yes     2026-12-31 23:59:59  2026-05-27 16:42:00  2026-05-27 14:30:00
17   _mail_route  mail-router              ddeeff334455  yes     (never)              2026-05-27 17:00:00  2026-04-01 09:00:00
12   _ai_analyze  ai-analyzer              33445566aa77  yes     (never)              2026-05-27 17:00:05  2026-04-01 09:01:00
```

USER je `login` z `core_system_users` (truncate na 11 znaků pokud delší,
přidej `…`). EXPIRES / LAST USED / CREATED jsou v DS timezone (jak je
v DB).

Pokud žádný klíč nematchne filtru, výstup je `No API keys found.` (resp.
`No active API keys found.` pokud byl použit default filter).

Tabulkový rendering: použij `Symfony\Component\Console\Helper\Table` —
existuje a stačí ho instanciovat z OutputInterface. Sleduj
[Symfony docs](https://symfony.com/doc/current/components/console/helpers/table.html);
příkladová implementace by se měla vejít do ~20 řádek.

Allowed IPs **nezobrazuj v listu** — moc široký sloupec. Pro detail
použij budoucí `api-key-show` (mimo Phase 1).

### 4. `api-key-revoke` Command

Nový soubor **`src/Command/DataSource/ApiKeyRevokeCommand.php`**.

Deaktivuje API klíč (`is_active = 0`). Nemaže ho z DB — auditní stopa
zůstává.

**Opce (přesně jeden je vyžadovaný):**

| Opce | Popis |
|---|---|
| `--id` | Numerické ID klíče (`core_system_api_keys.id`). Preferovaný způsob. |
| `--prefix` | `key_prefix` (12 znaků). Pokud několik klíčů sdílí prefix (vzácné, ale teoreticky možné), command selže s chybou a vyzve k použití `--id`. |

**Opce (volitelné):**

| Opce | Popis |
|---|---|
| `--yes` | Bez interaktivního potvrzení. Defaultně command zobrazí info o klíči a požádá o potvrzení `y/N` přes `QuestionHelper`. |

**Výstup (success):**

```
About to revoke this API key:

  ID:           42
  User:         alice (id=5)
  Name:         import-from-old-shipard
  Prefix:       aabbccdd1122
  Created:      2026-05-27 14:30:00
  Last used:    2026-05-27 16:42:00

Proceed? [y/N]: y

API key revoked. Active = 0.
```

**Výstup (idempotentní opakovaný revoke):**

Pokud klíč už neaktivní:

```
API key already revoked (revoked at 2026-05-27 15:00:00). No changes made.
```

`modified` z DB row interpretuj jako čas revoke pokud `is_active = 0`
(je to last change time — pro Phase 1 dostatečné aproximace).

**Výstup (failure):**

- Klíč nenalezen → exit 1.
- `--prefix` ambiguous → exit 1, vyzvi k `--id`.
- Neexistuje ani `--id` ani `--prefix` → exit 1.

### 5. Registrace v `bin/shpd-ds`

Přidat do registrační sekce:

```php
$app->add(new \Shipard\Command\DataSource\ApiKeyCreateCommand());
$app->add(new \Shipard\Command\DataSource\ApiKeyListCommand());
$app->add(new \Shipard\Command\DataSource\ApiKeyRevokeCommand());
```

Pozice v souboru: za `UserCreateCommand`, ať jsou user-related commandy
pohromadě.

### 6. Dokumentace

Aktualizovat **`docs/cli.md`** — sekce o `shpd-ds` příkazech. Přidej
podsekce `api-key-create`, `api-key-list`, `api-key-revoke` s opcemi a
příklady.

Pokud `docs/cli.md` má existující strukturu typu "User management",
přidej nový blok "API key management" s analogickou strukturou. Sleduj
formátování existujících sekcí.

### 7. Tests

#### 7.1 Unit testy

**`tests/Unit/Service/ApiKeyServiceTest.php`** (nebo
`tests/Unit/Core/Security/ApiKeyServiceTest.php` podle umístění):

- `createKey()` — vrátí plaintext s prefixem `shpd_ak_`, hashe se neukládá
  plaintext, key_prefix odpovídá prvním 12 znaků za prefixem. Insert
  rowu se správným user_id, name, allowed_ips JSON, expires_at, is_active=1.
- `createKey()` s prázdným `allowedIps` → ukládá `NULL`, ne `"[]"`.
- `createKey()` s nullable `expiresAt` → ukládá `NULL`.
- `listKeys(null, false)` → jen aktivní klíče, joinované s users.
- `listKeys(5, true)` → klíče usera 5 včetně neaktivních.
- `findUser()` — match po login / email / numeric id. Ambiguous → null.
- `revokeKey()` — UPDATE is_active=0, vrátí true. Druhé volání → false.
- `findKeyByPrefix()` — match podle key_prefix.
- `generateToken()` — formát validní (regex match `^shpd_ak_[0-9a-f]{32}$`),
  random (2 calls vrátí různé hodnoty).

#### 7.2 Integration testy commandů

**`tests/Integration/Command/ApiKeyCreateCommandTest.php`**:

- Happy path — vytvořit usera, spustit command, ověř že existuje row
  v `core_system_api_keys`, hash matchne SHA-256 plaintextu, key_prefix
  matchne prvních 12 znaků.
- `--user` resolve přes login, email, numeric ID — všechny tři varianty.
- `--user` nenalezen → exit 1, žádný insert.
- Multi `--ip` — výsledný `allowed_ips` JSON má víc prvků.
- `--expires=+30d` resolve na dnešní datum + 30 dní (±5s tolerance).
- `--expires=invalid` → exit 1.

**`tests/Integration/Command/ApiKeyListCommandTest.php`**:

- Default (bez opcí) → jen aktivní klíče. Neaktivní v DB se nezobrazí.
- `--include-inactive` → zobrazí oba.
- `--user=alice` → jen alicein klíče.
- Žádné klíče → `No API keys found.` na výstupu.

**`tests/Integration/Command/ApiKeyRevokeCommandTest.php`**:

- `--id=42` interaktivně → vyzve k potvrzení, po `y` deaktivuje (is_active=0).
- `--id=42 --yes` → bez interakce deaktivuje.
- `--prefix=aabbccdd1122` → matchne podle prefixu, deaktivuje.
- `--prefix` s ambiguous match → exit 1, žádná změna.
- Opakovaný revoke → idempotentní zpráva "already revoked".

#### 7.3 Smoke test celého flow

**`tests/Integration/Command/ApiKeyFullCycleTest.php`** — kombinovaný test:

1. Vytvořit usera `alice`.
2. `api-key-create --user=alice --name=test` → zachyť plaintext z výstupu.
3. Ověř, že AuthMiddleware (mock request) plaintext akceptuje a vrátí
   `AuthContext` se správným user_id.
4. `api-key-list --user=alice` → row se zobrazí, `ACTIVE = yes`.
5. `api-key-revoke --id=<id> --yes`.
6. AuthMiddleware ten samý plaintext odmítne (`is_active = 0`).
7. `api-key-list --user=alice` → bez `--include-inactive` neukáže nic.
8. `api-key-list --user=alice --include-inactive` → ukáže s `ACTIVE = no`.

## Hotovo když

1. **`ApiKeyService`** existuje, má všech 6 metod z sekce 4.1 a unit testy
   prochází.
2. **`api-key-create`** registrovaný v `bin/shpd-ds`. Akceptuje
   `--user`, `--name`, `--ip` (multi), `--expires` (relative i absolute).
   Vrací plaintext klíče s prefixem `shpd_ak_` + 32 hex chars.
3. **`api-key-list`** registrovaný. Default filter = jen aktivní.
   `--user` filter funguje. `--include-inactive` zobrazí i neaktivní.
   Output je tabulkový.
4. **`api-key-revoke`** registrovaný. Interaktivní potvrzení (přepsatelné
   `--yes`). Akceptuje `--id` nebo `--prefix`. Idempotentní pro
   už revokované klíče.
5. **AuthMiddleware nezměněn** — klíče vytvořené přes nový command
   jsou bit-kompatibilní s těmi z `mail-router-setup` / `ai-analyzer-setup`.
6. **`docs/cli.md`** aktualizovaný s novými příkazy.
7. **Tests** — unit testy `ApiKeyService` + integration testy tří commandů
   + smoke test full cycle všechno prochází.
8. **Stávající testy `MailRouterSetupCommand` / `AiAnalyzerSetupCommand`**
   prochází beze změny — Phase 1 nerefaktoruje existující commandy
   (viz Otevřené body 1).

## Doporučené pořadí implementace

1. **`ApiKeyService`** — vytvořit, naimplementovat všech 6 metod, unit testy.
2. **`ApiKeyCreateCommand`** — implementovat + integration test. Smoke
   test s reálnou DS DB.
3. **`ApiKeyListCommand`** — implementovat + test.
4. **`ApiKeyRevokeCommand`** — implementovat + test.
5. **Registrace v `bin/shpd-ds`** — přidat tři řádky.
6. **`docs/cli.md`** — aktualizovat.
7. **Smoke test full cycle** — ověř integraci s AuthMiddleware.

Po každém kroku spustit relevantní testy.

## Otevřené body / rozhodnutí

### 1. Refactor `MailRouterSetupCommand` / `AiAnalyzerSetupCommand`

`ApiKeyService` má `createKey()` metodu, která duplikuje logiku v
`MailRouterSetupCommand::execute()` (zhruba řádky 65–115). Refactor
existujících commandů na použití service je čistá cesta, ale vyžaduje:

- Měnit dva existující commandy.
- Spustit a ověřit jejich existující testy.
- Zachovat backwards-compat chování (one-active-key-per-name semantika
  zůstává v command vrstvě, ne v service).

**Doporučení:** Phase 1 NE-refactoruje. Service pojede paralelně.
Refactor zařadit jako Phase 2 follow-up, až bude důvěra v service ze
samostatných testů. Důvod: chceme tento task uzavřít rychle, generic
toolset stačí pro import flow.

Pokud při implementaci uvidíš, že refactor je triviální (5 minut), můžeš
ho udělat — ale beru to jako bonus, ne required item.

### 2. Umístění `ApiKeyService`

Dvě možnosti:

- **`src/Service/ApiKeyService.php`** — generic service vrstva v rootu
  src/. Konzistentní s existujícími `src/Api/`, `src/Command/` plochými
  namespaci.
- **`src/Core/Security/ApiKeyService.php`** — pod Core/Security, kam by
  postupně mohly migrovat security-relevantní helpery (rate limiting,
  audit logging, atd.).

Tento projekt zatím nemá ustavený pattern pro service vrstvu (autori
commandů dělají vše inline). Pro Phase 1 doporučuji
**`src/Service/ApiKeyService.php`** — menší ambice, snadnější refactor
později. Pokud uvidíš v kódu hint, že `src/Core/Security/` už je
zavedený namespace, použij ten.

### 3. `findKeyByPrefix` ambiguity

`key_prefix` má index v DB, ale není unique. Teoreticky se dvě generování
mohou strefit do stejných 12 znaků (pravděpodobnost ~`1/(16^12)` ≈
`3.5 × 10^-15` per pair, ale ne nemožné při milionech klíčů).

Pro Phase 1 `findKeyByPrefix` vrátí první match podle `id ASC`. Command
`api-key-revoke --prefix` zjistí ambiguity přes COUNT(*) a vrátí chybu,
když matchne víc než 1. To je dostatečně bezpečné — uživatel dostane
informativní hlášku.

### 4. Validation `--expires=+30d` formátu

PHP `DateTimeImmutable::modify('+30d')` akceptuje široký range relative
formátů. Pro Phase 1 nepokoušej se omezit, ať uživatel může používat
`+30d`, `+1 year`, `next monday`, atd. — co PHP akceptuje, akceptuj.

Validuj jen, že výsledné datetime je validní (`new DateTimeImmutable`
nevyhodí exception).

### 5. Output formát — JSON variant?

`api-key-list` v Phase 1 produkuje jen tabulkový output pro lidi. Pro
scripting by se hodil `--format=json` flag. Phase 1 to nedělá — pokud
bude potřeba, dodá se zvlášť. Komentář v kódu naznačit, kde by se
hodilo přidat.

### 6. Logging audit trail

Vytvoření a revoke API klíčů je security-relevant akce. Phase 1
**nelogguje** do dedikovaného audit trail (žádný takový subsystém zatím
neexistuje). DB row v `core_system_api_keys` s `created` / `modified`
sloupcem slouží jako audit aproximace.

Plně audit logging je Phase 2 follow-up, mimo scope.

### 7. Concurrency při `createKey`

Mezi `findUser` a `insertRow` existuje teoretická race condition — user
by se mohl deaktivovat / smazat. Phase 1 to neřeší (DB foreign key
constraint na `user_id` ho zachytí, command pak vrátí generic DB error).
Pro bullet-proof variantu by se selectoval `core_system_users` s
`is_active = 1` filter a transakce by obalovala obojí — Phase 2.
