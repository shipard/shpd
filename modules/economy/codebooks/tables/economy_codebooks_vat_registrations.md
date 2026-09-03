# Tabulka: economy_codebooks_vat_registrations

Registrace k DPH se stavy dokumentů (`core.system.docStatesArchive`).
Firma může mít 0, 1, nebo více registrací (různé země, OSS, diskontinuity
v plátcovství). Záznamy vznikají manuálně přes UI; provisioner do nich
nesahá.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(50) | Lidský název registrace (např. `"ČR DPH"`, `"SK DPH OSS"`) |
| `region` | enumString(10), cfgItem `world.trade.unions`, default `'eu'` | Klíč obchodní unie — ovlivňuje pravidla pro VAT-ID, intra-community plnění apod. |
| `country` | enumString(2), cfgItem `world.base.countries`, default `'cz'` | ISO 3166-1 alpha-2 lowercase |
| `taxpayer_kind` | enumInt default 0, cfgItem `economy.codebooks.vatTaxpayerKinds` | 0 = Klasický plátce, 1 = OSS (One-Stop-Shop pro EU služby) |
| `vat_id` | varchar(30) NULL | DIČ; nullable kvůli stavu „v procesu registrace" — formálně registrace existuje, ale DIČ ještě nepřišlo |

### Skupina `period`

| Sloupec | Typ | Popis |
|---|---|---|
| `tax_period_kind` | enumInt default 1, cfgItem `economy.codebooks.vatPeriodKinds` | Frekvence přiznání DPH: 1 = měsíční, 2 = čtvrtletní |
| `cs_period_kind` | enumInt default 1, cfgItem `economy.codebooks.vatPeriodKinds` | Frekvence kontrolního hlášení (CS); v ČR může být KH měsíční i u čtvrtletního plátce |
| `rs_period_kind` | enumInt default 1, cfgItem `economy.codebooks.vatPeriodKinds` | Frekvence souhrnného hlášení (RS); zákonnou podmínku čtvrtletního SH (§ 102 odst. 6) systém nehlídá |
| `valid_from` | date | Začátek platnosti registrace |
| `valid_to` | date NULL | Konec platnosti; `NULL` = bez konce |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (Koncept 10, V pořádku 40, …) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `idx_country` na `country`
- `idx_validity` na `valid_from, valid_to`
- `idx_doc_state` na `docStateMain ASC, name ASC`

## Pravidla

- Manuálně přes UI vznikne registrace jako `Koncept` (10); uživatel ji
  přepne do `V pořádku` (40), případně `Smazaný` (90).
- `VatPeriodsProvisioner` zpracovává registrace s `docState IN (10, 40, 80)`
  — i Koncept generuje období, aby uživatel viděl náhled.
- Dokladový systém (přijde později) podle data uskutečnění zdanitelného
  plnění najde registraci a její odpovídající období.

## Související

- [economy_codebooks_vat_periods](economy_codebooks_vat_periods.md) — období navázaná na registraci
- [VatRegistrationDocument](../src/VatRegistrationDocument.php) — validace
- [VatPeriodsProvisioner](../src/VatPeriodsProvisioner.php) — auto-generování období
