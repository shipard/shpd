# Task: Roletky v modalech — předvyplnit výchozí hodnotu u nového záznamu (Issue #60)

**Stav:** naplánováno

## Status / cíl

Po #61 (`tasks/form-select-empty-option.md`) mají prázdnou možnost
„nevybráno" už jen nullable pole. Několik z nich ale má v praxi jednu
běžnou hodnotu a uživatel ji musí u každého nového záznamu vybírat ručně —
navíc dvě z nich (`bank_account`, `vat_registration`) jsou na FVB schovaná
v tabu Nastavení a při Potvrdit povinná. Cíl: **nový záznam dostane výchozí
hodnotu server-side, v `applyNewRecordDefaults`**, takže roletka je
předvybraná hned po otevření modalu.

Zároveň se opravuje mechanismus: `DocsHeadsFormBase::applyClientDefaults`
běží uvnitř `buildFormDefinition`, kam `$data` chodí hodnotou, a jeho
mutace se **do response `data` nepropíší** (cookbook §21, Issue #24 A).
Defaulty, které mají doletět ke klientovi, se přesouvají do hooku
`applyNewRecordDefaults`; v `applyClientDefaults` zůstává jen to, co řídí
podmíněné renderování.

GitHub Issue: shipard/shpd#60. Přebírá bod **A.1** z shipard/shpd#24
(`issue_date = dnes`); zbytek #24 (A.3 zaokrouhlení, B `onchange` na
`DateInput`/`NumberInput`) zůstává v #24.

## Potvrzená designová rozhodnutí (Anna, 2026-09-07/08)

1. **Registrace DPH** (`docs_core_heads.vat_registration`): první podle
   `country ASC, id ASC` (dnešní ORDER BY v `resolveVatRegistrationOptions`),
   jen když `vat_mode !== 0`. Ve formuláři `required: $hasVat` — sedí
   s validací `DocDocument` („Registrace DPH je povinná“ při `vat_mode != 0`).
2. **Náš bankovní účet** (`docs_core_heads.bank_account`): účet
   s `is_default = 1` ve stavu V pořádku (`docState = 40`), **bez ohledu na
   měnu dokladu** — na přijaté faktuře se platí v EUR z českého účtu běžně.
   Bez výchozího účtu zůstane „nevybráno". Jen formuláře, které pole
   renderují (základní, FVB, FPB) — ne pokladní ani účetní doklad.
