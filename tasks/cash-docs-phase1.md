# Task B: Pokladní doklady (`cash`) a prodejky (`cashreg`) — moduly, pohyby, účtování, import

**Stav:** hotovo — 2026-09-06, 7 commitů na `stable` (kód, testy unit +
integrační, dokumentace, help); zbývá ruční proklik UI na dev DS (nutno
založit pokladnu) a ověření na alfě po re-importu (Task C). Odchylky od
zadání: `payment.*` mají `rowSide: 0` (jinak by položkový layout vyžadoval
DPH kód a applier by přepočítal `total_price`); sdílená logika obou typů je
v `docs.core` (`CashDeskDocumentBase` / `CashDeskFormBase`), moduly na sobě
nezávisejí; partner řádku úhrady se importuje přes existující pin
`_resolve.rows[i].partner`, žádné `rows[].party`; `ExchangeFormat.php`
neexistuje — validace je v `DocumentValidator`. Navíc opravená regrese
Task A (`DocsHeadsViewer::attachPartnerPersonId` volal smazanou metodu)
a tichý výběr první řady u vázaného typu ve formuláři.
**Issue:** #59 — rozhodnutí D1, D5–D9, D11, D12
**Návaznost:** vyžaduje hotový `tasks/docs-core-bound-series.md` (Task A: vázané řady,
`cash_desk` / `cash_dir` na hlavičce, `resolveTradeDir`, extension `accounting_account`
na pokladně). Exportní strana (`old_shipard`, Task C) se nasazuje **až po** tomto tasku.
Pokladní kniha a otevírací doklady jsou **fáze 2** (D10), samostatný task.

## Cíl

Dva nové typy dokladů nad `docs_core_heads`, každý ve vlastním modulu podle vzoru
`docs.invoicesOut`:

| typ | modul | docIdCode | směr | řada |
|---|---|---|---|---|
| `cash` — Pokladní doklad | `docs.cashDocs` | `31` | per doklad (`cash_dir` 1 příjem / 2 výdej) | vázaná na pokladnu |
| `cashreg` — Prodejka | `docs.cashRegister` | `14` | pevně výstup (`trade_dir: 1`) | vázaná na pokladnu |

Oba typy jdou přes existující DPH výstupy bez úprav (D11): směr určuje `world.vat`
kód, zařazení do tvrzení dělá `VatPeriodAssigner`. Účtování přes `AccountingEngine`
s protiúčtem pokladny (211 analytika z `cash_desk.accounting_account`) nebo peněz
na cestě (karta). Úhrady faktur hotově/kartou jsou saldokontní řádky (`payment.*`),
které páruje accbal.

Před implementací **přečti**:

- issue #59, `tasks/docs-core-bound-series.md` (Task A — co už existuje)
- `modules/docs/invoicesOut/{module.jsonc,README.md,src/*}`,
  `modules/docs/invoicesIn/src/*`, `modules/docs/accountingDocs/src/*` — vzor
  per-typ modulu (Document / Form / Viewer, `scopedDocType`)
- `modules/docs/core/src/DocsHeadsFormBase.php`, `DocRowsForm.php` (filtr pohybů
  a DPH kódů), `DocsHeadsViewer.php`, `DocDocument.php` (`validate`, `beforeSave`,
  snapshoty, `buildRowLines` konzumenti)
- `modules/docs/core/config/rowOperations.jsonc` — vlajky `rowPartner`,
  `rowPaymentId`, `rowSide`, `rowAccount`
- `docs/accounting.md` §2, §4, §5, §7; `modules/economy/accounting/config/accountingRules.cz.jsonc`;
  `modules/economy/accounting/src/AccountingEngine.php` (`buildStepLines`,
  `buildHeadLines`, `resolveCategoryAccount`, `matchesQuery`, `resolveRowIdentity`)
- `docs/accbal.md` — jak matcher čte deník (identita řádku, kategorie
  receivables/payables) — `payment.*` řádky musí vypadat jako bankovní úhrady
- `docs/exchange-format.md` §5 (polymorfismus podle `docType`), `modules/core/exchange/src/Document/DocumentApplier.php`,
  `ExchangeFormat.php` — import mód, `_importNumber`, `_importPartnerSnapshot`
