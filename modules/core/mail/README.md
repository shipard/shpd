# Modul: Pošta (core.mail)

Modul spravuje e-mailovou komunikaci. Fáze 1 implementuje evidenci došlé pošty —
schránky, příchozí zprávy a strukturu pro AI analýzy. Fáze 2a přidává HTTP
endpoint pro příjem pošty z externí služby `shipard-mail-router`. Pozdější fáze
přidají AI analyzátor a odeslanou poštu.

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
| [core_mail_incoming_idempotency](tables/core_mail_incoming_idempotency.md) | Idempotency klíče pro `POST /_mail/incoming` (TTL 7 dní) |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [IncomingMessageDocument.php](src/IncomingMessageDocument.php) | Document třída pro došlé zprávy — validace, generování ID |
| [MailboxDocument.php](src/MailboxDocument.php) | Document třída pro schránky — validace + invariant "max 1 `is_default` per DS" |
| [IncomingMessagesForm.php](src/IncomingMessagesForm.php) | Formulář pro ruční pořízení a úpravu došlé zprávy |
| [IncomingMessagesViewer.php](src/IncomingMessagesViewer.php) | Viewer pro seznam došlých zpráv |
| [MailRouterProvisioner.php](src/MailRouterProvisioner.php) | Idempotentní bootstrap systémového uživatele `_mail_router` + default schránky |
| [IdempotencyStore.php](src/IdempotencyStore.php) | Lookup/store pro idempotency klíče endpointu `/_mail/incoming` |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.mail.primaryTypes` | [config/primaryTypes.jsonc](config/primaryTypes.jsonc) | Primární typy došlých zpráv (faktura, objednávka, …) |
| `core.mail.docStatesIncoming` | [config/docStatesIncoming.jsonc](config/docStatesIncoming.jsonc) | Stavy životního cyklu došlé zprávy |

## API endpointy

| Endpoint | Popis |
|---|---|
| `POST /api/v1/_mail/incoming` | Příjem došlé pošty z externí služby `shipard-mail-router`. Multipart upload (zpráva + raw `.eml` + 0..N příloh), autentizace přes `shpd_ak_` klíč systémového uživatele `_mail_router`, idempotence přes `X-Idempotency-Key` (TTL 7 dní). Kontrakt: [docs/mail/api-contract.md](../../../docs/mail/api-contract.md) |

## CLI příkazy

| Příkaz | Popis |
|---|---|
| `bin/shpd-ds mail-router-bootstrap` | Idempotentně založí systémového uživatele `_mail_router` + výchozí schránku `default`. Volá se automaticky z `ds-upgrade`. |
| `bin/shpd-ds mail-router-setup [--force] [--ip=X]` | Vygeneruje (nebo zrotuje) API klíč pro mail-router. |
| `bin/shpd-ds mail-idempotency-prune [--days N]` | Vymaže idempotency klíče starší N dní (default 7). Spouštět z cronu 1×/den. |

## Dokumentace

- [docs/documentation.md](docs/documentation.md) — architektura modulu, datový tok, vztah k externím službám
