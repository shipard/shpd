# Shipard — REST API

## 1. Přehled

Shipard poskytuje univerzální REST API generované automaticky z JSONC definic tabulek. Každá tabulka s definicí v modulovém systému automaticky získá sadu CRUD endpointů bez nutnosti psát kód pro každou tabulku zvlášť.

API dodržuje standardní REST konvence, používá JSON pro request/response body a poskytuje OpenAPI 3.1 specifikaci pro dokumentaci a generování klientů.

---

## 2. Multitenant architektura

Každý zdroj dat (data source) = jedna firma = jedna databáze = jedna subdoména.

### Mapování subdomény na zdroj dat

```
https://firma1.shipard.cz/api/v1/...  →  data source "firma1"
https://demo.shipard.cz/api/v1/...    →  data source "demo"
```

Subdoména se mapuje na ID zdroje dat přes konfigurační soubor `/etc/shipard/domains.json`:

```json
{
    "firma1.shipard.cz": "a3f2-b8c1-d4e7-f9a0",
    "demo.shipard.cz": "x9y8-w7v6-u5t4-s3r2"
}
```

### Nginx konfigurace

Jeden nginx server block s wildcard doménou směřuje všechny requesty na společný entry point:

```nginx
server {
    listen 443 ssl;
    server_name *.shipard.cz;

    root /opt/shipard/shpd/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_HOST $host;
        include fastcgi_params;
    }
}
```

### Entry point

Soubor `/opt/shipard/shpd/public/index.php` — společný pro všechny zdroje dat:

1. Přečte `HTTP_HOST` z requestu
2. Vyhledá ID zdroje dat v `domains.json`
3. Načte `config/main.json` daného zdroje dat
4. Připojí se k databázi zdroje dat
5. Zpracuje API request

Pozn.: Původně jsme uvažovali o www-root v `/opt/shipard/data-sources/{id}/www/`, ale pro API je efektivnější jeden společný entry point v codebase — eliminuje duplicitu a zjednodušuje deploy. Adresář `data-sources/{id}/www/` zůstává k dispozici pro budoucí statické soubory specifické pro zdroj dat (loga, exporty apod.).

---

## 3. URL struktura

```
https://{subdomena}.shipard.cz/api/v1/{tabulka}
```

### Konvence

- Verze API v URL: `/api/v1/`
- Název tabulky v URL odpovídá ID tabulky z JSONC definice (snake_case): `core_system_users`, `economy_docs_heads`
- Jednotné číslo se nepoužívá — název je vždy totožný s názvem DB tabulky

### Endpointy pro tabulku

| Metoda | URL | Popis |
|--------|-----|-------|
| `GET` | `/api/v1/{table}` | Seznam záznamů (s filtrováním, řazením, stránkováním) |
| `GET` | `/api/v1/{table}/{id}` | Jeden záznam podle ID |
| `POST` | `/api/v1/{table}` | Vytvoření záznamu |
| `PUT` | `/api/v1/{table}/{id}` | Úplná aktualizace záznamu |
| `PATCH` | `/api/v1/{table}/{id}` | Částečná aktualizace záznamu |
| `DELETE` | `/api/v1/{table}/{id}` | Smazání záznamu |

### Speciální endpointy

| Metoda | URL | Popis |
|--------|-----|-------|
| `GET` | `/api/v1/_meta/tables` | Seznam dostupných tabulek |
| `GET` | `/api/v1/_meta/tables/{table}` | Metadata tabulky (sloupce, typy, indexy) |
| `GET` | `/api/v1/_openapi.json` | OpenAPI 3.1 specifikace |
| `POST` | `/api/v1/_auth/login` | Přihlášení (email + heslo → token) |
| `POST` | `/api/v1/_auth/refresh` | Obnovení tokenu |
| `DELETE` | `/api/v1/_auth/logout` | Odhlášení (invalidace tokenu) |

---

## 4. Formát odpovědí

Všechny odpovědi používají konzistentní obálku (envelope). Důvody:

