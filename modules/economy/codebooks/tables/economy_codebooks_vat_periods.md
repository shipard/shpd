# Tabulka: economy_codebooks_vat_periods

Jednotlivé období DPH (měsíc/čtvrtletí) navázané na konkrétní registraci.
Záznamy vznikají automaticky při `bin/shpd-ds ds-upgrade` přes
`VatPeriodsProvisioner`, nebo ručně přes sub-formulář v Registraci DPH.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `vat_registration` | int, reference `economy_codebooks_vat_registrations` | Vlastnická registrace |
| `name` | varchar(20) | `"MM/YYYY"` měsíční (např. `"01/2026"`) / `"QN/YYYY"` čtvrtletní (např. `"Q1/2026"`); uživatel může mít vlastní speciální názvy (`"Likvidace 2027"` apod.) |
| `date_begin` | date | Začátek období |
| `date_end` | date | Konec období |
| `locked` | boolean default 0 | `true` = doklady spadající do tohoto období nelze editovat (validace přijde s dokladovým systémem) |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `idx_vat_registration` na `vat_registration, date_begin`
- `idx_dates` na `date_begin, date_end` — připravený lookup pro budoucí
  mapování doklad → období DPH podle data zdanitelného plnění
- `idx_doc_state` na `docStateMain ASC, date_begin DESC`

## Pravidla

- Kalendářní (ne fiskální) periody — nezávislé na `economy_codebooks_fiscal_years`.
- Provisioner generuje období s `docState=40, docStateMain=3, locked=0`;
  manuálně přes UI vzniknou jako `Koncept` (10).
- Idempotence: lookup před insertem je `WHERE vat_registration AND date_begin`
  a **ignoruje docState** — smazané období (`docState=90`) zůstává smazané,
  další `ds-upgrade` ho znovu nevytvoří.

## Související

- [economy_codebooks_vat_registrations](economy_codebooks_vat_registrations.md) — rodičovská registrace
- [VatPeriodDocument](../src/VatPeriodDocument.php) — validace
- [VatPeriodsProvisioner](../src/VatPeriodsProvisioner.php) — auto-generování
- [forms/economy_codebooks_vat_periods.jsonc](../forms/economy_codebooks_vat_periods.jsonc) — sub-form pro editační formulář registrace
