# Modul `mail` — Fáze 2a: API endpoint `/_mail/incoming`

**Stav:** hotovo

**Cíl fáze:** Implementovat HTTP endpoint pro příjem došlé pošty z externí služby `shipard-mail-router`. Rozhodnutí z designové fáze jsou v konverzaci ze 17. 4. 2026.

**Návaznost:**
- Navazuje na Fázi 1 (`tasks/mail-phase1.md`)
- Proti tomuto endpointu se vyvíjí Fáze 2b (mail-router daemon, samostatný repozitář)

---

## 1. Scope

**V rozsahu:**

- Endpoint `POST /_mail/incoming` (multipart/form-data)
- Schema změny:
  - `core_mail_mailboxes.is_default` (nový sloupec)
  - Nová tabulka `core_mail_incoming_idempotency`
- Auto-provisioning default schránky a systémového uživatele při `DsCreate`
- CLI příkaz `mail-router-setup` (generuje API klíč pro mail-router)
- Reuse `core_system_api_keys` — žádná nová token infrastruktura
- Integrační testy přes curl + multipart

**Mimo rozsah:**

- Scope restrictions na API klíče (follow-up)
- Samotný mail-router daemon (Fáze 2b, samostatný repo)
- Antivir scan na shpd straně — proběhne v mail-routeru před odesláním
- Automatické matchování odesílatele na `base_persons_persons` (odloženo z F1)

---

## 2. API kontrakt

### 2.1 Request

```
POST /_mail/incoming
Authorization: Bearer shpd_ak_xxxxxxxxxxxxxxxxxxxxxxxx
X-Idempotency-Key: <sha256 hex string>
Content-Type: multipart/form-data; boundary=...
```

**Form fields:**

| Field                  | Typ       | Pov.? | Popis                                                   |
|------------------------|-----------|-------|---------------------------------------------------------|
| `mailbox`              | string    | ne    | `mailbox_id` (např. `invoices`). Prázdné → default.     |
| `external_message_id`  | string    | ne    | RFC822 Message-ID                                        |
| `received_at`          | ISO8601   | ano   | `2026-04-18T14:32:00+02:00`                             |
| `subject`              | string    | ne    | Prázdné → `(bez předmětu)`                              |
| `sender_email`         | string    | ano   | Validace RFC 5321 local-part@domain                     |
| `sender_name`          | string    | ne    | Display name z `From:` hlavičky                         |
| `body_plain`           | text      | ne    |                                                          |
| `body_html`            | text      | ne    |                                                          |
| `in_reply_to`          | string    | ne    | RFC822 hlavička                                         |
| `reply_references`     | string    | ne    | RFC822 hlavička (whitespace-separated)                  |
| `source_type`          | int       | ne    | Default 2 (email). Router nemění.                        |
| `raw_source`           | file      | ano   | Originální `.eml` (uloží se jako attachment)            |
| `attachments[]`        | file      | ne    | 0..N příloh                                             |

### 2.2 Response

**Úspěch:**

```
HTTP/1.1 201 Created
Content-Type: application/json

{
  "success": true,
  "data": {
    "ndx": 12345,
    "message_id": "MSG-20260418-0001",
    "idempotent_replay": false
  }
}
```

Při retry se stejným `X-Idempotency-Key` během TTL vrátí stejná odpověď s `idempotent_replay: true`. Status code stále **201** (ne 200 — klient nemá rozlišovat).

**Validační chyba:**

```
HTTP/1.1 422 Unprocessable Entity
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Schránka 'foo' neexistuje v tomto DS",
    "details": { "field": "mailbox" }
  }
}
```

**Auth chyba:**

```
HTTP/1.1 401 Unauthorized
{ "success": false, "error": { "code": "UNAUTHORIZED", "message": "..." } }
```

**Server chyba:**

```
HTTP/1.1 500 Internal Server Error
```

Při `5xx` router retryuje, při `4xx` ne (jde do dead-letteru).

### 2.3 Idempotency

- Klient generuje `X-Idempotency-Key` deterministicky z `sha256(domain + "/" + local_part + "/" + external_message_id)`.
  Pokud `external_message_id` chybí, klient nesmí posílat idempotency key (nebo ho generuje náhodně). Server v takovém případě idempotenci nevynucuje.
