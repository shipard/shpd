# Task: Zámek období — instance tvrzení DPH a fiskální měsíc, lock providery, kontrola zůstatků 343 (M1 Fáze 4a) — #55 D23–D27, D31

**Stav:** k implementaci — 2026-09-11
**Issue:** #55 — komentář „Fáze 4 — Zámek a zaúčtování: rozhodnutí D23–D31 (2026-09-11)"
**Návaznost:** staví na instancích tvrzení (`economy_vat_report_periods`,
`tasks/vat-report-periods.md`), podáních (`economy_vat_filings`, `tasks/vat-filings.md`)
a fiskálních měsících (`economy_codebooks_fiscal_months`). Zaúčtování přiznání =
navazující `tasks/vat-filing-accounting.md` (F4b) — kontrola zůstatků z §6 je jeho
protistrana a bez F4b bude hlásit všechny podané instance (to je správně, ne chyba).
Import zámku ze starého systému = `old_shipard` task 36.

## Cíl

Po podání tvrzení musí být obsah období **neměnný** — dnes jde podaný doklad opravit,
stornovat i smazat a nový doklad založit zpětně do podaného měsíce, a nikdo se to
nedozví. Fáze 4a přidá:

1. **Obecný mechanismus zámku dokladu** v `docs.core` (lock providery, D24) — jediný
   zdroj pravdy pro validaci, přechody stavů i UI banner, bez znalosti DPH v jádru.
   Náhrada starého `getDocumentLockState` (`old_shipard` `e10doc/core/tables/heads.php`
   ř. 1440–1463), které hlídalo fiskální rok a `taxPeriod`.
2. **Zámek instance tvrzení DPH** (D23, D25) — podle obsahu DPH dokladu, přes
   kterýkoli ze tří ukazatelů, ruční akce Uzamknout / Odemknout + alert.
3. **Zámek fiskálního měsíce** (D27) — natvrdo pro všechny doklady dle `fiscal_month`.
4. **Kontrolu zůstatků 343** za podané instance (D31) — Σ deníku na DPH analytikách
   přes doklady instance a účetní doklady jejích podání musí být 0.

Před implementací **přečti**:

- issue #55 komentáře D7–D13 (instance, ukazatele), D14–D22 (podání), D23–D31 (tato fáze)
- `docs/document-system.md` (Document hooky, `stateTransitionsRunDocumentHooks`,
  event handlery), `docs/edit-forms.md` (`doc_states`, transitions, read-only stav
  formuláře), `docs/viewer-grid.md` (detail, `actions`, toolbar), `docs/alerts.md`
  (`AlertCheck`, `AlertFinding`, registrace `alertChecks`), `docs/cli.md`
- `src/Core/Document/TableGateway.php::saveDocument` — **pořadí**: `validate()` běží
  před `beforeSave()` a před event handlery, dostává jen `$data` (originál si
  provider načte sám); `deleteDocument`
- `src/Core/Document/Document.php` (`validate`, `filterStateTransitions`, `beforeDelete`),
  `DocumentEventHandler.php` (handlery **nemají** validační hook — proto providery,
  ne handler)
- `modules/docs/core/src/DocDocument.php` — `validate()` (ř. 105+), `beforeSave()`
  (import mód `_importNumber`, ř. 422+), `trackStateChange`, `filterStateTransitions`
  (ř. 1748+, vzor odebrání přechodu), `resolveAccountingPeriods` (`fiscal_month`),
  `buildVatRecapitulation` (kdy má doklad recap); `config/docStates.jsonc`
- `modules/docs/core/src/DocsHeadsFormBase.php`, `DocsHeadsViewer.php` — kam přidat
  stav zámku pro UI; `frontend/src/components/viewer/Viewer.svelte` (dispatch akcí
  detailu, vzor `recomposeFiling` → `frontend/src/api/vat.js` → `VatFilingController`)
