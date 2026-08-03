# Task: Pokladny a vlastní bankovní spojení

**Stav:** hotovo

## Kontext

Implementujeme dva nové číselníky v existujícím modulu `economy.codebooks`,
které budou referencovány z hlaviček dokladů (pokladní lístky, bankovní
výpisy, faktury s předkontací na bankovní účet apod.):

- **`economy_codebooks_cash_desks`** — Pokladny pro hotovostní operace
- **`economy_codebooks_bank_accounts`** — Vlastní bankovní účty

Tato fáze řeší pouze datové schéma + CRUD UI. Napojení na hlavičku
dokladu přijde s dokladovým modulem.

Před implementací **přečti**:

- `docs/modules.md` — modulový systém, JSONC, i18n
- `docs/table-definitions.md` — formát definice tabulek
- `docs/edit-forms.md` — editační formuláře, deklarativní JSONC formy
- `docs/doc-states.md` — stavy dokumentů, `docStatesArchive`
- `docs/frontend.md` — viewers, sidebar, ikony
- `docs/documentation.md` — formát README a `tables/*.md`

Vzorové existující implementace k nastudování:

- `modules/economy/codebooks/tables/economy_codebooks_fiscal_years.jsonc` —
  vzor pro tabulku s `docStates` blokem a `columnGroups`
- `modules/economy/codebooks/src/FiscalYearDocument.php` — vzor pro
  Document třídu (jen `validate()`, bez `beforeSave`)
- `modules/economy/codebooks/forms/economy_codebooks_fiscal_months.jsonc` —
  vzor pro deklarativní JSONC form (sub-form, ale stejný formát platí
  i pro top-level formy)
- `modules/economy/codebooks/tables/economy_codebooks_fiscal_years.md` —
  vzor pro `tables/{id}.md` dokumentaci
- `modules/base/persons/tables/base_persons_bank_accounts.jsonc` —
  reference pro pojmenování bankovních polí (zdroj konvence `bic`)
- `modules/core/system/config/docStatesArchive.jsonc` — sada stavů,
  kterou obě tabulky používají

## Cíl

Po dokončení této fáze platí:

- V navigaci se objeví dvě nové položky **Pokladny** (ikona peněženky)
  a **Bankovní spojení** (ikona budovy se sloupy)
- Uživatel může přes UI vytvářet/editovat pokladny a bankovní účty;
  záznamy vznikají jako `Koncept` (10), uživatel je manuálně přepne
  do `V pořádku` (40)
- Každý záznam má `code` (UNIQUE), `name`, validity dates, `is_default`
  flag a docState lifecycle (Koncept/V pořádku/V archívu/Smazáno)
- `is_default = 1` je vynuceno jako **unikátní per měna** — při uložení
  záznamu jako default se ostatní defaulty se stejnou měnou automaticky
  odznačí (logika v `afterPersist`, atomická s persistem)
- `BankAccountDocument` validuje, že je vyplněný buď `account_number`
  NEBO `iban` (oba zároveň prázdné = error)
- Validace formátů: `currency` (lowercase 3 znaky), `iban` (basic
  shape, bez mod-97), `bic` (8 nebo 11 znaků v bankovním formátu)

## Návaznost

- Závisí na: `core.system`, funkční edit-forms infrastruktura, funkční
  doc-states, `economy.codebooks` modul (existuje, rozšiřujeme ho)
- Sousední tabulky v modulu: `economy_codebooks_fiscal_years` (313),
  `economy_codebooks_fiscal_months` (314), `economy_codebooks_vat_*`
  — náš design jejich vzor následuje
- Otevírá: dokladový systém — hlavička pokladního dokladu bude mít
  FK na `cash_desks`, hlavička bankovního dokladu / hlavička faktury
  s bankovní úhradou bude mít FK na `bank_accounts`

## Scope

### V rozsahu

- Tabulky `economy_codebooks_cash_desks` (tableId **103**) a
  `economy_codebooks_bank_accounts` (tableId **104**)
- Standardní `core.system.docStatesArchive` stavy pro obě tabulky
- PHP třídy `CashDeskDocument` a `BankAccountDocument` (validace +
  `afterPersist` pro default-per-currency uniqueness)
