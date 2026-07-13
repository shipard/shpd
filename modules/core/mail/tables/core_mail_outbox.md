# Tabulka: Odchozí pošta (core_mail_outbox)

Fronta a evidence odchozích zpráv — zpráva jako business objekt. Zprávy
sem zapisuje `Shipard\Core\Mail\MailOutboxService::enqueue()` /
`enqueueAndSend()`, odesílá je cron worker `mail-outbox-run` (nebo
synchronní první pokus u priority zpráv). Technické záznamy jednotlivých
pokusů jsou v [core_mail_outbox_log](core_mail_outbox_log.md).

## Struktura

### Původ (origin)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas zařazení do fronty |
| `created_by` | int → `core_system_users` | Uživatel, který odeslání vyvolal (NULL = systém) |
| `source_module` | varchar(50), NOT NULL | Modul původu, např. `core.auth` |
| `source_ref` | varchar(100) | Volitelná reference v rámci modulu (id pozvánky apod.) |

### Zpráva (message)

| Sloupec | Typ | Popis |
|---|---|---|
| `email_from` | varchar(200), NOT NULL | From adresa — rozhoduje o transportu (senders → relay) |
| `email_to` | varchar(200), NOT NULL | Příjemce |
| `recipient_person_id` | int → `base_persons_persons` | Vazba na Osobu (pro budoucí UI Odeslaná pošta) |
| `subject` | varchar(500), NOT NULL | Předmět |
| `body_text` | text | Textové tělo |
| `body_html` | text | HTML tělo; obě těla → multipart/alternative |
| `attachments` | text (JSON) | Pole id příloh z `core_attachments_files` |

### Fronta (queue)

| Sloupec | Typ | Popis |
|---|---|---|
| `priority` | tinyint, default 0 | 0 normal, 10 high (enqueueAndSend); vyšší jde ve frontě dřív |
| `state` | varchar(20), NOT NULL | `pending` / `sending` / `sent` / `failed` / `cancelled` |
| `attempt_count` | int, default 0 | Počet dokončených pokusů |
| `next_attempt` | datetime | Kdy nejdřív zkusit; backoff 60 s → 5 min → 30 min → 2 h → 6 h |
| `claimed_at` | datetime | Čas atomického claimu workerem (stav `sending`) |
| `sent_at` | datetime | Čas úspěšného odeslání |
| `last_error` | varchar(500) | Poslední chyba (u `failed` důvod terminálního selhání) |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_state` | index | `state` | Filtry fronty a checků |
| `idx_next_attempt` | index | `next_attempt` | Worker hledá due zprávy |

## Stavový automat

```
enqueue → pending ──claim──▶ sending ──ok──▶ sent
             ▲                  │
             │              fail, pokus < 6 (backoff)
             └──────────────────┘
                                │ fail, 6. pokus
                                ▼
   retry (mail-outbox-retry) ◀─ failed
```

- **Claim** je atomický `UPDATE … WHERE id = ? AND state = 'pending'` —
  souběžné workery si zprávu nevezmou dvakrát.
- **Recovery**: `sending` starší 10 minut (pád workeru) vrací
  `processQueue()` zpět na `pending`. Zotavený pokus neinkrementuje
  `attempt_count` (spadlý pokus nic nezalogoval) — patologicky padající
  zpráva je bounded 10min oknem a alert checkem `core.mail.outbox_health`.
- `cancelled` zatím nenastavuje žádný kód — rezervováno pro budoucí UI.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_outbox_log](core_mail_outbox_log.md) | `log.outbox_id` → `outbox.id` | Záznam per pokus |
| [core_mail_senders](core_mail_senders.md) | `outbox.email_from` → lookup | Resolution transportu |

## Mazání a reset

Data (ne konfigurace) — při `ds-reset` se maže. Retenci starých záznamů
řeší provozovatel (viz runbook v `docs/mail/outbound.md`).