- Jednotný formát pro klienty — vždy víš, kde hledat data a kde chyby
- Místo pro metadata (stránkování, celkový počet) bez kolize s daty
- Snadné rozlišení úspěchu a chyby bez parsování HTTP kódu

### Úspěšná odpověď — kolekce

```json
{
    "success": true,
    "data": [
        {"id": 1, "login": "admin", "full_name": "Administrator"},
        {"id": 2, "login": "jan.novak", "full_name": "Jan Novák"}
    ],
    "meta": {
        "total": 42,
        "limit": 20,
        "offset": 0
    }
}
```

### Úspěšná odpověď — jeden záznam

```json
{
    "success": true,
    "data": {
        "id": 1,
        "login": "admin",
        "full_name": "Administrator",
        "email": "admin@example.com"
    }
}
```

### Úspěšná odpověď — vytvoření záznamu (201 Created)

```json
{
    "success": true,
    "data": {
        "id": 43,
        "login": "petra.svobodova",
        "full_name": "Petra Svobodová"
    }
}
```

### Chybová odpověď

Chybový formát vychází z RFC 7807 (Problem Details), adaptovaný pro obálku:

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "Validation failed",
        "details": [
            {
                "field": "email",
                "code": "REQUIRED",
                "message": "Field 'email' is required"
            },
            {
                "field": "login",
                "code": "UNIQUE",
                "message": "Value 'admin' already exists"
            }
        ]
    }
}
```

### HTTP stavové kódy

| Kód | Použití |
|-----|---------|
| `200` | Úspěšný GET, PUT, PATCH |
| `201` | Úspěšný POST (vytvořen záznam) |
| `204` | Úspěšný DELETE (bez těla) |
| `400` | Chybný request (malformed JSON, neplatné query parametry) |
| `401` | Chybí nebo neplatná autentizace |
| `403` | Nedostatečná oprávnění |
| `404` | Záznam nebo tabulka neexistuje |
| `409` | Konflikt (porušení UNIQUE indexu) |
| `422` | Validační chyba (chybějící povinná pole, neplatné hodnoty) |
| `429` | Rate limit překročen |
| `500` | Interní chyba serveru |

### Chybové kódy

| Kód | Popis |
|-----|-------|
| `VALIDATION_ERROR` | Validace vstupních dat selhala |
| `NOT_FOUND` | Záznam nebo tabulka neexistuje |
| `UNAUTHORIZED` | Chybí autentizace |
| `FORBIDDEN` | Nedostatečná oprávnění |
| `CONFLICT` | Porušení UNIQUE constraintu |
| `RATE_LIMITED` | Příliš mnoho požadavků |
| `BAD_REQUEST` | Malformed request |
| `INTERNAL_ERROR` | Neočekávaná chyba serveru |
| `TABLE_NOT_FOUND` | Tabulka neexistuje nebo není přístupná přes API |

---

## 5. Filtrování, řazení, stránkování

### Stránkování — offset-based

Pro většinu případů v účetním systému je offset-based stránkování vhodnější než cursor-based:

- Uživatelé potřebují přístup ke konkrétním stránkám ("chci stránku 5")
- Data se nemění tak rychle, aby offset způsoboval problémy
- Jednodušší implementace i použití

```
GET /api/v1/economy_docs_heads?limit=20&offset=40
```

| Parametr | Výchozí | Max | Popis |
|----------|---------|-----|-------|
| `limit` | 20 | 100 | Počet záznamů na stránku |
| `offset` | 0 | — | Přeskočit N záznamů |

Odpověď vždy obsahuje `meta.total` s celkovým počtem záznamů (po aplikaci filtrů).

### Řazení

```
GET /api/v1/economy_docs_heads?sort=issue_date:desc,doc_number:asc
```

Formát: `sort={sloupec}:{asc|desc}` — více sloupců oddělených čárkou. Výchozí řazení: `id:asc`.

Řadit lze pouze podle sloupců, které existují v definici tabulky. Neplatný sloupec → `400 Bad Request`.

### Filtrování

Filtry se zadávají přes query parametry s názvem sloupce a operátorem:

```
GET /api/v1/economy_docs_heads?filter[doc_state]=eq:1&filter[issue_date]=gte:2026-01-01
```

Formát: `filter[{sloupec}]={operátor}:{hodnota}`

| Operátor | Popis | Příklad |
|----------|-------|---------|
| `eq` | Rovná se | `filter[doc_state]=eq:1` |
| `neq` | Nerovná se | `filter[doc_state]=neq:0` |
| `gt` | Větší než | `filter[amount]=gt:1000` |
| `gte` | Větší nebo rovno | `filter[issue_date]=gte:2026-01-01` |
| `lt` | Menší než | `filter[amount]=lt:5000` |
| `lte` | Menší nebo rovno | `filter[issue_date]=lte:2026-12-31` |
| `like` | Obsahuje (LIKE %...%) | `filter[full_name]=like:Novák` |
| `in` | Hodnota je v seznamu | `filter[doc_state]=in:1,2,3` |
| `null` | Je NULL | `filter[note]=null:true` |
| `notnull` | Není NULL | `filter[note]=null:false` |

Více filtrů se kombinuje logickým AND.

Filtrovat lze pouze podle sloupců z definice tabulky. Neplatný sloupec → `400 Bad Request`.

### Výběr sloupců

```
GET /api/v1/core_system_users?fields=id,login,full_name,email
```

Vrátí pouze vybrané sloupce. Sloupec `id` je vždy zahrnut, i když není uveden. Neplatný sloupec → `400 Bad Request`.

---

## 6. Autentizace

API podporuje dva způsoby autentizace.

### API klíče

Statické tokeny pro strojové integrace (M2M). Ukládají se v tabulce `core_system_api_keys`.

```
GET /api/v1/core_system_users
Authorization: Bearer shpd_ak_a3f2b8c1d4e7f9a0...
```

Prefix `shpd_ak_` jednoznačně identifikuje API klíč (odlišení od session tokenu).

Vlastnosti API klíče:
- Vázaný na konkrétní zdroj dat
- Volitelný datum expirace
- Volitelné omezení na IP adresy
- Přiřazen k uživateli (pro audit log)
- Lze deaktivovat bez smazání

### Session token (login)

Pro interaktivní uživatele — přihlášení emailem a heslem, získání krátkodobého tokenu.

**Login:**

```
POST /api/v1/_auth/login
Content-Type: application/json

