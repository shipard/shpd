# Tabulka: Log odchozí pošty (core_mail_outbox_log)

Technické záznamy jednotlivých pokusů o odeslání zpráv z
[core_mail_outbox](core_mail_outbox.md). Jeden řádek per pokus — úspěšný
i neúspěšný. Odpovídá na otázku „proč zpráva neodešla a kudy se to
zkoušelo", aniž by se technikálie míchaly do business tabulky outboxu.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `outbox_id` | int → `core_mail_outbox`, NOT NULL | Zpráva |
| `attempt` | int, NOT NULL | Pořadí pokusu (1..6) |
| `ts` | datetime, NOT NULL | Čas pokusu |
| `transport` | varchar(200), NOT NULL | `sender:{id}` (custom SMTP) nebo `host:port` (relay); `unresolved` když selhala už resolution |
| `result` | varchar(10), NOT NULL | `ok` / `fail` |
| `smtp_response` | varchar(500) | Odpověď/debug SMTP serveru, u `fail` chybová hláška (truncated na 500) |
| `duration_ms` | int | Trvání pokusu v ms |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_outbox_id` | index | `outbox_id` | Log zprávy v detailu / rekonstrukce historie |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_outbox](core_mail_outbox.md) | `log.outbox_id` → `outbox.id` | Rodičovská zpráva |

## Mazání a reset

Data — při `ds-reset` se maže. Řádky se nemažou při smazání zprávy
aplikačně (žádný Document hook); retenci řeší provozovatel.
