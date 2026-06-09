# Tabulka: economy_accounting_accounts

Účtový rozvrh — seznam účtů podvojného účetnictví se stavy dokumentů
(`core.system.docStatesArchive`). Hierarchie (třída → skupina → syntetika →
analytický účet) i samotné účty žijí v **jedné tabulce**, rozlišené enumem
`account_level`. Účtovat se bude jen na úroveň analytického účtu (4); vynucení
je věcí budoucího dokladového / účtovacího modulu, ne této tabulky.

Modernizovaná obdoba staré tabulky `e10doc.debs.accounts` ze Starého Shipardu.

> Standardní obsah (firemní nebo nezisková osnova) se sype přes
> `AccountChartProvisioner` při `ds-upgrade`. Varianta se čte per-DS z
> `config/main.json` polem `accountChart` (`"default"` | `"npo"`).
> Naseedované záznamy mají `is_system = 1`.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `number` | varchar(12), UNIQUE | Číslo účtu — přirozený klíč; jen číslice |
| `name` | varchar(180) | Název účtu |
| `short_name` | varchar(100) NULL | Zkrácený název |

### Skupina `classification`

| Sloupec | Typ | Popis |
|---|---|---|
| `account_level` | enumInt `economy.accounting.accountLevels` | Úroveň (1 třída … 4 analytický účet) — dopočítává se z `number` |
| `g1` | varchar(1) NULL | Třída (prefix čísla, 1 znak) — computed z `number` |
| `g2` | varchar(2) NULL | Skupina (prefix, 2 znaky) — computed z `number` |
| `g3` | varchar(3) NULL | Syntetika (prefix, 3 znaky) — computed z `number` |
| `account_kind` | enumInt `economy.accounting.accountKinds` NULL | Povaha účtu (Aktiva, Pasiva, …) |
| `costs_type` | enumInt `economy.accounting.costsTypes` NULL | Druh nákladu (daňově uznatelný / neuznatelný) |
| `results_type` | enumInt `economy.accounting.resultsTypes` NULL | Druh výsledku (provozní / finanční / mimořádný) |

### Skupina `settings`

| Sloupec | Typ | Popis |
|---|---|---|
| `valid_from` | date NULL | Platnost od |
| `valid_to` | date NULL | Platnost do |
| `is_system` | boolean default 0 | 1 = pochází ze standardní osnovy |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `note` | text NULL | Popis |
| `docState` | tinyint default 10 | Stav dokumentu |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_number` UNIQUE na `number`
- `idx_account_kind` na `account_kind`
- `idx_level` na `account_level`
- `idx_g3` na `g3` — pro `GROUP BY` přes syntetiku v SQL sestavách
- `idx_doc_state` na `docStateMain ASC, number ASC`

## Pravidla

- `number` je UNIQUE a obsahuje jen číslice (regex `^[0-9]{1,12}$`).
- `account_level`, `g1`, `g2`, `g3` se **nezadávají ručně** — dopočítávají se
  z `number` přes `AccountDocument::deriveStructure()` (jediný zdroj pravdy,
  sdílený s provisionerem). Délka `number` určuje úroveň: 1=třída, 2=skupina,
  3=syntetika, 4+=analytický účet.
- `valid_from <= valid_to`, jinak validační chyba na `valid_to`.
- Hodnota „--- / nezadáno" u `costs_type` / `results_type` se reprezentuje
  jako NULL (sloupce jsou nullable), ne hodnotou v enumu. U `account_kind`
  je `0` platná hodnota (Aktiva).
- Provisioner je idempotentní podle `number` — existující záznam (libovolný
  stav) nepřepisuje, respektuje uživatelovo zarchivování / úpravu.

## Související

- `modules/economy/accounting/config/account{Levels,Kinds}.jsonc`,
  `costsTypes.jsonc`, `resultsTypes.jsonc` — enumy.
- `AccountDocument`, `AccountsViewer`, `AccountChartProvisioner` v `src/`.
- Seed: `config/accountChartDefault.jsonc`, `config/accountChartNpo.jsonc`.