{
    "login": "jan.novak",
    "password": "heslo123"
}
```

Odpověď:

```json
{
    "success": true,
    "data": {
        "token": "shpd_st_eyJhbGciOiJIUzI1NiJ9...",
        "expires_at": "2026-03-16T15:30:00+01:00",
        "user": {
            "id": 2,
            "login": "jan.novak",
            "full_name": "Jan Novák"
        }
    }
}
```

Prefix `shpd_st_` identifikuje session token.

Token se posílá stejně jako API klíč:

```
GET /api/v1/core_system_users
Authorization: Bearer shpd_st_eyJhbGciOiJIUzI1NiJ9...
```

**Refresh:**

```
POST /api/v1/_auth/refresh
Authorization: Bearer shpd_st_eyJhbGciOiJIUzI1NiJ9...
```

Vrátí nový token s prodlouženou expirací. Starý token se invaliduje.

**Logout:**

```
DELETE /api/v1/_auth/logout
Authorization: Bearer shpd_st_eyJhbGciOiJIUzI1NiJ9...
```

Invaliduje token. Odpověď: `204 No Content`.

### Rozlišení typu tokenu

Server rozpozná typ tokenu podle prefixu:
- `shpd_ak_` → vyhledání v `core_system_api_keys`
- `shpd_st_` → vyhledání v `core_system_sessions`

Neplatný nebo expirovaný token → `401 Unauthorized`.

### Tabulka `core_system_api_keys`

Nová tabulka v modulu `core.system`:

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | int (PK) | — |
| `user_id` | int | Uživatel, pod kterým klíč operuje |
| `name` | varchar(100) | Popis klíče ("ERP integrace", "Mobilní app") |
| `key_hash` | varchar(255) | SHA-256 hash klíče (raw klíč se neukládá) |
| `key_prefix` | varchar(12) | Prvních 12 znaků pro identifikaci |
| `expires_at` | datetime | Datum expirace (null = neexpiruje) |
| `allowed_ips` | text | JSON pole povolených IP (null = bez omezení) |
| `is_active` | boolean | Aktivní/deaktivovaný |
| `last_used_at` | datetime | Poslední použití |
| `created` | datetime | — |
| `modified` | datetime | — |

---

## 7. Validace vstupů

API automaticky validuje vstupní data na základě definice tabulky.

### Pravidla odvozená z JSONC definice

| Vlastnost sloupce | Validační pravidlo |
|-------------------|--------------------|
| `nullable: false` (výchozí) | Pole je povinné při POST |
| `type: varchar`, `length: N` | Max délka N znaků |
| `type: int` / `smallint` / `bigint` | Musí být celé číslo v rozsahu typu |
| `type: numeric`, `precision`, `scale` | Musí být číslo, max číslic a desetinných míst |
| `type: boolean` | Musí být `true`/`false` nebo `0`/`1` |
| `type: date` | Formát `YYYY-MM-DD` |
| `type: datetime` | Formát `YYYY-MM-DD HH:MM:SS` nebo ISO 8601 |
| `type: time` | Formát `HH:MM:SS` |
| `type: json` | Musí být platný JSON |
| `type: enumInt` | Hodnota musí existovat v konfigurační položce (`cfgItem`) |
| `type: enumString` | Hodnota musí existovat v konfigurační položce (`cfgItem`) |
| index `type: unique` | Hodnota musí být unikátní (→ `409 Conflict`) |

### Automaticky spravovaná pole

Tato pole se nastavují automaticky a klient je nesmí posílat (budou ignorována):

| Pole | Chování |
|------|---------|
| `id` | Auto-increment, nelze nastavit |
| `created` | Nastaví se při POST na aktuální datetime |
| `modified` | Nastaví se při POST a PUT/PATCH na aktuální datetime |

### Příklad validační chyby

```
POST /api/v1/core_system_users
Content-Type: application/json