- Server při příjmu requestu:
  1. Pokud `X-Idempotency-Key` existuje → lookup v `core_mail_incoming_idempotency`
  2. Match → vrátí uloženou odpověď s `idempotent_replay: true`
  3. Neúspěch → pokračuje ve zpracování; po úspěšném vytvoření zapíše key + response do tabulky
- TTL: **7 dní**. Cleanup přes cron/CLI `mail-idempotency-prune` (Task 6).

### 2.4 Rozlišení schránky

```
mailbox field → core_mail_mailboxes.mailbox_id lookup
  match → použije se
  no match → 422 Unprocessable Entity
  empty/missing → default schránka (is_default = true)
  no default configured → 422 s error "DS has no default mailbox"
```

---

## 3. Schema změny

### 3.1 `core_mail_mailboxes.is_default`

Nový boolean sloupec, default `false`. Per-DS smí mít nejvýš jedna schránka `is_default = true` — vynuceno **partial unique index**:

```
CREATE UNIQUE INDEX unq_mail_mailboxes_default
ON core_mail_mailboxes (is_default)
WHERE is_default = true;
```

(MySQL/MariaDB: via functional index nebo aplikační validace v `MailboxDocument::beforeSave`.)

Migrace: `DsUpgrade` přidá sloupec, žádné auto-populate — seed data v Task 7 nastaví.

### 3.2 `core_mail_incoming_idempotency`

```jsonc
{
  "tableId": 304,
  "name:cs": "Idempotency klíče pro došlou poštu",
  "columns": [
    { "id": "id", "type": "int", "autoIncrement": true, "primaryKey": true },
    { "id": "idempotency_key", "type": "varchar", "length": 64, "nullable": false },
    { "id": "message_ndx", "type": "int", "nullable": false,
      "reference": "core_mail_incoming_messages" },
    { "id": "response_body", "type": "text", "nullable": false },
    { "id": "created", "type": "datetime", "nullable": false }
  ],
  "indexes": [
    { "id": "unq_idempotency_key", "type": "unique",
      "columns": [{ "column": "idempotency_key" }] },
    { "id": "idx_created", "type": "index",
      "columns": [{ "column": "created" }] }
  ]
}
```

---

## 4. Systémový uživatel + default schránka (auto-provisioning)

### 4.1 Při `DsCreate`

Rozšířit `DsCreateCommand` tak, aby po vytvoření schématu založil:

1. **Systémového uživatele `_mail_router`**
   - `login = '_mail_router'`
   - `full_name = 'Mail Router (system)'`
   - `email = null`
   - Heslo: náhodný 64-znakový hash, uživatel se nikdy nepřihlašuje interaktivně
   - Flag `is_system = true` (pokud sloupec existuje; ověřit ve schématu `core_system_users`, případně přidat)

2. **Default schránku**
   - `mailbox_id = 'default'`
   - `name = 'Hlavní schránka'`
   - `email_address = '<ds-hash-id>@shipard.email'` (viz design pro primární adresu)
   - `is_default = true`
   - `default_primary_type = 'other'`
   - `docState = 40` (V pořádku)

### 4.2 Existující DS

Pro DS založené před Fází 2a: CLI `mail-router-bootstrap` udělá totéž idempotentně (ověří existenci, vytvoří chybějící). Spouští se ručně při upgrade.

---

## 5. CLI příkazy

### 5.1 `bin/shpd-ds mail-router-setup`

Vytvoří (nebo rotuje) API klíč pro `_mail_router` uživatele.

```
Usage: shpd-ds mail-router-setup [--force]

Flags:
  --force    Rotate key even if one already exists (old key becomes inactive)

Output:
  API Key created for data source abcd-efgh-ijkl-mnop:

    shpd_ak_AbCdEf1234567890xxxxxxxxxxxxxxxxxxxx

  IMPORTANT: This is the only time this key will be displayed.
  Store it in /etc/shipard-mail-router/lookup.json.
```

