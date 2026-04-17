# Modul `core.mail` — dokumentace

Modul spravuje e-mailovou komunikaci. **Fáze 1** implementuje evidenci došlé pošty:
datový model, ruční pořízení, prohlížeč, fake data. Externí služby (mail-router,
AI analyzátor) jsou samostatné fáze.

## 1. Rozsah Fáze 1

**V rozsahu:**

- 3 tabulky: `core_mail_mailboxes`, `core_mail_incoming_messages`, `core_mail_message_analyses`
- 2 cfgItemy: `core.mail.primaryTypes`, `core.mail.docStatesIncoming`
- PHP třídy: `IncomingMessagesViewer`, `IncomingMessagesForm`, `IncomingMessageDocument`, `FakeMailboxGenerator`, `FakeIncomingMessageGenerator`
- CLI: `seed-mail`, `seed-mail-clear`
- Kontrakt pro API endpoint (Fáze 2) — viz [`docs/mail/api-contract.md`](../../../../docs/mail/api-contract.md)

**Mimo rozsah:**

- Mail-router (SMTP/IMAP příjem, parsing `.eml`) — Fáze 2
- AI analyzátor — Fáze 3
- Threading (`In-Reply-To`, `References`) — ukládáme, nezpracováváme
- Deduplikace dle `external_message_id` — ukládáme, nevyužíváme
- Automatické matchování odesílatele na `base_persons_persons` — naplňuje Fáze 3
- Odeslaná pošta (`core_mail_outgoing_messages`) — pozdější fáze

## 2. Terminologie

| EN (kód)          | CZ (UI)        | Popis                                                     |
|-------------------|----------------|-----------------------------------------------------------|
| Mailbox           | Schránka       | Jedna e-mailová adresa s konfigurací                      |
| Incoming message  | Došlá zpráva   | Jednotka doručená do schránky                             |
| Primary type      | Primární typ   | Předpokládaný druh dokumentu (cfgItem klíč)               |
| Message analysis  | Analýza zprávy | Výstup AI pipeline (1:N na zprávu)                        |
| Raw source        | Originál       | Uložený `.eml` pro audit/debugging                        |

**Zpráva ≠ dokument.** Zpráva je pouze transportní jednotka. Business entita
(přijatá faktura, objednávka, …) vzniká zpracováním zprávy a je držena v jiné
tabulce. Vazba je polymorfní přes `target_table_id` + `target_row` na
`core_mail_incoming_messages`.

## 3. Datový tok

```
┌──────────────────┐
│ External source  │   Mail-router (Fáze 2), API, manuální pořízení
│  (SMTP / IMAP)   │
└────────┬─────────┘
         │ POST /_mail/incoming  (kontrakt pro Fázi 2)
         ▼
┌─────────────────────────────┐
│ core_mail_incoming_messages │   docState = 10 (Nová)
│ + core_attachments_files    │   obsahové přílohy + raw .eml
└────────┬────────────────────┘
         │  zařazení do fronty (Fáze 3)
         ▼                            20 (V analýze, readOnly)
┌──────────────────────────┐
│  AI analyzer (Fáze 3)    │─→ core_mail_message_analyses
└────────┬─────────────────┘        (historie pokusů; MAX(analyzed_at) = aktuální)
         ▼                           30 (Analyzovaná)
┌──────────────────────────┐
│  Uživatel potvrdí       │  target_table_id, target_row → business entita
│  a vznikne business       │  (např. economy.docs.issued_invoices_received)
│  entita (manuálně)       │           40 (Zpracovaná)
└──────────────────────────┘
```

## 4. Datový model

Podrobnosti ke každé tabulce jsou v samostatných Markdown souborech:

- [`tables/core_mail_mailboxes.md`](../tables/core_mail_mailboxes.md) — schránky
- [`tables/core_mail_incoming_messages.md`](../tables/core_mail_incoming_messages.md) — zprávy
- [`tables/core_mail_message_analyses.md`](../tables/core_mail_message_analyses.md) — analýzy

### 4.1 Raw `.eml` jako separátní sloupec

`core_mail_incoming_messages.raw_source_attachment` je FK do
`core_attachments_files`, ale **nikoli** obsahová příloha zprávy. Viewer
a editor ji v panelu „Přílohy" nezobrazují — je dostupná pouze v tabu „Originál".
Důvod: oddělit audit artefakty od uživatelsky relevantních dokumentů.

### 4.2 Obsahové přílohy

Uloženy standardně v `core_attachments_files` s `table_id = 303` (tableId
`core_mail_incoming_messages`) a `record_id = message.id`. Přes standardní
`AttachmentService` — žádný vlastní flow.

### 4.3 AI analýzy bez `is_current` flagu

Historie je chronologická (`analyzed_at` DESC). „Aktuální" analýza = první
řádek podle `MAX(analyzed_at)` per `message`. Nezavádíme `is_current`,
abychom se vyhnuli nutnosti ho přepínat při každém novém pokusu.