{
    "login": "",
    "email": "not-an-email"
}
```

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "Validation failed",
        "details": [
            {
                "field": "login",
                "code": "EMPTY",
                "message": "Field 'login' cannot be empty"
            },
            {
                "field": "full_name",
                "code": "REQUIRED",
                "message": "Field 'full_name' is required"
            },
            {
                "field": "password_hash",
                "code": "REQUIRED",
                "message": "Field 'password_hash' is required"
            }
        ]
    }
}
```

---

## 8. OpenAPI specifikace

API automaticky generuje OpenAPI 3.1 specifikaci z metadat tabulek.

### Endpoint

```
GET /api/v1/_openapi.json
```

### Přístupnost

Konfigurovatelné per data source v `config/main.json`:

```json
{
    "api": {
        "openApiPublic": true
    }
}
```

| Hodnota | Chování |
|---------|---------|
| `true` | OpenAPI spec je přístupná bez autentizace (výchozí pro dev mód) |
| `false` | Vyžaduje platný token (výchozí pro produkční mód) |

### Co se generuje

Pro každou tabulku dostupnou přes API:

- **Paths** — všech 6 CRUD endpointů
- **Schemas** — request/response schémata odvozená ze sloupců
- **Parameters** — filter, sort, limit, offset, fields
- **Security** — Bearer token (API key i session token)
- **Responses** — úspěšné odpovědi i chybové stavy

### Příklad vygenerované specifikace (zkráceno)

