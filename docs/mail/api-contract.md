# `core.mail` — API kontrakt pro mail-router

**Status:** Stabilní od Fáze 2a.
**Implementace:** `src/Api/Controller/MailController.php`, `src/Api/Router.php`,
idempotence přes `core_mail_incoming_idempotency`.

## 1. Přehled

Mail-router je samostatná služba v samostatném repozitáři. Přijímá e-maily přes
SMTP/IMAP, parsuje `.eml`, a každou zprávu odešle do Shipardu přes HTTP endpoint
`POST /_mail/incoming`. Shipard zprávu zvaliduje, uloží spolu s přílohami do
jedné transakce a vrací ID nového záznamu.

### Separace zodpovědností

| Vrstva | Odpovědnost |
|---|---|
| Mail-router | Příjem SMTP/IMAP, parsing MIME, antivir, persistent queue + retry, idempotency key generation |
| Shipard `/_mail/incoming` | Auth, validace, atomické uložení zprávy + příloh, idempotency cache |
| Shipard `IncomingMessageDocument` | Generování `message_id`, normalizace sender_email, cascade delete |

## 2. Endpoint

```
POST /api/v1/_mail/incoming
Host: {ds-host}
Authorization: Bearer shpd_ak_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Idempotency-Key: <hex sha256>  // volitelné
Content-Type: multipart/form-data; boundary=...
```

### 2.1 Autentizace

- **API klíč** typu `shpd_ak_` vydaný přes `bin/shpd-ds mail-router-setup`.
- Kontroluje se existence klíče (hash v `core_system_api_keys`), platnost a
  optional IP whitelist.
- Controller navíc vynucuje, aby uživatel klíče byl systémový `_mail_router`
  (jiné API klíče tento endpoint nesmějí používat → **403 FORBIDDEN**).

### 2.2 Pole požadavku (multipart/form-data)

| Pole | Typ | Pov.? | Popis |
|---|---|---|---|
| `mailbox` | string | | `mailbox_id` (např. `invoices`). Prázdné / chybějící → použije se schránka s `is_default = 1`. |
| `external_message_id` | string | | RFC822 `Message-ID` — ukládá se pro pozdější dedup |
| `received_at` | ISO 8601 | ✓ | Čas doručení s TZ, např. `2026-04-18T14:32:00+02:00` |
| `subject` | string | | Předmět. Prázdné → `(bez předmětu)` |
| `sender_email` | string | ✓ | Musí projít `FILTER_VALIDATE_EMAIL` |
| `sender_name` | string | | Display name z `From:` hlavičky |
| `body_plain` | text | | Tělo v plain textu |
| `body_html` | text | | Tělo v HTML |
| `in_reply_to` | string | | RFC822 `In-Reply-To` header |
| `reply_references` | string | | RFC822 `References` header (whitespace-separated) |
| `source_type` | int | | Default `2` (email). Router nemění. |
| `raw_source` | file | ✓ | Originální `.eml` — ukládá se jako attachment a propojuje přes `raw_source_attachment` FK |
| `attachments[]` | file | | 0..N obsahových příloh |

## 3. Odpověď

### 3.1 Úspěch — 201 Created

```json
{
    "success": true,
    "data": {
        "ndx": 12345,
        "message_id": "MSG-20260418-0001",
        "idempotent_replay": false
    }
}
```

Při retry se stejným `X-Idempotency-Key` během TTL vrátí server uloženou odpověď
s `idempotent_replay: true`. Status code zůstává **201**.

### 3.2 Chybové odpovědi

| Kód | Error code | Situace |
|---|---|---|
| 401 | `UNAUTHORIZED` | Chybí / neplatný / expirovaný API klíč nebo session token |
| 403 | `FORBIDDEN` | API klíč patří jinému uživateli než `_mail_router` |
| 422 | `VALIDATION_ERROR` | Chybí povinné pole, neplatný formát, neznámá/chybějící default schránka |
| 500 | `INTERNAL_ERROR` | Neočekávaná chyba (rollback proběhl, uploaded soubory se smažou) |

Mail-router **retryuje jen 5xx**, při 4xx putuje zpráva do DLQ.

