# Shipard — Účtování dokladů

Automatické generování záznamů účetního deníku z dokladů. Uživatel nikde nezadává
čísla účtů — zaúčtování se odvozuje z obsahu dokladu (pohyby řádků, DPH
rekapitulace, hlavička) podle deklarativního účtovacího předpisu.

Inspirováno `AccountingDocEngine` ze starého Shipardu; základní princip
(pohyb → předpis → kategorie → maska účtu → účtový rozvrh) zůstává, výrazně
se ale zjednodušuje a modernizuje. Co se ze starého systému **nepřebírá**, je
shrnuto v sekci [Mimo scope](#10-mimo-scope).

---

## 1. Přehled

```
┌──────────────────────────────────────────────────────────────────┐
│  Doklad (docs_core_heads + rows + vat_recap)                     │
│  - řádek má pohyb (operation) — co řádek "dělá"                  │
│  - přechod do docState 40 (V pořádku)                            │
├──────────────────────────────────────────────────────────────────┤
│  AccountingEngine (economy.accounting)                           │
│  - načte účtovací předpis pro zemi (rules.cz)                    │
│  - projde kroky předpisu pro docType                             │
│  - dohledá účty v účtovém rozvrhu                                │
│  - vygeneruje a seskupí řádky deníku, zkontroluje MD = DAL       │
├──────────────────────────────────────────────────────────────────┤
│  Účetní deník (economy_accounting_journal)                       │
│  - jednostranné řádky: účet + MD nebo DAL částka                 │
│  - částky vždy v domácí měně I měně dokladu                      │
└──────────────────────────────────────────────────────────────────┘
```

Klíčové vlastnosti:

- **Deklarativní předpis** — postup účtování je v JSONC konfiguraci per země,
  ne v kódu. Engine je obecný interpret předpisu.
- **Tolerance chyb** — nedohledaný účet nebo nevyrovnané strany **neblokují**
  přechod dokladu do stavu 40. Doklad dostane příznak chyby účtování
  (`accounting_state = 2`) a systém alertů uživatele upomíná, dokud to nedá
  do pořádku.
- **Deník je derivát** — řádky deníku se kdykoliv dají smazat a vygenerovat
  znovu z dokladu. Žádná ruční editace deníku, žádné docStates na deníku.
- **Invariant**: doklad má řádky v deníku právě tehdy, když je ve stavu 40.

---

## 2. Pohyby řádků (rowOperations)

Pohyb říká, co řádek dokladu *znamená* (prodej služeb, nákup zboží, …).
Je to **business** sémantika — na rozdíl od `row_kind`, který zůstává čistě
strukturální (0 = textový řádek, 1 = běžný řádek).

Pohyby jsou koncept dokladového systému (časem na ně naváže i sklad), proto
žijí v `docs.core`, účetnictví se na ně jen odkazuje.

### Konfigurace

`modules/docs/core/config/rowOperations.jsonc` → cfgItem `docs.core.rowOperations`

```jsonc
{
    // docs.core.rowOperations
    //
    // Pohyby řádků dokladů. Klíč = stabilní stringový identifikátor.
    // docTypes: pro které typy dokladů je pohyb povolený; order určuje
    // pořadí v nabídce UI (vzestupně, první = default pro nový řádek).

    "sale.services": {
        "name": "Sale of services",
        "name:cs": "Prodej služeb",
        "name:en": "Sale of services",
        "docTypes": {
            "invno": {"order": 100}
        }
    },
    "sale.goods": {
        "name": "Sale of goods",
        "name:cs": "Prodej zboží",
        "name:en": "Sale of goods",
        "docTypes": {
            "invno": {"order": 200}
        }
    },
    "purchase.goods": {
        "name": "Purchase of goods",
        "name:cs": "Nákup zboží a materiálu",
        "name:en": "Purchase of goods",
        "docTypes": {
            "invni": {"order": 100}
        }
    },
    "purchase.services": {
        "name": "Purchase of services",
        "name:cs": "Nákup služeb",
        "name:en": "Purchase of services",
        "docTypes": {
            "invni": {"order": 200}
        }
    },
    "purchase.other": {
        "name": "Other purchase",
        "name:cs": "Ostatní nákup",
        "name:en": "Other purchase",
        "docTypes": {
            "invni": {"order": 300}
        }
    },
    "acc.entry": {
        "name": "Accounting entry",
        "name:cs": "Účetní položka",
        "name:en": "Accounting entry",
        "docTypes": {
            "invno": {"order": 900},
            "invni": {"order": 900}
        }
    }
}
```

Oproti starému Shipardu (`e10.docs.operations.json`):

- čitelné stringové klíče místo magických čísel (`sale.services` místo `1010001`)
- žádné `docDir`, `itemType`, `paymentSymbols`, `paymentBalance`, `costOff`,
  `currencyMode` — atributy navázané na saldo, sklad a směr dokladu přijdou
  až s příslušnými úkoly

### Sloupec `operation` v `docs_core_rows`

```jsonc
{
    "id": "operation",
    "name": "Operation",
    "name:cs": "Pohyb",
    "name:en": "Operation",
    "type": "enumString",
    "length": 40,
    "cfgItem": "docs.core.rowOperations",
    "nullable": true
}
```

Pravidla (validace v `DocDocument::validate`, tvrdá — blokuje uložení):

- `row_kind = 1` (běžný řádek): `operation` je **povinný** a musí být povolený
  pro `doc_type` hlavičky
- `row_kind = 0` (textový řádek): `operation` je prázdný

Default pro nový řádek: pohyb s nejnižším `order` pro daný `doc_type`
(doplňuje formulář / `DocRowsForm`).

### Pohyb `acc.entry` — účetní položka

Řádek s pohybem `acc.entry` se účtuje **přímo na účet uvedený na položce**
(`economy_items.accounting_account`, viz sekce 3). Slouží pro řádky typu
bankovní poplatek, úrok, pojistné apod., kde účet určuje předem připravená
položka, ne předpis.

- Tvrdá validace (při uložení dokladu): řádek `acc.entry` musí mít vyplněný
  `item`.
- Měkká kontrola (při účtování): položka musí být `item_type = 2` (Účetní
  položka) a mít vyplněný `accounting_account`. Pokud ne → chybový řádek
  deníku (viz sekce 7.4). Měkce proto, že konfigurace položky se může změnit
  nezávisle na dokladu.

---

## 3. Změny ve stávajících tabulkách

### 3.1 `docs_core_rows` — domácí měna

Nové systémové sloupce (plní přepočet v `beforeSave`, viz sekce 8):

| sloupec | typ | popis |
|---|---|---|
| `vat_base_dom` | numeric 15,2 | Základ DPH v domácí měně |
| `vat_amount_dom` | numeric 15,2 | DPH v domácí měně |
| `vat_total_dom` | numeric 15,2 | Celkem s DPH v domácí měně |

### 3.2 `docs_core_heads` — zaokrouhlení v domácí měně

Nový systémový sloupec `total_rounding_dom` (numeric 15,2) — doplňuje řadu
`total_*_dom` (důsledná politika "každá částka v obou měnách").

### 3.3 Extension `economy.accounting` → `docs_core_heads`

Stav účtování vlastní účetnictví, ne dokladový systém — proto extension
(`modules/economy/accounting/extensions/ext-docs-core-heads.jsonc`),
stejný princip jako `payment_term_days` z `docs.core` na osobách.

| sloupec | typ | popis |
|---|---|---|
| `accounting_state` | enumInt, default 0, system, cfgItem `economy.accounting.accountingStates` | 0 = neúčtováno, 1 = zaúčtováno, 2 = chyba účtování |
| `accounting_messages` | json, nullable, system | seznam chyb z enginu: `[{"code": "...", "message": "...", "rowId": 123}]` |

Config `modules/economy/accounting/config/accountingStates.jsonc`:

```jsonc
{
    // economy.accounting.accountingStates
    "0": {"name": "Not accounted", "name:cs": "Neúčtováno",      "name:en": "Not accounted"},
    "1": {"name": "Accounted",     "name:cs": "Zaúčtováno",      "name:en": "Accounted"},
    "2": {"name": "Error",         "name:cs": "Chyba účtování",  "name:en": "Accounting error"}
}
```

### 3.4 Extension `economy.accounting` → `economy_items`

`modules/economy/accounting/extensions/ext-economy-items.jsonc`:

```jsonc
{
    "table": "economy_items",
    "columns": [
        {
            "id": "accounting_account",
            "name": "Accounting account",
            "name:cs": "Účet",
            "name:en": "Accounting account",
            "type": "int",
            "nullable": true,
            "reference": "economy_accounting_accounts",
            "group": "classification"
        }
    ]
}
```

Závislost směřuje správně: `economy.accounting` zná items i doklady, ale ani
`economy.items`, ani `docs.core` nezávisí na účetnictví.

Formulář položky: pole viditelné pro `item_type = 2`; picker omezený na
analytické účty (`account_level = 4`) a aktivní záznamy.

---

## 4. Účtovací předpis

Per-country JSONC konfigurace v modulu `economy.accounting`:

`modules/economy/accounting/config/accountingRules.cz.jsonc`
→ cfgItem `economy.accounting.rules.cz`

Výběr předpisu: podle země vlastní firmy (`OwnCompanyResolver`), fallback `cz`.

### Struktura

Tři sekce (zachováno ze starého `acc-default.json`, ale kategorie jsou
sémantické stringy místo čísel účtů):

```
documents   per docType seznam kroků — odkud vzít částku, na kterou stranu,
            jaká kategorie
accounts    kategorie → maska účtu (případně s podmínkou query)
categories  jen názvy kategorií pro dokumentaci/UI
```

### Krok předpisu (step)

| pole | význam |
|---|---|
| `cat` | kategorie → dohledání masky v `accounts`. Nepovinné, pokud je `accountSrc` |
| `accountSrc` | alternativní zdroj účtu mimo kategorie. Zatím jediná hodnota: `"item"` = účet z položky řádku (`acc.entry`) |
| `src` | `"rows"` (řádky dokladu) \| `"vat"` (DPH rekapitulace) \| `"head"` (hlavička) |
| `col` | pro `head`: `"total"` (default) \| `"rounding"`. Pro `rows`/`vat` se nepoužívá (MVP) |
| `operation` / `operations` | filtr pohybu řádku (jen `src: rows`) |
| `side` | 0 = MD (Má dáti), 1 = DAL |
| `sign` | `"+"` / `"-"` — krok platí jen pro kladnou / zápornou částku |
| `reverseSign` | 1 = otočit znaménko částky (typicky se `sign: "-"`) |
| `query` | obecný filtr `{sloupec: hodnota}` nad zdrojovým záznamem (head/row) |
| `text` | text řádku deníku; pokud chybí, použije se default podle `src` |

Zdroje částek (vždy pár domácí měna + měna dokladu):

| `src` | domácí měna | měna dokladu |
|---|---|---|
| `rows` | `vat_base_dom` | `vat_base` |
| `vat` (per řádek rekapitulace) | `tax_dom` | `tax` |
| `head`, `col: total` | `total_amount_dom` | `total_amount` |
| `head`, `col: rounding` | `total_rounding_dom` | `total_rounding` |

Default texty: `rows` → `description` řádku; `vat` → `"DPH {vat_code} {vat_pct}%"`;
`head` → `doc_text`.

### Předpis CZ pro MVP (invno, invni)

```jsonc
{
    // economy.accounting.rules.cz
    //
    // Účtovací předpis — Česká republika, podvojné účetnictví.

    "categories": {
        "receivables":      {"name:cs": "Pohledávky"},
        "payables":         {"name:cs": "Závazky"},
        "vat":              {"name:cs": "DPH"},
        "revenue":          {"name:cs": "Výnosy"},
        "costs":            {"name:cs": "Náklady"},
        "rounding.cost":    {"name:cs": "Zaokrouhlení — náklad"},
        "rounding.revenue": {"name:cs": "Zaokrouhlení — výnos"}
    },

    "accounts": [
        {"cat": "revenue", "accountMask": "602", "query": {"operation": "sale.services"}},
        {"cat": "revenue", "accountMask": "604", "query": {"operation": "sale.goods"}},

        {"cat": "costs", "accountMask": "504", "query": {"operation": "purchase.goods"}},
        {"cat": "costs", "accountMask": "518", "query": {"operation": "purchase.services"}},
        {"cat": "costs", "accountMask": "548", "query": {"operation": "purchase.other"}},

        {"cat": "receivables",      "accountMask": "311"},
        {"cat": "payables",         "accountMask": "321"},
        {"cat": "vat",              "accountMask": "343"},
        {"cat": "rounding.cost",    "accountMask": "548"},
        {"cat": "rounding.revenue", "accountMask": "648"}
    ],

    "documents": [
        {"docType": "invno",
            "accounting": [
                {"cat": "revenue", "src": "rows", "side": 1,
                    "operations": ["sale.services", "sale.goods"]},
                {"accountSrc": "item", "src": "rows", "side": 1,
                    "operation": "acc.entry"},
                {"cat": "vat", "src": "vat", "side": 1},
                {"cat": "rounding.revenue", "src": "head", "col": "rounding",
                    "side": 1, "sign": "+", "text": "Zaokrouhlení dokladu"},
                {"cat": "rounding.cost", "src": "head", "col": "rounding",
                    "side": 0, "reverseSign": 1, "sign": "-", "text": "Zaokrouhlení dokladu"},
                {"cat": "receivables", "src": "head", "col": "total", "side": 0}
            ]
        },
        {"docType": "invni",
            "accounting": [
                {"cat": "costs", "src": "rows", "side": 0,
                    "operations": ["purchase.goods", "purchase.services", "purchase.other"]},
                {"accountSrc": "item", "src": "rows", "side": 0,
                    "operation": "acc.entry"},
                {"cat": "vat", "src": "vat", "side": 0},
                {"cat": "rounding.cost", "src": "head", "col": "rounding",
                    "side": 0, "sign": "+", "text": "Zaokrouhlení dokladu"},
                {"cat": "rounding.revenue", "src": "head", "col": "rounding",
                    "side": 1, "reverseSign": 1, "sign": "-", "text": "Zaokrouhlení dokladu"},
                {"cat": "payables", "src": "head", "col": "total", "side": 1}
            ]
        }
    ]
}
```

Kontrolní příklad — faktura vydaná 1 000 Kč služby + 21 % DPH,
zaokrouhleno na 1 210 Kč (rounding 0):

```
602xxx  DAL  1 000,00   (rows, sale.services)
343xxx  DAL    210,00   (vat)
311xxx  MD   1 210,00   (head, total)
                         MD 1 210 = DAL 1 210 ✓
```

---

## 5. Dohledávání účtů

Pořadí:

1. **`accountSrc: "item"`** — účet přímo z `economy_items.accounting_account`
   položky řádku (FK). Maska se nepoužívá.
2. **`cat`** — v sekci `accounts` se najde **první** záznam se shodnou `cat`
   a vyhovující `query` (porovnání rovností nad zdrojovým záznamem — u
   `src: rows` nad řádkem, u `src: head` nad hlavičkou). Výsledkem je
   `accountMask`.
3. **Maska → účtový rozvrh** (`economy_accounting_accounts`):

```sql
SELECT id, number FROM economy_accounting_accounts
WHERE number LIKE '{mask}%'
  AND account_level = 4                         -- jen analytické účty
  AND docStateMain <= 2                         -- aktivní (ne archiv/koš)
  AND (valid_from IS NULL OR valid_from <= :accounting_date)
  AND (valid_to   IS NULL OR valid_to   >= :accounting_date)
ORDER BY number
LIMIT 1
```

Deterministické: první účet podle čísla vzestupně. Praktický důsledek shodný
se starým systémem: `602` najde `602000` dřív než `602100`.

### Nenalezený účet

Řádek deníku se přesto zapíše:

- `account = NULL`
- `account_number` = maska doplněná `?` na 6 znaků (např. `504???`)
- `is_error = 1`
- do `accounting_messages` hlavičky přibude `{code: "account_not_found",
  message: "Účet nenalezen pro masku 504", rowId: ...}`
- `accounting_state = 2`

Reporty (obratová předvaha apod.) chybové řádky snadno vyloučí/zvýrazní
podle `is_error`.

---

## 6. Účetní deník — `economy_accounting_journal`

`modules/economy/accounting/tables/economy_accounting_journal.jsonc`,
**tableId 413**, `hideFromNavigation` ne (deník má vlastní viewer), ale
**bez docStates** — řádky se nikdy needitují, jen mažou a generují celé.

| sloupec | typ | popis |
|---|---|---|
| `id` | int, PK, autoincrement | řazení deníku = řazení podle `id` |
| `doc_head` | int, FK `docs_core_heads`, not null | zdrojový doklad |
| `doc_type` | enumString 20, denorm | typ dokladu |
| `doc_number` | varchar 40, denorm | číslo dokladu |
| `accounting_date` | date, not null | účetní datum (z hlavičky) |
| `fiscal_year` | int, FK `economy_codebooks_fiscal_years` | denorm z hlavičky |
| `fiscal_month` | int, FK `economy_codebooks_fiscal_months` | denorm z hlavičky |
| `account` | int, FK `economy_accounting_accounts`, **nullable** | NULL = nedohledaný účet |
| `account_number` | varchar 12, not null | denorm číslo účtu / chybová maska |
| `is_error` | boolean, default 0 | řádek s nedohledaným účtem |
| `operation` | enumString 40, nullable, cfgItem `docs.core.rowOperations` | pohyb zdrojového řádku (head/vat kroky: NULL) |
| `money_dr` | numeric 15,2, default 0 | MD — **domácí měna** |
| `money_cr` | numeric 15,2, default 0 | DAL — domácí měna |
| `currency` | enumString 3, cfgItem `world.base.currencies` | měna dokladu |
| `money_dr_cur` | numeric 15,2, default 0 | MD — měna dokladu |
| `money_cr_cur` | numeric 15,2, default 0 | DAL — měna dokladu |
| `partner` | int, FK `base_persons_persons`, nullable | partner z hlavičky |
| `text` | varchar 200 | text řádku |

Indexy: (`doc_head`), (`account_number`, `accounting_date`),
(`fiscal_year`, `fiscal_month`), (`partner`).

Poznámky:

- Jednostranné řádky: vyplněno je vždy právě `money_dr` XOR `money_cr`
  (a párový `_cur` sloupec).
- U dokladu v domácí měně jsou `*_cur` shodné s domácími — **plní se vždy**,
  reporty pak nemusí rozlišovat.
- Žádné saldokontní sloupce (symboly, balance, párování) — viz Mimo scope.

---

## 7. Engine a lifecycle

### 7.1 Obecný mechanismus — documentEventHandlers

Účetnictví se potřebuje zaháknout na změny stavu dokladu, ale `docs.core`
nesmí záviset na `economy.accounting`. Zavádí se obecný mechanismus
(použitelný později i pro sklad, saldo, …):

`module.jsonc` (libovolného modulu):

```jsonc
"documentEventHandlers": [
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Economy\\Accounting\\DocsHeadsEventHandler",
        "events": ["stateChanged", "beforeDelete"]
    }
]
```

Interface `Shipard\Core\Document\DocumentEventHandler`:

```php
interface DocumentEventHandler
{
    /** Po commitu uložení, pokud se změnil docState. */
    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void;

    /** Před smazáním dokumentu (uvnitř transakce, před child delete). */
    public function onBeforeDelete(string $tableId, array $data): void;
}
```

Dispatch zajišťuje `TableGateway`: `stateChanged` po `Document::afterSave`,
`beforeDelete` po `Document::beforeDelete`. Registrace se kompiluje z
`module.jsonc` do cfg (analogie `documentClasses`).

### 7.2 Lifecycle účtování

Handler `DocsHeadsEventHandler` (economy.accounting):

| událost | akce |
|---|---|
| přechod **do** 40 (V pořádku) | smazat případné staré řádky deníku dokladu → spustit `AccountingEngine` → zapsat deník + `accounting_state`/`accounting_messages` |
| přechod **ze** 40 (→ 80 V opravě, → 30 Storno, → 90 Smazáno) | smazat řádky deníku, `accounting_state = 0`, `accounting_messages = NULL` |
| `beforeDelete` | smazat řádky deníku (jinak FK blokuje delete) |

Storno (30) = doklad účetně neexistuje. Generování je idempotentní
(delete + insert), takže opakovaný průchod 40 → 80 → 40 je bezpečný.

### 7.3 Algoritmus AccountingEngine

`Shipard\Module\Economy\Accounting\AccountingEngine`
(`modules/economy/accounting/src/AccountingEngine.php`):

```
1. Najdi předpis: rules.{country} → documents[docType]. Nenalezen → chyba (state 2).
2. Ověř fiscal_year / fiscal_month na hlavičce. Chybí → chyba (state 2).
3. Pro každý krok předpisu:
   a. src=rows: iteruj řádky dokladu (row_kind=1), aplikuj filtry
      (operation/operations, query, sign) → částka z vat_base_dom / vat_base
   b. src=vat:  iteruj vat_recap → tax_dom / tax
   c. src=head: jedna částka podle col
   d. dohledej účet (sekce 5), sestav řádek deníku
   e. money == 0 → řádek se přeskakuje
4. Seskupení: klíč (side, account_number, partner, operation) — shodné
   řádky se sčítají (domácí i cur částky), text z prvního řádku skupiny.
5. Kontroly:
   - round(Σ money_dr, 2) == round(Σ money_cr, 2), jinak chyba "unbalanced"
   - prázdný deník → chyba "empty_journal"
   - existují is_error řádky → state 2
6. Zápis: DELETE + INSERT řádků deníku, update accounting_state (1 ok / 2 chyba)
   a accounting_messages na hlavičce. Vše v transakci.
```

Chybové kódy (`accounting_messages[].code`): `rules_not_found`,
`fiscal_period_missing`, `account_not_found`, `item_account_missing`,
`unbalanced`, `empty_journal`.

### 7.4 Chyby a alerty

Filozofie: účtování **nikdy neblokuje** přechod do stavu 40 (jedna
nedohledatelná položka nesmí zablokovat celý doklad — relevantní hlavně pro
budoucí bankovní výpisy). Místo toho:

- doklad ve stavu 40 s `accounting_state = 2` je "dluh" uživatele
- alert check (inline v `module.jsonc` modulu `economy.accounting`, vzor
  `core.alerts`): per-record alert na doklady
  `docState = 40 AND accounting_state = 2`
- po opravě (rozvrh, položka, …) uživatel spustí přeúčtování — detail akce
  **Přeúčtovat** (`detail.actions` pattern) → endpoint znovu spustí engine
  pro doklad ve stavu 40; úspěch alert rozpustí

### 7.5 Tvrdá vs. měkká validace — shrnutí

| kontrola | kdy | typ |
|---|---|---|
| `operation` povinný a povolený pro docType | uložení dokladu | tvrdá (`validate`) |
| `acc.entry` řádek má `item` | uložení dokladu | tvrdá |
| položka `acc.entry` je typ 2 + má účet | účtování | měkká (chybový řádek) |
| účet dle masky existuje v rozvrhu | účtování | měkká |
| MD = DAL, neprázdný deník | účtování | měkká |

---

## 8. Měny a přepočty

Zásada: **každá částka existuje v měně dokladu i v domácí měně**, přepočet se
provádí jednou (při uložení dokladu) a deník už jen čte hotové hodnoty.
(Ve starém Shipardu byl deník jen v domácí měně a působilo to problémy.)

### Přepočet v `beforeSave` dokladu

1. `doc_currency == home_currency` → `_dom` = kopie, `exchange_rate = 1`.
2. Jinak `_dom = round(cur × exchange_rate, 2)` + **haléřové dorovnání**, aby
   platily invarianty:

```
Σ rows.vat_base_dom   (per vat_code) == vat_recap.base_dom   (daného kódu)
Σ rows.vat_amount_dom (per vat_code) == vat_recap.tax_dom
Σ vat_recap.base_dom  == heads.total_base_dom
Σ vat_recap.tax_dom   == heads.total_vat_dom
total_base_dom + total_vat_dom + total_rounding_dom == total_amount_dom
```

Dorovnání: rozdíl ze zaokrouhlení se přičte k poslednímu nenulovému řádku
příslušné skupiny (vat_code). Závazné jsou head totals (top-down: head →
recap → rows).

Díky invariantům deník automaticky bilancuje i u cizoměnových dokladů —
strana pohledávky/závazku (head total) přesně odpovídá součtu základů + DPH
+ zaokrouhlení.

---

## 9. Zobrazení (Fáze 3)

- **Detail dokladu** — nový tab/sekce "Zaúčtování": tabulka řádků deníku
  (účet, text, MD, DAL — domácí měna; u cizí měny i cur), chybové řádky
  zvýrazněné, banner s `accounting_messages`, akce **Přeúčtovat**.
- **Viewer deníku** (`economy.accounting`): seznam řádků deníku, filtry
  fiskální rok/měsíc, účet, partner; fulltext na text. Obratová předvaha a
  další reporty = samostatný pozdější úkol.

---

## 10. Mimo scope

Vědomě se teď neřeší (a předpis/schéma na to nic nepředpřipravuje):

- **Saldokonto** — párování úhrad, zálohy (přijaté/poskytnuté, odpočty,
  zdanění), symboly a balance v deníku, kurzové rozdíly, zápočty. Bude
  samostatný velký úkol, navržený od nuly a jinak než ve starém Shipardu.
  Ze starého enginu tím odpadá: `balanceRows`, `balancePayment`/
  `balanceRequest`, `paymentSymbols`, dohledávání účtu z deníku.
- **Metody účtování** (`accMethod`, daňová evidence, `stockA`/`stockB`) a
  **účetní skupiny** (`e10doc.debs.groups`).
- **Sklad** — skladové pohyby, `invPriceAcc`, ocenění. Pohyby `sale.goods` /
  `purchase.goods` zatím účtují jen výnos/náklad bez vazby na sklad.
- **Majetek** (property, odpisy), **accRing** (účetní okruhy), `accExts`,
  `cashBookId`.
- **Doklady bank / cash / cashreg / purchase / cmnbkp** — přijdou s dalšími
  typy dokladů; mechanismus `query` v krocích předpisu pokryje budoucí
  potřeby typu filtr podle `payment_method`. Hotovostní úhrada faktury
  (protistrana 211) se vyřeší až s pokladnou.
- **DPH analytiky per vatCode** — zatím jediná maska `343`. Až budou
  `vatCodes` řádný číselník, analytika bude **atribut kódu**
  (`accountAnalytics`) — žádné odvozování ze substringů jako ve starém
  systému. Stejný princip později pro analytiky bankovních účtů a pokladen
  (atribut v codebooku, jako `debsAccountId` ve starém systému).
- **Automatické zakládání DPH účtů za běhu** (`checkAccountVAT`) — účty
  zakládá provisioner / uživatel, engine jen dohledává.

---

## 11. Fáze implementace

### Fáze 1 — pohyby a sloupce

- `rowOperations.jsonc` + sloupec `operation` v `docs_core_rows` + validace
  v `DocDocument` + select v řádkovém formuláři (filtrovaný dle docType,
  default dle `order`)
- `_dom` sloupce v `docs_core_rows`, `total_rounding_dom` v heads, přepočet
  s dorovnáním + invarianty (testy!)
- extension `accounting_account` na `economy_items` + pole ve formuláři
  položky

### Fáze 2 — deník a engine

- mechanismus `documentEventHandlers` (core: interface, registry, dispatch
  v `TableGateway`)
- tabulka `economy_accounting_journal` (413)
- extension `accounting_state` / `accounting_messages` na heads + config
  `accountingStates.jsonc`
- `accountingRules.cz.jsonc` + `AccountingEngine` + `DocsHeadsEventHandler`
- endpoint Přeúčtovat + alert check na chybové doklady
- testy: kontrolní příklady invno/invni (CZK, cizí měna, zaokrouhlení,
  acc.entry, chybové stavy)

### Fáze 3 — UI

- detail dokladu: sekce Zaúčtování + banner chyb + akce Přeúčtovat
- viewer deníku s filtry

---

## 12. Log rozhodnutí

1. Pohyb = nový sloupec `operation` (enumString) v `docs_core_rows`;
   `row_kind` zůstává strukturální.
2. Pohyby definuje `docs.core` (`rowOperations.jsonc`), čitelné stringové klíče.
3. Deník: jednostranné řádky `money_dr`/`money_cr`, FK `account` +
   denormalizované `account_number`; částky vždy v obou měnách; řazení podle
   `id`; bez docStates.
4. Účtování neblokuje přechod do stavu 40 — `accounting_state` +
   `accounting_messages` (extension z economy.accounting) + alert.
5. Nedohledaný účet: `account = NULL`, `account_number` = maska + `?`
   (`504???`), `is_error = 1`.
6. `_dom` sloupce v řádcích dokladu, haléřové dorovnání top-down
   (head → recap → rows).
7. Předpis per-country (`accountingRules.cz.jsonc`) v `economy.accounting`;
   výběr dle země vlastní firmy.
8. DPH zatím jedna maska `343`; analytika později jako atribut vatCode.
9. Účetní položky: extension `accounting_account` na items (FK na rozvrh),
   pohyb `acc.entry`, účet přímo z položky (`accountSrc: "item"`).
10. Saldokonto kompletně mimo scope, bez předpřípravy ve schématu.
11. Kategorie předpisu jsou sémantické stringy (`receivables`, `revenue`, …),
    ne čísla účtů.
12. Hook přes obecný mechanismus `documentEventHandlers`
    (`stateChanged`, `beforeDelete`) — použitelný i pro budoucí sklad/saldo.