```yaml
openapi: "3.1.0"
info:
  title: "Shipard API"
  version: "1.0.0"
servers:
  - url: "https://{subdomain}.shipard.cz/api/v1"
    variables:
      subdomain:
        default: "demo"

paths:
  /core_system_users:
    get:
      summary: "List core_system_users"
      tags: ["core_system_users"]
      parameters:
        - name: limit
          in: query
          schema: { type: integer, default: 20, maximum: 100 }
        - name: offset
          in: query
          schema: { type: integer, default: 0 }
        - name: sort
          in: query
          schema: { type: string }
        - name: fields
          in: query
          schema: { type: string }
      responses:
        "200":
          description: "Success"
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/core_system_users_list_response"
    post:
      summary: "Create core_system_users"
      requestBody:
        content:
          application/json:
            schema:
              $ref: "#/components/schemas/core_system_users_create"
      responses:
        "201":
          description: "Created"

components:
  schemas:
    core_system_users_item:
      type: object
      properties:
        id: { type: integer, readOnly: true }
        login: { type: string, maxLength: 50 }
        full_name: { type: string, maxLength: 100 }
        email: { type: string, maxLength: 200 }
        is_active: { type: boolean }
        created: { type: string, format: date-time, readOnly: true }
        modified: { type: string, format: date-time, readOnly: true }
    core_system_users_create:
      type: object
      required: [login, full_name, password_hash]
      properties:
        login: { type: string, maxLength: 50 }
        full_name: { type: string, maxLength: 100 }
        email: { type: string, maxLength: 200 }

  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
security:
  - bearerAuth: []
```

### Swagger UI

V budoucnu lze přidat Swagger UI na adrese `/api/docs`, které načte OpenAPI spec a zobrazí interaktivní dokumentaci. Pro první verzi stačí endpoint `_openapi.json` — vývojáři si spec mohou importovat do Postmanu, Insomnie nebo jiného nástroje.

---

## 9. Meta endpointy

### Seznam tabulek

```
GET /api/v1/_meta/tables
```

```json
{
    "success": true,
    "data": [
        {
            "table": "core_system_users",
            "name": "Uživatelé",
            "tableId": 1,
            "module": "core.system"
        },
        {
            "table": "economy_docs_heads",
            "name": "Doklady - hlavičky",
            "tableId": 10,
            "module": "economy.docs"
        }
    ]
}
```

### Metadata tabulky

```
GET /api/v1/_meta/tables/core_system_users
```

```json
{
    "success": true,
    "data": {
        "table": "core_system_users",
        "name": "Uživatelé",
        "tableId": 1,
        "module": "core.system",
        "displayPattern": "{full_name} ({login})",
        "columns": [
            {
                "id": "id",
                "name": "ID",
                "type": "int",
                "primaryKey": true,
                "autoIncrement": true
            },
            {
                "id": "login",
                "name": "Login",
                "type": "varchar",
                "length": 50,
                "nullable": false
            },
            {
                "id": "full_name",
                "name": "Celé jméno",
                "type": "varchar",
                "length": 100,
                "nullable": false
            }
        ],
        "columnGroups": [
            {"id": "auth", "name": "Přihlašování"},
            {"id": "profile", "name": "Profil"}
        ],
        "indexes": [
            {"id": "idx_login", "type": "unique", "columns": ["login"]}
        ]
    }
}
```

Meta endpointy vrací lokalizovaná data podle hlavičky `Accept-Language` nebo výchozího jazyka zdroje dat.

---

## 10. HTTP hlavičky

### Request hlavičky

| Hlavička | Povinná | Popis |
|----------|---------|-------|
| `Authorization` | Ano* | `Bearer {token}` — API klíč nebo session token |
| `Content-Type` | Ano** | `application/json` pro POST/PUT/PATCH |
| `Accept-Language` | Ne | Jazyk pro lokalizované odpovědi (`cs`, `en`). Výchozí: `defaultLanguage` ze zdroje dat |

*Kromě login endpointu a OpenAPI spec.
**Pouze pro requesty s tělem.

### Response hlavičky

