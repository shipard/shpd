# `core.mail` — API kontrakt pro mail-router (Fáze 2)

> **Status:** Návrh — kontrakt je zafixovaný ve Fázi 1, endpoint se implementuje
> ve Fázi 2. Tento dokument slouží autorům externí mail-router služby a
> autorovi Fáze 2 jako závazná specifikace, aby se schéma nemuselo měnit.

## 1. Přehled

Mail-router je **samostatná služba** v samostatném repozitáři. Přijímá e-maily
přes SMTP/IMAP, parsuje `.eml`, a každou zprávu odešle do Shipardu přes
**interní HTTP API endpoint**. Shipard zprávu validuje, uloží a vrací ID
nového záznamu.

### Separace zodpovědností

| Vrstva | Odpovědnost |
|---|---|
| Mail-router | Příjem SMTP/IMAP, parsing MIME, deduplikace na úrovni doručování, retry queue |
| Shipard `/_mail/incoming` | Validace, uložení do DB, uložení attachmentů, vrácení `message_id` |
| Shipard `IncomingMessageDocument` | Generování `message_id`, audit pole, cascade delete |

Mail-router **netuší nic** o business modelech Shipardu — posílá data ve
definovaném kontraktu.

## 2. Endpoint

```
POST /{ds-id}/_mail/incoming
Content-Type: multipart/form-data
Authorization: Bearer <per-ds-token>
```

### 2.1 Autentizace

Per-DS bearer token definovaný v konfiguraci data sourcu:

```jsonc
// /opt/shipard/data-sources/{ds-id}/config/main.json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "mail": {
        "incomingApiToken": "<náhodných 64 znaků>"
    }
}
```

Token je long-lived (ne session). Rotace manuální přes admin (mimo scope Fáze 2).

### 2.2 Pole požadavku

| Pole | Typ | Povinné | Popis |
|---|---|---|---|
| `mailbox` | string | ✓ | `core_mail_mailboxes.mailbox_id` — lidský kód schránky |
| `received_at` | ISO 8601 | ✓ | Čas doručení s TZ, např. `2026-04-17T10:23:00+02:00` |
| `subject` | string | ✓ | Předmět zprávy (UTF-8, libovolná délka) |
| `sender_email` | string | ✓ | E-mailová adresa z `From:` (pouze email, bez display name) |
| `sender_name` | string |  | Display name z `From:` — nullable |
| `external_message_id` | string |  | RFC822 `Message-ID` — používá se v budoucnosti pro dedup |
| `in_reply_to` | string |  | RFC822 `In-Reply-To` header |
| `references` | string |  | RFC822 `References` header (čárkou nebo mezerou oddělený seznam) |
| `body_plain` | string |  | Tělo v plain textu |
| `body_html` | string |  | Tělo v HTML (preferováno pro UI renderování) |
| `raw_source` | file | ✓ | Originální `.eml` (MIME `message/rfc822`) |
| `attachments[]` | file |  | Obsahové přílohy — 0..N |

### 2.3 Validace

Server provádí:

1. Ověření bearer tokenu (401 při neshodě).
2. Schránka s `mailbox_id = {mailbox}` existuje a není ve stavu `archive` / `trash` (404 jinak).
3. `sender_email` projde `FILTER_VALIDATE_EMAIL` (422 jinak).
4. `received_at` je validní ISO 8601 datetime (422 jinak).
5. Velikost `raw_source` a všech `attachments[]` ≤ DS limit (413 jinak).
6. Neduplicitní `external_message_id` pro danou schránku — **pouze warning** ve Fázi 2,
   deduplikace se implementuje až ve Fázi 4.

## 3. Odpověď

### 3.1 Success — 201 Created

```json
{
    "success": true,
    "data": {
        "id": 12345,
        "message_id": "MSG-20260417-0023",
        "docState": 10
    }
}
```

### 3.2 Chybové odpovědi

