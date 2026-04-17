# Tabulka: Analýzy zpráv (core_mail_message_analyses)

Historie AI analýz došlých zpráv. Vztah 1:N na [core_mail_incoming_messages](core_mail_incoming_messages.md)
— každá zpráva může mít více pokusů o analýzu (první pokus selhal, druhý uspěl
s upraveným promptem; znovu analyzováno po upgradu modelu apod.).

**Fáze 1 tabulku pouze zakládá** — CRUD funguje z CLI a testů, AI služba ještě
neexistuje (přijde ve Fázi 3).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message` | int → `core_mail_incoming_messages`, NOT NULL | Zpráva, ke které analýza patří |
| `analyzed_at` | datetime, NOT NULL | Čas dokončení analýzy (start nebo konec — nastavuje AI pipeline) |
| `status` | tinyint, default 1 | `1` = pending, `2` = success, `3` = failed |

### Model (model)

| Sloupec | Typ | Popis |
|---|---|---|
| `model_name` | varchar(100), NOT NULL | Identifikátor modelu (`claude-sonnet-4`, `gpt-4o`, …) |
| `model_version` | varchar(100) | Volitelná verze modelu (např. datum snapshotu) |
| `prompt_version` | varchar(50), NOT NULL | Verze použitého promptu — SemVer nebo git-hash |

### Výsledek (result)

| Sloupec | Typ | Popis |
|---|---|---|
| `analysis_json` | longtext | Strukturovaný výstup AI — JSON s extrahovanými poli (hlavička faktury, řádky apod.). NULL u `status = failed`. |
| `confidence` | numeric(4,3) | Sebehodnocení modelu v rozsahu `0.000`–`1.000` |
| `error_message` | text | Detail chyby u `status = failed` |

### Telemetrie (telemetry)

| Sloupec | Typ | Popis |
|---|---|---|
| `tokens_input` | int | Počet vstupních tokenů (cost tracking) |
| `tokens_output` | int | Počet výstupních tokenů |
| `duration_ms` | int | Trvání volání v milisekundách |

### Auditní pole (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime | Čas založení záznamu |
| `created_by` | int → `core_system_users` | Obvykle systémový uživatel AI služby |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_message` | index | `message` | Vyhledávání analýz pro zprávu |
| `idx_message_analyzed` | index | `message`, `analyzed_at` DESC | „Current" analýza = `MAX(analyzed_at)` per message |

## Současná analýza

Žádný flag `is_current` — aktuální analýza se určuje dotazem:

```sql
SELECT * FROM core_mail_message_analyses
WHERE message = :messageId
ORDER BY analyzed_at DESC
LIMIT 1
```

Viewer zprávy `IncomingMessagesViewer::renderDetail()` tímto způsobem určuje, která
analýza se zobrazí jako výchozí v tabu "Analýzy".

## Mazání

Při mazání zprávy (`IncomingMessageDocument::beforeDelete()`) se nejprve smažou
všechny analýzy této zprávy — Shipard nepoužívá FOREIGN KEY, referenční integritu
kontroluje aplikační kód.
