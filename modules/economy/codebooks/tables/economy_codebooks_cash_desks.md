# Tabulka: economy_codebooks_cash_desks

Pokladny pro hotovostní operace se stavy dokumentů
(`core.system.docStatesArchive`). Záznamy vznikají ručně přes UI jako
`Koncept` (10), uživatel je manuálně přepne do `V pořádku` (40).
Dokladový modul (přijde později) bude na hlavičce pokladního dokladu
držet referenci na konkrétní pokladnu.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `code` | varchar(10), UNIQUE | Krátký kód pro selecty (`HP1`, `EUR`) |
| `name` | varchar(150) | Název pokladny |
| `notice` | varchar(250) NULL | Poznámka (kontakt na pokladníka, číslo smlouvy, …) |

### Skupina `settings`

| Sloupec | Typ | Popis |
|---|---|---|
| `currency` | varchar(3) default `'czk'` | ISO 4217 lowercase |
| `is_default` | boolean default 0 | Výchozí pokladna pro danou měnu — vynucené unikátně per měna v `afterPersist` |
| `valid_from` | date NULL | Platnost od |
| `valid_to` | date NULL | Platnost do |
| `sort_order` | smallint default 0 | Pořadí pro řazení ve výpisu |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (Koncept 10, V pořádku 40, …) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_code` UNIQUE na `code`
- `idx_sort_order` na `sort_order ASC, name ASC` — výchozí řazení
  ve výpisu (uživatelské pořadí, abecedně podle názvu)
- `idx_doc_state` na `docStateMain ASC, sort_order ASC` — viewer řadí
  aktivní záznamy nahoře, pak podle pořadí

## Pravidla

- `code` je UNIQUE — pokus o duplicitu vrátí 422 s pochopitelnou chybou.
- `currency` je lowercase regex `^[a-z]{3}$` — v UI zatím prostý
  text input s defaultem `czk`, picker přijde později jako globální
  vylepšení.
- `is_default = 1` je unikátní **per měna**: při uložení záznamu jako
  default `CashDeskDocument::afterPersist` automaticky odznačí ostatní
  defaulty se stejnou měnou. Logika běží v transakci se save, takže je
  atomická (rollback funguje při výjimce).
- `valid_from <= valid_to` (validace v `CashDeskDocument`).
- `code`, `name`, `notice` se v `beforeSave` trimují; `currency` se
  normalizuje na lowercase.

## Související

- [CashDeskDocument](../src/CashDeskDocument.php) — validace + default-per-currency uniqueness
- [forms/economy_codebooks_cash_desks.jsonc](../forms/economy_codebooks_cash_desks.jsonc) — deklarativní edit form
- Hlavička pokladního dokladu (přijde s dokladovým modulem) bude
  obsahovat FK na tuto tabulku.
