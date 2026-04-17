# Modul: Pošta (core.mail)

Modul spravuje e-mailovou komunikaci. Fáze 1 implementuje evidenci došlé pošty —
schránky, příchozí zprávy a strukturu pro AI analýzy. Pozdější fáze přidají
externí mail-router, AI analyzátor a odeslanou poštu.

## Závislosti

- `core.system` — docState set (archivační), uživatelé
- `core.attachments` — přílohy zpráv a uložený originál (`.eml`)
- `base.persons` — budoucí matching odesílatele na osobu (Fáze 3)

## Tabulky

| Tabulka | Popis |
|---|---|
| [core_mail_mailboxes](tables/core_mail_mailboxes.md) | Konfigurované e-mailové schránky DS |
| [core_mail_incoming_messages](tables/core_mail_incoming_messages.md) | Došlé zprávy |
| [core_mail_message_analyses](tables/core_mail_message_analyses.md) | Historie AI analýz zpráv |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [IncomingMessageDocument.php](src/IncomingMessageDocument.php) | Document třída pro došlé zprávy — validace, generování ID |
| [IncomingMessagesForm.php](src/IncomingMessagesForm.php) | Formulář pro ruční pořízení a úpravu došlé zprávy |
| [IncomingMessagesViewer.php](src/IncomingMessagesViewer.php) | Viewer pro seznam došlých zpráv |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.mail.primaryTypes` | [config/primaryTypes.jsonc](config/primaryTypes.jsonc) | Primární typy došlých zpráv (faktura, objednávka, …) |
| `core.mail.docStatesIncoming` | [config/docStatesIncoming.jsonc](config/docStatesIncoming.jsonc) | Stavy životního cyklu došlé zprávy |

## Dokumentace

- [docs/documentation.md](docs/documentation.md) — architektura modulu, datový tok, vztah k externím službám
