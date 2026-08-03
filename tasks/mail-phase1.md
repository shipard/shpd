# Modul `mail` — Fáze 1: Došlá pošta (evidence + UI)

**Stav:** hotovo

**Cíl fáze:** Plně funkční evidence došlé pošty v aplikaci — datový model, prohlížeč, editor s drag & drop, testovací data. Externí služby (mail-router, AI analyzátor) jsou samostatné fáze.

---

## 1. Scope Fáze 1

**V rozsahu:**

- Nový modul `mail` s dokumentací
- Tabulky: `mail_mailboxes`, `mail_incoming_messages`, `mail_message_analyses`
- cfgItems: `mailIncomingMessagePrimaryTypes`, docState set `mailIncomingStates`
- Viewer `IncomingMessagesViewer` podle vzoru `PersonsViewer`
- Editor `IncomingMessageEditor` s drag & drop příloh (využití `AttachmentPanel`)
- Fake data generator + CLI příkazy `seed-mail` / `seed-mail-clear`
- Náčrt kontraktu pro API endpoint (Fáze 2) — jen dokumentace

**Mimo rozsah (pozdější fáze):**

- Externí mail-router služba (SMTP/IMAP příjem, parsing) → **Fáze 2**
- AI analyzátor → **Fáze 3**
- Threading (`In-Reply-To`, `References`)
- Deduplikace zpráv podle `external_message_id`
- Automatické matchování odesílatele na `base_persons_persons`
- Odeslaná pošta (`mail_outgoing_messages`)

---

## 2. Terminologie

| EN (kód)            | CZ (UI)        | Poznámka                                      |
|---------------------|----------------|-----------------------------------------------|
| Mailbox             | Schránka       | Jedna e-mail adresa s konfigurací             |
| Incoming message    | Došlá zpráva   | Jednotka doručená do schránky                 |
| Primary type        | Primární typ   | Předpokládaný druh dokumentu (cfgItem)        |
| Message analysis    | Analýza zprávy | Výstup AI pipeline (1:N na zprávu)            |
| Raw source          | Originál       | Uložený `.eml` pro audit/debugging            |

**Zpráva ≠ dokument.** Zpráva je transportní jednotka, ze které může časem vzniknout business entita (přijatá faktura, atd.). Vazba na cíl je polymorfní (`target_table_id` + `target_row_ndx`).

---

## 3. Datový model

### 3.1 `mail_mailboxes`

Konfigurované e-mailové schránky DS. Jedna DS může mít N schránek pro různé účely
(faktury, obecný kontakt, podpora, …).

| Pole                        | Typ           | Pozn.                                                       |
|-----------------------------|---------------|-------------------------------------------------------------|
| `ndx`                       | int PK        | standard                                                    |
| `id`                        | string        | standard, lidský identifikátor (`invoices`, `info`, …)      |
| `name`                      | string        | UI název (CZ)                                               |
| `email_address`             | string        | unikátní per DS                                             |
| `description`               | text          | nullable                                                    |
| `default_primary_type`      | string        | cfgItem key, nullable                                       |
| `docState`, `docStateMain`  | tinyint       | standardní archivní set                                     |
| `created`, `createdBy`      | standardní    |                                                             |

Index: unique (`email_address`).

### 3.2 `mail_incoming_messages`

Hlavní tabulka došlé pošty.

