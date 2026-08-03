# Task: Mail — import endpoint (Fáze 4)

**Stav:** hotovo

## Kontext

Importer ze starého Shipardu (`old_shipard:modules/imports/newShipard/`) potřebuje
vytvářet záznamy došlých zpráv (`core_mail_incoming_messages`) z legacy dat
(`wkf_core_issues`, `issueType=1` = Došlá pošta). Tento task přidává **dedikovaný
REST endpoint** `POST /api/v1/_mail/import` pro programové založení jedné importované
zprávy.

**Proč nový endpoint a ne generický CRUD ani `/_mail/incoming`:**

1. **Generický CRUD (`POST /{table}`) nestačí.** `CrudController::create` zapisuje
   přímo přes `insertRow` a **nevolá Document hooky**. `message_id`
   (`MSG-YYYYMMDD-NNNN`, NOT NULL UNIQUE) se ale generuje v
   `IncomingMessageDocument::beforeSave()` — přes CRUD by se nevytvořil a insert by
   spadl na NOT NULL. CRUD navíc filtruje `system` sloupce.
2. **`/_mail/incoming` (MailController) je pro mail-router.** Je `multipart/form-data`,
   vyžaduje `raw_source` (.eml), je tvrdě omezený na systémového uživatele
   `_mail_router` (jinak 403), používá idempotency hlavičky a defaultuje
   `source_type=2`. Pro import (žádný `.eml`, jiný zdroj, jiná autentizace, vazba na
   doklad) je to špatný tvar.

Endpoint vytváří zprávu **přes Document path** (jako `MailController::insertIncomingMessage`),
takže `beforeSave` vygeneruje `message_id` a znormalizuje `sender_email`. Navíc
umožní nastavit pole, která UI/mail-router neřeší: `sender_person`, `primary_type`,
`source_type`, vazbu na doklad (`target_table_id` + `target_row`) a explicitní
`docState`.

Tento endpoint je **prerekvizita** importního tasku `old_shipard:…/tasks/07b-mail.md`.

## Před implementací přečti

- **`src/Api/Controller/MailController.php`** — zejména `insertIncomingMessage()`
  (vzor pro vytvoření zprávy přes `DocumentRegistry->getDocument()->beforeSave()`
  v rámci Dibi transakce) a `resolveMailbox()` (resolve `mailbox_id` kódu → id,
  fallback na default schránku). Endpoint bude jeho menší sourozenec.
- **`src/Api/Router.php`** — `resolveAttachmentRoute` / blok `/_mail/*`. Sem přidat
  routu `/_mail/import`.
- **`modules/core/mail/src/IncomingMessageDocument.php`** — `validate()` (povinné
  `mailbox`, `subject`, `sender_email` validní, `received_at`) a `beforeSave()`
  (generace `message_id`, default `source_type`/`primary_type`).
- **`modules/core/mail/tables/core_mail_incoming_messages.jsonc`** — sloupce.
  `target_table_id` (varchar 100, nullable), `target_row` (int, nullable),
  `source_type` (tinyint, default 1), `docState`/`docStateMain` (system).
- **`modules/core/mail/config/docStatesIncoming.jsonc`** — stavy. `40` = Zpracovaná
  (`mainState: 4`), `10` = Nová (`mainState: 1`).
- **`src/Api/Controller/CrudController.php`** — `initDocState()` jako vzor, jak se
  počítá `docStateMain` přes `DocStateConfig::getMainState()`.
- **`src/Api/Controller/AuthContext.php`** + `Middleware/AuthMiddleware.php` —
  autentizace (api_key).

## Co implementovat

### 1. Routa

V `Router::resolve`, do bloku `/_mail/*` (před generický CRUD):

```php
if ($subpath === '/_mail/import') {
    if ($method !== 'POST') {
        return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
    return new Route('mail', 'importMessage');
}
```

### 2. `MailController::importMessage(AuthContext $auth, Request $request)`

JSON endpoint (ne multipart — přílohy řeší samostatně `POST /_attachments/upload`).

**Autentizace:** vyžadovat přihlášení přes **api_key** (jako exchange endpointy).
**Bez** omezení na konkrétního uživatele — importer běží pod vlastním API klíčem
(typicky `_legacy_importer`), stejně jako už volá `/_exchange/*` a generický CRUD.
Tj. `if (!$auth->isAuthenticated || $auth->tokenType !== 'api_key') → 401`.

**Tělo (JSON):**

| Pole | Typ | Povinné | Pozn. |
|---|---|---|---|
| `mailbox` | string | ano | `mailbox_id` kód schránky; prázdné → default schránka (jako `/_mail/incoming`) |
| `subject` | string | ano | prázdné → `(bez předmětu)` |
| `sender_email` | string | ano | musí být validní e-mail (volající dodá fallback placeholder) |
| `sender_name` | string\|null | ne | |
| `sender_person` | int\|null | ne | FK `base_persons_persons` (volající resolvuje přes svou LocalIdMap) |
| `received_at` | string | ano | ISO8601 |
| `body_plain` | string\|null | ne | |
| `body_html` | string\|null | ne | |
| `primary_type` | string | ne | klíč z `core.mail.primaryTypes` (default `other`) |
| `source_type` | int | ne | default 1 |
| `target_table_id` | string\|null | ne | např. `docs_core_heads` |
| `target_row` | int\|null | ne | |
| `external_message_id` | string\|null | ne | |
| `in_reply_to` | string\|null | ne | |
| `reply_references` | string\|null | ne | |
| `docState` | int | ne | default **40** (Zpracovaná) — importovaná zpráva, ze které vznikl doklad. Volající pošle 10 pro nenavázané zprávy. |