| Kód | Error code | Situace |
|---|---|---|
| 400 | `INVALID_REQUEST` | Chybí povinné pole nebo neparsovatelný multipart |
| 401 | `UNAUTHORIZED` | Chybí / neplatný bearer token |
| 404 | `UNKNOWN_MAILBOX` | Schránka s `mailbox_id = {mailbox}` neexistuje nebo je v archive/trash |
| 413 | `PAYLOAD_TOO_LARGE` | Attachment překračuje limit |
| 422 | `VALIDATION_FAILED` | `sender_email` / `received_at` ve špatném formátu |
| 500 | `INTERNAL_ERROR` | Neočekávaná chyba (např. DB výpadek) |

Tělo chyby odpovídá standardní Shipard chybové struktuře (viz [`docs/rest-api.md`](../rest-api.md)):

```json
{
    "success": false,
    "error": {
        "code": "UNKNOWN_MAILBOX",
        "message": "Schránka 'invoices' neexistuje nebo není aktivní",
        "details": []
    }
}
```

## 4. Idempotence a retry

### 4.1 Retry na straně klienta

Mail-router má vlastní persistent queue. Při 5xx nebo síťové chybě:
1. Exponential backoff (1s, 5s, 30s, 2min, …).
2. Po 10 neúspěšných pokusech se zpráva přesune do DLQ a odešle alert.

### 4.2 Idempotence na straně serveru

Server **není** idempotentní per se — každý request vygeneruje nový `message_id`.
Pokud mail-router odešle duplicitní request (např. po timeout + retry), vzniknou
**dvě zprávy v DB**.

Ve Fázi 4 implementujeme dedup na `external_message_id` + `mailbox` — pokud
takový záznam už existuje, server vrátí 200 s existujícím ID:

```json
{
    "success": true,
    "data": {
        "id": 12345,
        "message_id": "MSG-20260417-0023",
        "docState": 10,
        "duplicate": true
    }
}
```

Dokud dedup neexistuje, mail-router musí mít vlastní lokální dedup (hash
`Message-ID` v jeho persistent queue).

## 5. Flow na straně Shipardu

Při přijetí requestu:

1. Middleware ověří bearer token.
2. Controller parsuje multipart, mapuje pole na data array.
3. Ukládá `raw_source` do `core_attachments_files` (table_id = 303, record_id = 0 placeholder).
4. Ukládá obsahové `attachments[]` do `core_attachments_files`.
5. `IncomingMessageDocument::beforeSave()` generuje `message_id`, audit pole.
6. `INSERT INTO core_mail_incoming_messages` (v transakci).
7. Aktualizuje `raw_source_attachment` na ID z kroku 3 + přemapuje `record_id`
   attachmentů z kroku 4 na nové `message.id`.
8. Commit transakce → 201.

## 6. Bezpečnost

- **HTTPS povinné** v produkci (v development módu HTTP přípustné pro localhost).
- Bearer token je per-DS — kompromitace tokenu ohrozí pouze jednu DS.
- Attachmenty jsou naskenovány na MIME podle obsahu (ne jen podle
  Content-Type hlavičky) — viz `AttachmentService::detectMimeType()`.
- Velikost single-request limitovaná nginx / PHP konfigurací.
- `body_html` se při zobrazení sanituje — nikdy se nerenderuje jako `innerHTML`
  bez izolace (sandboxovaný iframe nebo prefiltrace).

## 7. Kompatibilita

Tento kontrakt je **stable** od Fáze 2. Breaking změny vyžadují:

1. Nový versioning přes hlavičku (`X-Mail-Api-Version`), nebo
2. Nový endpoint (`/_mail/v2/incoming`).

Přidávání **volitelných polí** je vždy zpětně kompatibilní.

## 8. Otevřené otázky (k dořešení před Fází 2)

- [ ] Jak mail-router získá bearer token? (Sdílený config adresář? Tooling?)
- [ ] Preferujeme multipart nebo JSON + base64 attachments?
  (multipart víc streamovací, JSON snadnější debug)
- [ ] Jak mapujeme emoji / non-UTF-8 kódování z originálního `.eml`?
- [ ] Timeout pro upload velkých attachmentů — per-DS nebo global?