- `modules/economy/vat/docs/README.md` (nic se nemění, ale testy VAT je třeba rozšířit)
- `docs/edit-forms.md`, `docs/edit-forms-cookbook.md`, `docs/frontend.md` §7

## Scope

### 1. Typy dokladů — `docs.core.docTypes`

`modules/docs/core/config/docTypes.jsonc`:

```jsonc
"cash": {
    "name": "Cash document", "name:cs": "Pokladní doklad", "name:en": "Cash document",
    "shortcut": "PD", "shortcut:cs": "PD", "shortcut:en": "CD",
    "doc_id_code": "31",
    "trade_dir": 0,
    "trade_dir_column": "cash_dir",
    "series_binding": "cash_desk",
    "doc_number_pattern_default": "%D%C%y%5",
    "subclass": "Shipard\\Module\\Docs\\CashDocs\\CashDocument"
},
"cashreg": {
    "name": "Retail sale", "name:cs": "Prodejka", "name:en": "Retail sale",
    "shortcut": "PRO", "shortcut:cs": "PRO", "shortcut:en": "RS",
    "doc_id_code": "14",
    "trade_dir": 1,
    "series_binding": "cash_desk",
    "doc_number_pattern_default": "%D%C%y%5",
    "subclass": "Shipard\\Module\\Docs\\CashRegister\\CashRegisterDocument"
}
```

Číslo dokladu pak vypadá např. `31HP12600001` (typ 31, pokladna `HP1`, rok 26,
pořadí) — odpovídá starému `%D%B%y%5`.

### 2. Pohyby řádků — `docs.core.rowOperations`

Nový atribut v `docTypes` položce pohybu: `cashDir` (1 / 2) — pohyb je pro typ
`cash` povolený jen při daném `cash_dir` hlavičky. Bez `cashDir` = povolený pro oba
směry.

| pohyb | `cash` | `cashreg` | poznámka |
|---|---|---|---|
| `sale.services` | `{"order": 100, "cashDir": 1}` | `{"order": 100}` | |
| `sale.goods` | `{"order": 200, "cashDir": 1}` | `{"order": 200}` | |
| `payment.receivable` *(nový)* | `{"order": 300, "cashDir": 1}` | — | Úhrada pohledávky; `rowPartner: 1`, `rowPaymentId: 1`, bez `rowAccount`, bez `rowSide` (strana z kroku). Řádek bez DPH. |
| `purchase.goods` / `purchase.services` / `purchase.other` | `{"order": 100/200/300, "cashDir": 2}` | — | |
| `payment.payable` *(nový)* | `{"order": 400, "cashDir": 2}` | — | Úhrada závazku; vlajky jako výše. |
| `acc.entry` | `{"order": 900}` | `{"order": 900}` | oba směry |

Komentář v souboru: `payment.*` = protějšek bankovních spárovaných úhrad
(`bank.matched.*`); `payment_reference` řádku nese VS / číslo hrazené faktury,
partner řádku = dlužník/věřitel. Zálohy (`*.advance*`) na pokladní doklady zatím
**nedáváme** (saldo záloh je accbal fáze 4+).

**`DocRowsForm`:** nabídka pohybů filtrovaná i podle `cashDir` vs. `cash_dir`
hlavičky; default pohybu = nejnižší `order` pro daný směr. Pro `payment.*` řádek:
kontační-like layout **bez** DPH bloku (částka, partner, `payment_reference`,
volitelně `specific_symbol`, `due_date` skrytý) — zvol nejbližší existující layout
(vlajky `rowPartner` + `rowPaymentId` bez `rowSide` už kombinaci ovládají u záloh;
ověř, že DPH kód se pro `payment.*` nenabízí a řádek se uloží s nulovou DPH).

**`DocDocument::validate`:** pohyb musí být povolený pro (`doc_type`, `cash_dir`) —
rozšířit stávající tvrdou validaci. Řádky `payment.*` musí mít `partner` řádku
a `payment_reference` (tvrdá chyba `rows.{i}.payment_reference`).

### 3. Modul `docs.cashDocs` (typ `cash`)

`modules/docs/cashDocs/` — `module.jsonc` (dependencies `docs.core`,
`economy.accounting`), `README.md`, `src/`:

