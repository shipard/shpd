# Tabulka: docs_core_number_counters

Atomický counter čísel dokladů per `(number_series, fiscal_year)`. Plně
využívaný až ve Fázi 2 (`DocDocument::assignDocumentNumber`).

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `number_series` | int → `docs_core_number_series` | Řada |
| `fiscal_year` | int → `economy_codebooks_fiscal_years`, nullable | Fiskální rok (NULL pro `reset_scope = 'none'`) |
| `last_assigned` | int default 0 | Poslední přidělené sequence_number |

## Bez doc-state

Tabulka **nemá** `docState` / `docStateMain` — je čistě technickým
záznamem. Životní cyklus = vznik při prvním přidělení čísla, existuje
dokud existuje řada.

## Indexy

- **`unq_series_year` UNIQUE** — `(number_series, fiscal_year)`. UNIQUE
  v MariaDB neporušují NULL hodnoty, takže pro řady s `reset_scope='none'`
  (kde `fiscal_year` je vždy NULL) by mohlo vzniknout víc záznamů. Aplikační
  logika (`SELECT … FOR UPDATE` s `WHERE fiscal_year IS NULL`) zajistí
  jediný záznam.

## Algoritmus přidělení čísla (Fáze 2)

1. `INSERT IGNORE` placeholder counter (idempotentní)
2. `BEGIN TRANSACTION`
3. `SELECT last_assigned … FOR UPDATE` (lock)
4. `UPDATE … SET last_assigned = last_assigned + 1`
5. Použít `last_assigned + 1` jako `sequence_number` na hlavičce
6. `COMMIT`

Pojistka: na `docs_core_heads` je UNIQUE constraint na trojici
`(number_series, fiscal_year, sequence_number)`. I kdyby logika selhala,
INSERT/UPDATE nikdy nezpůsobí duplicitu — místo toho transakce spadne
na duplicate key error.

## Související

- [docs_core_number_series](docs_core_number_series.md) — parent
- [docs_core_heads](docs_core_heads.md) — `unq_series_seq` UNIQUE jako pojistka
- `docs/docs-mvp.md` sekce 5.3 — algoritmus přidělení