Implementační detaily:
- Ověří existenci `_mail_router` uživatele (pokud chybí, založí ho)
- Pokud existuje aktivní klíč a není `--force`, selže s chybou
- Generuje klíč podle stávajícího pattern (`shpd_ak_` + 32 hex)
- `name = 'mail-router'`
- Volitelně: `allowed_ips = ['<ip mail-routeru>']` přes flag `--ip`

### 5.2 `bin/shpd-ds mail-router-bootstrap`

Idempotentní bootstrap pro existující DS (viz §4.2).

### 5.3 `bin/shpd-ds mail-idempotency-prune`

```
Usage: shpd-ds mail-idempotency-prune [--days N]

Defaults:
  --days 7    Remove idempotency keys older than N days

Output:
  Removed 1234 idempotency keys older than 7 days.
```

Spouštět z cronu 1×/den.

---

## 6. Implementace endpointu

### 6.1 Registrace

`src/Api/Router.php` — přidat routu:

```php
$routes[] = new Route('POST', '/_mail/incoming', 'mail', 'receiveIncoming');
```

### 6.2 Controller

Nový: `src/Api/Controller/MailController.php`.

```php
class MailController
{
    public function __construct(
        private DataSourceConnection $db,
        private string $dsPath,
        private array $tables,
    ) {
        $this->attachmentService = new AttachmentService($db, $dsPath, $tables);
        $this->documentRegistry = ...;
    }

    public function receiveIncoming(AuthContext $auth, Request $request): Response
    {
        // 1. Validate auth — required (no anonymous)
        // 2. Validate idempotency key (if present) — lookup & early-return
        // 3. Validate form fields
        // 4. Resolve mailbox (explicit or default)
        // 5. Upload raw_source → attachment
        // 6. Create IncomingMessage document (via DocumentRegistry)
        // 7. Upload attachments[] → attachments table
        // 8. Store idempotency response (if key provided)
        // 9. Return 201
    }
}
```

### 6.3 Flow (normální case)

```
Router POSTs multipart
  → AuthMiddleware validuje shpd_ak_ token → AuthContext(user=_mail_router)
  → MailController::receiveIncoming
    → Idempotency lookup (pokud X-Idempotency-Key)
    → Parse & validate form fields
    → Resolve mailbox
    → BEGIN transaction
      → AttachmentService::upload(raw_source) → attachment_ndx
      → IncomingMessageDocument::create([...headers, raw_source_attachment: ndx, docState: 10])
        → beforeSave generuje message_id
        → TableGateway uloží
      → Pro každou attachments[]:
        → AttachmentService::upload(file, table=303, record=message_ndx)
      → Idempotency záznam (pokud key)
    → COMMIT
  → Response 201
```

### 6.4 Flow (error handling)

- **Auth failure** → `AuthMiddleware` vrátí 401 sám, controller se nespustí
- **Mailbox neexistuje** → 422 s `field: "mailbox"`
- **Validační chyba** → 422 s polem
- **DB chyba uvnitř tx** → rollback, 500
- **Attachment upload fail** (disk plný, corrupted file) → rollback, 500

Atomic guarantee: zprávu + všechny přílohy buď uložíme celé, nebo nic (PHP tx wraps AttachmentService volání). `FileStorage` ukládá soubory mimo DB — po rollbacku je potřeba orphan files cleanup; existující `AttachmentService` už toto řeší v `cleanup()` metodě (ověřit, případně doplnit).

### 6.5 Limity

- `message_size_limit` — žádný tvrdý limit v shpd (Postfix v mail-routeru odřízne 25 MB dřív)
- `attachment count` — žádný, ale logujeme `count > 50` jako warning
- `body_plain/body_html` — v DB `longtext`, žádný limit
- Per-request timeout: PHP-FPM default (typicky 30 s). Pro velké maily (20 MB) může být těsný; zvýšit dle potřeby v deploymentu, ne v kódu.

---

## 7. Task breakdown

### Task 1 — Schema: `is_default` + idempotency table

- Přidat sloupec `is_default` do `core_mail_mailboxes.jsonc`
- Přidat partial unique constraint (nebo aplikační validaci v `MailboxDocument`)
- Nová tabulka `core_mail_incoming_idempotency.jsonc` (tableId 304)
- Migrace `DsUpgrade` testována na čerstvém DS i existujícím (s daty z F1)
- Markdown docs pro obě

