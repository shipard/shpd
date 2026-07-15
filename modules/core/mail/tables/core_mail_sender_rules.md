# Tabulka: Pravidla odesílatelů (core_mail_sender_rules)

Deterministická pravidla pro zpracování šumu v došlé poště (Fáze 3 programu
Spisovny, design `docs/registry-mvp.md` §8, zásady D6/D7). Zpráva, jejíž
odesílatel matchne **potvrzené** pravidlo (docState 40) s `disposition =
archive`, se při ingestu rovnou archivuje bez AI analýzy — s plným auditem
na zprávě (`auto_disposed_by`, `auto_disposed_at`).

Životní cyklus jede na `core.system.docStatesArchive`: návrh učícího
handleru vzniká jako Koncept (10), potvrzení kartou na dashboardu = přechod
10→40, zamítnutí = 90. Žádný `is_confirmed` flag — stavový automat stačí.
**Matchují výhradně pravidla ve stavu 40.**

Pozor na záměnu: `core_mail_senders` jsou odchozí SMTP transporty a
s pravidly došlé pošty nesouvisí.

## Struktura

### Pravidlo (rule)

| Sloupec | Typ | Popis |
|---|---|---|
| `pattern_kind` | enumString(10), NOT NULL, default `email` | `email` \| `domain` (`core.mail.senderRulePatternKinds`). Přesný e-mail > doména; první zásah vyhrává |
| `pattern` | varchar(190), NOT NULL | Lowercase vzor (vynucuje `SenderRuleDocument`): celá adresa, nebo doména bez `@` |
| `disposition` | enumString(20), NOT NULL, default `archive` | Co se zprávou (`core.mail.senderRuleDispositions`); zatím jen `archive` |
| `origin` | enumString(10), NOT NULL, default `user` | `user` (ručně) \| `suggested` (učící handler) — `core.mail.senderRuleOrigins` |
| `notice` | varchar(250) | Poznámka; u návrhů počet ručních zásahů, které návrh vyvolaly |

### Statistiky (stats)

| Sloupec | Typ | Popis |
|---|---|---|
| `hit_count` | int, NOT NULL, default 0 | Kolikrát pravidlo auto-archivovalo zprávu |
| `last_hit_at` | datetime | Čas posledního zásahu |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření |
| `created_by` | int → core_system_users | Kdo založil; NULL u návrhů z učícího handleru |
| `modified` | datetime, NOT NULL | Čas poslední změny |
| `docState` / `docStateMain` | tinyint, system | `core.system.docStatesArchive` (10 Koncept, 40 V pořádku, 80 V opravě, 70 V archívu, 90 Smazáno) |

Unikátnost `(pattern_kind, pattern)` mezi „živými" pravidly (docState 10/40/80)
vynucuje aplikačně `SenderRuleDocument` — koš/archiv reuse vzoru neblokuje.

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_match` | index | `docStateMain`, `pattern_kind`, `pattern` | Lookup při ingestu (match jen aktivních) |
| `idx_state` | index | `docState` | Návrhové karty feedu (docState 10 + origin) |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `messages.auto_disposed_by` → id | Audit auto-archivace; digest karta a „Vrátit vše" se derivují dotazem |

## Mazání a reset

Tabulka je v `keepOnReset` — pravidla jsou konfigurace, ne data. Smazání
pravidla nechává `auto_disposed_by` na historických zprávách jako sirotčí
referenci (referenční integrita je aplikační, digest se dívá jen na dnešek).