- **`CashDocument extends DocsHeadsDocument`**
  - `validate`: `cash_dir` ∈ {1, 2} (Task A hlídá tvar, tady srozumitelná chyba na
    poli); `payment_method` ∈ {0 Hotovost, 2 Kartou} — jiné metody na pokladním
    dokladu nedávají smysl; `doc_currency` = měna pokladny (`cash_desk.currency`),
    chyba `currency_mismatch` na `doc_currency`; `partner` **nepovinný** (anonymní
    příjem/výdej), `payment.*` řádky partnera nesou samy.
  - `beforeSave`: defaulty `accounting_date = vat_duzp = issue_date` (Task A /
    `applyDateDefaults` už dělá část — jen doplnit chybějící), `due_date = issue_date`,
    `payment_method` default 0, `doc_currency` z pokladny; snapshoty: partner jen
    když je vyplněný (`resolveTradeDir` řekne, jestli je customer nebo supplier),
    own snapshot vždy.
  - Přechod do 40 bez řádků → chyba (jako u faktur).
- **`CashDocForm extends DocsHeadsFormBase`**: titulky „Pokladní doklad" / „Nový
  pokladní doklad", ikona `cash` (ověř existenci v ikon setu, jinak nejbližší);
  hlavička: `number_series` (hidden, předvyplněná ze záložky), `cash_dir` (select,
  required, `triggers: 'reload'`, po existenci řádků `readOnly` — změna směru by
  zneplatnila pohyby), `payment_method` (select omezený na 0/2), `partner`
  (lookup, nepovinný), `issue_date`, `accounting_date`, `vat_duzp`, `vat_mode`,
  `vat_registration`, `doc_text`, `doc_notice`; `doc_currency` readOnly (z pokladny).
  Header info: label „Příjmový pokladní doklad" / „Výdajový pokladní doklad" dle
  `cash_dir`; partner snapshot key dle `resolveTradeDir`.
  Sekce „Pokladna" (readOnly): kód + název pokladny, měna — ať uživatel vidí, kam
  doklad patří, i když ho nezadává.
- **`CashDocsViewer extends DocsHeadsViewer`**: `scopedDocType = 'cash'`,
  `navSection: "accounting"` (za Účetními doklady, `navOrder` zvol), ikona.
  `renderRow`: t1 = partner nebo `doc_text`, t2 = „Příjem"/„Výdej" (barva: příjem
  neutrální, výdej muted) + datum + způsob úhrady, i1 = číslo, částka vpravo
  (záporná u výdeje? **ne** — částka kladná, směr nese badge; záporné částky jsou
  vratky D9).
- Registrace `documentClasses` / `forms` / `viewers` v `module.jsonc` jako
  u `docs.invoicesOut`.

### 4. Modul `docs.cashRegister` (typ `cashreg`)

`modules/docs/cashRegister/` — stejná kostra:

- **`CashRegisterDocument`**: `cash_dir` musí být 0 (typ má pevný `trade_dir`);
  `payment_method` ∈ {0, 2}; `doc_currency` = měna pokladny; partner nepovinný;
  vratka = záporné řádky (D9) — validace nesmí záporný řádek odmítnout (ověř
  `DocDocument` / `DocRowsDocument`, faktury záporné řádky u dobropisů už umí).
- **`CashRegisterForm`**: „Prodejka" / „Nová prodejka"; hlavička minimalistická:
  `payment_method`, `partner` (nepovinný), `issue_date`, `vat_mode`, `doc_text`.
- **`CashRegisterViewer`**: `scopedDocType = 'cashreg'`, `navSection: "sales"`
  (za Fakturami vydanými).

### 5. Účtování (D8)

`modules/economy/accounting/config/accountingRules.cz.jsonc`:

**Kategorie:** `cash` (Pokladna — účet z pokladny, bez masky; komentář), `card.transit`
(Karta — peníze na cestě, maska `261100`; ověř, že seed rozvrhy `261100` mají a že
nekoliduje s bankou `261200/261300` — pokud kolize, zvolit `261400` a doplnit do
obou seed variant + `VatAnalyticsCompletenessTest`-like test pro 261).

