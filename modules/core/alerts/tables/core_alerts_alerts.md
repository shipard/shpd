# Tabulka: Upozornění (core_alerts_alerts)

Konkrétní instance problému, který systém detekoval a uživatel by ho měl
vidět. Jeden řádek = jedna identitní událost ve smyslu páru
`(check_id, finding_key)` — reconciler tu identitu používá pro UPDATE
existujícího vs. INSERT nového alertu.

Tabulka je produkována výhradně subsystémem **AlertReconciler**
(viz `docs/alerts.md`). Uživatelský přístup je read-only přes API (CRUD list,
detail) plus dedikované endpointy pro change state (`snooze`/`dismiss`/`unsnooze`).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `check_id` | varchar(200), NOT NULL | ID checku ve formátu `<group>.<module>.<slug>`, např. `base.persons.missing_own_person`. FK na `alertChecks[].id` v `module.jsonc`, ale pouze logická — žádný DB constraint. |
| `finding_key` | varchar(200), NOT NULL, default `""` | Opaque identita nálezu v rámci checku. Singleton checky používají prázdný řetězec; checky s 0..N nálezy si volí sami (typicky `<table>:<row_id>`). **Není** UUID — je to deterministický klíč, aby reconciler dokázal sjednotit dva běhy. |

### Obsah (content)

| Sloupec | Typ | Popis |
|---|---|---|
| `title` | varchar(250), NOT NULL | Lidský titulek, již v jazyce DS (check ho generuje localizovaný). Hlavní text v listě. |
| `message` | text | Delší popis. Žádný HTML — plain text, případně později markdown. |
| `severity` | enumInt → `core.alerts.severities`, default 20 | 10=info, 20=warning, 30=error. |
| `actions` | json | Pole akcí, které UI nabídne. Schéma popisuje `docs/alerts.md` §9. |
| `context` | json | Volné meta pole pro debugging — co check viděl, kolik bylo záznamů, atp. UI nemusí renderovat. |

### Předmět (subject) — proklik

| Sloupec | Typ | Popis |
|---|---|---|
| `subject_table_id` | smallint | Tabulka, na kterou alert odkazuje (volitelné). |
| `subject_row_id` | int | Konkrétní řádek v té tabulce (volitelné). |

Lidský popis předmětu **nepatří sem** — patří do `title`/`message`. Tyto
sloupce slouží jen jako technický proklik z UI na detail dotčeného záznamu.

### Stav (state)

| Sloupec | Typ | Popis |
|---|---|---|
| `alert_state` | enumInt → `core.alerts.alertStates`, default 10 | 10=Active, 20=Snoozed, 70=Resolved, 80=Dismissed. **Není** to standardní `docState` mechanismus — alerts mají vlastní lifecycle. |
| `snoozed_until` | datetime | Při `alert_state=20` nese moment, od kterého reconciler může alert vrátit na 10. |
| `dismissed_at` | datetime | Čas zamítnutí uživatelem. |
| `resolved_at` | datetime | Čas auto-vyřešení reconcilerem (problém zmizel ze světa). |

### Časování (timing)

| Sloupec | Typ | Popis |
|---|---|---|
| `first_seen_at` | datetime, NOT NULL | Kdy reconciler poprvé tento finding viděl. |
| `last_seen_at` | datetime, NOT NULL | Kdy ho viděl naposled. |
| `seen_count` | int, default 1 | Inkrementuje při každém běhu, který finding znovu potvrdí. |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_check_finding_state` | index | `check_id`, `finding_key`, `alert_state` | Hlavní lookup reconcileru — "existuje pro tento check_id+finding_key open alert?" |
| `idx_state_last_seen` | index | `alert_state`, `last_seen_at DESC` | Default sort vieweru (open alerty, nejnovější napřed). |
| `idx_subject` | index | `subject_table_id`, `subject_row_id` | "Existují alerty na tento záznam?" (pro inline badge u doc detailu — out of scope MVP). |

## Životní cyklus

```
                  ┌─────────────────────────────┐
                  │ reconciler: finding poprvé  │
                  │   → INSERT state=10 (Active)│
                  └──────────────┬──────────────┘
                                 │
                                 │  reconciler vidí finding znovu
                                 │  (next interval)
                                 ▼
   ┌────────────────────────────────────────────┐
   │ state=10, last_seen_at+=NOW, seen_count++  │
   └─┬─────────────────────────┬────────────────┘
     │                         │
  user snooze 1h         user dismiss
     │                         │
     ▼                         ▼
   state=20                state=80
   snoozed_until=...       dismissed_at=NOW
     │                         │
     │ NOW > snoozed_until,    │ (terminál)
     │ reconciler vidí finding │
     ▼                         │
   state=10                    │
     │                         │
     │ finding zmizel ze světa │
     │ (reconciler ho nevidí)  │
     ▼                         │
   state=70                    │
   resolved_at=NOW             │
                               │
                               │ check v dalším běhu znovu vidí
                               │ finding (po prune to už ale neplatí —
                               │ stejný finding_key vyrobí nový INSERT)
                               ▼
                          (nový lifecycle)
```

Reconciler **nikdy** nedělá DELETE — vyřešené a zamítnuté alerty si žijí
v tabulce dál pro audit. Cron `shpd-ds alerts-prune --days=90` je maže
po retenční době.

## Bezpečnostní poznámky

- `actions.target.preset` může obsahovat hodnoty, které jsou v cílové tabulce
  citlivé. Check si zodpovídá, aby do `actions` nepoukládal nic, co by
  uživatel s right-to-view alertu neměl vidět.
- `context` je free-form — nikdy do něj neukládat plaintext citlivá data
  (API klíče, hesla). Patří tam jen meta (counts, IDs, timestamps).
