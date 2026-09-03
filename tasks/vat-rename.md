# Task: Přejmenování `economy.taxes` → `economy.vat` + terminologie CS/RS

**Stav:** hotovo
**Issue:** #55 — komentář „Revize návrhu po fázi 1" (rozhodnutí o přejmenování, D10)
**Návaznost:** dělej PŘED `tasks/vat-report-periods.md` (ten už staví na nových
názvech). Stará strana (rename pole v registrations runneru) je součástí
`old_shipard: modules/imports/newShipard/tasks/30-vat-report-periods-import.md`
— do jejího nasazení nespouštět re-import registrací (poslala by starý název
sloupce).

## Kontext

Modul zůstává čistě DPH agendou — DPPO a další daně nesdílejí zdroje dat ani
mechanismy (viz issue #55). Zastřešující název `taxes` lže o obsahu; `economy.vat`
tvoří symetrii s `world.vat` (legislativa vs. výkaznictví, dělicí čára D3).
Oficiální terminologie: kontrolní hlášení = **Control Statement** (FS),
souhrnné hlášení = **Recapitulative Statement** (směrnice o DPH) — kód dosud
používá britské „EC Sales List".

Žádná změna chování — čistě přejmenování. Všechny výskyty dohledej greppem,
tento seznam je kontrolní, ne vyčerpávající.

## Co udělat

### 1. Modul

- Adresář `modules/economy/taxes/` → `modules/economy/vat/`, module id
  `economy.taxes` → `economy.vat` (module.jsonc vč. závislostí odkazujících
  na modul, pokud nějaké vznikly).
- PHP namespace dle konvence modulů (`…\Economy\Taxes\…` → `…\Economy\Vat\…`),
  testy `tests/Unit/Module/Economy/Taxes/` → `…/Vat/`.
- cfgItem `economy.taxes.reports.cz` → `economy.vat.reports.cz`
  (+ všechna čtení klíče).
- Dokumentace: `modules/economy/vat/docs/README.md` (úprava odkazů),
  zmínky v `docs/reports.md` §14.

### 2. Terminologie RS

- `EcSalesListCalculator` → `RecapitulativeStatementCalculator`,
  `VatEcSalesLiveBuilder` → `VatRecapitulativeStatementLiveBuilder`,
  související testy a message kódy (`vatSh.*` → `vatRs.*`; zkontroluj
  i `vatKh.*` → `vatCs.*`, ať je konvence jednotná — messages zatím nikdo
  nekonzumuje, teď je poslední levná chvíle).
- Report ID bez zdvojeného „vat" (registrace builderů + help + docs):
  - `economy.taxes.vatReturnLive` → `economy.vat.returnLive`
  - `economy.taxes.vatControlStatementLive` → `economy.vat.controlStatementLive`
  - `economy.taxes.vatEcSalesLive` → `economy.vat.recapitulativeStatementLive`
- Názvy tříd s prefixem `Vat*` (kalkulátory, selection, mapping) zůstávají —
  uvnitř modulu čitelnosti pomáhají.

### 3. Registrace DPH (`economy_codebooks_vat_registrations`)

- Sloupec `report_period_kind` → `cs_period_kind` (label zůstává „Frekvence
  kontrolního hlášení").
- Nový sloupec `rs_period_kind` — enumInt, cfgItem
  `economy.codebooks.vatPeriodKinds`, default 1 (měsíčně), group `period`,
  label „Frekvence souhrnného hlášení" / „Recapitulative statement period
  kind". Zákonnou podmínku čtvrtletního RS (§ 102 odst. 6) systém nehlídá.
- Dosah rename (grep `report_period_kind`): `DbVatPeriodProvider`,
  `SetupController` (**REST kontrakt setup wizardu — dohledej i frontend**),
  `VatRegistrationsViewer`, testy (`SetupControllerTest`,
  `VatRegistrationDocumentTest`), table jsonc + md, `docs/ds-setup.md`,
  `docs/rest-api.md`, komentář ve `vatPeriodKinds.jsonc`.
- Mechanika: ověř, zda `DsUpgrade` umí rename sloupce; pokud ne, drop+add je
  před produkcí přijatelný (alfa DS projdou ds-resetem v rámci tasku 30,
  jiné DS s daty registrace neexistují — ověř hosting DS).

## Mimo scope

- Jakákoli změna chování, tabulka instancí (`tasks/vat-report-periods.md`),
  stará strana importu (task 30).

## Commity

1. Rename modulu + namespace + cfgItem + report ID.
2. RS terminologie (třídy, message kódy).
3. Sloupce registrace + dotčená místa + testy.
4. Dokumentace.

## Hotovo když

- [ ] `grep -rn "economy.taxes\|EcSales\|report_period_kind"` nad repem
      nevrací nic mimo tasks/ a archivní docs.
- [ ] Testy zelené, `ds-upgrade` na čistém DS projde (rebuild configu,
      schema registrace).
- [ ] Všechny tři živé reporty běží pod novými ID (ruční kontrola na dev DS).