**Engine — dvě malá rozšíření `AccountingEngine`:**

1. `accountSrc: "cashDesk"` pro `src: head`: účet = `economy_codebooks_cash_desks
   .accounting_account` pokladny z `head.cash_desk`; chybí pokladna nebo účet →
   chybový řádek `211???`, kód `cash_desk_account_missing` (vzor
   `item_account_missing`). Vzor lookupu účtu podle id: `resolveRowAccount`
   (LINKABLE_STATES).
2. `headQuery` na kroku: filtr `{sloupec: hodnota}` nad **hlavičkou** pro kroky
   s `src: rows` / `src: vat` (dnešní `query` se u nich vyhodnocuje nad řádkem /
   rekapitulací). Bez toho nejde v jednom bloku `documents[cash]` rozlišit strany
   podle `cash_dir`.

**Předpis `cash`:**

```jsonc
{"docType": "cash", "accounting": [
    // ── příjem (cash_dir 1): jako vydaná faktura, protistrana pokladna/karta
    {"headQuery": {"cash_dir": 1}, "cat": "revenue", "src": "rows", "side": 1,
        "operations": ["sale.services", "sale.goods"]},
    {"headQuery": {"cash_dir": 1}, "cat": "receivables", "src": "rows", "side": 1,
        "operation": "payment.receivable", "text": "Úhrada pohledávky"},
    {"headQuery": {"cash_dir": 1}, "accountSrc": "item", "src": "rows", "side": 1,
        "operation": "acc.entry"},
    {"headQuery": {"cash_dir": 1}, "cat": "vat", "src": "vat", "side": 1},
    {"headQuery": {"cash_dir": 1}, "cat": "rounding.revenue", "src": "head", "col": "rounding",
        "side": 1, "sign": "+", "text": "Zaokrouhlení dokladu"},
    {"headQuery": {"cash_dir": 1}, "cat": "rounding.cost", "src": "head", "col": "rounding",
        "side": 0, "reverseSign": 1, "sign": "-", "text": "Zaokrouhlení dokladu"},
    {"accountSrc": "cashDesk", "src": "head", "col": "total", "side": 0,
        "query": {"cash_dir": 1, "payment_method": 0}},
    {"cat": "card.transit", "src": "head", "col": "total", "side": 0,
        "query": {"cash_dir": 1, "payment_method": 2}},

    // ── výdej (cash_dir 2): jako přijatá faktura
    {"headQuery": {"cash_dir": 2}, "cat": "costs", "src": "rows", "side": 0,
        "operations": ["purchase.goods", "purchase.services", "purchase.other"]},
    {"headQuery": {"cash_dir": 2}, "cat": "payables", "src": "rows", "side": 0,
        "operation": "payment.payable", "text": "Úhrada závazku"},
    {"headQuery": {"cash_dir": 2}, "accountSrc": "item", "src": "rows", "side": 0,
        "operation": "acc.entry"},
    {"headQuery": {"cash_dir": 2}, "cat": "vat", "src": "vat", "side": 0},
    {"headQuery": {"cash_dir": 2}, "cat": "rounding.cost", "src": "head", "col": "rounding",
        "side": 0, "sign": "+", "text": "Zaokrouhlení dokladu"},
    {"headQuery": {"cash_dir": 2}, "cat": "rounding.revenue", "src": "head", "col": "rounding",
        "side": 1, "reverseSign": 1, "sign": "-", "text": "Zaokrouhlení dokladu"},
    {"accountSrc": "cashDesk", "src": "head", "col": "total", "side": 1,
        "query": {"cash_dir": 2, "payment_method": 0}},
    {"cat": "card.transit", "src": "head", "col": "total", "side": 1,
        "query": {"cash_dir": 2, "payment_method": 2}}
]}
```

(`query` u `src: head` se už dnes vyhodnocuje nad hlavičkou — tam `headQuery` není
potřeba; u head kroků použij `query`.)

**Předpis `cashreg`:** kopie příjmové části bez `headQuery` a bez `payment.*`.