| Pole                          | Typ        | Pozn.                                                        |
|-------------------------------|------------|--------------------------------------------------------------|
| `ndx`                         | int PK     |                                                              |
| `id`                          | string     | generovaný identifikátor (`MSG-YYYYMMDD-NNNN`)              |
| `mailbox_ndx`                 | int FK     | → `mail_mailboxes.ndx`, RESTRICT delete                     |
| `source_type`                 | tinyint    | 1=manual, 2=email, 3=api, 4=scan                            |
| `received_at`                 | datetime   | čas doručení (u manuálu = `created`)                        |
| `subject`                     | text       |                                                              |
| `body_plain`                  | mediumtext | nullable                                                     |
| `body_html`                   | mediumtext | nullable                                                     |
| `sender_email`                | string     |                                                              |
| `sender_name`                 | string     | nullable, display name z hlavičky                           |
| `sender_person_ndx`           | int FK     | → `base_persons_persons.ndx`, **NULL ve Fázi 1**            |
| `primary_type`                | string     | cfgItem key                                                  |
| `external_message_id`         | string     | RFC822 Message-ID, nullable (index pro budoucí dedup)       |
| `in_reply_to`                 | string     | nullable (ukládáme, nevyužíváme)                            |
| `references`                  | text       | nullable (ukládáme, nevyužíváme)                            |
| `raw_source_attachment_ndx`   | int FK     | → `core_attachments.ndx`, nullable                          |
| `target_table_id`             | string     | nullable — odkaz na vzniklou entitu                         |
| `target_row_ndx`              | int        | nullable                                                     |
| `docState`, `docStateMain`    | tinyint    | set `mailIncomingStates`                                    |
| `created`, `createdBy`        | standardní |                                                              |

Indexy: `mailbox_ndx`, `received_at` DESC, `external_message_id`, `docState`,
  `(target_table_id, target_row_ndx)`.

**Poznámka k `raw_source_attachment_ndx`:** Originální `.eml` je vždy jako attachment,
ale odkazovaný separátním sloupcem (ne přes společný attachment panel). V UI se
v seznamu příloh nezobrazuje mezi "obsahovými" přílohami — pouze jako samostatný
odkaz "Zobrazit originál" v detailu zprávy.

### 3.3 `mail_message_analyses`

Historie AI analýz. Fáze 1 ji jen zakládá (struktura + CRUD z CLI/testů),
neprovádí žádné volání AI.

| Pole                 | Typ         | Pozn.                                                 |
|----------------------|-------------|-------------------------------------------------------|
| `ndx`                | int PK      |                                                       |
| `message_ndx`        | int FK      | → `mail_incoming_messages.ndx`, CASCADE delete        |
| `analyzed_at`        | datetime    |                                                       |
| `status`             | tinyint     | 1=pending, 2=success, 3=failed                        |
| `model_name`         | string      | `claude-sonnet-4`, `gpt-4o`, …                        |
| `model_version`      | string      | nullable                                              |
| `prompt_version`     | string      | verze promptu (SemVer nebo hash)                      |
| `analysis_json`      | mediumtext  | strukturovaný výstup, nullable u failed               |
| `confidence`         | decimal(4,3)| 0.000–1.000, nullable                                 |
| `error_message`      | text        | nullable                                              |
| `tokens_input`       | int         | nullable — pro cost tracking                          |
| `tokens_output`      | int         | nullable                                              |
| `duration_ms`        | int         | nullable                                              |
| `created`, `createdBy` | standardní |                                                       |

Index: `message_ndx`, `(message_ndx, analyzed_at DESC)`.

"Current" analýza se určuje jako `MAX(analyzed_at)` per message, žádný `is_current` flag.

---

## 4. cfgItems

### 4.1 `mailIncomingMessagePrimaryTypes`

Umístění: `modules/mail/cfgItems/mailIncomingMessagePrimaryTypes.jsonc`

Výchozí sada (ve Fázi 1 aktivní jen první dvě, zbytek připravený, `enabled: false`):

```jsonc
{
  "invoiceReceived": { "name": "Přijatá faktura", "enabled": true,  "order": 10 },
  "other":           { "name": "Ostatní",         "enabled": true,  "order": 999 },
  "creditNote":      { "name": "Dobropis",        "enabled": false, "order": 20 },
  "order":           { "name": "Objednávka",      "enabled": false, "order": 30 },
  "quotation":       { "name": "Nabídka",         "enabled": false, "order": 40 },
  "statement":       { "name": "Výpis / Saldo",   "enabled": false, "order": 50 },
  "complaint":       { "name": "Reklamace",       "enabled": false, "order": 60 }
}
```

### 4.2 docState set `mailIncomingStates`