**Akceptace:** `DsUpgrade` proběhne bez chyby, constraint funguje (test: dva `is_default=true` v jednom DS selžou).

### Task 2 — Systémový uživatel `_mail_router`

- Ověřit, zda `core_system_users` má flag `is_system` — pokud ne, přidat
- Rozšířit `DsCreateCommand` o vytvoření `_mail_router` uživatele
- CLI `mail-router-bootstrap` pro existující DS

**Akceptace:** Čerstvý DS má uživatele; `mail-router-bootstrap` na existujícím DS ho doplní idempotentně.

### Task 3 — Auto-provisioning default mailboxu

- Rozšíření `DsCreateCommand` (nebo `FakeMailboxGenerator` pattern) o vytvoření default mailboxu
- Email adresa: `<ds-id>@shipard.email` (šablona konfigurovatelná?  Pro MVP hardcoded.)
- Bootstrap pro existující DS — součást `mail-router-bootstrap`

**Akceptace:** Nový DS má default mailbox s `is_default = true`.

### Task 4 — CLI `mail-router-setup`

- Generování `shpd_ak_` klíče (reuse existing logiku z uživatelských API klíčů, ověřit v `core_system_api_keys`)
- Ukládá hash, zobrazuje plaintext jednou
- `--force` pro rotaci (stávající klíč → `is_active = false`)
- `--ip` pro IP whitelist

**Akceptace:** Volání vytvoří klíč, lze jím autentizovat proti `/_mail/incoming`.

### Task 5 — MailController + routa

- Nový `src/Api/Controller/MailController.php`
- Registrace routy v `Router.php`
- Implementace podle §6.2–6.4
- Interní jednotkové testy

**Akceptace:** Unit testy zelené. Mock AuthContext, mock DB, ověření že se volají správné service metody.

### Task 6 — Idempotency middleware/logika + prune

- Helper `IdempotencyStore` (lookup, store)
- Integrace v `MailController`
- CLI `mail-idempotency-prune`

**Akceptace:** Dvojí POST se stejným klíčem vrátí stejnou odpověď, jen druhá má `idempotent_replay: true`. Prune maže staré záznamy.

### Task 7 — Integrační testy

End-to-end přes curl, v `tests/Integration/MailEndpointTest.php`:

1. Happy path: POST s raw .eml + 2 přílohami → 201 + DB ověření
2. Idempotent retry: druhý POST se stejným key → 201 replay
3. Validační chyba: chybějící `sender_email` → 422
4. Neznámá schránka → 422
5. Neplatný token → 401
6. Default mailbox použit, když `mailbox` field prázdný

Integrační testy vyžadují spuštěný shpd server — spec v `tests/Integration/README.md` jak je pouštět.

**Akceptace:** Všechny scénáře procházejí.

### Task 8 — Dokumentace

- `docs/mail/api-contract.md` — finální, bez "náčrt Fáze 2" označení
- Update `modules/core/mail/README.md` — sekce "API endpointy"
- Update `docs/doc-states.md` — pokud se cokoliv změnilo
- `modules/core/mail/docs/documentation.md` — doplnit sekci o Fázi 2a

---

## 8. Open decisions

1. **Doména default schránky** — hardcoded `@shipard.email`, nebo konfigurovatelné? Pro MVP hardcoded, konfigurace přes ENV proměnnou v `DataSourceConfig` je přirozené rozšíření.

2. **`is_system` flag u users** — jestli ještě neexistuje, stojí přidat? Alternativa: prefix `_` v loginu je konvence. Doporučuji sloupec, je čistší.

3. **Cleanup orphaned raw_source attachmentů** při rollbacku — ověřit, že `AttachmentService` toto umí. Pokud ne, dopsat (součást Task 5).

4. **Rate limit** na `/_mail/incoming` — zatím žádný vlastní (router je trusted). Případně využít `RateLimitMiddleware` jen jako pojistka proti chybě v routeru (např. 1000 req/min).