**Faktury placené hotově (D8):** u `invno` krok `receivables` a u `invni` krok
`payables` dostanou `"query": {"payment_method": {"$ne": 0}}` — pokud `matchesQuery`
nerovnost neumí, přidej minimální podporu (`{"$ne": v}`) nebo rozděl na explicitní
výčet hodnot; a přibude krok
`{"accountSrc": "cashDesk", "src": "head", "col": "total", "side": 0/1,
"query": {"payment_method": 0}}`. Faktura s Hotovostí bez `cash_desk` → chybový
řádek `211???` + alert (měkká chyba, nestandardní stav, uživatel doplní pokladnu
a přeúčtuje).

**Identita `payment.*` řádků pro accbal:** `resolveRowIdentity` musí pro
`rowPartner`/`rowPaymentId` operace razítkovat partnera a `payment_reference`
z řádku (už funguje u záloh a zápočtů) — deník pak vypadá jako `bank.matched.*`
úhrada a matcher ho spáruje. Ověřit integračním testem přes `LedgerGenerator` /
matcher z accbal (viz `tests/Integration/Accbal`).

Kontrolní příklady do `docs/accounting.md` §4:

```
Příjmový PD, hotově, prodej služby 1 000 + 21 % (cz-120):
602xxx DAL 1 000   343120 DAL 210   211HP1 MD 1 210
Příjmový PD, kartou, úhrada FVB 1 210 (payment.receivable, VS = číslo FVB):
311xxx DAL 1 210 (partner, payment_reference)   261100 MD 1 210
Výdajový PD, hotově, nákup materiálu 500 + 21 %:
501/504 MD 500   343120 MD 105   211HP1 DAL 605
```

### 6. Import (D12) — strana nového Shipardu

`docs/exchange-format.md` + `ExchangeFormat` + `DocumentApplier`:

- Nové `docType` kanonického dokumentu: `cashDocument`, `cashRegisterDocument`.
  Nová pole: `cashDesk` (kód pokladny, string), `cashDirection` (1/2, jen
  `cashDocument`), `paymentMethod` už existuje. `supplier`/`customer` volitelné
  (anonymní doklady); partner na řádku pro úhrady: `rows[].party` +
  `rows[].paymentReference` (ověř, co exchange format pro saldokontní řádky už má
  z vlny D — zálohy, kurzové rozdíly — a použij totéž).
- Applier: řadu dohledá podle (`doc_type` z mapy docType → `cash`/`cashreg`,
  `cash_desk` = pokladna dle `code`), ne podle typu jako u faktur; chybějící
  pokladna/řada → validační chyba importu (kód `cash_desk_not_found`), ne tichý
  fallback. `cash_dir` z `cashDirection`.
- Faktury: `cashDesk` kód na `invoiceIssued`/`invoiceReceived` → `cash_desk`
  hlavičky (jen při `paymentMethod = cash`).
- Mapa pohybů importu (`docs/exchange-format.md`, klíč = docType): default pro
  `cashDocument` dle směru (`sale.services` / `purchase.other`), pro úhrady
  `payment.receivable` / `payment.payable`; `cashRegisterDocument` → `sale.goods`.
- Číslo se zachová přes existující `_importNumber` + counter-sync (Task A nic
  nemění). Snapshot partnera v import módu (`_importPartnerSnapshot`) i pro
  pokladní doklady, když partner je.

### 7. Dokumentace

- `modules/docs/cashDocs/README.md`, `modules/docs/cashRegister/README.md` (vzor
  invoicesOut README: účel, co modul přidává, co ne).