Umístění: `modules/mail/cfgItems/docStates/mailIncomingStates.jsonc`
Aktualizovat: `docs/doc-states.md`.

| Kód | Název CZ        | `docStateMain` | `viewGroup` | `stateStyle` | `readOnly` | `mainState` |
|-----|-----------------|----------------|-------------|--------------|------------|-------------|
| 10  | Nová            | 10             | active      | `st-new`     | false      | true        |
| 20  | V analýze       | 20             | active      | `st-working` | true       | false       |
| 30  | Analyzovaná     | 30             | active      | `st-ready`   | false      | true        |
| 40  | Zpracovaná      | 40             | active      | `st-done`    | true       | true        |
| 80  | Archiv          | 80             | archive     | `st-archive` | true       | false       |
| 90  | Trash           | 90             | trash       | `st-trash`   | true       | false       |

`goto` transitions:

- 10 → {20, 40, 80, 90}
- 20 → {30, 10 (failed re-queue)} (nastavuje jen AI služba ve Fázi 3, manuálně nepřístupné)
- 30 → {40, 10 (reset), 80, 90}
- 40 → {80, 90}
- 80 → {10, 90}
- 90 → {10}

Do doby, než existuje AI služba (Fáze 3), jsou stavy 20 a 30 využívány pouze v testech.

---

## 5. Viewer specifikace

**Komponenta:** `IncomingMessagesViewer.svelte`
**Vzor:** `PersonsViewer.svelte`
**Tabulka:** `mail_incoming_messages`

### 5.1 Layout řádku

| Slot | Obsah                                                                  |
|------|------------------------------------------------------------------------|
| t1   | `subject` (zkráceno na 1 řádek)                                        |
| i1   | `received_at` (relativní: "před 2 h", "včera 14:32", "12. 3.")         |
| t2   | `sender_name` pokud není prázdné, jinak `sender_email`                 |
| i2   | Badge s názvem `primary_type` (barva podle cfgItem)                    |
| t3   | `[mailbox.name]` + první řádek `body_plain` (preview, zkrácené)        |

### 5.2 Fulltext search

Prohledává: `subject`, `sender_email`, `sender_name`, `body_plain`. Debounce 300 ms (konzistentně s `PersonsViewer`).

### 5.3 Detail panel

Taby:

1. **Obsah** — subject, sender, tělo (HTML preferované, plain fallback)
2. **Přílohy** — `AttachmentPanel` s `tableId='mail_incoming_messages'`
3. **Analýzy** — seznam z `mail_message_analyses` (zatím prázdný), latest = default
4. **Originál** — samostatný tab; odkaz na `raw_source_attachment_ndx` s preview

### 5.4 docState toolbar

Standardní, využívá komponentu z docState systému. V active view: Nová, V analýze,
Analyzovaná, Zpracovaná. Archive a Trash přes viewGroup přepínač.

---

## 6. Editor specifikace

**Komponenta:** `IncomingMessageEditor.svelte`
**Režim:** `create` (manuální pořízení) / `edit` (úprava existující)

### 6.1 Formulář

- **Schránka** (select z `mail_mailboxes`, povinné; při create default = první active)
- **Předmět** (text, povinné)
- **Odesílatel — e-mail** (text, povinné)
- **Odesílatel — jméno** (text, volitelné)
- **Datum doručení** (datetime; při create default = now)
- **Primární typ** (select z cfgItem; default = `default_primary_type` schránky nebo `other`)
- **Tělo** (textarea; zatím jen plain — HTML edit až později)
- **Přílohy** (`AttachmentPanel` s drag & drop)

### 6.2 Chování

- `source_type` při create = `manual` (1), needitovatelné
- Uložením se nastaví `docState = 10` (Nová)
- `external_message_id` se negeneruje (zůstane NULL)
- `raw_source_attachment_ndx` se v Fázi 1 neplní (u manuálu není zdrojový `.eml`)

### 6.3 Drag & drop preview (Fáze 3+)

