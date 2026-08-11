# vat-payer — Task 01: Neplátce DPH (Issue #17)

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D5, D10, D11**, kontrakt **§5.2** a **§6**.
> [Issue #17](https://github.com/shipard/shpd/issues/17), milník **M0 —
> Věcná správnost výpočtů**, proto přednostně před zbytkem oblasti.

## Kontext

Neplátce DPH dnes v aplikaci neexistuje jako pojem. `vat_mode = 0`
(„Bez DPH") na dokladu ale existuje a `DocDocument::validate` při něm
Registraci DPH nevyžaduje — technicky je tedy neplátcovský doklad možný
už teď, jen se musí u každého dokladu ručně přepnout a agenda DPH svítí
v navigaci, i když je prázdná.

**Zjištění, které tento task výrazně zmenšuje.** Skrývání jednotlivých
polí DPH je hotové: `DocsHeadsFormBase::buildHeaderTab()` počítá
`$hasVat = $vatMode !== 0` a věší `hidden: !$hasVat` na `vat_calc_source`,
`vat_place`, `vat_registration`, `vat_duzp`, `vat_dppd`; `DocRowsForm`
dělá totéž. Účtování je také v pořádku — `AccountingEngine::buildVatLines()`
staví řádky z `docs_core_vat_recap`, `buildRowLines()` bere `vat_base_dom`,
takže doklad s `vat_mode = 0` zaúčtuje plnou částku a na 343xxx nesáhne.

Zbývá tedy default, navigace, sekce „DPH" v hlavičce a období DPH.

## Cíl

1. Klíč `economy.vatAgenda` v `LayerCParameters`.
2. Výchozí `vat_mode` nového dokladu odvozený z klíče.
3. Viditelnost agendy DPH v navigaci podle D11.
4. Provisioning období DPH při vzniku registrace.

## Závislosti

- **Závisí na Tasku 03** (mechanismus settings klíčů, `LayerCParameters`,
  `ds-setting`) — hotový.
- Otevírá: nic. Průvodce (Fáze 4) pak jen volá hotové věci.

## Potvrzená designová rozhodnutí (Anna)

1. **D5 — varianta 1.** Absence Registrace DPH pro dané datum znamená
   neplátce. Žádný `neplátce` jako `taxpayer_kind`, žádné fiktivní
   registrace.
2. **Klíč se jmenuje `economy.vatAgenda`**, ne `vatPayer` — záměrně ve
   tvaru předvolby („vede agendu DPH"), aby ho nikdo později nepoužil
   jako zdroj pravdy o plátcovství. Tou jsou registrace.
3. **D10 — příznak řídí budoucnost a navigaci; renderování existujících
   dat řídí doklad sám** (`vat_mode`). Tohle je nejdůležitější věta
   celého tasku: **existující `$hasVat` mechanismus na příznak
   nepřepojovat.** Kdyby renderování řídil příznak, bývalý plátce by
   přestal vidět svá stará data o DPH.
4. **D11 — navigace se skrývá jen když je příznak neplátce a zároveň
   nikdy neexistovala žádná registrace.** Samotný příznak jako podmínka
   nestačí.
5. **Období DPH přes hook na uložení registrace**, ne voláním z průvodce
   — registraci lze založit i ručně ve vieweru a u firem, které se
   plátcem stanou později, to bude nejčastější cesta.

## Před implementací přečti

- `docs/ds-setup.md` — §5.2, §6 (celé), rozhodnutí D5/D10/D11
- `src/Core/Settings/LayerCParameters.php` — `SPECS` a `validate()`
- `modules/docs/core/src/DocsHeadsFormBase.php` — `applyDefaults()`
  (~ř. 266, zadrátovaná `vat_mode = 1`) a `buildHeaderTab()` (~ř. 340,
  separátor „DPH" a `$hasVat`)
- `modules/docs/core/src/DocRowsForm.php` — ~ř. 29, tentýž vzor
- `modules/economy/codebooks/src/VatPeriodsProvisioner.php` — iteruje
  registrace, roky bere z kalendáře (`$currentYear`, `$currentYear + 1`)
- `modules/economy/codebooks/module.jsonc` — `navSections` /
  `settingsItems` s `economy.codebooks.vatRegistrations`
  a `economy_codebooks_vat_periods`
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc`
  — `valid_from` / `valid_to`, `tax_period_kind`, `report_period_kind`
- dokument registrace DPH v `modules/economy/codebooks/src/` — najdi,
  jak se jmenuje, a použij jeho `afterSave` (vzor jiných dokumentů
  s hookem, např. `DocsHeadsEventHandler`)

## Rozsah

### `src/Core/Settings/LayerCParameters.php`

Nový klíč do `SPECS`:

```php
'economy.vatAgenda' => [
    'module'  => 'economy.codebooks',
    'example' => 'true',
],
```

Do `validate()` větev: povolené `true` / `false` (přijmi i `1` / `0`,
ulož jako bool). Do doc komentáře třídy dopiš, že `vatAgenda` je
**předvolba, ne zdroj pravdy o plátcovství** — komentář je jediné místo,
kde se to dá říct budoucímu čtenáři, který uvidí jen jméno klíče.

Ověř, že `[TODO]` výpis v `ds-upgrade` klíč automaticky pobral (měl by —
iteruje `SPECS`).

### `modules/docs/core/src/DocsHeadsFormBase.php`

`applyDefaults()`, dnes:

```php
if (!isset($data['vat_mode'])) {
    $data['vat_mode'] = 1;
}
```

Nahradit odvozením z klíče: `vatAgenda === false` → `0`, jinak `1`.
Nerozhodnutý klíč (`null`) → `1`, tedy dnešní chování — nerozhodnuto
nesmí měnit sémantiku dokladů.

**Jen default.** `$hasVat` v `buildHeaderTab()` a `DocRowsForm` zůstávají
na `vat_mode` dokladu (D10). Neber si `$hasVat` jako pozvánku
k přepojení.

Sekce **„DPH" jako celek**: separátor + `vat_mode` select skryj, když
`vatAgenda === false` **a** doklad má `vat_mode = 0`. Druhá podmínka je
tam kvůli D10 — doklad z doby plátcovství musí svůj režim dál ukazovat,
i když firma dnes plátcem není. Ověř, jak se `separator()` schovává;
pokud to `FormTab` neumí, přidej podporu (a napiš to do commit message,
je to změna form builderu).

### Navigace — `modules/economy/codebooks/`

Skrytí položek `economy.codebooks.vatRegistrations`
a `economy_codebooks_vat_periods` z `navSections` / `settingsItems`, když
platí **obě** podmínky D11:

- `economy.vatAgenda === false`
- `SELECT COUNT(*) FROM economy_codebooks_vat_registrations` = 0,
  **včetně** záznamů s vyplněným `valid_to` a včetně neaktivních
  `docState` — jde o „nikdy neexistovala", ne „není aktivní"

Zjisti, jak se dnes nav položky podmiňují — hledej existující mechanismus
(gate na aktivní modul je v `DsUpgradeCommand::isModuleActive()`, ale
runtime gating navigace bude jinde, nejspíš při skládání odpovědi
`/_app/nav` nebo obdobného endpointu). **Pokud podmíněné nav položky
dnes neexistují, zastav a napiš to** — je to nový mechanismus a chci
o něm vědět, ne aby vznikl mimochodem.

Nerozhodnutý klíč → **nic neskrývat**. Neúplné nastavení nesmí schovávat
funkčnost.

### Období DPH — hook na uložení registrace

Po uložení Registrace DPH spustit `VatPeriodsProvisioner`:

- Idempotentní — provisioner si existující období hledá
  (`SELECT id FROM economy_codebooks_vat_periods ...`), takže opakované
  uložení nic nezduplikuje. Ověř to, nespoléhej na to.
- Hook na `afterSave` dokumentu registrace, ne ve formuláři — musí
  fungovat i pro apply z exchange a pro budoucí volání z průvodce.
- Chyba provisioningu **nesmí** shodit uložení registrace. Zaloguj
  (`log/shipard.log`) a nech uživatele pokračovat; období dorovná další
  `ds-upgrade`.
- Změna `tax_period_kind` na existující registraci → provisioner
  vygeneruje období nové frekvence. **Stará období nemazat** — mohou být
  navázaná na doklady. Jen dogenerovat a nechat obojí; úklid je věcné
  rozhodnutí, ne technické.

### Dokumentace

- `docs/ds-setup.md` — pokud se cokoli odchýlí od §6, uprav spec
  (spec je nadřazený tomuhle PRD).
- `docs/cli.md` — do scénáře „nastavení čerstvého DS z konzole" přidat
  `ds-setting set economy.vatAgenda false` jako cestu pro neplátce.
- Issue #17 — do commit message `Refs #17`, ať se to na GitHubu propojí.

## Testy

`tests/Unit/Core/Settings/LayerCParametersTest.php` — validace
`economy.vatAgenda` (true/false/1/0 projde, cokoli jiného ne).

`DocsHeadsFormBase` / `applyDefaults()`:

- `vatAgenda = false` → nový doklad má `vat_mode = 0`
- `vatAgenda = true` → `1`
- klíč nerozhodnutý → `1`
- **explicitně zadaný `vat_mode` v datech příznak nepřebíjí** (regresní
  test na `!isset()` podmínku)

Sekce DPH v hlavičce:

- `vatAgenda = false` + doklad `vat_mode = 0` → sekce skrytá
- `vatAgenda = false` + doklad `vat_mode = 1` (historický) → sekce
  **viditelná** včetně polí (to je D10 v jednom testu)

Navigace: neplátce bez registrací → skryto; neplátce s ukončenou
registrací → viditelné; nerozhodnuto → viditelné.

Období DPH: uložení registrace vygeneruje období; druhé uložení
nezduplikuje; pád provisioneru neshodí uložení.

Spuštění: `vendor/bin/phpunit --filter 'LayerCParameters|DocsHeads|VatPeriods'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS, `ds-setting set economy.vatAgenda false` → nový doklad má
   „Bez DPH", sekce DPH skrytá, Registrace a Období DPH nejsou v navigaci.
2. Potvrzení přijaté i vydané faktury projde **bez** Registrace DPH.
3. Účetní deník: plná částka na nákladovém/výnosovém účtu, žádný řádek
   na 343xxx.
4. Přepnutí na `true` + založení registrace → období DPH vzniknou hned,
   bez `ds-upgrade`.
5. Přepnutí zpět na `false` + `valid_to` na registraci → nové doklady
   default „Bez DPH", ale **staré doklady i ukončená registrace zůstávají
   viditelné** (D10/D11 v provozu).

## Hotovo když

- [ ] Neplátce potvrdí přijatou i vydanou fakturu bez Registrace DPH
- [ ] Nový doklad má default podle `economy.vatAgenda`, explicitní
      hodnota v datech ho přebíjí
- [ ] Agenda DPH je z navigace skrytá jen u firmy, která nikdy plátcem
      nebyla
- [ ] Historické doklady a ukončené registrace zůstávají plně viditelné
      i po přepnutí na neplátce
- [ ] Období DPH vznikají při uložení registrace, idempotentně
- [ ] `vendor/bin/phpunit --filter 'LayerCParameters|DocsHeads|VatPeriods'`
      zelené

## Pasti / na co pozor

- **Největší riziko tohohle tasku je přepojit `$hasVat` na příznak.**
  Vypadá to jako zjednodušení („máme přece globální flag"), ale rozbije
  to všechny doklady firmy, která plátcem být přestala. D10 existuje
  právě proto.
- **Nerozhodnutý klíč se nesmí chovat jako `false`.** `null` znamená
  nerozhodnuto a musí zachovat dnešní chování (`vat_mode = 1`,
  navigace viditelná). Neplést `?? false` s `=== false`.
- `VatPeriodsProvisioner` bere roky z kalendáře (`$currentYear`,
  `$currentYear + 1`), **ne** z fiskálních roků — registrace
  s `valid_from` v hluboké minulosti tedy období za minulé roky
  nevygeneruje. To je dnešní chování a v tomhle tasku ho neměň; jen si
  toho buď vědom při ověřování bodu 4.
- `economy_codebooks_vat_registrations` má `country` a `region` s defaulty
  `cz` / `eu`. Tento task se jich nedotýká, předvyplňuje je průvodce
  (Fáze 4) z vrstvy A.
- Sekce „DPH" v hlavičce obsahuje i `vat_registration` select. Když
  sekci skryješ, ověř, že se `resolveVatRegistrationOptions()` nevolá
  naprázdno s dotazem do DB při každém renderu formuláře.