| Hlavička | Popis |
|----------|-------|
| `Content-Type` | `application/json; charset=utf-8` |
| `X-Request-Id` | Unikátní ID requestu pro debugging |
| `X-RateLimit-Limit` | Max požadavků za okno |
| `X-RateLimit-Remaining` | Zbývající požadavky |
| `X-RateLimit-Reset` | Unix timestamp resetu |

---

## 11. Rate limiting

Omezení počtu požadavků chrání server před přetížením.

| Typ | Limit | Okno |
|-----|-------|------|
| API klíč | 1000 req | 1 minuta |
| Session token | 300 req | 1 minuta |
| Login endpoint | 10 req | 1 minuta (per IP) |

Při překročení → `429 Too Many Requests`:

```json
{
    "success": false,
    "error": {
        "code": "RATE_LIMITED",
        "message": "Too many requests. Retry after 23 seconds.",
        "details": [
            {"field": "_retry_after", "code": "SECONDS", "message": "23"}
        ]
    }
}
```

---

## 12. CORS

Pro první verzi jednoduchá konfigurace — wildcard pro subdomény Shipard:

```
Access-Control-Allow-Origin: https://*.shipard.cz
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, Accept-Language
Access-Control-Max-Age: 86400
```

Preflight `OPTIONS` requesty se zpracují automaticky.

---

## 13. Architektura PHP tříd

### Nové třídy

```
src/
├── Api/
│   ├── Router.php              # Parsování URL → tabulka + akce + parametry
│   ├── Request.php             # Abstrakce HTTP requestu (query, body, headers)
│   ├── Response.php            # Stavba JSON odpovědí (envelope, chybové formáty)
│   ├── Middleware/
│   │   ├── AuthMiddleware.php          # Ověření tokenu, načtení uživatele
│   │   ├── RateLimitMiddleware.php     # Kontrola rate limitů
│   │   └── CorsMiddleware.php          # CORS hlavičky
│   ├── Controller/
│   │   ├── CrudController.php          # Univerzální CRUD operace nad tabulkou
│   │   ├── MetaController.php          # _meta endpointy (seznam tabulek, metadata)
│   │   ├── AuthController.php          # Login, refresh, logout
│   │   └── OpenApiController.php       # Generování OpenAPI spec
│   ├── Validation/
│   │   └── InputValidator.php          # Validace vstupů podle definice tabulky
│   └── OpenApi/
│       └── SpecGenerator.php           # Generování OpenAPI 3.1 JSON z metadat
├── Core/
│   └── ...                     # Existující třídy (beze změn)
└── ...
```

### Entry point

```
public/index.php                # Front controller
```

### Tok requestu

```
nginx → public/index.php
  → Resolve data source (subdoména → domains.json → DS config)
  → CorsMiddleware (OPTIONS → okamžitá odpověď)
  → AuthMiddleware (ověření tokenu, výjimka pro login/openapi)
  → RateLimitMiddleware
  → Router (URL → controller + akce + parametry)
  → Controller (CrudController / MetaController / AuthController / OpenApiController)
  → Response (JSON envelope)
```

### CrudController — univerzální CRUD

Jeden controller pro všechny tabulky. Přijímá název tabulky z routeru, načte definici tabulky, provede operaci:

- `list()` — SELECT s filtry, řazením, stránkováním
- `show(int $id)` — SELECT WHERE id = ?
- `create()` — INSERT, validace vstupů
- `update(int $id)` — UPDATE (plný), validace
- `patch(int $id)` — UPDATE (částečný), validace
- `delete(int $id)` — DELETE

Používá Dibi pro databázové operace. Sestavuje SQL dynamicky z parametrů requestu a definice tabulky.

---

## 14. Bezpečnost

### SQL injection

Veškeré hodnoty se předávají přes Dibi parametry (prepared statements). Názvy sloupců a tabulek se validují proti definici — do SQL se dostanou pouze sloupce, které existují v JSONC definici.

### Input sanitizace