V PRD je to jen poznámka: časem bude drag & drop přílohy spouštět AI extrakci a
prefillnout formulář. Ve Fázi 1 jen ukládáme přílohu, nic víc.

---

## 7. Fake data

### 7.1 `FakeMailboxGenerator`

Vytvoří 3 testovací schránky:

- `invoices@` — default_primary_type = `invoiceReceived`
- `info@` — default_primary_type = `other`
- `support@` — default_primary_type = `other`

Doména podle testovacího DS (placeholder). Identifikace: `mailbox.id` začíná `TEST-`.

### 7.2 `FakeIncomingMessageGenerator`

- 40–80 zpráv rozdělených mezi schránky
- Subject z předem definovaného seznamu českých vzorů
  (`Faktura č. 2026000123`, `Objednávka …`, `Výzva k platbě …`, `Newsletter …`, …)
- Sender_email z Faker-style generátoru (česká doména, `@firma.cz`)
- `received_at` v rozsahu posledních 90 dní
- `primary_type` odvozený podle subjectu (jednoduchý keyword match)
- Distribuce `docState`: cca 60 % Nová, 20 % Analyzovaná, 15 % Zpracovaná, 5 % Archiv

Identifikace test záznamů: `id` začíná `TEST-MSG-`.

### 7.3 Přílohy

V `modules/mail/testdata/` uložen jeden vzorový PDF (`sample-invoice.pdf`) —
cca 80 % zpráv dostane jednu kopii tohoto PDF jako přílohu, ostatní bez příloh.

Sekundární testovací přílohy (text files) jsou mimo scope — jeden PDF stačí pro
demonstraci flow.

### 7.4 CLI příkazy

- `bin/shpd-ds seed-mail` — založí fake data
- `bin/shpd-ds seed-mail-clear` — smaže vše s `TEST-MSG-` / `TEST-` prefixem

---

## 8. Vztah k externí službě (náčrt Fáze 2)

Mail-router bude samostatná služba v samostatném repozitáři. Do `shpd` přijímá
zprávy přes interní API endpoint. Pro účely Fáze 1 **nepotřebujeme endpoint
implementovat**, ale kontrakt si nastřelíme, aby se Fáze 2 nemusela vracet ke
schématu.

**Návrh endpointu** (pouze dokumentace, `docs/mail/api-contract.md`):

```
POST /_mail/incoming
Content-Type: multipart/form-data

Fields:
  mailbox           string   (povinné, mapuje se na mail_mailboxes.id)
  external_message_id string (volitelné)
  received_at       ISO8601  (povinné)
  subject           string
  sender_email      string   (povinné)
  sender_name       string
  body_plain        text
  body_html         text
  in_reply_to       string
  references        string
  raw_source        file     (`.eml`, povinné)
  attachments[]     file     (0..N)

Response:
  201 Created
  { "message_id": "MSG-20260417-0001", "ndx": 12345 }
```

Endpoint bude chráněn per-DS bearer tokenem z konfigurace. Detaily auth jsou
mimo scope Fáze 1.

---

## 9. Task breakdown pro Claude Code

Níže jednotky commitovatelné odděleně. Pořadí má význam (závislosti).

### Task 1 — Skeleton modulu `mail`

**Cíl:** Připravit prázdný modul, zaregistrovat ho.

- `modules/mail/module.jsonc` (id, name, dependencies: `core`, `base.persons`)
- `modules/mail/README.md` (stručný popis modulu)
- `modules/mail/docs/documentation.md` (stub)
- Adresáře `tables/`, `cfgItems/`, `cfgItems/docStates/`, `testdata/`
- Registrace modulu v central module loaderu

**Akceptace:** `bin/shpd-ds` vidí modul, žádné nové tabulky, testy procházejí.

### Task 2 — cfgItem `mailIncomingMessagePrimaryTypes` + docState set

- `cfgItems/mailIncomingMessagePrimaryTypes.jsonc` (viz §4.1)
- `cfgItems/docStates/mailIncomingStates.jsonc` (viz §4.2)
- Update `docs/doc-states.md` — přidat sekci pro nový set