- `modules/economy/vat/src/ReportPeriodDocument.php` (guardy, `cancellationBlockers`
  už čte `locked`), `ReportPeriodsViewer.php` (`renderDetail`, `actions`),
  `DocsHeadsVatPeriodHandler.php` + `VatPeriodAssigner.php` (výpočet nových
  ukazatelů — provider je použije), `VatPeriodRecalculator.php`, `FilingDocument.php`
  (přechod 10→40, `afterPersist`), `FilingsViewer.php`, `VatFilingController.php`,
  `Checks/DraftReportPeriodsCheck.php` (vzor kontroly), `VatJournalCrossCheck.php`
  (dotaz na 343 deník — jiná kontrola, ale stejná konvence analytik),
  `module.jsonc` (`alertChecks`, `documentEventHandlers`)
- `modules/economy/codebooks/src/FiscalMonthDocument.php`, `FiscalYearsViewer.php`
  (detail roku = tabulka měsíců, ř. 129+), `tables/economy_codebooks_fiscal_months.jsonc`
  (`period_type` 0/1/2 — zamykají se jen běžné měsíce 1)
- `modules/economy/accounting/src/AccountingController.php::reaccount`,
  `AccountingEngine.php` — přegenerování deníku (D27: přes zamčený doklad jen s force)
- `src/Command/DataSource/VatPeriodsEnsureCommand.php` (vzor DS příkazu)
- old_shipard: `e10doc/core/tables/heads.php::getDocumentLockState`,
  `e10doc/base/config/e10doc.base.taxperiods.docStates.json` (9000 Uzavřeno →
  8000 V opravě → 4000) — referenční sémantika

## Co vznikne

### 1. Lock providery — `docs.core` (D24)

**`src/Core/Document/DocumentLockProvider.php`** (rozhraní, jádro — ať ho může
použít i budoucí zámek jiných tabulek):

```php
interface DocumentLockProvider
{
    /**
     * Důvody, proč záznam nejde uložit / změnit stav / smazat. Prázdné = volný.
     * $data = nový stav (payload po injektáži docState), $original = uložený
     * řádek (null u insertu). Provider rozhoduje sám, co je pro něj „obsah"
     * (DPH provider koukne na recap, měsíční na fiscal_month).
     *
     * @return list<DocumentLockReason>
     */
    public function lockReasons(string $tableId, array $data, ?array $original): array;
}
```

