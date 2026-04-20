# Tabulka: Idempotency klíče došlé pošty (core_mail_incoming_idempotency)

Logika deduplikace pro endpoint `POST /_mail/incoming`. Klient (mail-router) generuje
deterministický `X-Idempotency-Key` z `sha256(domain + "/" + local_part + "/" +
external_message_id)`. Při každém požadavku se provede lookup — pokud klíč existuje,
vrátí se uložená odpověď (zpráva se znovu neukládá).

TTL je 7 dní; staré záznamy se mažou CLI příkazem `mail-idempotency-prune` (cron 1×/den).

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `id` | int PK | Autoincrement |
| `idempotency_key` | varchar(64), NOT NULL, UNIQUE | Hex sha256 z hlavičky `X-Idempotency-Key` |
| `message` | int → `core_mail_incoming_messages` | Odkaz na vytvořenou zprávu |
| `response_body` | text | Serializovaná JSON odpověď pro replay (bez rekonstrukce přes DB) |
| `created` | datetime | Čas zápisu; TTL se počítá od něj |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_idempotency_key` | unique | `idempotency_key` | Vynucuje unikátnost klíče |
| `idx_created` | index | `created` | Pro prune podle stáří |
| `idx_message` | index | `message` | Pro cleanup při mazání zprávy |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `idempotency.message` → `incoming_messages.id` | Zpráva, kterou idempotency klíč identifikuje |

## Mazání

Záznamy se mažou přes `mail-idempotency-prune --days N` (default 7). Při smazání
zprávy se idempotency záznamy neudržují — po prune zmizí samy, do té doby by
nalezený klíč mířil na neexistující `message`, což controller řeší fallbackem
na znovu vytvoření zprávy.