Tělo chyby:

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "Schránka 'foo' neexistuje v tomto DS",
        "details": [{"field": "mailbox"}]
    }
}
```

## 4. Idempotence

### 4.1 Generování klíče

Klient generuje `X-Idempotency-Key` deterministicky:

```
sha256(ds_domain + "/" + local_part + "/" + external_message_id)
```

Pokud `external_message_id` chybí, klient **nesmí** posílat idempotency key
(nebo ho vygeneruje náhodně) — server v tom případě idempotenci nevynucuje.

### 4.2 Serverová logika

1. Při příjmu requestu lookup v `core_mail_incoming_idempotency`.
2. **Match + ≤ 7 dní** → server vrátí uloženou odpověď s `idempotent_replay: true`
   a zprávu **nevytvoří znovu**.
3. **No match / expired** → pokračuje ve zpracování. Po commitu tx zapíše key +
   response body do tabulky.

TTL: **7 dní**. Cleanup: `bin/shpd-ds mail-idempotency-prune --days 7` (cron 1×/den).

## 5. Flow na straně Shipardu

```
Router POSTs multipart
  → AuthMiddleware validuje shpd_ak_ token → AuthContext(user=_mail_router)
  → MailController::receiveIncoming
    → verify auth + scope (musí být _mail_router, jinak 403)
    → Idempotency lookup (pokud X-Idempotency-Key)
    → Parse & validate form fields (422 při chybě)
    → Resolve mailbox (explicitní nebo default)
    → BEGIN transaction
      → Insert core_mail_incoming_messages (IncomingMessageDocument::beforeSave
        vygeneruje message_id a normalizuje pole)
      → AttachmentService::upload(raw_source) → UPDATE message.raw_source_attachment
      → Pro každou attachments[]: AttachmentService::upload(file, message_id)
      → IdempotencyStore::store() pokud key existuje
    → COMMIT
  → Response 201
```

Při jakékoli výjimce uvnitř transakce: rollback + unlink orphaned files z disku.

## 6. Auto-provisioning

Výchozí schránku a systémového uživatele `_mail_router` vytváří provisioner
automaticky na konci `bin/shpd-ds ds-upgrade` (idempotentně). Pro existující DS
založené před Fází 2a lze spustit samostatně:

```bash
bin/shpd-ds mail-router-bootstrap
```

Default schránka má:
- `mailbox_id = 'default'`
- `email_address = '{ds-id}@shipard.email'`
- `is_default = 1`
- `docState = 40` (V pořádku)

Per-DS smí existovat nejvýš jedna `is_default = 1` schránka — vynuceno
aplikačně v `MailboxDocument::validate()` (MariaDB neumí filtrovaný unikátní
index).

## 7. Bezpečnost

- **HTTPS povinné** v produkci.
- API klíče se ukládají pouze jako sha256 hash, plaintext se zobrazí jen při
  vytvoření.
- IP whitelist volitelný přes `mail-router-setup --ip=<address>`.
- `body_html` se v UI renderuje sandboxovaně (iframe nebo prefiltrace) — raw
  HTML se v DB ukládá beze změny.
- Antivir scan **neproběhne v Shipardu** — očekává se na straně mail-routeru
  před odesláním.
- Rate limit na `/_mail/incoming` zatím není — mail-router je trusted. Pojistka
  přes `RateLimitMiddleware` může přijít jako follow-up.

## 8. Kompatibilita

Kontrakt je **stable**. Breaking změny vyžadují:

1. Nový versioning přes hlavičku (`X-Mail-Api-Version`), nebo
2. Nový endpoint (`/_mail/v2/incoming`).

Přidávání **volitelných polí** je vždy zpětně kompatibilní.

## 9. Známé limity

- **Velikost message:** v Shipardu žádný tvrdý limit. Postfix v mail-routeru
  odřízne 25 MB dřív. Pro lokální konfiguraci nginx/PHP zvýšit `client_max_body_size`
  a `upload_max_filesize` dle potřeby.
- **Per-request timeout:** PHP-FPM default (typicky 30 s). Pro 20 MB request
  může být těsný — konfigurovat v deploymentu, ne v kódu.
- **Scope restrictions** na API klíče (omezit klíč jen na `/_mail/incoming`)
  zatím neexistuje — follow-up.