- `docs/accounting.md`: §2 tabulka pohybů (+ `payment.*`, `cashDir`), §4 předpis
  `cash`/`cashreg` + `headQuery` + `accountSrc: cashDesk` + kontrolní příklady, §5
  dohledání účtu pokladny, §7.3 chybový kód `cash_desk_account_missing`, §10 mimo
  scope (vyškrtnout cash/cashreg, doplnit pokladní knihu jako fázi 2 s odkazem
  na #59 D10).
- `docs/docs-mvp.md`: seznam typů dokladů.
- `docs/exchange-format.md`: nové docTypes a pole.
- `docs/roadmap.md` / `docs/features.md`, pokud typy dokladů vyjmenovávají.

### 8. Testy

- Unit: `CashDocument` / `CashRegisterDocument` validace (směr, payment_method,
  měna vs. pokladna, `payment.*` bez `payment_reference`); filtr pohybů dle
  `cashDir`.
- Integration `tests/Integration/Accounting/`: 5 kontrolních příkladů výše
  (příjem hotově s DPH, příjem kartou úhrada FVB, výdej hotově, prodejka hotově,
  prodejka s vratkou — záporný řádek), faktura vydaná s Hotovostí → 211 místo 311,
  faktura s Hotovostí bez pokladny → chybový řádek; `headQuery`; pokladna bez
  `accounting_account` → `cash_desk_account_missing`.
- Integration accbal: `payment.receivable` z pokladního dokladu spáruje otevřenou
  FVB (matcher), zůstatek 311 partnera 0.
- Integration VAT (`tests/Integration/Reports`): příjmový PD s cz-120 přistane
  v DP3 na řádku výstupu, výdajový PD s cz-120 na vstupu; KH: prodejka > 10 000
  s DIČ v A4, bez DIČ v A5.
- Integration Exchange: apply `cashDocument` (obě strany, s číslem z importu) →
  správná řada, `cash_desk`, `cash_dir`; chybějící pokladna → chyba.
- Úzké filtry (`--filter 'CashDoc|CashRegister|CashDesk|HeadQuery'`),
  `timeout_sec: 120`.

## Commit strategie

1. `docTypes` `cash`/`cashreg` + `rowOperations` (`payment.*`, `cashDir`) +
   `DocRowsForm`/`DocDocument` filtr a validace + testy. Po tomto commitu
   `ds-upgrade` na dev DS vygeneruje řady pro existující pokladny (Task A).
2. Modul `docs.cashDocs` (Document, Form, Viewer, README) — doklad jde založit
   a potvrdit v UI.
3. Modul `docs.cashRegister`.
4. Engine: `accountSrc: cashDesk`, `headQuery`, `$ne` (nebo výčet) + kategorie
   `card.transit` + předpis `cash`/`cashreg` + úprava `invno`/`invni` + integrační
   testy účtování a accbal.
5. VAT integrační testy (ověření D11, žádná změna kódu economy.vat se nečeká).
6. Exchange: nové docTypes, applier, testy.
7. Dokumentace.

Před každým commitem `git diff`, úzké PHPUnit filtry, po `.jsonc` změnách rebuild cfg
+ `ds-upgrade`; frontend `npm run check:i18n`. Push dělá David.

## Mimo scope

- Pokladní kniha, otevírací doklady, importní kontrola `initBalance` (D10, fáze 2).
- Zálohy na pokladních dokladech, platební terminály (per-terminál 261 analytika),
  tisk PD/prodejky (render šablony — samostatný task, až bude struktura ověřená).
- Sklad (`stockin/stockout`), `purchase`.
- Export ve starém Shipardu (Task C).

## Hotovo když

- [ ] Na dev DS existují viewery Pokladní doklady (účetnictví) a Prodejky (prodej)
      se záložkami per pokladna; „Přidat" založí doklad se správnou řadou
      a pokladnou bez zadávání pokladny.
- [ ] Příjmový i výdajový PD lze potvrdit (40); číslo má tvar `31{kód}{yy}{00001}`,
      prodejka `14{kód}{yy}{00001}`.
- [ ] Pohyby v řádku odpovídají směru; DPH kódy jsou u příjmu výstupní, u výdeje
      vstupní (`resolveTradeDir`).
- [ ] Zaúčtování odpovídá kontrolním příkladům; karta jde na 261, hotovost na účet
      pokladny; FVB/FPB s Hotovostí účtuje na pokladnu místo 311/321.
- [ ] `payment.receivable` z PD spáruje FVB v accbal (integrační test).
- [ ] Příjmový PD s DPH se objeví v živém DP3/KH správného období.
- [ ] Import `cashDocument` / `cashRegisterDocument` přes exchange applier funguje
      včetně zachování čísla; chybějící pokladna hlásí chybu.
- [ ] Všechny stávající testy procházejí; dokumentace aktualizována; README obou
      modulů existují.
- [ ] Ověření na alfě po re-importu (Task C) — DP3 kontrolního období qrce sedí na
      starý Shipard (zaznamenat do issue #59).