**Flow:**

1. Resolve mailbox přes `resolveMailbox($body['mailbox'] ?? '')` (existující metoda —
   vrací id nebo `Response` error).
2. Sestav `$data` (mailbox id, subject, sender_email/name, received_at, body,
   external_message_id, in_reply_to, reply_references, source_type, sender_person,
   primary_type, target_table_id, target_row, created_by = `$auth->userId`).
   `primary_type`/`source_type` z těla **mají přednost** před defaulty v `beforeSave`
   (proto je nastav do `$data` před voláním `beforeSave`).
3. Vytvoř přes Document path (vzor `insertIncomingMessage`):
   ```php
   $doc = $this->documentRegistry->getDocument(self::MAIL_TABLE);
   $dibi = $this->db->getDibiConnection();
   $doc->setDb($dibi);

   $validation = $doc->validate($data);
   if (!$validation->isValid()) { /* 422 s první chybou */ }

   $doc->beforeSave($data);   // vygeneruje message_id, znormalizuje sender_email
   ```
4. **Nastav `docState` + `docStateMain` explicitně** (beforeSave je neřeší; DB default
   by byl 10). `docState` z těla (default 40), `docStateMain` přes
   `DocStateConfig::fromCfgItem($config->cfgItem('core.mail.docStatesIncoming'))->getMainState($docState)`.
   → Vyžaduje `ConfigRuntime` v controlleru. Pokud `MailController` config nemá,
   přidej ho do konstruktoru (vzor `CrudController` má `?ConfigRuntime`); uprav místo,
   kde se controller instancuje (dispatcher API). Fallback bez configu: pevná mapa
   `mainState` z `docStatesIncoming` (10→1, 20→2, 30→3, 40→4, 70→7, 80→5, 90→6).
5. `INSERT` přes Dibi (`$dibi->insert(self::MAIL_TABLE, $data)->execute()`),
   `$messageId = (int) $dibi->getInsertId()`.
6. Načti `message_id` a vrať:
   ```json
   { "success": true, "data": { "ndx": 123, "message_id": "MSG-20250602-0007" } }
   ```
   se statusem **201**.

**Idempotence řeší volající** (importer drží `LocalIdMap` `ENTITY_MESSAGE` a zprávu
podruhé neposílá). Endpoint proto idempotency neřeší — drž ho jednoduchý.

**Transakce:** stačí jednoduchý insert (přílohy jdou samostatnými requesty).
Nemíchej sem upload souborů.

### 3. OpenApi / testy

- Přidej endpoint do OpenAPI spec (pokud se generuje deklarativně, jinak vynech).
- API test(y): create s minimálními poli (vygeneruje se `message_id`, docState=40);
  create s `target_table_id`/`target_row`; create s `docState=10`; validation error
  pro chybějící `sender_email` / nevalidní e-mail; neexistující `mailbox` → 422.

## Hotovo když

1. `POST /api/v1/_mail/import` existuje a je dostupný pro libovolný api_key
   (api_key auth, bez omezení na `_mail_router`).
2. Zpráva vzniká **přes Document path** — `message_id` se generuje, `sender_email`
   se normalizuje.
3. Lze nastavit `sender_person`, `primary_type`, `source_type`,
   `target_table_id` + `target_row` a explicitní `docState` (default 40,
   `docStateMain` dopočítán z cfg).
4. Resolve `mailbox` kódu funguje (prázdné → default schránka, neexistující → 422).
5. Odpověď 201 vrací `{ ndx, message_id }`.
6. Testy procházejí (happy path + validace + target link + docState 10/40).

## Doporučené pořadí implementace

1. Routa + skeleton `importMessage` (jen 201 s dummy daty) → smoke přes curl.
2. Resolve mailbox + Document validate/beforeSave + insert → vznikne zpráva s
   `message_id`.
3. `docState`/`docStateMain` explicitně (+ ConfigRuntime wiring).
4. Volitelná pole (sender_person, primary_type, source_type, target_*).
5. Testy + OpenAPI.

## Otevřené body / rozhodnutí

1. **ConfigRuntime v MailController.** Pokud injektáž configu do controlleru je
   nečekaně invazivní (dispatcher), použij pevnou `mainState` mapu (viz krok 4) a
   poznamenej to. Hodnoty jsou stabilní v `docStatesIncoming.jsonc`.
2. **Žádné omezení uživatele.** Záměrně liberálnější než `/_mail/incoming` — import
   běží pod běžným importním klíčem. Pokud bude potřeba zpřísnit (např. jen
   `is_system` uživatelé), je to follow-up; teď konzistentní s `/_exchange/*`.
3. **`docState` při vložení rovnou na 40.** Vkládáme čerstvý záznam přímo na cílový
   stav (ne přes transition) — to je v pořádku, transition validace se na create
   neaplikuje. Ověř, že přímý insert s `docState=40` nespustí žádné nežádoucí hooky
   (zpráva žádné `beforeSave` side-efekty kromě message_id nemá).
