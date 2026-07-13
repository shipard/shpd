# Tabulka: Odesílatelé pošty (core_mail_senders)

Per-sender SMTP transporty pro odchozí poštu — generalizace osvědčených
sendmails ze starého Shipardu. Když odchozí zpráva má `email_from`, který
odpovídá aktivnímu záznamu zde, odejde přes jeho SMTP server (typicky
Google Workspace app password — zpráva skončí v Odeslané poště uživatele,
nulový spam skór). Bez shody jde zpráva přes relay z konfigurace
(server.json / main.json, klíč `mail.relay`). Resolution dělá
`Shipard\Core\Mail\TransportResolver`.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `email_from` | varchar(200), NOT NULL, UNIQUE | From adresa — klíč lookupů (case-insensitive) |

### SMTP transport (transport)

| Sloupec | Typ | Popis |
|---|---|---|
| `smtp_host` | varchar(200), NOT NULL | SMTP server |
| `smtp_port` | int, NOT NULL, default 587 | Port |
| `smtp_security` | varchar(10), default `starttls` | `starttls` (587), `tls` (implicitní TLS, 465), `none` (localhost:25) |
| `smtp_username` | varchar(200) | Přihlašovací jméno; NULL = bez autentizace |
| `password_enc` | encrypted_text, **sensitive** | Heslo šifrované `DsSecretCipher`. Nikdy nejde přes generické CRUD — čtení se stripuje, zápis vrací 400. Nastavuje se výhradně přes `POST /_mail/senders/{id}/password` (admin). |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_active` | boolean, default 1 | Neaktivní sender se při resolution ignoruje (zpráva jde přes relay) |
| `created` | datetime, NOT NULL | Čas vytvoření |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_email_from` | unique | `email_from` | Jedna from adresa = jeden transport |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_outbox](core_mail_outbox.md) | `outbox.email_from` → lookup | Volná vazba přes adresu, ne id — sender lze smazat bez dopadu na historii |

## Mazání a reset

Tabulka je v `keepOnReset` — je to konfigurace, ne data. Smazání senderu
nic neláme: zprávy s jeho from adresou začnou padat na relay (nebo na
chybu „no relay configured", kterou reportuje alert check).