- Názvy sloupců v `filter`, `sort`, `fields` se kontrolují proti definici tabulky
- Operátory filtrů se kontrolují proti whitelistu
- `limit` a `offset` se přetypují na int s kontrolou rozsahu

### Skryté sloupce

Sloupce typu `password_hash` by neměly být ve výstupu GET. V budoucnu přidáme do definice sloupce volitelné pole `"api": {"readable": false}` nebo `"hidden": true`. Pro první verzi hardcoded seznam: sloupce obsahující `password` v názvu se nevracejí v GET odpovědích.

---

## 15. Mapování typů — JSONC → JSON response

| Typ v JSONC | Typ v JSON odpovědi | Příklad |
|-------------|---------------------|---------|
| `int`, `smallint`, `bigint`, `tinyint` | number (integer) | `42` |
| `numeric`, `float` | number | `1234.56` |
| `varchar`, `text`, `longtext` | string | `"Jan Novák"` |
| `boolean` | boolean | `true` |
| `date` | string (YYYY-MM-DD) | `"2026-03-16"` |
| `datetime` | string (ISO 8601) | `"2026-03-16T14:30:00+01:00"` |
| `time` | string (HH:MM:SS) | `"14:30:00"` |
| `json` | objekt/pole (parsovaný) | `{"key": "value"}` |
| `enumInt` | number | `1` |
| `enumString` | string | `"INV"` |

---

## 16. Budoucí rozšíření (mimo scope první verze)

Tyto funkce se implementují později, ale architektura by je měla umožnit:

- **Dokumentové API** — práce s hlavičkou + řádky jako jedním celkem (`POST /api/v1/economy_docs_heads` s vnořeným `rows`)
- **Related data** — `?include=rows` pro dotažení souvisejících záznamů
- **Webhooks** — notifikace o změnách v datech
- **Bulk operace** — `POST /api/v1/{table}/_bulk` pro hromadné vytvoření/aktualizaci
- **Swagger UI** — interaktivní dokumentace na `/api/docs`
- **API oprávnění** — granulární práva per tabulka / per operace pro API klíče
- **Audit log** — záznam všech změn přes API

---

## 17. Příklad kompletní interakce

### 1. Přihlášení

```
POST https://demo.shipard.cz/api/v1/_auth/login
Content-Type: application/json

{"login": "jan.novak", "password": "heslo123"}
```

→ `200 OK` s tokenem

### 2. Výpis faktur

```
GET https://demo.shipard.cz/api/v1/economy_docs_heads?filter[doc_state]=eq:1&sort=issue_date:desc&limit=10
Authorization: Bearer shpd_st_eyJ...
```

→ `200 OK` s polem faktur

### 3. Vytvoření faktury

```
POST https://demo.shipard.cz/api/v1/economy_docs_heads
Authorization: Bearer shpd_st_eyJ...
Content-Type: application/json

{
    "doc_number": "FV-2026-001",
    "doc_type": "INV",
    "doc_state": 1,
    "customer_id": 42,
    "issue_date": "2026-03-16",
    "due_date": "2026-04-15",
    "total_amount": 12500.00,
    "currency": "CZK"
}
```

→ `201 Created` s vytvořeným záznamem

### 4. Aktualizace stavu

```
PATCH https://demo.shipard.cz/api/v1/economy_docs_heads/1
Authorization: Bearer shpd_st_eyJ...
Content-Type: application/json

{"doc_state": 2}
```

→ `200 OK` s aktualizovaným záznamem

### 5. Metadata tabulky

```
GET https://demo.shipard.cz/api/v1/_meta/tables/economy_docs_heads
Authorization: Bearer shpd_st_eyJ...
Accept-Language: cs
```

→ `200 OK` s definicí sloupců v češtině

### 6. OpenAPI specifikace

```
GET https://demo.shipard.cz/api/v1/_openapi.json
```

→ `200 OK` s kompletní OpenAPI 3.1 specifikací (přístupnost závisí na konfiguraci zdroje dat — viz sekce 8)