**`DocumentLockReason`** (value object): `source` (`vat_period` | `fiscal_month` | …),
`subjectTableId`, `subjectRowId`, `title` (cs/en dle jazyka), `message`, volitelně
`unlockAction` (akce pro budoucí průvodce — viz „Navazující"). `title` je to, co
uvidí uživatel v banneru: „Kontrolní hlášení 01/2026 je uzamčené" /
„Fiskální měsíc 2026/01 je uzamčený".

**`DocumentLockRegistry`** — sbírá providery z `module.jsonc` sekce
`documentLockProviders: [{table, class}]` (vzor `documentEventHandlers`), injektuje
db/config, metoda `reasons(tableId, data, original)`. `TableGateway` ji dostane
volitelně v konstruktoru (stejně jako `eventDispatcher`) a předá dokumentu přes
`Document::setLockRegistry()`.

**Vynucení — `DocDocument`** (jen doklady; jiné tabulky zatím providery nemají):

- `validate()`: na konci, po ostatních kontrolách, `$this->lockReasons($data)` —
  originál si načte přes `loadDocument`/`fetchRow` podle `id` (1 SELECT, jen když
  registry má providery pro tabulku). Každý důvod → `addError(FIELD_FORM, message,
  'locked')`. Platí pro **všechny** zápisy vč. přechodů stavů (`docStates` dokladů
  mají `stateTransitionsRunDocumentHooks`? — ověř; pokud přechod jde mimo
  `validate`, doplň guard i do cesty přechodu, jinak zámek obejde tlačítko
  „Opravit").
- `beforeDelete()`: stejné důvody → `DomainException` (vzor `ReportPeriodDocument`).
- `filterStateTransitions()`: zamčený doklad → **žádné** přechody.
- **Import mód obchází zámek** (D26): payload s `_importNumber` (a `dataset-seed`,
  pokud jde jinou cestou — ověř `Applier`) providery nevolá. Označ v `validate`
  přes tentýž marker, který čte `beforeSave` (`_importNumber` je v `$data` ještě
  před `beforeSave`, protože `validate` běží dřív).
- **Force** (D27): virtuální pole `_forceUnlock: true` v payloadu obchází zámek
  **jen** z CLI (`bin/shpd-ds …` s `--force`) — HTTP vrstva ho z payloadu strhne
  (FormController), aby ho klient nemohl poslat. Použití se zaloguje
  (`ErrorLogger`/`shipard.log`, level notice, s docId a důvody).

**UI:**

- Form meta / viewer detail dokladu nese `lock: {locked: bool, reasons: [{title,
  message, source}]}` — najdi místo, kde se dnes vrací `readOnly` stavu; zámek se
  chová jako read-only + banner nad formulářem (varianta warning, ikona zámku)
  s `title` každého důvodu. Přechody v detailu nejsou (filterStateTransitions),
  toolbar `edit` skrytý.
- Jedna sdílená komponenta banneru (`DocumentLockBanner.svelte`), používá ji
  FormEditor i detail vieweru.

### 2. Zámek instance tvrzení DPH — `economy.vat` (D23, D25, D26)

**Tabulka `economy_vat_report_periods`:** k existujícímu `locked` přidat `locked_at`
(datetime, nullable) a `locked_by` (int, reference `core_system_users`, nullable)
— jednotný tvar zamykatelné entity (stejné sloupce dostane fiskální měsíc). `.md`
doplnit. `ds-upgrade`.

**`src/VatPeriodLockProvider.php`** (`DocumentLockProvider`, tabulka `docs_core_heads`):

1. Recap: `hasVatOriginal` = existuje řádek `docs_core_vat_recap` pro `id`;
   `hasVatNew` = nový stav bude mít recap — rozhodni **stejnou logikou jako
   `DocDocument::buildVatRecapitulation` / `useDeclaredRecap`**, ale bez výpočtu
   částek: `vat_mode != 0` a (aspoň jeden řádek s neprázdným `vat_code`, nebo
   deklarovaná rekapitulace v payloadu). Extrahuj z `DocDocument` čistou
   statickou metodu `willHaveVatRecap(array $data): bool` a použij ji z obou míst,
   aby se pravidlo nerozjelo.
2. Ukazatele: původní = `vat_period/cs_period/rs_period` z uloženého řádku; nové =
   `DocsHeadsVatPeriodHandler::manualOverrides` + `VatPeriodAssigner::compute` nad
   novým stavem (recap pro membership = kódy z řádků payloadu; stejný
   `ReportPeriodsProvisioner`, ale **`setCreateMissing(false)`** — validace nesmí
   zakládat koncepty, to udělá až handler v `beforeSave`).
3. `locked` instancí pro sjednocení obou trojic (1 SELECT `WHERE id IN`). Důvod
   per zamčená instance, když `(hasVatOriginal && původní ukazatel zamčený) ||
   (hasVatNew && nový ukazatel zamčený)`.
4. Bez `vat_registration` / DUZP / bez recapu → prázdné (bezdaňový pokladní
   převod je volný i v podaném měsíci — o něj se stará fiskální měsíc).

**`ReportPeriodDocument`:** zamčená instance nesmí změnit rozsah ani stav (kromě
`locked` samotného a `name`); `cancellationBlockers` už `locked` čte. Přechod
`locked 0→1 / 1→0` = jediná povolená mutace zamčené instance, nastaví
`locked_at`/`locked_by` (uživatel z kontextu dokumentu — ověř, jak Document získá
aktuálního uživatele; FilingDocument to dělá pro `date_filed`?). Zamknout lze i
instanci bez podání (historicky podané ve starém systému) a i s živým konceptem
podání — sestavení podání nad zamčenou instancí je **povolené** (snapshot je nad
neměnnými daty).

**`VatPeriodRecalculator`** (D26): před přepočtem dotčených dokladů zjisti, jestli
některý má současný nebo nový ukazatel na zamčenou instanci → `DomainException`
s výčtem (max. 5 čísel dokladů + počet) → rollback uložení sousední instance.
Test.

**Akce a UI:**

- `ReportPeriodsViewer::renderDetail`: akce `lockReportPeriod` („Uzamknout",
  primary, jen když `!locked`) / `unlockReportPeriod` („Odemknout", secondary,
  s `confirm`), viewer řádek: čip „Uzamčeno" + `locked_at`. V properties detailu
  „Uzamčeno od / kým"; `locked = 1` s `locked_at IS NULL` = zámek z importu
  (`old_shipard` task 36 posílá jen `locked`) → „Uzamčeno (import)".
- `FilingsViewer::renderDetail`: u **podaného** podání (40) s neuzamčenou instancí
  akce `lockReportPeriod` („Uzamknout tvrzení", primary) — to je ten „jeden klik
  po podání". Pokud přechodový dialog `doc_states` umí volitelné pole
  (`docs/edit-forms.md`), přidej k přechodu 10→40 checkbox `_lockPeriod`
  (default zapnuto u `regular`), který `FilingDocument::afterPersist` provede;
  pokud neumí, **neimplementuj nový UI primitiv** — stačí nabídnutá akce.
- `VatFilingController`: endpointy `POST /_vat/report-period-lock` (`{periodId,
  locked: bool}`) → `saveDocument` instance s `locked` (guardy jdou přes Document);
  `frontend/src/api/vat.js` + větve v `Viewer.svelte`.

**Alert `economy.vat.filed_unlocked_periods`** — `Checks/FiledUnlockedPeriodsCheck`:
instance `return` s podáním ve stavu 40 (`date_filed` starší než 3 dny — ať
nehlásí uprostřed práce) a `locked = 0`. Finding per instance, akce otevřít
tvrzení. Registrace v `module.jsonc` `alertChecks` (denně).

### 3. Zámek fiskálního měsíce — `economy.codebooks` (D27)

**Tabulka `economy_codebooks_fiscal_months`:** `locked` (boolean, default 0),
`locked_at`, `locked_by` — stejný tvar jako instance. `.md`, `ds-upgrade`.
Zamykají se jen `period_type = 1`; roční `economy_codebooks_fiscal_years.locked`
se **nevynucuje ani nemění** (uzávěrka je mimo scope).

**`src/FiscalMonthLockProvider.php`** (tabulka `docs_core_heads`): důvod, když
`original.fiscal_month` nebo nový `fiscal_month` (dopočítej z `accounting_date`
stejným dotazem jako `DocDocument::resolveFiscalMonthId` — extrahuj do sdílené
statické metody / `FiscalMonthLookup`) míří na zamčený měsíc. **Bez ohledu na
obsah a stav dokladu** — koncepty včetně (David: „koncepty v zamčeném měsíci
nepovolovat"). Bez `accounting_date` → prázdné.

**`FiscalMonthDocument`:** zamčený měsíc nesmí měnit rozsah; `locked` přepínač
nastaví `locked_at/by`. Při **zamykání** spusť kontrolu z §4 pro instance `return`
s `date_end` v měsíci a nenulový výsledek vrať jako **warning** (`addWarning`,
ne error) — David: varovat, neblokovat.

**UI:** `FiscalYearsViewer::renderDetail` záložka měsíců: sloupec Zámek (čip),
akce per řádek `lockFiscalMonth` / `unlockFiscalMonth` (`confirm` u odemknutí).
Endpoint `POST /_codebooks/fiscal-month-lock` (`{monthId, locked}`) — je-li pro
`economy.codebooks` controller, přidej tam; není-li, založ
`FiscalPeriodsController` po vzoru `VatFilingController`.

**Přegenerování deníku** (D27, bod 3): `AccountingController::reaccount` a
`AccountingEngine` cesta pro jeden doklad — je-li doklad zamčený (registry
`reasons` nad uloženým řádkem, `data = original`), odmítnout s výčtem důvodů.
Existující/nový CLI příkaz pro přeúčtování (ověř, co je: `docs/cli.md`) dostane
`--force`, který přejde na `_forceUnlock` sémantiku (log). Deník je derivát —
bez změny dokladu se přegenerováním nesmí změnit, takže odmítnutí nic nerozbije.

### 4. Kontrola zůstatků 343 za podané instance — `economy.vat` (D31)

**`src/ClosedPeriodBalanceCheck.php`** (čistá třída s DB dotazy, sdílená alertem,
`FiscalMonthDocument` varováním i budoucím CLI):

```
pro instanci return s podáním 40:
  docs  = docs_core_heads.id WHERE vat_period = instance AND docState != 90
        ∪ economy_vat_filings.acc_document WHERE report_period = instance
          AND docState = 40 AND acc_document IS NOT NULL
  Σ per account_number LIKE '343%' AND account_number NOT IN (343801, 343802)
    (money_dr − money_cr) FROM economy_accounting_journal WHERE doc_head IN docs
  nenulové (|Σ| > 0.005) → nález {account, balance}
```

Pozn.: sloupec `acc_document` zakládá F4b — do té doby kontrola bere jen doklady
instance a hlásí všechny podané instance. To je očekávané; **kontrola vzniká tady**,
protože její nosič (zámek/podané období) je tady, a F4b jen doplní druhou množinu.
Zapiš do třídy TODO odkaz na `tasks/vat-filing-accounting.md`.

**Alert `economy.vat.closed_period_balance`** — `Checks/ClosedPeriodBalanceCheck`
(obálka): finding per (instance, účet) s částkou, severity `warning`, akce otevřít
tvrzení. Denně. Text v cs: „Za podané přiznání {name} zůstává na {account}
{balance} Kč — chybí zaúčtování přiznání, nebo se DPH po podání změnila."

**Detail instance** (`ReportPeriodsViewer`): sekce „Zůstatky DPH" s výsledkem
kontroly (jen u instancí s podaným podáním).

### 5. Import (D26) — jen na nové straně

Nic v `docs_core` — import mód providery obchází (§1). Runner na staré straně
(`old_shipard` task 36) začne posílat `locked` = 1 pro instance kryté starým
`taxperiods.docState 9000`; nová strana pole už přijímá (`economy_vat_report_periods`
je běžná tabulka přes `saveDocument`). Ověř, že exchange/applier instance
`locked` nefiltruje a že přiřazení importovaných dokladů (`DocsHeadsVatPeriodHandler`
v import módu) do zamčené instance projde.

### 6. Testy

- `tests/Unit/Core/Document/DocumentLockRegistryTest` — registrace, agregace důvodů.
- `tests/Unit/Module/Docs/Core/DocDocumentLockTest` — provider vrací důvod →
  validate error `locked`, `beforeDelete` výjimka, `filterStateTransitions` prázdné;
  import mód provider nevolá; `_forceUnlock` obejde a zaloguje.
- `VatPeriodLockProviderTest` — matice: {recap orig/new: ano/ne} × {ukazatel
  orig/new zamčený: ano/ne} × typ ukazatele (return/cs/rs); bezdaňový pokladní
  doklad v zamčeném období volný; přidání DPH řádku do bezdaňového dokladu
  blokované; ruční přesun `cs_period` do zamčené instance blokovaný; nový doklad
  s DUZP v zamčeném rozsahu blokovaný (a **nezaloží** koncept instance).
- `ReportPeriodDocumentTest` — rozšířit: zamčená instance odmítne změnu rozsahu
  a stavu, povolí `locked` 1→0; `locked_at/by`.
- `VatPeriodRecalculatorTest` — přesun přes zamčenou instanci → výjimka.
- `FiscalMonthLockProviderTest` — orig/new měsíc, koncept blokován, bez
  `accounting_date` volný; `FiscalMonthDocumentTest` — zámek, warning ze zůstatků.
- `ClosedPeriodBalanceCheckTest` — integrační nad dev DS: podaná instance bez
  účetního dokladu → nálezy per 343 analytika; po ručním `cmnbkp`, který analytiky
  vynuluje, prázdné.
- `FiledUnlockedPeriodsCheckTest`.
- Integrační (dev DS 4l3j): zamkni instanci → uložení dokladu z ní přes
  `saveDocument` vrátí `locked`; `reaccount` odmítne; `--force` projde.

### 7. Dokumentace

- `docs/document-system.md` — sekce „Zámek dokladu (lock providery)": rozhraní,
  registrace, pořadí ve `saveDocument`, import mód, force, UI kontrakt `lock`.
- `modules/economy/vat/docs/README.md` — zámek instance (D23/D25/D26), kontrola
  zůstatků (D31), alerty; `tables/economy_vat_report_periods.md`.
- `modules/economy/codebooks/README.md` — zámek fiskálního měsíce, co se
  nevynucuje (rok).
- `docs/accounting.md` — přegenerování deníku vs. zámek, `--force`.
- Help texty akcí (cs).

## Mimo scope

- Zaúčtování přiznání, `acc_document` (F4b — `tasks/vat-filing-accounting.md`).
- Vynucení `fiscal_years.locked`, uzávěrka roku, uzávěrkové doklady.
- Viewer „Uzávěrky" (rok × měsíc, čipy), průvodce odemknutí z banneru dokladu —
  navazující issue; F4a jen zajistí tvar `locked/locked_at/locked_by` a
  `DocumentLockReason.unlockAction`.
- Oprávnění k zámku (role) — dnes žádný systém rolí per akce; zaloguje se `locked_by`.
- Zámek jiných tabulek než `docs_core_heads` (registry to umí, nikdo ho nevolá).
- Import zámku ze starého systému — stará strana (`old_shipard` task 36).

## Commity

1. `docs.core`: `DocumentLockProvider` + `DocumentLockReason` + `DocumentLockRegistry`
   + `module.jsonc` sekce + `TableGateway`/`Document` napojení + `DocDocument`
   vynucení (validate/beforeDelete/filterStateTransitions, import mód, force)
   + `willHaveVatRecap` + testy.
2. `economy.vat`: sloupce `locked_at/by` + `VatPeriodLockProvider` + registrace +
   `ReportPeriodDocument` (zámek rozsahu/stavu, přepínač) + `VatPeriodRecalculator`
   guard + testy.
3. `economy.codebooks`: sloupce `locked*` na měsících + `FiscalMonthLookup` +
   `FiscalMonthLockProvider` + `FiscalMonthDocument` + testy.
4. `ClosedPeriodBalanceCheck` (třída + alert) + `FiledUnlockedPeriodsCheck` +
   varování při zamykání měsíce + testy.
5. UI: banner zámku (form + detail), akce Uzamknout/Odemknout (instance, podání,
   měsíc), endpointy, `api/*.js`, `Viewer.svelte`; `reaccount` guard + CLI `--force`.
6. Dokumentace + help.

## Hotovo když

- [ ] Testy zelené (registry, DocDocument lock, oba providery, recalculator,
      kontroly).
- [ ] `ds-upgrade` na dev DS projde (sloupce na instancích a měsících).
- [ ] Dev DS 4l3j: zamčená instance `return` → doklad s DPH z ní nejde uložit,
      opravit (40→80), stornovat ani smazat; formulář ukáže banner; přechody
      v detailu chybí. Bezdaňový pokladní doklad ve stejném období jde uložit.
      Nový doklad s DUZP v zamčeném rozsahu se neuloží a **nevznikne** koncept
      instance.
- [ ] Zamčený fiskální měsíc blokuje libovolný doklad (i koncept, i bezdaňový)
      s `accounting_date` v měsíci; při zamykání měsíce s nenulovými 343 přijde warning.
- [ ] Alerty: podaná instance bez zámku → `filed_unlocked_periods`; podaná instance
      bez zaúčtování → `closed_period_balance` per 343 analytika (F4b ho zhasne).
- [ ] `reaccount` zamčeného dokladu odmítne; CLI s `--force` projde a zaloguje.
- [ ] Po reimportu zdroje 689089 s `old_shipard` taskem 36: instance kryté starým
      stavem 9000 mají `locked = 1`, import dokladů do nich prošel.
- [ ] Dokumentace dle §7.

## Odchylky od zadání

(doplní implementace)
