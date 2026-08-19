# Tabulka: Pravidla obsahových štítků (core_exchange_tag_rules)

Deterministická osa obsahové eskalace párování položek
(tasks/content-tag-enrichment.md): pravidlo **IČO dodavatele → obsahový
štítek** z taxonomie `core.exchange.contentTags`. Zásah pravidla přeskakuje
LLM klasifikaci (D12); pravidla vznikají učením z apply dokladu s LLM
štítkem (D22, `ContentTagRuleCaptureHandler`), ručně (UI task
`content-tag-ui.md`) nebo importem seedu ze starého Shipardu (rezervováno).

`tableId = 438`. Bez stavového modelu, bez vieweru/formu (v1), skryto
z navigace. Bez `keepOnReset` — learned pravidla se obnoví provozem.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `company_id` | varchar(20), NOT NULL, UNIQUE | IČO dodavatele, normalizované (bez mezer). Jedno pravidlo per IČO — vědomé v1 zjednodušení; dodavatel s pestrým sortimentem pravidlo nedostane (learning ho při konfliktu štítků smaže). |
| `tag` | enumString(40), cfgItem `core.exchange.contentTags`, NOT NULL | Obsahový štítek |
| `origin` | enumString(10), NOT NULL | `user` \| `learned` \| `seed`. Learning mění jen `learned`; `user`/`seed` nikdy nepřepisuje ani nemaže. |
| `confirmed` | tinyint, default 1 | V1 vždy 1 (pravidlo platí okamžitě); pole pro budoucí auto-režim žebříkem (D14). |
| `hit_count` | int, default 0 | Statistika — inkrement při skutečném použití pravidla v pipeline. |
| `last_hit_at` | datetime, nullable | Čas posledního zásahu. |
| `created`, `created_by`, `modified` | — | Audit. |

## Indexy

- `unq_company_id` — unique(`company_id`); změna štítku = UPDATE, ne druhý řádek.
- `idx_tag` — index(`tag`).