3. **Kód DPH** (`docs_core_rows.vat_code`): první možnost z
   `buildVatCodeOptions($headContext)` (pro CZ tuzemsko „Základní"),
   **včetně dopočtu `vat_pct`** stejnou cestou, jakou dnes jde
   `recalculate('vat_code')`. Reload není překážka — dělá jen tento dopočet.
4. **Druh položky** (`economy_items.item_kind`): systémový druh
   `system_code = 'other'` (sedí se schéma defaultem `item_type = 3`);
   `item_type` odvodit stejnou cestou jako `recalculate('item_kind')`.
   Dnešní „Ostatní" v Typu položky je náhoda schéma defaultu, ne odvození.
5. **Přesun mrtvých klientských defaultů do hooku**, pokud jsou potřeba pro
   gating nebo je přebírá #24: `issue_date = dnes` (#24 A.1), `vat_mode = 0`
   účetního dokladu (`AccountingDocsForm`), `payment_method = 0` + měna
   pokladny (`CashDeskFormBase`). Bez nich by base hook nevěděl, že na
   účetní doklad nepatří registrace DPH a na pokladní doklad bankovní účet.
6. **Živý výpočet DPH na řádku** (`vat_base`/`vat_amount`/`vat_total` v
   modalu před uložením) — **zatím ne**, Anna se připomene. Dnes je počítá
   až `DocDocument::calculateRowVat` při uložení dokladu.
7. **`resolveVatRegistrationOptions` nefiltruje `valid_from/valid_to`** —
   ponechat, jen poznámka.

## Před implementací přečti

- `docs/edit-forms-cookbook.md` §21 — proč mutace v `buildFormDefinition`
  nedoletí a jak se používá `applyNewRecordDefaults`.
- `src/Api/Controller/FormController.php` ř. 60–112 — pořadí pro GET /meta
  bez id: column defaults ze schématu → `defaults[...]` z query →
  `applyNewRecordDefaults($data)` → `buildFormDefinition($data)` (hodnotou)
  → response `data` = kopie controlleru.
- `src/Core/Form/TableForm.php` ř. 66 — signatura hooku.
- `modules/docs/core/src/DocsHeadsFormBase.php`:
  - ř. 79–91 `applyNewRecordDefaults` (dnes jen `vat_mode` neplátce),
  - ř. 300–355 `applyClientDefaults` (mrtvé větve `number_series`,
    `issue_date`; zbytek duplikuje schéma defaulty),
  - ř. 439 `select('vat_registration', …)`, ř. 489 `select('bank_account', …)`,
  - ř. 911–925 `resolveDefaultCashDesk` — vzor „`is_default` ve stavu 40",
  - ř. 1010–1034 `resolveVatRegistrationOptions`, ř. 1037 `resolveBankAccountOptions`.
- `modules/docs/invoicesOut/src/IssuedInvoiceForm.php` ř. 170–200
  (`buildSettingsTab`: `bank_account`, `vat_registration`),
  `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php` ř. 185–202,
  `modules/docs/cashDocs/src/CashDocForm.php` ř. 105–112,
  `modules/docs/cashRegister/src/CashRegisterForm.php` ř. 81–86 —
  všech pět míst s `vat_registration`.
- `modules/docs/accountingDocs/src/AccountingDocsForm.php` ř. 57–64
  (`applyClientDefaults` → `vat_mode = 0`);
  `modules/docs/accountingDocs/src/AccountingDocument.php` ř. 45–60
  (záchytná síť při uložení — po přesunu zůstává).
- `modules/docs/core/src/CashDeskFormBase.php` ř. 31–43
  (`applyClientDefaults` → `payment_method = 0`, měna pokladny);
  `CashDeskDocumentBase.php` ř. 76 (záchytná síť).
- `modules/docs/core/src/DocRowsForm.php`:
  - ř. 28 `$headHasVat`, ř. 58 `$showVat = $headHasVat && !$isText`,
  - ř. 227–239 `applyNewRecordDefaults` (default `operation`; **vrací se
    předčasně, když je `operation` prefillnutá** — to se musí změnit),
  - ř. 354–372 `recalculate('vat_code')` → `VatRateResolver::resolveVatPct`,
  - ř. 455–480 `buildVatCodeOptions`, ř. 398–450 `loadHeadContext`.
- `modules/world/vat/config/vat-cz.jsonc` ř. 65+ — pořadí `vatCodes`
  = pořadí options (první tuzemský vstup/výstup je „Základní").
- `modules/economy/items/src/ItemsForm.php` ř. 45–53 (selecty),
  ř. 197–215 `recalculate('item_kind')`, ř. 219–233 `resolveItemKindOptions`.
- `modules/economy/items/config/itemKindsSeed.jsonc` — `system_code`
  `service|stock|accounting|other`; `economy_items_kinds.system_code`
  je nullable varchar(25).
- `modules/docs/core/src/DocDocument.php` ř. 114 — validace registrace.
- `help/faktury-vydane/vystaveni-faktury.md` ř. 37–38 — pasáž „Datum
  vystavení a Účetní datum vyplň sám… Shipard je nepředplňuje" — po
  opravě přepsat (Účetní datum se dál nepředplňuje, dokud #24 B nespraví
  reload na datumu).
- Testy: `tests/Unit/Module/Docs/Core/DocRowsFormOperationsTest.php`
  ř. 163–190 (vzor testů hooku), `DocsHeadsFormTest.php`,
  `InvoiceFormsCashDeskTest.php`, `tests/Unit/Module/Docs/CashDocs/CashDocFormTest.php`,
  `tests/Unit/Module/Economy/Items/ItemsFormHeaderInfoTest.php`,
  `tests/Fixtures/Module/Docs/Core/TestableDocsHeadsForm.php`.

## Rozsah

### 1. `DocsHeadsFormBase::applyNewRecordDefaults` — rozšíření

Pořadí uvnitř hooku je podstatné (gating závisí na `vat_mode` a na tom,
zda typ dokladu pole vůbec má):

```php
public function applyNewRecordDefaults(array &$data): void
{
    // 1. vat_mode neplátce (stávající)
    // 2. issue_date = dnes, když prázdné (#24 A.1)
    // 3. vat_registration: když (int)($data['vat_mode'] ?? 1) !== 0
    //    && empty($data['vat_registration']) → první z resolveVatRegistrationOptions()
    // 4. bank_account: když $this->newRecordUsesBankAccount()
    //    && empty($data['bank_account']) → resolveDefaultBankAccount()
}
```

- `protected function newRecordUsesBankAccount(): bool` — base `true`,
  `CashDeskFormBase` a `AccountingDocsForm` `false` (pole nerenderují).
  Alternativa přes `payment_method !== 0` nestačí — účetní doklad má
  `payment_method = 1` ze schématu a pole přesto nemá.
- `protected function resolveDefaultBankAccount(): ?int` — dle vzoru
  `resolveDefaultCashDesk`: `SELECT id FROM economy_codebooks_bank_accounts
  WHERE is_default = 1 AND docState = 40 ORDER BY sort_order, id LIMIT 1`.
  Bez měnového filtru (rozhodnutí 2). Víc výchozích účtů by být nemělo,
  `ORDER BY` je jen determinismus.
- Registrace: sdílet dotaz s `resolveVatRegistrationOptions` (první prvek
  options), aby „první" znamenalo totéž, co uživatel vidí v roletce.
- Z `applyClientDefaults` **odstranit** mrtvé větve `number_series`
  (řadu prefilluje klient z aktivního tabu, `Viewer.svelte` ř. 400–409)
  a `issue_date` (přesunuto). Ostatní větve (duplikáty schéma defaultů,
  `home_currency`/`doc_currency`) nechat — řídí renderování
  (`$hasForeignCurrency`, měnový filtr účtů) a jsou vstupem pro #24 A.3.
- Explicitní hodnota v `$data` (import, kopie, `defaults[...]`) vždy
  vyhrává — všechny větve `empty()`/`!isset()` guardy.

### 2. Overrides hooku v subclassách

- `AccountingDocsForm::applyNewRecordDefaults`: `$data['vat_mode'] = 0;`
  **před** `parent::` (aby base neuplatnil registraci), plus
  `newRecordUsesBankAccount(): false`. Z `applyClientDefaults` větev
  `vat_mode = 0` **nechat** — řídí skrytí sekce DPH při renderu i u
  recalculate (kde hook neběží).
- `CashDeskFormBase::applyNewRecordDefaults`: `payment_method = 0`
  (když neprefillnuto) a `doc_currency` z pokladny (když
  `resolveCashDesk($data)` něco vrátí), pak `parent::`;
  `newRecordUsesBankAccount(): false`. Větve v `applyClientDefaults`
  nechat ze stejného důvodu (recalculate při změně řady přepíná měnu).
- `IssuedInvoiceForm` / `ReceivedInvoiceForm`: hook nepřepisují
  (dědí base), jen `required: $hasVat` u `vat_registration` v
  `buildSettingsTab` / nastavení FPB. Totéž `CashDocForm` ř. 107,
  `CashRegisterForm` ř. 83, base ř. 439.

### 3. `DocRowsForm::applyNewRecordDefaults` — Kód DPH

- Zrušit předčasný `return` při prefillnuté `operation`; struktura:

```php
public function applyNewRecordDefaults(array &$data): void
{
    if ((int) ($data['row_kind'] ?? 1) !== 1) { return; }        // textový řádek
    $headContext = $this->loadHeadContext($data['doc_head'] ?? null);
    if (empty($data['operation'])) { /* stávající default + applyContationRowDefaults */ }
    // kontační řádek (hasRowSideLayout) nemá DPH blok → skip
    // headHasVat (vat_mode !== 0) && empty(vat_code)
    //   → první z buildVatCodeOptions($headContext), pak deriveVatPct($data, $headContext)
}
```

- `private function deriveVatPct(array &$data, ?array $headContext): void`
  — vytáhnout tělo větve `recalculate('vat_code')` (ř. 354–372, včetně
  `catch (\LogicException)`) a volat ji z obou míst. `recalculate` se
  chováním nemění.
- Podmínka `$headHasVat` je dnes lokální v `buildFormDefinition` (ř. 28) —
  vytáhnout do helperu nad `$headContext`, aby hook i build používaly
  totéž.
- `loadHeadContext` se v hooku volá jednou a předá dál (dnes ho
  `buildOperationOptions` dostává jako parametr — zachovat).

### 4. `ItemsForm::applyNewRecordDefaults` — Druh položky

```php
public function applyNewRecordDefaults(array &$data): void
{
    if (!empty($data['item_kind']) || $this->db === null) { return; }
    $row = $this->db->fetchRow(
        'SELECT id, item_type FROM economy_items_kinds'
        . ' WHERE system_code = %s AND docState IN (10, 40, 80) LIMIT 1', 'other');
    if ($row !== null) { $data['item_kind'] = (int) $row['id']; $data['item_type'] = (int) $row['item_type']; }
}
```

- Sdílet odvození `item_type` s `recalculate('item_kind')` (helper
  `deriveItemType(array &$data)`), aby byl jeden dotaz na jednom místě.
- Když systémový druh `other` v DS neexistuje (DS před provisionerem) →
  nic nenastavit, roletka zůstane prázdná jako dnes.

### 5. Testy

- `DocsHeadsFormTest` (nebo nový `DocsHeadsFormNewRecordDefaultsTest`)
  přes `TestableDocsHeadsForm` s fake DB:
  - `issue_date` = dnes, když prázdné; explicitní prefill vyhrává;
  - `vat_registration` = první z options při `vat_mode` 1; **ne** při
    `vat_mode` 0; explicitní vyhrává; bez registrací zůstává prázdné;
  - `bank_account` = `is_default`; bez výchozího → nenastaveno;
  - `vat_registration` element `required` true při `vat_mode` 1, false při 0.
- `AccountingDocsForm`: hook nastaví `vat_mode = 0` a **nenastaví**
  `vat_registration` ani `bank_account`.
- `CashDocFormTest`: hook nastaví `payment_method = 0`, `doc_currency`
  z pokladny, `bank_account` nenastaven; `vat_registration` ano (pokladní
  doklad DPH má).
- `DocRowsFormOperationsTest` / nový test: nový položkový řádek dostane
  `vat_code` = první option a `vat_pct` z resolveru; textový řádek ne;
  kontační řádek (`cmnbkp`) ne; hlavička `vat_mode = 0` ne; prefillnutá
  `operation` **už neblokuje** default `vat_code`; bez `vat_duzp` se
  `vat_code` nastaví, `vat_pct` ne.
- `ItemsForm`: `item_kind` = id druhu `other`, `item_type = 3`; prefill
  vyhrává; bez systémového druhu nic.
- `php -l` na dotčené soubory,
  `vendor/bin/phpunit --filter 'DocsHeadsForm|AccountingDocs|CashDocForm|DocRowsForm|ItemsForm'`.

### 6. Dokumentace a issues

- `docs/edit-forms-cookbook.md` §21: doplnit, že `DocsHeadsFormBase`
  hook používá (příklad `issue_date`/`vat_registration`/`bank_account`)
  a že `applyClientDefaults` je **jen pro renderování**.
- `help/faktury-vydane/vystaveni-faktury.md` ř. 37–38: Datum vystavení
  je předvyplněné dneškem; Účetní datum stále vyplnit ručně (do #24 B).
  Zmínit, že Registrace DPH a Náš bankovní účet v tabu Nastavení jsou
  předvybrané, když existuje registrace / výchozí účet. Projít i
  `help/faktury-prijate/*.md` a `help/polozky/zalozeni-polozky.md`, zda
  něco netvrdí opak; `python3 scripts/help-index.py` (a `--check`, pokud
  existuje).
- Komentář do #24: A.1 vyřešeno v #60 (commit), `number_series` větev
  byla mrtvá a odstraněna (řadu prefilluje klient), přesunuty i
  `vat_mode`/`payment_method` defaulty; **zbývá A.3** (zaokrouhlení —
  věcné rozhodnutí) a **B** (`onchange` na `DateInput`/`NumberInput`).
- Komentář do #60 po implementaci: co se předvyplňuje kde; poznámka, že
  živý výpočet `vat_base`/`vat_amount`/`vat_total` v modalu řádku je
  samostatná věc (rozhodnutí 6).
- Issue komentáře s diakritikou: `--body-file` přes temp soubor,
  `gh issue comment --repo shipard/shpd`.

### Mimo rozsah

- #24 A.3 (výchozí zaokrouhlení `total_rounding_mode`/`vat_rounding_mode`
  — schéma `0`, `applyClientDefaults` `1`/`2`; M0 rozhodnutí).
- #24 B (`onchange` prop na `DateInput`/`NumberInput`, Účetní datum a
  DUZP z Data vystavení před uložením).
- Živý výpočet DPH na řádku (rozhodnutí 6).
- Filtr platnosti registrací (rozhodnutí 7).
- Měnový filtr výchozího účtu (rozhodnutí 2).

## Pasti

- **Hodnotou vs. referencí.** Hook mutuje `$data` referencí a `FormController`
  ji pak pošle klientovi; `buildFormDefinition` dostane kopii. Cokoli, co má
  uživatel vidět v inputu, patří do hooku. Cokoli, co řídí `hidden`/options
  při renderu **i při recalculate**, musí zůstat v `applyClientDefaults`
  (recalculate hook nevolá). Proto se větve `vat_mode = 0`
  a `payment_method = 0` **duplikují**, ne přesouvají.
- **Pořadí v hooku:** registrace se rozhoduje podle `vat_mode` — subclass
  (účetní doklad) musí `vat_mode` nastavit **před** `parent::`.
- **`empty()` vs. `0`:** `vat_mode = 0` je platná hodnota; guardy pro
  `vat_mode` používat `isset`, nikdy `empty`.
- **`DocRowsForm` předčasný return** při prefillnuté `operation` (ř. 229) —
  dnes blokuje cokoli dalšího v hooku; testy
  `testNewRowDefaultKeepsExplicitPrefill` musí dál projít.
- **Řádek bez DUZP hlavičky:** `deriveVatPct` vyžaduje `vat_duzp`
  (`loadHeadContext`); bez něj kód nastavit, sazbu ne — stejné chování
  jako dnešní recalculate.
- **Kontační řádek** (`hasRowSideLayout`) nemá DPH blok; `vat_code`
  default by tam zapsal hodnotu do skrytého pole → nesmí.
- **`system_code` je nullable** — dotaz s `WHERE system_code = 'other'`
  je v pořádku, ale nespoléhat na existenci řádku.
- **`is_default` může být 1 u víc účtů** (nic to nebrání) — `ORDER BY
  sort_order, id LIMIT 1`, nepadat.
- **`required: $hasVat` u `vat_registration`** odebere prázdnou možnost;
  pokud DS nemá žádnou registraci a `vat_mode = 1`, roletka je prázdná
  bez hodnoty (Svelte efekt z #61) — validace to stejně odmítá, není
  regrese, ale ověřit, že se chyba zobrazí u pole v tabu Nastavení.
- **`TestableDocsHeadsForm` a fake DB** — nové dotazy (`is_default`,
  registrace) musí fixture umět vrátit; podívat se, jak fixture mockuje
  `fetchRow`/`fetchAll` (podle SQL prefixu?), než se napíšou testy.
- **Diakritika**: Python heredoc + `assert s.count(old) == 1`; commit
  message přes `.git/COMMIT_MSG_TMP`.
- **Alfa je read-only** — E2E jen na dev DS.

## Hotovo když

1. Testy z bodu 5 zelené; celý `vendor/bin/phpunit` bez regresí.
2. E2E dev DS — **nová FVB** z prohlížeče (aktivní tab řady): Datum
   vystavení = dnes, tab Nastavení má Registraci DPH i Náš bankovní účet
   předvybrané (DS musí mít registraci a účet s `is_default`), Registrace
   DPH je s hvězdičkou a bez „nevybráno". Uložit → Potvrdit projde bez
   ručního zásahu v Nastavení.
3. E2E — **nová FPB**: totéž; bankovní účet předvybraný i když je měna
   dokladu jiná než měna účtu.
4. E2E — **nový účetní doklad**: sekce DPH skrytá, po uložení má záznam
   `vat_registration IS NULL` a `bank_account IS NULL` (ověřit SQL na dev).
5. E2E — **nový pokladní doklad**: Způsob platby Hotovost, měna pokladny,
   Registrace DPH předvybraná, `bank_account IS NULL` po uložení.
6. E2E — **nový řádek FVB**: Kód DPH „Základní", DPH % vyplněné hned po
   otevření; textový řádek bez Kódu DPH; u FVB s `vat_mode` Bez DPH se
   Kód DPH nenastaví.
7. E2E — **nová položka**: Druh položky „Ostatní", Typ položky „Ostatní";
   po změně druhu na „Služba" se typ přepne (recalculate beze změny).
8. E2E — **existující doklad/řádek/položka** otevřený a zavřený bez
   editace: dirty guard se neaktivuje, žádná hodnota se nezměnila.
9. Komentáře v #24 a #60 napsané; help i cookbook aktualizované;
   `python3 scripts/tasks-index.py && python3 scripts/tasks-index.py --check
   && python3 scripts/check-sensitive.py` projde.
