# Tabulka: Pravidla předzpracování (core_mail_preprocess_rules)

Pravidla technického předzpracování došlé zprávy před AI analýzou
(`tasks/mail-preprocess.md`, issue #33). Zpráva, která při intake matchne
**potvrzené** pravidlo (docState 40), dostane `preprocess_state = 10`
a uložený plán akcí; asynchronní runner (`shpd-ds mail-preprocess`) plán
vykoná — typicky stáhne fakturu z odkazu v těle zprávy jako novou obsahovou
přílohu — a teprve pak zpráva doteče do AI fronty. Koncept a provoz:
[docs/preprocess.md](../docs/preprocess.md).

Životní cyklus jede na `core.system.docStatesArchive`. **Matchují výhradně
pravidla ve stavu 40.** Systémová pravidla (`origin = system`) zakládá
a aktualizuje `PreprocessRulesProvisioner` při `ds-upgrade`; archivované
(70) ani smazané (90) systémové pravidlo se nekřísí — přizpůsobení =
archivovat systémové a založit uživatelskou kopii.

## Struktura

### Pravidlo (rule)

| Sloupec | Typ | Popis |
|---|---|---|
| `rule_id` | varchar(60), NOT NULL, unique | Stabilní klíč: u systémových z katalogu (`bolt-invoice-link`), u uživatelských generuje `PreprocessRuleDocument` (`user-…`) |
| `origin` | enumString(10), NOT NULL, default `user` | `system` \| `user` — `core.mail.preprocessRuleOrigins` |
| `actions` | longtext, NOT NULL | JSON: uspořádaný seznam `[{"action": "fetchLinkedDocument", "linkHrefRegex": "…", "allowedDomains": ["…"]}]`. Klíče akcí: `core.mail.preprocessActions`; akce s `phase > 1` validace odmítne |
| `notice` | varchar(250) | Lidský popis pravidla (zobrazuje se v seznamu) |

### Podmínky shody (match)

Vyhodnocují se **AND** přes vyplněné sloupce; aspoň jeden musí být vyplněný
(vynucuje `PreprocessRuleDocument`). Regexy jsou case-insensitive PCRE bez
oddělovačů.

| Sloupec | Typ | Popis |
|---|---|---|
| `sender_email` | varchar(200) | Přesná adresa odesílatele (lowercase) |
| `sender_domain` | varchar(190) | Doména odesílatele bez `@` (lowercase), matchuje i subdomény |
| `subject_regex` | varchar(500) | Regex nad předmětem |
| `body_regex` | varchar(500) | Regex nad `body_html` i `body_plain` (stačí shoda v jednom) — kotva pro `Fwd:` forwardy, kde odesílatel je interní |

### Statistiky (stats)

| Sloupec | Typ | Popis |
|---|---|---|
| `hit_count` | int, NOT NULL, default 0 | Kolikrát pravidlo při intake matchlo (`--force` nepočítá) |
| `last_hit_at` | datetime | Čas posledního zásahu |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření |
| `created_by` | int → core_system_users | Kdo založil; NULL u systémových |
| `modified` | datetime, NOT NULL | Čas poslední změny |
| `docState` / `docStateMain` | tinyint, system | `core.system.docStatesArchive` (10 Koncept, 40 V pořádku, 70 V archívu, 80 V opravě, 90 Smazáno) |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_rule_id` | unique | `rule_id` | Upsert systémových pravidel |
| `idx_state` | index | `docState` | Matcher načítá jen stav 40 |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `preprocess_log.plan[].ruleId` → `rule_id` | Snapshot plánu na zprávě; změna pravidla po intake plán nemění |
| `core_attachments_files` | `metadata.ruleId` → `rule_id` | Provenance vygenerované přílohy (`generatedBy: preprocess`) |

## Mazání a reset

Tabulka je v `keepOnReset` — pravidla jsou konfigurace, ne data. Smazání
pravidla nechává `ruleId` v logu historických zpráv jako sirotčí referenci.