## 5. Stavový automat

Definován v [`config/docStatesIncoming.jsonc`](../config/docStatesIncoming.jsonc)
(`core.mail.docStatesIncoming`). Viz také [`docs/doc-states.md §8.1`](../../../../docs/doc-states.md).

| docState | Název | viewGroup | Přechody do |
|---|---|---|---|
| 10 | Nová | active | 20, 40, 80, 90 |
| 20 | V analýze | active (readOnly) | 30, 10 — nastavuje pouze AI pipeline |
| 30 | Analyzovaná | active | 40, 10, 80, 90 |
| 40 | Zpracovaná | active (readOnly) | 80, 90 |
| 80 | Archiv | archive | 10, 90 |
| 90 | Trash | trash | 10 |

Stav 20 je nepřístupný z UI — manipuluje s ním výhradně AI pipeline (Fáze 3).
Do té doby jsou stavy 20 a 30 využívány pouze v testech a seedu.

## 6. Komponenty

### 6.1 Viewer (`IncomingMessagesViewer`)

PHP třída extends `TableViewer`. Layout řádku 5-slotový:

- `t1` — subject (bold, left)
- `i1` — received_at relativně („před 2 h", „včera 14:32", „12. 3.")
- `t2` — sender_name, fallback sender_email
- `i2` — badge s názvem primárního typu (barva dle mapy)
- `t3` — `[mailbox.name]` + první řádek body_plain (preview, zkrácené)

Detail panel: 4 taby — Obsah / Přílohy / Analýzy / Originál.

Fulltext hledá v `subject`, `sender_email`, `sender_name`, `body_plain`.

### 6.2 Formulář (`IncomingMessagesForm`)

PHP třída extends `TableForm`. Dva taby:

1. **Zpráva** — schránka (select), datum doručení, primární typ (select),
   odesílatel (email + jméno), předmět, tělo (textarea)
2. **Přílohy** — standardní `AttachmentPanel` (drag & drop, tableId = 303)

Změna schránky spustí `recalculate` — pokud uživatel nemá `primary_type`
vyplněný, doplní se `mailbox.default_primary_type` (fallback `other`).

### 6.3 Document (`IncomingMessageDocument`)

Životní hooky:

- `validate()` — povinné pole `mailbox`, `subject`, `sender_email` (validní e-mail), `received_at`
- `beforeSave()` — normalizace sender_email (lowercase/trim), default `source_type = 1`,
  generování `message_id` ve tvaru `MSG-YYYYMMDD-NNNN`, audit pole
- `beforeDelete()` — cascade delete přidružených analýz a obsahových attachmentů
  (Shipard nepoužívá FOREIGN KEY, integritu řeší aplikace)

## 7. CLI příkazy

```bash
# Z adresáře data sourcu
cd /opt/shipard/data-sources/<ds-id>

shpd-ds seed-mail                  # 3 schránky (TEST-invoices, -info, -support) + 60 zpráv
shpd-ds seed-mail --count 80       # ručně 80 zpráv
shpd-ds seed-mail --attachment-ratio 50  # jen 50 % zpráv dostane PDF přílohu

shpd-ds seed-mail-clear            # smaže TEST- schránky a TEST-MSG- zprávy včetně analýz a attachmentů
```

Seed příkaz je idempotentní nad `TEST-` schránkami — pokud existují, použije je.
`message_id` indexy pokračují od posledního použitého (lze volat opakovaně a doplňovat).

## 8. Extensions (budoucí)

Jiné moduly mohou rozšířit tabulky `core_mail` o sloupce, které je zajímají.
Příklady:

- `economy.docs` přidá `extensions/ext-core-mail-incoming-messages.jsonc` s FK
  na `invoice_ref` pro rychlý přímý odkaz bez polymorfní vazby.
- `base.persons` přidá auto-match logiku na `sender_email` → `sender_person`.

Ve Fázi 1 žádný jiný modul tabulky `core.mail` nerozšiřuje.

## 9. Bezpečnost (výhled pro Fázi 2)

- API endpoint pro příjem zpráv bude chráněn **per-DS bearer tokenem** v konfiguraci.
- Rate limiting (shodný s ostatními API endpointy).
- Sanitace HTML těla při zobrazení (klient: trustované rendering nebo oriframe).
- Validace velikosti attachmentů (MAX_UPLOAD_SIZE v DS konfiguraci).

## 10. Odkazy

- PRD Fáze 1: [`tasks/mail-phase1.md`](../../../../tasks/mail-phase1.md)
- API kontrakt Fáze 2: [`docs/mail/api-contract.md`](../../../../docs/mail/api-contract.md)
- docStates specifikace: [`docs/doc-states.md`](../../../../docs/doc-states.md)
- Attachments systém: [`docs/attachments.md`](../../../../docs/attachments.md)
