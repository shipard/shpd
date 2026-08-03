# Modul `mail` — Fáze 3a: AI analýza (shpd strana)

**Stav:** hotovo

**Cíl fáze:** Rozšířit shpd o podporu AI analýzy došlých zpráv. Nové
tabulky pro extrahované dokumenty a AI konfiguraci, pull-based API
endpointy pro externí analyzer, UI integraci (nový tab + akce "Znova
analyzovat"), auto-provisioning default AI profilu.

**Návaznost:**

- **Prerekvizita:** `tasks/ds-encrypted-secrets.md` musí být dokončen
  jako první. AI backends ukládají API klíče přes `encrypted_text`
  sloupcový typ a `DsSecretCipher`, které definuje ten task.
- Navazuje na Fázi 1 a 2a (modul `mail` + endpointy).
- Proti tomuto endpointu se vyvíjí Fáze 3b (analyzer daemon, samostatný
  repozitář `ai_analyzer`).

---

## 1. Scope

**V rozsahu:**

- Nové tabulky:
  - `core_mail_extracted_documents` (kandidáti na business entity)
  - `core_mail_ai_backends` (provider config, per DS) — `api_key` jako
    `encrypted_text`
  - `core_mail_ai_profiles` (prompty, schémata, per use-case)
  - `core_mail_analysis_claims` (lease mechanism pro pull)
- Rozšíření `core_mail_message_analyses` o `profile_ndx`, `backend_ndx`,
  `cost_usd`
- Rozšíření `core_mail_incoming_messages` o `ai_analysis_enabled`
  (override per zpráva) a `needs_reanalysis` (flag pro re-run)
- Nové cfgItems: `mailExtractedDocStates`, `mailExtractedDocTypes`
- API endpointy pro pull-based analyzer:
  - `GET  /api/v1/_mail/analysis/queue`
  - `POST /api/v1/_mail/analysis/{message_ndx}/claim`
  - `GET  /api/v1/_mail/analysis/{message_ndx}/payload`
  - `GET  /api/v1/_mail/analysis/{message_ndx}/attachments/{att_ndx}/content`
  - `POST /api/v1/_mail/analysis/{message_ndx}/result`
  - `POST /api/v1/_mail/analysis/{message_ndx}/failed`
- Viewer rozšíření:
  - Nový tab "Extrahované dokumenty" v detail panelu zprávy
  - Akce "Znova analyzovat" na zprávě
  - Badges / highlighting pro extracted docs podle `status` a `confidence`
- Systémový uživatel `_ai_analyzer` + CLI `ai-analyzer-setup`
- Auto-provisioning default backend a profile při `DsCreate`
- Bootstrap pro existující DS

**Mimo rozsah:**

- Skutečná integrace extracted document → přijatá faktura. V MVP vzniká jen
  extracted document s `extracted_json`. Konverze na entity je samostatný
  úkol (mail-phase3c).
- Ollama / non-Anthropic providers
- Budget limits / cost enforcement (jen tracking)
- Office dokumenty
- Bulk operations (apply všech extracted docs najednou)
- Generický secrets mechanismus — řeší samostatný task
  `ds-encrypted-secrets.md`

---

## 2. Datový model

### 2.1 `core_mail_extracted_documents` (tableId 306)

Jeden záznam = jeden kandidát na business entitu, kterou AI našla.

| Pole                      | Typ         | Pozn.                                                   |
|---------------------------|-------------|---------------------------------------------------------|
| `id`                      | int PK      |                                                         |
| `message`                 | int FK      | → `core_mail_incoming_messages`, CASCADE delete         |
| `analysis`                | int FK      | → `core_mail_message_analyses`, CASCADE delete          |
| `doc_type`                | string      | cfgItem key (`invoiceReceived`, `creditNote`, ...)      |
| `source_attachments`      | text        | JSON array `[ndx, ndx, ...]` — z kterých příloh        |
| `extracted_json`          | longtext    | strukturovaný obsah (faktura, položky, částky, ...)     |
| `confidence`              | numeric(4,3)| 0.000–1.000                                             |
| `status`                  | tinyint     | viz §2.1.1 níže                                         |
| `target_table_id`         | string      | nullable — do které tabulky byl aplikován               |
| `target_row_ndx`          | int         | nullable — konkrétní záznam                             |
| `applied_at`              | datetime    | nullable                                                |
| `applied_by`              | int FK      | nullable → `core_system_users`                          |
| `rejected_reason`         | text        | nullable                                                |
| `superseded_by`           | int FK      | nullable → self (pro re-analýzy)                        |
| `created`, `created_by`   | standardní  |                                                         |

Indexy: `message`, `analysis`, `status`, `(message, status)`.

**Status values (tinyint):**

| Kód | ID                | Význam                                              |
|-----|-------------------|-----------------------------------------------------|
| 10  | `ready_to_apply`  | confidence ≥ 0.9 — UI nabízí jen "Použít"           |
| 20  | `pending_review`  | 0.6 ≤ confidence < 0.9 — default, čeká review       |
| 30  | `low_confidence`  | confidence < 0.6 — vyžaduje pozornost               |
| 40  | `applied`         | Uživatel potvrdil, entity vznikla                   |
| 50  | `rejected`        | Uživatel zamítl (false positive)                    |
| 60  | `superseded`      | Nahrazen novou analýzou (re-run)                    |
| 70  | `ai_failed`       | AI nemohla extract (např. nečitelné PDF)            |

### 2.2 `core_mail_ai_backends` (tableId 307)

Konfigurace AI provideru. Per DS může být více (typicky jeden Anthropic,
později další), jeden `is_default = true`.

| Pole                          | Typ             | Pozn.                                           |
|-------------------------------|-----------------|-------------------------------------------------|
| `id`                          | int PK          |                                                 |
| `backend_id`                  | string          | lidský identifikátor (`default`, `claude-opus`) |
| `name`                        | string          | UI název                                        |
| `provider`                    | string          | `anthropic` (jediný v MVP)                      |
| `model`                       | string          | `claude-sonnet-4-5`, atd.                       |
| `api_key`                     | `encrypted_text`| šifrované přes `DsSecretCipher` (viz prerekvizit task) |
| `base_url`                    | string          | nullable — pro non-default endpoints            |
| `max_tokens`                  | int             | default 4096                                    |
| `temperature`                 | numeric(3,2)    | default 0.00 — extrakce je deterministická      |
| `is_default`                  | bool            | partial unique (max jeden `true` per DS)        |
| `is_active`                   | bool            | default false — aktivuje se po nastavení klíče  |
| `docState`, `docStateMain`    | tinyint         | standardní archivní set                         |
| `created`, `created_by`       | standardní      |                                                 |

`api_key` má typ `encrypted_text` definovaný v
`tasks/ds-encrypted-secrets.md` §5. Document class (`AIBackendDocument`)
volá `DsSecretCipher::encrypt()` v `beforeSave()` při dirty change.
Decrypt probíhá v `AnalysisController::claim()` před vložením plaintext
hodnoty do response (§4.2).

### 2.3 `core_mail_ai_profiles` (tableId 308)

Profil popisuje, **co** se analyzuje. Per DS typicky 1–2 profily
(např. `czech_invoices`, `english_invoices`).

| Pole                          | Typ         | Pozn.                                        |
|-------------------------------|-------------|----------------------------------------------|
| `id`                          | int PK      |                                              |
| `profile_id`                  | string      | lidský id (`czech_invoices`)                 |
| `name`                        | string      | UI název                                     |
| `backend`                     | int FK      | → `core_mail_ai_backends`                    |
| `supported_doc_types`         | text        | JSON array cfgItem keys                      |
| `language`                    | string      | ISO 639-1 (`cs`, `en`)                       |
| `prompt_version`              | string      | semver (`v1.0.0`)                            |
| `prompt_template`             | longtext    | Jinja-style placeholders                     |
| `output_schema`               | longtext    | JSON schema                                  |
| `confidence_thresholds`       | text        | JSON `{ready: 0.9, review: 0.6}`             |
| `is_default`                  | bool        | partial unique per DS                        |
| `is_active`                   | bool        |                                              |
| `docState`, `docStateMain`    | tinyint     |                                              |
| `created`, `created_by`       | standardní  |                                              |

Default profil se vytváří při `DsCreate` ze šablony v
`modules/core/mail/profiles/default_czech_invoices.jsonc`.

### 2.4 `core_mail_analysis_claims` (tableId 309)

Lease mechanism pro pull model. Když analyzer claimne zprávu, vytvoří se
záznam s expirací. Při expiraci se zpráva vrací do queue (recovery při
crash analyzeru).

| Pole               | Typ        | Pozn.                                         |
|--------------------|------------|-----------------------------------------------|
| `id`               | int PK     |                                               |
| `message`          | int FK     | → `core_mail_incoming_messages`               |
| `analyzer_id`      | string     | UUID analyzeru (self-reported)                |
| `claim_token`      | string(64) | náhodný, server-generated                     |
| `claimed_at`       | datetime   |                                               |
| `expires_at`       | datetime   |                                               |
| `released`         | bool       | default false — true po result/failed         |
| `released_at`      | datetime   | nullable                                      |

Unique index: `(message) WHERE released = false` — jedna aktivní claim
na zprávu. Index na `expires_at` pro reaper cron.

### 2.5 Rozšíření existujících tabulek

**`core_mail_message_analyses`** — přidat:
- `profile` (FK → `core_mail_ai_profiles`, nullable pro legacy)
- `backend` (FK → `core_mail_ai_backends`, nullable pro legacy)
- `cost_usd` (numeric(10,6), nullable)
- `extracted_document_count` (int, default 0)

**`core_mail_incoming_messages`** — přidat:
- `ai_analysis_enabled` (bool, nullable — NULL = zděděno z DS default;
  true/false override)
- `needs_reanalysis` (bool, default false — zapne tlačítko "Znova
  analyzovat")
- `profile_override` (FK → `core_mail_ai_profiles`, nullable — pro ad-hoc
  re-analýzu s jiným profilem)

### 2.6 Nové docState transitions pro `core_mail_incoming_messages`

Stav 20 "V analýze" a 30 "Analyzovaná" ze Fáze 1 teď ožívají:

- **10 (Nová) → 20 (V analýze):** analyzer při `claim`
- **20 → 30 (Analyzovaná):** analyzer při úspěšném `result`
- **20 → 10 (re-queue):** analyzer při `failed` s `retryable=true`, nebo
  claim expirace
- **20 → 70 (chyba, nový stav):** analyzer při `failed` s `retryable=false`
- **30 → 40 (Zpracovaná):** všechny extracted_documents buď `applied` nebo
  `rejected` (automatic state transition hook)
- **30 → 10 (re-queue):** uživatel kliknul "Znova analyzovat"

**Nový state 70 "Chyba AI":** permanentní AI chyba (např. mail obsahuje
corrupted PDF). Potřebuje manuální zásah. Přidat do `mailIncomingStates`
cfgItem (viz §7).

---

## 3. API endpointy — pull protocol

Všechny pod `/api/v1/_mail/analysis/*`. Auth přes `shpd_ak_` token
systémového uživatele `_ai_analyzer`.

### 3.1 `GET /queue`

```
GET /api/v1/_mail/analysis/queue?limit=5&profile_id=czech_invoices
Authorization: Bearer shpd_ak_...
```

Vrátí seznam zpráv připravených k analýze (docState=10,
`ai_analysis_enabled != false`, není claimed), seřazených podle
`received_at ASC`.

Response:

```json
{
  "success": true,
  "data": {
    "messages": [
      {
        "ndx": 12345,
        "received_at": "2026-04-24T10:00:00Z",
        "subject": "Faktura č. 2026000123",
        "sender_email": "accounts@example.com",
        "attachment_count": 2,
        "recommended_profile_ndx": 17,
        "has_raw_source": true
      }
    ],
    "total_available": 23
  }
}
```

`recommended_profile_ndx` je defaultní profil DS nebo `profile_override`
ze zprávy. Analyzer si může vybrat jiný přes parameter `profile_id`
v claim.

### 3.2 `POST /{message_ndx}/claim`

```
POST /api/v1/_mail/analysis/12345/claim
{
  "analyzer_id": "uuid-of-this-analyzer-instance",
  "profile_ndx": 17,
  "lease_seconds": 300
}
```

Atomicky:
1. Ověří, že zpráva je `docState=10` a není claimed (nebo má expired claim).
2. Vytvoří `core_mail_analysis_claims` záznam s `claim_token`.
3. Přepne zprávu na `docState=20` (V analýze).
4. **Decryptuje** `backend.api_key` přes `DsSecretCipher`, vkládá plaintext
   do response.
5. Vrátí `claim_token` a vše, co analyzer potřebuje.

Response 200:

```json
{
  "success": true,
  "data": {
    "claim_token": "ct_abc123xyz...",
    "expires_at": "2026-04-24T10:05:00Z",
    "profile": {
      "profile_id": "czech_invoices",
      "prompt_version": "v1.0.0",
      "prompt_template": "...",
      "output_schema": { ... },
      "supported_doc_types": ["invoiceReceived", "creditNote"],
      "language": "cs",
      "confidence_thresholds": {"ready": 0.9, "review": 0.6}
    },
    "backend": {
      "provider": "anthropic",
      "model": "claude-sonnet-4-5",
      "api_key": "sk-ant-...",
      "base_url": null,
      "max_tokens": 4096,
      "temperature": 0.0
    }
  }
}
```

Response 409 pokud už claimed:

```json
{ "success": false, "error": { "code": "ALREADY_CLAIMED", ... } }
```

**API key v claim response** je záměrné — analyzer ho má jen v paměti po
dobu zpracování. Alternativy viz §10.

### 3.3 `GET /{message_ndx}/payload`

Vrátí subject, body, sender, metadata příloh (BEZ obsahu).

```
GET /api/v1/_mail/analysis/12345/payload
Authorization: Bearer shpd_ak_...
X-Claim-Token: ct_abc123xyz...
```

Response:

```json
{
  "success": true,
  "data": {
    "message": {
      "subject": "...",
      "sender_email": "...",
      "sender_name": "...",
      "body_plain": "...",
      "body_html": null,
      "received_at": "..."
    },
    "attachments": [
      {
        "ndx": 456,
        "filename": "faktura.pdf",
        "mime_type": "application/pdf",
        "size_bytes": 245678
      }
    ]
  }
}
```

### 3.4 `GET /{message_ndx}/attachments/{att_ndx}/content`

Vrátí binární obsah jedné přílohy. Streamable.

```
GET /api/v1/_mail/analysis/12345/attachments/456/content
Authorization: Bearer shpd_ak_...
X-Claim-Token: ct_abc123xyz...
```

Response:

```
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Length: 245678
Content-Disposition: attachment; filename="faktura.pdf"

<binary PDF data>
```

Validace: attachment_ndx musí patřit ke specifikované message_ndx.

### 3.5 `POST /{message_ndx}/result`

```
POST /api/v1/_mail/analysis/12345/result
Authorization: Bearer shpd_ak_...
X-Claim-Token: ct_abc123xyz...

{
  "model_name": "claude-sonnet-4-5",
  "model_version": "20260101",
  "prompt_version": "v1.0.0",
  "tokens_input": 4500,
  "tokens_output": 1200,
  "duration_ms": 12340,
  "cost_usd": 0.0234,
  "overall_confidence": 0.92,
  "analysis_json": { ... celý raw output ... },
  "extracted_documents": [
    {
      "doc_type": "invoiceReceived",
      "source_attachment_ndxs": [456],
      "extracted_json": { ... },
      "confidence": 0.94
    }
  ]
}
```

Server transakčně:
1. Ověří claim_token je platný a nevyexpirovaný.
2. Vytvoří `core_mail_message_analyses` záznam.
3. Pro každý extracted document:
   - Vytvoří `core_mail_extracted_documents` záznam
   - Nastaví `status` podle `confidence` vs. profile thresholds
4. Označí claim jako `released=true`.
5. Přepne zprávu na `docState=30` (Analyzovaná).
6. Pokud `extracted_documents` je prázdný list, rovnou přepne na
   `docState=40` (Zpracovaná — není co řešit).

Response 200 s `{analysis_ndx, extracted_document_ndxs: [...]}`.

### 3.6 `POST /{message_ndx}/failed`

```
POST /api/v1/_mail/analysis/12345/failed
X-Claim-Token: ct_abc123xyz...

{
  "error_type": "ai_error | mime_error | timeout | config_error",
  "error_message": "...",
  "tokens_used": 123,
  "retryable": true
}
```

Server:
1. Ověří claim.
2. Vytvoří `core_mail_message_analyses` záznam se `status=failed`.
3. Uvolní claim.
4. Přepne zprávu:
   - `retryable=true` → `docState=10` (vrací se do queue)
   - `retryable=false` → `docState=70` (Chyba AI, manuální zásah)

### 3.7 Reaper — expirované claim-y

CLI příkaz `bin/shpd-ds mail-analysis-reap` (cron 1×/min):
- Najde claims s `expires_at < now()` a `released=false`
- Označí je `released=true` + reason "expired"
- Přepne zprávy zpět na `docState=10`
- Loguje pro audit (`{claim_ndx, analyzer_id, duration}`)

---

## 4. Re-analýza ("Znova analyzovat")

UI akce v detail panelu zprávy. Tlačítko volá nový endpoint:

```
POST /api/v1/_mail/messages/{ndx}/reanalyze
{ "profile_override_ndx": 42  // volitelné, default = použij stávající profil
}
```

Server:
1. Ověří, že zpráva je ve stavu `30 (Analyzovaná)` nebo `70 (Chyba AI)`.
   Z jiných stavů nelze (integrity — v 20 se analyzuje, v 40 by to bylo
   už aplikované).
2. Všechny existující `core_mail_extracted_documents` s `status IN (10,20,30)`
   dostanou `status=60 (superseded)`.
   Dokumenty se `status=40 (applied)` nebo `50 (rejected)` zůstávají beze změny.
3. Nastaví `needs_reanalysis=true` a `profile_override=?`.
4. Přepne zprávu na `docState=10`.

Analyzer zprávu při dalším `GET /queue` vidí (včetně override profilu) a
začne od začátku.

---

## 5. UI integrace

### 5.1 Viewer detail panel — nové taby

Rozšíření existujícího `IncomingMessagesViewer` detail panelu:

| Tab (existující)      | Změny                                                   |
|-----------------------|---------------------------------------------------------|
| Obsah                 | Bez změn                                                |
| Přílohy               | Bez změn                                                |
| **Analýzy** (existuje)| Nyní naplněné — seznam běhů z `core_mail_message_analyses` s tlačítkem "Zobrazit detail" |
| **Extrahované dokumenty** (NOVÝ) | Hlavní tab pro review extracted docs      |
| Originál              | Bez změn                                                |

### 5.2 Tab "Extrahované dokumenty"

Pro každý extracted doc řádek s:
- Typ (cfgItem badge)
- Zdroj (odkaz na source attachmenty, obrázky thumbnailů)
- Confidence badge (barevný podle status)
- Status
- Shrnutí extracted_json (např. "Faktura č. X, 12 500 Kč, dodavatel Y")
- Akce: "Zobrazit detail", "Použít", "Zamítnout"

"Zobrazit detail" otevře modal s celým `extracted_json` (JSON viewer + strukturovaný preview).

"Použít" v MVP zatím jen nastaví `status=40 (applied)` — skutečná entity
nevzniká, přidá se v Fázi 3c.

"Zamítnout" otevře malý dialog s povinným důvodem, nastaví `status=50
(rejected)`.

### 5.3 Akce na zprávě

V toolbaru detail panelu nové tlačítko **"Znova analyzovat"**, viditelné
jen když `docState IN (30, 70)`. Při kliknutí dialog:

```
Znovu spustit AI analýzu této zprávy?

[ ] Použít jiný profil: [dropdown s profily]

Existující extrahované dokumenty ve stavech
"Ready / Pending / Low confidence" budou označeny jako nahrazené.
Dokumenty, které jste již použili nebo zamítli, zůstanou beze změny.

[Zrušit] [Spustit analýzu]
```

### 5.4 Extracted doc řádek — vizuální state

| Status            | Barva     | Ikona | Popis                                 |
|-------------------|-----------|-------|---------------------------------------|
| `ready_to_apply`  | zelená    | ✓     | Jistota ≥ 90 %, jedno kliknutí        |
| `pending_review`  | modrá     | ?     | Review vyžadován                      |
| `low_confidence`  | oranžová  | ⚠     | Nízká jistota, pečlivý review         |
| `applied`         | šedá      | ✓     | Už použito                            |
| `rejected`        | šedá      | ✗     | Zamítnuto                             |
| `superseded`      | šedá      | ⟲     | Nahrazeno novou analýzou              |
| `ai_failed`       | červená   | ✗     | AI nemohla extrahovat                 |

---

## 6. Systémový uživatel + auto-provisioning

### 6.1 Při `DsCreate`

Rozšíření `DsCreateCommand`:

1. **Uživatel `_ai_analyzer`** (analogicky k `_mail_router`)
2. **Default backend**:
   - `backend_id = 'default'`, `name = 'Anthropic Claude'`
   - `provider = 'anthropic'`, `model = 'claude-sonnet-4-5'`
   - `api_key = NULL` (admin doplní přes CLI)
   - `is_default = true`, `is_active = false` (aktivuje se po nastavení key)
3. **Default profile**:
   - `profile_id = 'czech_invoices'`, načten z
     `modules/core/mail/profiles/default_czech_invoices.jsonc`
   - `is_default = true`

Předpoklad: `secrets/secrets.key` už existuje (vytvořeno
v `ds-encrypted-secrets` Tasku 3).

### 6.2 Existující DS

CLI `bin/shpd-ds ai-analyzer-bootstrap` — idempotentní.

### 6.3 CLI `ai-analyzer-setup`

Analogicky k `mail-router-setup` — vytváří/rotuje shpd_ak_ token.

### 6.4 CLI `ai-analyzer-set-key`

```
Usage: shpd-ds ai-analyzer-set-key --backend default --api-key sk-ant-...

Encrypts the given API key via DsSecretCipher and stores it on the
specified backend. Sets is_active=true on success.
```

Implementace:
1. Načíst backend podle `backend_id`
2. `DsSecretCipher::forConfig($config)->encrypt($apiKey)` (používá
   infrastrukturu z `ds-encrypted-secrets` Tasku 1)
3. Update sloupec `api_key` + `is_active = true`
4. Při chybě (missing secrets.key, wrong permissions) → chyba s instrukcí

### 6.5 `AIBackendDocument` třída

Nová Document třída v `modules/core/mail/src/`:

```php
class AIBackendDocument extends DefaultDocument
{
    public function beforeSave(): void {
        parent::beforeSave();
        if ($this->isFieldDirty('api_key') && $this->data['api_key'] !== null) {
            $cipher = DsSecretCipher::forConfig($this->config);
            $this->data['api_key'] = $cipher->encrypt($this->data['api_key']);
        }
    }
}
```

Pravidlo: šifruj jen když pole je dirty. Šifrování beze změny by
generovalo nový nonce a měnilo ciphertext bez sémantické změny.

---

## 7. Default profil `default_czech_invoices`

Umístění: `modules/core/mail/profiles/default_czech_invoices.jsonc`

Obsah (náčrt — finální prompt iterativně):

```jsonc
{
  "profile_id": "czech_invoices",
  "name": "České faktury (default)",
  "language": "cs",
  "prompt_version": "v1.0.0",
  "supported_doc_types": ["invoiceReceived", "creditNote", "other"],
  "confidence_thresholds": { "ready": 0.9, "review": 0.6 },

  "prompt_template": "Jsi asistent pro zpracování došlé pošty českých firem. ... (viz docs/ai-prompts.md)",

  "output_schema": {
    "$schema": "http://json-schema.org/draft-07/schema#",
    "type": "object",
    "properties": {
      "overall_confidence": { "type": "number", "minimum": 0, "maximum": 1 },
      "documents": {
        "type": "array",
        "items": {
          "type": "object",
          "required": ["doc_type", "source_attachment_ndxs", "confidence", "fields"],
          "properties": {
            "doc_type": { "type": "string", "enum": ["invoiceReceived", "creditNote", "other"] },
            "source_attachment_ndxs": { "type": "array", "items": { "type": "integer" } },
            "confidence": { "type": "number" },
            "fields": {
              "type": "object",
              "properties": {
                "supplier": {
                  "type": "object",
                  "properties": {
                    "name": { "type": "string" },
                    "ico": { "type": "string" },
                    "dic": { "type": "string" },
                    "address": { "type": "string" }
                  }
                },
                "invoice_number": { "type": "string" },
                "variable_symbol": { "type": "string" },
                "issue_date": { "type": "string", "format": "date" },
                "due_date": { "type": "string", "format": "date" },
                "tax_date": { "type": "string", "format": "date" },
                "total_amount": { "type": "number" },
                "currency": { "type": "string" },
                "payment_method": { "type": "string" },
                "account_number": { "type": "string" },
                "iban": { "type": "string" },
                "vat_breakdown": { "type": "array", "items": { ... } },
                "line_items": { "type": "array", "items": { ... } },
                "notes": { "type": "string" }
              }
            }
          }
        }
      }
    }
  }
}
```

Prompt bude v samostatném tasku iterativně laděn.

---

## 8. docState set rozšíření — `mailIncomingStates`

Přidat nový state 70 "Chyba AI":

| Kód | Název CZ    | `docStateMain` | `viewGroup` | `stateStyle`   | `readOnly` | `mainState` |
|-----|-------------|----------------|-------------|----------------|------------|-------------|
| 70  | Chyba AI    | 70             | active      | `st-ai-error`  | false      | false       |

Goto: 70 → {10 (retry), 40 (manuálně zpracováno), 80, 90}

---

## 9. Task breakdown

**Prerekvizita:** všechny tasky z `tasks/ds-encrypted-secrets.md`
musí být dokončeny.

### Task 1 — Schema: extracted documents + AI config

- JSONC definice:
  - `core_mail_extracted_documents` (306)
  - `core_mail_ai_backends` (307) — `api_key` jako `encrypted_text`
  - `core_mail_ai_profiles` (308)
  - `core_mail_analysis_claims` (309)
- Rozšíření `core_mail_message_analyses` (profile, backend, cost_usd,
  extracted_document_count)
- Rozšíření `core_mail_incoming_messages` (ai_analysis_enabled,
  needs_reanalysis, profile_override)
- DsUpgrade migrace testovaná na DS s daty z Fáze 2
- Markdown docs pro všechny nové tabulky

**Akceptace:** DsUpgrade proběhne bez ztráty dat. Constrainty fungují.

### Task 2 — docState rozšíření + cfgItem

- Přidat state 70 do `mailIncomingStates`
- `mailExtractedDocStates` cfgItem (pro UI mapping status → label/ikona)
- Update `docs/doc-states.md`

**Akceptace:** State transitions podle §2.6 fungují (test suite).

### Task 3 — `AIBackendDocument` s encryption

- Nová třída `AIBackendDocument` v `modules/core/mail/src/`
- `beforeSave()` šifruje `api_key` když dirty (volá `DsSecretCipher`
  z prerekvizit tasku)
- `hasEncryptedKey()` helper — zda aktuálně uložená hodnota lze decryptovat
- Unit testy: save s novou hodnotou šifruje, save beze změny key
  nerešifruje, read vrací plaintext, partial updates

**Akceptace:** Testy zelené. Plaintext API key nikdy neleží v DB.

### Task 4 — Systémový uživatel + auto-provisioning

- `_ai_analyzer` user při `DsCreate`
- Default backend (bez API klíče, is_active=false)
- Default profile z `default_czech_invoices.jsonc`
- Bootstrap pro existující DS
- Šablona profilu v `modules/core/mail/profiles/`

**Akceptace:** Čerstvý DS má backend+profile připravený. Bootstrap na
existujícím DS idempotentní.

### Task 5 — CLI příkazy (AI-specific)

- `ai-analyzer-setup` (token)
- `ai-analyzer-set-key` (API key encryption přes `DsSecretCipher`)
- `mail-analysis-reap` (expired claims)
- `ai-analyzer-bootstrap`

**Akceptace:** Všechny příkazy fungují, integration testy.

### Task 6 — Pull API endpointy

- Nový `src/Api/Controller/AnalysisController.php`
- Routy pod `/api/v1/_mail/analysis/*`
- `GET /queue`, `POST /{ndx}/claim` (s decrypt API key),
  `GET /{ndx}/payload`, `GET /{ndx}/attachments/{att_ndx}/content`,
  `POST /{ndx}/result`, `POST /{ndx}/failed`
- Transakční semantika (claim + docState atomicky)
- Lease expiration handling při claim (auto-expire stale claim)

**Akceptace:** Integration testy pokrývají happy path, expired claim,
already claimed, invalid token, decrypt failure (missing secrets.key).

### Task 7 — Re-analýza endpoint + hook

- `POST /api/v1/_mail/messages/{ndx}/reanalyze`
- Validace dovoleného stavu (30 nebo 70)
- Superseded logika pro extracted docs
- Auto-transition zprávy 30→40 když všechny ext docs applied/rejected

**Akceptace:** Re-analýza projde, superseded docs zachované, stávající
applied/rejected neruší.

### Task 8 — Viewer rozšíření: tab Extrahované dokumenty

- `ExtractedDocumentsTab.svelte`
- List s badge, confidence, status
- Akce "Zobrazit detail", "Použít" (mock), "Zamítnout" (s reason dialog)
- Propojení na attachments tab přes source_attachments highlight

**Akceptace:** Zpráva s 3 extracted docs se zobrazí korektně, akce
mění DB stav.

### Task 9 — Viewer rozšíření: tab Analýzy

- `AnalysesTab.svelte` — naplnit existující stub
- List běhů z `core_mail_message_analyses`
- Detail modal s raw JSON + cost + timing

**Akceptace:** Zobrazí všechny analýzy zprávy včetně re-runs.

### Task 10 — Viewer: akce "Znova analyzovat"

- Tlačítko v toolbaru, conditional render
- Dialog s profile selectorem
- Volání reanalyze endpointu
- Refresh detail panelu po akci

**Akceptace:** UI akce projde end-to-end.

### Task 11 — Integrační testy

End-to-end v `tests/Integration/AnalysisEndpointTest.php`:

1. Happy path: queue → claim → payload → result → extracted docs vzniknou
2. Expired claim: reaper uvolní zprávu
3. Already claimed: 409
4. Failed retryable: zpráva zpět do queue
5. Failed non-retryable: docState=70
6. Re-analyze: superseded logika
7. Auto-transition 30→40 když user aplikuje poslední extracted doc
8. `ai-analyzer-set-key` → claim response obsahuje plaintext API key
9. Simulovaný corruption `secrets.key` → claim fails s jasnou chybou

**Akceptace:** Všechny zelené.

### Task 12 — Dokumentace

- `modules/core/mail/docs/ai-analysis.md` — architektura, flow, pull
  protocol
- `docs/mail/api-contract.md` — doplnit analysis endpointy
- `modules/core/mail/docs/ai-prompts.md` — default prompt + guidelines
  pro customizaci
- Update `modules/core/mail/README.md`

---

## 10. Rozhodnutí k designu (potvrzená)

1. ✓ **Per-attachment endpoint (§3.4)** — analyzer si stahuje přílohy
   jednotlivě přes `GET /{ndx}/attachments/{att_ndx}/content`.
   Streamable, selective download. Vše v claim response by bylo příliš
   těžké.

2. ✓ **API key v claim response (§3.2)** — MVP. Analyzer dostane plaintext
   API key v každé claim response, drží ho v paměti pouze po dobu
   zpracování zprávy.

   **Bezpečnostní požadavky:**
   - Claim endpoint MUSÍ vyžadovat HTTPS (nebo jen lokální HTTP, pokud
     je analyzer na stejném hostu — vyžaduje konfigurační check)
   - `AnalysisController::claim()` přidává hlavičky:
     `Cache-Control: no-store, no-cache, must-revalidate`
     `Pragma: no-cache`
   - Plaintext klíč nesmí být v žádném log výstupu (ani DEBUG level)

   V pozdější verzi: analyzer-side config s mapping DS → key alias.

3. ✓ **Profile output_schema je v DB.** Admin může upravit per-DS bez git
   commit. Žádné schema verzování v gitu — historie přes
   `prompt_version` v `core_mail_message_analyses`.

4. ✓ **Auto-transition 30 → 40.**

   **Implementační detail:** trigger je explicitní hook
   v `ExtractedDocumentDocument::afterSave()`. Když se status mění
   na `applied`, `rejected`, nebo `superseded`, hook:
   1. Načte všechny sourozence (extracted docs téže zprávy)
   2. Pokud žádný není ve stavu `ready_to_apply`, `pending_review` ani
      `low_confidence` → zpráva přechází 30 → 40
   3. Přechod proběhne ve stejné transakci jako save (atomic)

   Stav `ai_failed` (status 70) nebrání přechodu — admin se může
   rozhodnout zprávu uzavřít i s neúspěšnou analýzou.

5. ✓ **Claim lease 5/15 min.** Server akceptuje `lease_seconds` v rozsahu
   60–900 s (1–15 min). Default 300 s (5 min). Analyzer si žádá delší
   pro velké maily — konkrétní logika v PRD 3b.

6. ✓ **UI pro editaci API klíče** — odloženo. V MVP pouze CLI
   `ai-analyzer-set-key`. UI pattern pro password fields je samostatný
   UX úkol, který přijde s editací backends z UI.

---

## 11. Prerekvizita — `ds-encrypted-secrets`

Task `tasks/ds-encrypted-secrets.md` musí být dokončen **před** každým
DS, ve kterém běží `mail-phase3a` migrace. Konkrétně každý DS musí mít:

- `{ds_path}/secrets/secrets.key` existuje s permissions 0600
- `DsSecretCipher` třída funkční
- `encrypted_text` sloupcový typ registrovaný v schema parseru

Ověření před spuštěním Task 1:

```bash
bin/shpd-ds ds-secrets-health
```

Pokud check selže, spustit `bin/shpd-ds ds-upgrade` pro existující DS
(vytvoří chybějící secrets.key idempotentně).