**Akceptace:** cfgItems načtené, docState set registrovaný, v docs referenčně zmíněn.

### Task 3 — Tabulka `mail_mailboxes`

- JSONC definice tabulky podle §3.1
- Migrace DsCreate/DsUpgrade
- Markdown docs `tables/mail_mailboxes.md`
- Unit test základní CRUD (insert/update/delete)

**Akceptace:** Tabulka existuje v novém DS, lze do ní vkládat, testy zelené.

### Task 4 — Tabulka `mail_incoming_messages`

- JSONC definice podle §3.2
- Migrace
- Indexy podle §3.2
- Markdown docs
- Napojení docState systému na set `mailIncomingStates`
- Unit test CRUD + state transitions

**Akceptace:** Tabulka funkční, docState transitions fungují podle §4.2.

### Task 5 — Tabulka `mail_message_analyses`

- JSONC definice podle §3.3
- Migrace + CASCADE delete test
- Markdown docs

**Akceptace:** Vložená zpráva + 2 analýzy, smazání zprávy → analýzy zmizí. MAX(analyzed_at) query funguje.

### Task 6 — Fake data generátory

- `FakeMailboxGenerator`
- `FakeIncomingMessageGenerator`
- Uložení `testdata/sample-invoice.pdf`
- CLI příkazy `seed-mail`, `seed-mail-clear`
- Přílohy přes `core.attachments` API (viz existující `FakePersonGenerator` vzor)

**Akceptace:** `seed-mail` vytvoří 3 schránky + 40–80 zpráv + přílohy. `seed-mail-clear` vše vyčistí.

### Task 7 — `IncomingMessagesViewer`

- `src/modules/mail/IncomingMessagesViewer.svelte` podle `PersonsViewer`
- Layout řádků podle §5.1
- Fulltext search podle §5.2
- Detail panel s taby Obsah / Přílohy / Analýzy / Originál
- docState toolbar
- Server-side endpoint `GET /_mail/messages` pro list + search (pagination, filtering)

**Akceptace:** V browseru načte 40+ seeded zpráv, search funguje, detail otvírá přílohy, docState filtr funguje.

### Task 8 — `IncomingMessageEditor`

- `src/modules/mail/IncomingMessageEditor.svelte`
- Formulář podle §6.1, create + edit režim
- `AttachmentPanel` integrace (drag & drop)
- Server-side endpoint `POST /_mail/messages` a `PUT /_mail/messages/:ndx`
- Validace polí

**Akceptace:** Lze ručně založit zprávu, přetáhnout přílohu, uložit. Reload → zpráva + příloha viditelná.

### Task 9 — Dokumentace a finalizace

- Update `modules/mail/docs/documentation.md` (architektura, datový model, flow)
- `docs/mail/api-contract.md` — kontrakt pro Fázi 2 (viz §8)
- Update `docs/README.md` — přidat modul do seznamu
- Update `docs/frontend.md` — nový viewer

**Akceptace:** Dokumentace prochází internal review checklistem projektu.

---

## 10. Rozhodnutí k designu (potvrzená 17. 4. 2026)

1. ✓ **Raw `.eml` jako separátní sloupec `raw_source_attachment_ndx`** (ne flag na attachmentu).
   Attachment panel zůstává čistý pro "obsahové" přílohy, raw source je oddělený koncept.

2. ✓ **Layout viewer řádku** podle §5.1 — t3 kombinuje `mailbox.name` (zkrácené, v závorkách)
   s preview prvního řádku těla.

3. ✓ **cfgItem keys** — rozšířená sada v JSONC s `enabled: false` pro budoucí typy
   (creditNote, order, quotation, statement, complaint). Ve Fázi 1 aktivní: invoiceReceived, other.

4. ✓ **Fake data** — jeden vzorový PDF kopírovaný jako placeholder (~80 % zpráv).

5. ✓ **Distribuce `docState`** ve fake datech: 60 % Nová, 20 % Analyzovaná, 15 % Zpracovaná, 5 % Archiv.