- Deklarativní JSONC formy `forms/economy_codebooks_cash_desks.jsonc`
  a `forms/economy_codebooks_bank_accounts.jsonc` (bez vlastní `TableForm`
  subclassy — formy jsou jednoduché, viz `docs/edit-forms.md` § 13)
- Viewer entries v `module.jsonc` (bez vlastní viewer třídy — automatický
  z TableDefinition, jako vat_periods)
- Frontend ikony: `iconWallet` (`faWallet`) a `iconBank` (`faBuildingColumns`),
  zaregistrované v `iconMap` jako `'wallet'` a `'bank'`
- Documentation: rozšíření `modules/economy/codebooks/README.md`,
  nové `tables/economy_codebooks_cash_desks.md` a
  `tables/economy_codebooks_bank_accounts.md`
- Unit testy pro oba Documenty

### Mimo rozsah (odložené)

- Provisioner se seedovanými záznamy — ať si firma sama nadefinuje
- Napojení na hlavičku dokladu (přijde s dokladovým modulem)
- Auto-generování IBAN z domácího formátu (`account_number` → `iban`)
- IBAN mod-97 checksum validace (jen length + shape regex)
- Auto-vyplnění `bank_name` z bankovního kódu (číselník bank ČNB) —
  zatím manuální zadání
- Currency picker — `currency` zůstává prostý `varchar(3)` text input
  s defaultem `czk` (stejně jako u `fiscal_years`)
- Custom viewer třídy — výchozí auto-viewer postačí; pokud řazení a
  badge stavu sedí ven z TableDefinition, nepotřebujeme custom logiku
- Per-form recalculate (žádná dynamická pole)

## Datový model

### Nový: `economy_codebooks_cash_desks` (tableId 103)

`docStates`: `core.system.docStatesArchive`. `displayPattern`: `"{code} — {name}"`.

**Skupina `identity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `code` | varchar(10) NOT NULL | UNIQUE; krátký kód pro selecty (`HP1`, `EUR`) |
| `name` | varchar(150) NOT NULL | Název pokladny |
| `notice` | varchar(250) NULL | Poznámka |

**Skupina `settings`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `currency` | varchar(3) NOT NULL default `'czk'` | ISO 4217 lowercase |
| `is_default` | boolean NOT NULL default 0 | Výchozí pokladna pro danou měnu |
| `valid_from` | date NULL | |
| `valid_to` | date NULL | |
| `sort_order` | smallint NOT NULL default 0 | |

**Systémové (bez `group`):**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `docState` | tinyint default 10 | `system: true` |
| `docStateMain` | tinyint default 1 | `system: true` |

Indexy:

- `unq_code` UNIQUE na `code`
- `idx_sort_order` na `sort_order ASC`, `name ASC`
- `idx_doc_state` na `docStateMain ASC`, `sort_order ASC`

### Nový: `economy_codebooks_bank_accounts` (tableId 104)

`docStates`: `core.system.docStatesArchive`. `displayPattern`: `"{code} — {name}"`.

**Skupina `identity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `code` | varchar(10) NOT NULL | UNIQUE |
| `name` | varchar(150) NOT NULL | Název účtu |
| `notice` | varchar(250) NULL | Poznámka |

**Skupina `account`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `bank_name` | varchar(150) NULL | Název banky (lidsky čitelný; nedopočítává se) |
| `account_number` | varchar(40) NULL | Domácí formát např. `19-2000145399/0800` |
| `iban` | varchar(34) NULL | IBAN (uppercase) |
| `bic` | varchar(11) NULL | BIC/SWIFT (uppercase). Pojmenování `bic` převzato z `base_persons_bank_accounts` pro konzistenci; UI label "BIC/SWIFT" |

**Skupina `settings`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `currency` | varchar(3) NOT NULL default `'czk'` | |
| `is_default` | boolean NOT NULL default 0 | Výchozí účet pro danou měnu |
| `valid_from` | date NULL | |
| `valid_to` | date NULL | |
| `sort_order` | smallint NOT NULL default 0 | |

**Systémové (bez `group`):**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `docState` | tinyint default 10 | `system: true` |
| `docStateMain` | tinyint default 1 | `system: true` |

Indexy:

- `unq_code` UNIQUE na `code`
- `idx_sort_order` na `sort_order ASC`, `name ASC`
- `idx_doc_state` na `docStateMain ASC`, `sort_order ASC`
- `idx_iban` na `iban` (pro budoucí lookup z dokladového systému —
  rozpoznání účtu podle IBAN ze SEPA platby)

## Adresářová struktura

Modul `economy.codebooks` už existuje. Přidáváme do něj:

```
modules/economy/codebooks/
├── module.jsonc                  # ROZŠÍŘIT — nové tables, forms, viewers, documentClasses
├── README.md                     # ROZŠÍŘIT — přidat řádky pro nové tabulky
├── forms/
│   ├── economy_codebooks_cash_desks.jsonc       # NOVÝ — deklarativní form
│   └── economy_codebooks_bank_accounts.jsonc    # NOVÝ — deklarativní form
├── tables/
│   ├── economy_codebooks_cash_desks.jsonc       # NOVÝ
│   ├── economy_codebooks_cash_desks.md          # NOVÝ
│   ├── economy_codebooks_bank_accounts.jsonc    # NOVÝ
│   └── economy_codebooks_bank_accounts.md       # NOVÝ
└── src/
    ├── CashDeskDocument.php                     # NOVÝ
    └── BankAccountDocument.php                  # NOVÝ
```

Plus:

```
frontend/src/icons.js              # ROZŠÍŘIT — iconWallet, iconBank + iconMap
```

Plus testy:

```
tests/Unit/Module/Economy/Codebooks/
├── CashDeskDocumentTest.php       # NOVÝ
└── BankAccountDocumentTest.php    # NOVÝ
```

Namespace: `Shipard\Module\Economy\Codebooks\*`.

## Task breakdown

### Krok 1: Tabulky JSONC

Vytvoř `modules/economy/codebooks/tables/economy_codebooks_cash_desks.jsonc`
podle schématu výše. `docStates` blok stejný jako u `fiscal_years`:

```jsonc
"docStates": {
    "stateColumn": "docState",
    "mainColumn": "docStateMain",
    "cfgItem": "core.system.docStatesArchive"
},
```

`columnGroups`: `identity`, `settings`. Czech labely u všech sloupců.

Vytvoř `modules/economy/codebooks/tables/economy_codebooks_bank_accounts.jsonc`
analogicky. `columnGroups`: `identity`, `account`, `settings`.

### Krok 2: Document classes

`src/CashDeskDocument.php` — extends `Shipard\Core\Document\Document`,
namespace `Shipard\Module\Economy\Codebooks`.

```php
public function validate(array &$data): ValidationResult
{
    $r = new ValidationResult();

    if (empty($data['code'])) {
        $r->addError('code', 'Kód je povinný', 'required');
    }
    if (empty($data['name'])) {
        $r->addError('name', 'Název je povinný', 'required');
    }
    if (empty($data['currency'])) {
        $r->addError('currency', 'Měna je povinná', 'required');
    } elseif (!preg_match('/^[a-z]{3}$/', (string) $data['currency'])) {
        $r->addError('currency', 'Měna musí být tříznakový kód malými písmeny.', 'invalid_format');
    }

    if (!empty($data['valid_from']) && !empty($data['valid_to'])
        && (string) $data['valid_from'] > (string) $data['valid_to']) {
        $r->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
    }

    return $r;
}

public function beforeSave(array &$data): void
{
    // Normalizace
    if (isset($data['currency'])) {
        $data['currency'] = strtolower(trim((string) $data['currency']));
    }
    foreach (['code', 'name', 'notice'] as $col) {
        if (isset($data[$col])) {
            $data[$col] = trim((string) $data[$col]);
        }
    }
}

public function afterPersist(array $data): void
{
    // Default-per-currency uniqueness — pokud jsme uložili tento záznam
    // jako default, odznač ostatní defaulty se stejnou měnou.
    if (empty($data['is_default'])) {
        return;
    }
    $this->db->query(
        'UPDATE [economy_codebooks_cash_desks] SET [is_default] = 0
         WHERE [currency] = %s AND [is_default] = 1 AND [id] != %i',
        (string) $data['currency'],
        (int) $data['id'],
    );
}
```

`src/BankAccountDocument.php` — analogicky, navíc:

- Validace: pokud jsou `account_number` i `iban` oba prázdné → error
  na `account_number` s kódem `'required_one_of'` (message: „Musí být
  vyplněn alespoň jeden z údajů: Číslo účtu nebo IBAN.")
- Validace `iban` (pokud vyplněný): regex `^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$`,
  message „IBAN má neplatný formát."
- Validace `bic` (pokud vyplněný): regex `^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$`,
  message „BIC/SWIFT má neplatný formát."
- `beforeSave`: navíc uppercase pro `iban` a `bic`, trim pro
  `bank_name`/`account_number`/`iban`/`bic`
- `afterPersist`: stejná logika default-per-currency proti tabulce
  `economy_codebooks_bank_accounts`

**Pozn.:** validaci IBANu i BICu provádíme až **po** uppercase normalizaci,
aby uživatel mohl zadat lowercase. To znamená validovat hodnotu z
`$data` po `beforeSave`-style normalizaci, ale `validate()` běží PŘED
`beforeSave()`. Řešení: v `validate()` proveď uppercase pouze pro
porovnání (nechej `$data` na pokoji), v `beforeSave()` pak skutečně
přepiš `$data['iban']`/`$data['bic']`. Vzor:

```php
$iban = strtoupper(trim((string) ($data['iban'] ?? '')));
if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
    $r->addError('iban', 'IBAN má neplatný formát.', 'invalid_format');
}
```

### Krok 3: Deklarativní JSONC formy

`modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc`:

```jsonc
{
    "title": "Pokladna",
    "titleNew": "Nová pokladna",
    "fullSize": false,

    "tabs": [
        {
            "id": "basic",
            "label": "Pokladna",
            "elements": [
                {"type": "input", "column": "code", "cols": 1, "required": true},
                {"type": "input", "column": "name", "cols": 2, "required": true},
                {"type": "input", "column": "currency", "cols": 1, "required": true},
                {"type": "input", "column": "notice", "cols": 4},

                {"type": "separator", "label": "Nastavení"},
                {"type": "input", "column": "is_default", "cols": 1},
                {"type": "input", "column": "sort_order", "cols": 1},
                {"type": "input", "column": "valid_from", "cols": 1},
                {"type": "input", "column": "valid_to", "cols": 1}
            ]
        }
    ]
}
```

`type: "input"` u boolean a date sloupců se automaticky přemapuje na
`checkbox` resp. `date` z TableDefinition (viz `docs/edit-forms.md` § 4.1
„`input_type` se odvozuje automaticky z DB typu sloupce"). Pokud by to
nefungovalo (např. cestou `JsoncFormLoader` se odvození nedoplní),
přidej explicitně `"input_type": "checkbox"` resp. `"date"`.

`modules/economy/codebooks/forms/economy_codebooks_bank_accounts.jsonc`:

```jsonc
{
    "title": "Bankovní spojení",
    "titleNew": "Nové bankovní spojení",
    "fullSize": false,

    "tabs": [
        {
            "id": "basic",
            "label": "Účet",
            "elements": [
                {"type": "input", "column": "code", "cols": 1, "required": true},
                {"type": "input", "column": "name", "cols": 2, "required": true},
                {"type": "input", "column": "currency", "cols": 1, "required": true},
                {"type": "input", "column": "notice", "cols": 4},

                {"type": "separator", "label": "Bankovní údaje"},
                {"type": "input", "column": "bank_name", "cols": 2},
                {"type": "input", "column": "account_number", "cols": 2},
                {"type": "input", "column": "iban", "cols": 2},
                {"type": "input", "column": "bic", "cols": 2},

                {"type": "separator", "label": "Nastavení"},
                {"type": "input", "column": "is_default", "cols": 1},
                {"type": "input", "column": "sort_order", "cols": 1},
                {"type": "input", "column": "valid_from", "cols": 1},
                {"type": "input", "column": "valid_to", "cols": 1}
            ]
        }
    ]
}
```

### Krok 4: Frontend ikony

V `frontend/src/icons.js`:

1. Přidej do importu z `@fortawesome/free-solid-svg-icons`:
   ```js
   faWallet,
   faBuildingColumns,
   ```
2. Pod sekci „Číselníky / moduly" přidej:
   ```js
   export const iconWallet = faWallet;
   export const iconBank = faBuildingColumns;
   ```
3. Do `iconMap` přidej:
   ```js
   'wallet': iconWallet,
   'bank': iconBank,
   ```

Spusť `npm run build` v `frontend/` a ověř, že build projde.

### Krok 5: module.jsonc rozšíření

V `modules/economy/codebooks/module.jsonc`:

1. Do `tables` array přidej (na konec):
   ```jsonc
   "economy_codebooks_cash_desks",
   "economy_codebooks_bank_accounts"
   ```
2. Do `viewers` array přidej:
   ```jsonc
   {
       "id": "economy.codebooks.cashDesks",
       "name": "Cash desks",
       "name:cs": "Pokladny",
       "name:en": "Cash desks",
       "icon": "wallet",
       "table": "economy_codebooks_cash_desks"
   },
   {
       "id": "economy.codebooks.bankAccounts",
       "name": "Bank accounts",
       "name:cs": "Bankovní spojení",
       "name:en": "Bank accounts",
       "icon": "bank",
       "table": "economy_codebooks_bank_accounts"
   }
   ```
   (žádný `class` — defaultní auto-viewer)
3. Do `forms` array přidej:
   ```jsonc
   { "table": "economy_codebooks_cash_desks",    "id": "economy.codebooks.cash_desks" },
   { "table": "economy_codebooks_bank_accounts", "id": "economy.codebooks.bank_accounts" }
   ```
4. Do `documentClasses` array přidej:
   ```jsonc
   { "table": "economy_codebooks_cash_desks",    "class": "Shipard\\Module\\Economy\\Codebooks\\CashDeskDocument" },
   { "table": "economy_codebooks_bank_accounts", "class": "Shipard\\Module\\Economy\\Codebooks\\BankAccountDocument" }
   ```

Aktualizuj i `description` modulu — přidej zmínku o pokladnách a
bankovních spojeních (nahraď „Warehouses, cost centers, fiscal periods,
VAT registrations and other codebooks" za rozšířenou variantu).

### Krok 6: Testy

`tests/Unit/Module/Economy/Codebooks/CashDeskDocumentTest.php` — pokrýt:

- Chybějící `code`/`name`/`currency` → error per pole s kódem `required`
- `currency` jiný než 3 znaky lowercase (`"CZK"`, `"cz"`, `"czechk"`) → error `invalid_format`
- `valid_from > valid_to` → error na `valid_to` s kódem `invalid_range`
- Validní záznam (všechna povinná pole, validní currency) → ValidationResult bez chyb
- `beforeSave` normalizuje `currency` na lowercase
- `beforeSave` trimuje `code`, `name`, `notice`

Pro `afterPersist` test stačí mock `Dibi\Connection` (nebo skutečné
in-memory připojení, podle existujícího patternu) — pokud `is_default = 0`,
neproběhne žádný UPDATE; pokud `is_default = 1`, proběhne UPDATE
s WHERE clause obsahujícím `currency` a vyloučení `id`.

`tests/Unit/Module/Economy/Codebooks/BankAccountDocumentTest.php` —
analogicky, navíc:

- Oba `account_number` i `iban` prázdné → error na `account_number`
  s kódem `required_one_of`
- Vyplněný jen `account_number` → OK
- Vyplněný jen `iban` (validní formát) → OK
- Vyplněný `iban` v neplatném formátu (`CZ12`, `1234567890`) → error
  na `iban` s kódem `invalid_format`
- Vyplněný `bic` v neplatném formátu (`abc`, `123456789`) → error na
  `bic` s kódem `invalid_format`
- `beforeSave` převede `iban`/`bic` na uppercase

Vzor existujících testů: `tests/Unit/Module/Base/Persons/PersonDocumentTest.php`
(struktura testovací třídy + assertion patterns).

### Krok 7: Documentation

**`modules/economy/codebooks/README.md`** — rozšíř:

1. V úvodním odstavci nebo separátní sekci zmiň, že modul nově obsahuje
   pokladny a vlastní bankovní spojení, které budou referencovány
   z hlaviček dokladů
2. Do tabulky **Tabulky** přidej řádky:
   ```markdown
   | [economy_codebooks_cash_desks](tables/economy_codebooks_cash_desks.md) | Pokladny pro hotovostní operace |
   | [economy_codebooks_bank_accounts](tables/economy_codebooks_bank_accounts.md) | Vlastní bankovní účty (firma) |
   ```
3. Do tabulky **Zdrojové soubory** přidej řádky pro `CashDeskDocument`
   a `BankAccountDocument`

**`modules/economy/codebooks/tables/economy_codebooks_cash_desks.md`** —
podle vzoru `economy_codebooks_fiscal_years.md`:

- Krátký úvodní odstavec popisující účel
- Sekce **Sloupce** rozdělená podle column groups (`identity`,
  `settings`, **Systémové**)
- Sekce **Indexy**
- Sekce **Pravidla**: `code` UNIQUE; `currency` lowercase ISO 4217;
  `is_default = 1` se automaticky vynucuje jako jediný per měna
  (afterPersist hook); `valid_from <= valid_to`
- Sekce **Související**: odkazy na Document třídu a budoucí napojení
  z dokladového systému (napsat „Hlavička pokladního dokladu (přijde
  s dokladovým modulem) bude obsahovat FK na tuto tabulku.")

**`modules/economy/codebooks/tables/economy_codebooks_bank_accounts.md`** —
analogicky, navíc:

- V sekci **Pravidla**: musí být vyplněn `account_number` NEBO `iban`;
  `iban`/`bic` automaticky uppercase v `beforeSave`
- Vysvětlení, proč pojmenování `bic` a ne `swift` (konzistence s
  `base_persons_bank_accounts`; UI label „BIC/SWIFT")

## Rozhodnutí k designu (potvrzená)

✓ **Modul `economy.codebooks`** — pokladny a bankovní spojení patří
mezi ekonomické číselníky, ne do `base.persons` (`base_persons_bank_accounts`
jsou bankovní účty kontaktů, ne firmy)

✓ **TableId 103 (cash_desks), 104 (bank_accounts)** — navazují
na existující řadu codebooku (101 warehouses, 102 cost_centers)

✓ **Obě tabulky mají `docStates`** (`core.system.docStatesArchive`) —
budou referencovány z hlaviček dokladů, lifecycle Koncept→V pořádku→
V archívu→Smazáno dává smysl

✓ **`code` (varchar 10, UNIQUE) + `displayPattern` `"{code} — {name}"`** —
pattern převzatý z ostatních codebooků (warehouses, cost_centers)

✓ **`notice` (varchar 250, nullable)** — drobné, ale praktické pole
pro účetní (číslo smlouvy s bankou, kontakt na pokladníka apod.)

✓ **Default per currency** — `is_default = 1` je unikátní per měna,
vynucováno aplikačně v `afterPersist` (UPDATE ostatních se stejnou
měnou na 0). DB constraint nepoužíváme — JSONC table-definition
formát partial unique indexy nepodporuje (`docs/table-definitions.md`
§ 7), DB constraint by byl mimo standard projektu

✓ **`afterPersist` (ne `beforeSave`)** pro default uniqueness — řádek
už má své ID a finální stav v DB, transakce zůstává atomická (rollback
funguje, viz docstring `Document::afterPersist`)

✓ **`bank_accounts.bic` (ne `swift`)** — sjednoceno s
`base_persons_bank_accounts`, které už používá `bic`. UI label
„BIC/SWIFT" zůstává uživatelsky srozumitelný

✓ **`account_number` a `iban` oba nullable, ale aspoň jeden povinný** —
pokrývá CZ-only účty (jen `account_number`) i čistě zahraniční (jen
`iban`); cross-field validace v `BankAccountDocument::validate`

✓ **`bank_name` nullable, ručně zadávaný** — nedopočítává se z banky
podle bankovního kódu / IBANu; číselník bank ČNB se neřeší

✓ **`currency` default `'czk'`, prostý varchar(3) lowercase** —
stejně jako u `fiscal_years`; currency picker přijde později jako
globální vylepšení

✓ **Žádný custom viewer ani TableForm subclass** — formy stačí
deklarativní JSONC; viewery automatické z TableDefinition (jako
vat_periods)

✓ **Žádný provisioner / seed** — uživatel si pokladny i účty
nadefinuje sám

✓ **Validace `iban`/`bic` jen shape, ne mod-97** — basic regex
postačí; pokročilejší validace lze dodat později (např. doplněný
ČNB lookup nebo IBAN.com lib)

✓ **`idx_iban` na `bank_accounts`** — připravený lookup pro budoucí
SEPA modul (rozpoznání účtu podle IBANu z příchozí platby)

✓ **`sort_order` (ne `order_pos`)** — konzistence s ostatními
codebooku (warehouses, cost_centers, fiscal_*); `order_pos` je
konvence persons modulu pro sub-tabulky, ne codebooků

## Hotovo když

- [ ] `bin/shpd-ds ds-create test_xyz` projde a obě tabulky existují
  s odpovídajícími sloupci, indexy a UNIQUE constraintem na `code`
- [ ] `bin/shpd-ds ds-upgrade test_xyz` druhý běh je no-op
- [ ] V navigaci se objeví **Pokladny** (ikona peněženky) a **Bankovní
  spojení** (ikona budovy se sloupy)
- [ ] Lze vytvořit pokladnu i účet přes UI; vznikají jako Koncept (10),
  lze přepnout do V pořádku (40)
- [ ] Validace na úrovni Document funguje:
  - Prázdný `code`/`name`/`currency` → 422 + chyba u pole
  - `currency` `"CZK"` → 422 (musí být lowercase)
  - U bank_account oba `account_number` i `iban` prázdné → 422
  - Špatný formát IBANu (`"CZ12"`) nebo BICu (`"abc"`) → 422
  - `valid_from > valid_to` → 422
- [ ] Vytvoření druhého `is_default = 1` se stejnou měnou automaticky
  odznačí default na prvním (UPDATE proběhne v jedné transakci se save)
- [ ] Vytvoření `is_default = 1` v jiné měně neovlivní default v
  původní měně (independence per currency)
- [ ] `iban` i `bic` se v DB ukládají jako uppercase i když uživatel
  zadá lowercase
- [ ] Viewer zobrazuje záznamy seřazené podle `docStateMain ASC,
  sort_order ASC` (aktivní nahoře, pak podle pořadí)
- [ ] `unq_code` UNIQUE — pokus o uložení duplicitního `code` vrátí
  pochopitelnou chybu (TableGateway/Dibi exception se přemění na 422
  s message obsahující `code`)
- [ ] PHPUnit testy prochází: `vendor/bin/phpunit tests/Unit/Module/Economy/Codebooks`
- [ ] Frontend build prochází po přidání ikon (`npm run build`)
- [ ] `README.md` modulu rozšířený, obě nové `tables/*.md` napsané

## Konvence a upozornění

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v JSONC má `:cs` a `:en` variantu
- **PHP 8.5** strict_types, readonly properties kde možné
- **Snake_case** pro DB sloupce; `is_default` (ne `default` — to je
  rezervované klíčové slovo) ani `default_flag`
- **Dibi placeholdery**: `%s` pro string, `%i` pro int. Pro safety
  v `afterPersist` UPDATE používej `[table]` a `[column]` syntax pro
  identifier escaping
- **`Document::afterPersist`** běží v transakci save — pokud vyhodí
  exception, transakce se rolluje; pokud projde, pokračuje commit
- **`Document::validate` běží PŘED `beforeSave`** — proto u IBANu/BICu
  validuj uppercase verzi explicitně (`strtoupper(trim(...))`), ne
  hodnotu z `$data` (která ještě nebyla normalizovaná)
- **Composer autoload** — po vytvoření nových src souborů spusť
  `composer dump-autoload`
- **Po každém kroku** ověř na testovacím DS, ať se chyby nehromadí

## Doporučené pořadí implementace

Krok 1 (tabulky JSONC) → Krok 5 (module.jsonc — aby `ds-upgrade` viděl
nové tabulky) → ověřit `ds-upgrade` (vytvoří prázdné tabulky) → Krok 2
(Documenty — validace + afterPersist) → Krok 3 (deklarativní formy) →
Krok 4 (frontend ikony + build) → ověřit UI ručně (vytvoř pokladnu,
účet, nastav default, ověř že 2. default se stejnou měnou odznačí 1.)
→ Krok 6 (testy) → Krok 7 (docs).
