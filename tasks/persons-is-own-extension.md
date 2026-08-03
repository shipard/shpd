# Task: Rozšíření `base.persons` — vlastní firma a obchodní rejstřík

**Stav:** hotovo

## Kontext

Připravujeme dokladový systém (faktury vydané a přijaté). Ten potřebuje na hlavičku
dokladu ukládat **snapshot dodavatele a odběratele** v okamžiku potvrzení dokladu —
tj. zamrznout fakturační údaje, aby pozdější změna v evidenci osob nezměnila
historický doklad.

Pro tento snapshot potřebujeme dvě věci, které dnes v `base_persons_persons`
chybí:

1. **Vlastní firma** — flag `is_own` na osobě, který říká „toto je naše firma".
   Maximálně jeden takový záznam v DS. Doklad si při potvrzení vezme údaje
   o naší firmě právě z tohoto záznamu (jméno, IČO, DIČ, sídlo).
2. **Zápis v obchodním rejstříku** — varchar `court_registration` typu
   *„Městský soud v Praze, oddíl C, vložka 12345"*. Relevantní u firem
   (jak naší vlastní, tak partnerů).

Tato fáze je úmyslně izolovaná — nezávisí na nic jiném (fáze 2+ kompletního
plánu MVP staví na tomhle), ale sama o sobě nemění chování existujícího
systému. Nově přidané sloupce zatím nikdo nečte; to přijde s docs MVP.

Před implementací **přečti**:

- `docs/docs-mvp.md` sekce 10 — kompletní specifikace tohoto rozšíření a jeho
  role v dokladovém MVP
- `docs/table-definitions.md` — jak se zapisují sloupce do JSONC
- `docs/document-system.md` — `validate` hook, error codes, konvence
- `docs/edit-forms.md` — `TableForm`, `addInput`, hidden parametr

Vzorové existující soubory:

- `modules/base/persons/tables/base_persons_persons.jsonc` (do něj přidáváme)
- `modules/base/persons/src/PersonDocument.php` (do něj přidáváme validaci)
- `modules/base/persons/src/PersonsForm.php` (do něj přidáváme UI)
- `modules/base/persons/tables/base_persons_persons.md` (aktualizujeme)

## Cíl

Po dokončení této fáze platí:

- `base_persons_persons` má dva nové sloupce: `is_own` (boolean) a
  `court_registration` (varchar(250) NULL)
- `bin/shpd-ds ds-upgrade` na **existujícím** DS bez problému přidá oba sloupce
  (alter table)
- V editačním formuláři osoby:
  - `court_registration` se zobrazuje v sekci „Identifikace firmy" (pod IČO/DIČ),
    skrytý u fyzické osoby
  - `is_own` je checkbox zobrazený pod sekcí „Identifikace firmy", skrytý
    u fyzické osoby
- Validace odmítne uložit záznam s `is_own = 1`, pokud:
  - existuje jiný aktivní záznam s `is_own = 1` (chyba `is_own_duplicate`)
  - typ osoby není Firma (chyba `is_own_not_company`)
- Tabulková dokumentace popisuje oba nové sloupce a obchodní pravidla

## Návaznost

- Závisí na: `core.system` (existuje), `base.persons` v aktuální podobě
- Otevírá: Fáze 2 (`world.vat` modul) a Fáze 3+ (docs.core) — viz `docs/docs-mvp.md`

## Scope

### V rozsahu

- Sloupec `is_own` (boolean default 0, skupina `status`)
- Sloupec `court_registration` (varchar(250) nullable, skupina `identity`)
- Validace v `PersonDocument::validate`:
  - `is_own = 1` vyžaduje `person_type = Company` (PersonType::Company → hodnota 2)
  - `is_own = 1` vyžaduje, aby žádný jiný aktivní záznam neměl `is_own = 1`
- UI úprava `PersonsForm::buildFormDefinition`:
  - `court_registration` jako třísloupcový input v sekci „Identifikace firmy",
    hidden pokud osoba není firma
  - `is_own` jako boolean (checkbox) na konci basic tabu, hidden pokud osoba
    není firma
- Aktualizace `tables/base_persons_persons.md`

### Mimo rozsah (řeší pozdější fáze)

- Helper třída `OwnCompanyResolver` (řeší fáze 3 — docs.core)
- Setup workflow / wizard pro označení vlastní firmy v UI (řeší později,
  zatím musí uživatel ručně)
- Validace formátu `court_registration` (volný text, žádný parsing)
- Index na `is_own` (efektivně se hledá `WHERE is_own = 1`, což je 1 řádek
  z hodně málo aktivních; sequential scan je v pohodě)

## Změny souborů

### `modules/base/persons/tables/base_persons_persons.jsonc`

Do pole `columns` přidat (umístění viz níže):

```jsonc
{
    "id": "court_registration",
    "name": "Court registration",
    "name:cs": "Zápis v obchodním rejstříku",
    "name:en": "Court registration",
    "type": "varchar",
    "length": 250,
    "nullable": true,
    "group": "identity"
},
```

```jsonc
{
    "id": "is_own",
    "name": "Own company",
    "name:cs": "Vlastní firma",
    "name:en": "Own company",
    "type": "boolean",
    "default": 0,
    "group": "status"
},
```

**Pozice v souboru:**

- `court_registration` umístit **bezprostředně za `vat_id`** (poslední sloupec
  ve skupině `identity`)
- `is_own` umístit **bezprostředně za `closed_date`** (poslední sloupec ve
  skupině `status`)

### `modules/base/persons/src/PersonDocument.php`

V metodě `validate` přidat na konec (před `return $result`):

```php
// is_own validation
if (!empty($data['is_own'])) {
    // Must be a company
    if ($personType !== PersonType::Company) {
        $result->addError(
            'is_own',
            'Vlastní firma musí být typu Firma',
            'is_own_not_company',
        );
    }

    // Uniqueness check (only one own company across active records)
    if ($this->db !== null) {
        $sql = 'SELECT id FROM base_persons_persons
                WHERE is_own = 1 AND docState != %i';
        $params = [90];
        if (!empty($data['id'])) {
            $sql .= ' AND id != %i';
            $params[] = (int) $data['id'];
        }
        $sql .= ' LIMIT 1';

        $existing = $this->db->fetch($sql, ...$params);
        if ($existing) {
            $result->addError(
                'is_own',
                'Vlastní firma už je nastavena na jiném záznamu',
                'is_own_duplicate',
            );
        }
    }
}
```

Pozn: `docState != 90` — záměrně nepočítáme smazané záznamy, aby uživatel
mohl vlastní firmu nahradit přes flow „smazat starou + označit novou".
`existing` test `WHERE id != $data['id']` je standardní pattern pro update.

### `modules/base/persons/src/PersonsForm.php`

V metodě `buildFormDefinition` rozšířit `basic` tab takto:

1. **Přidat `court_registration`** do sekce „Identifikace firmy", konkrétně
   na nový řádek **za `vat_id`**:

```php
// V sekci "Identifikace firmy" - po vat_id input
->addInput('court_registration', cols: 3, hidden: $isPerson || $isUndefined)
```

2. **Přidat `is_own`** úplně na konec basic tabu (před `->build()`):

```php
->addSeparator('Naše firma', hidden: $isPerson || $isUndefined)
->addCheckbox('is_own', cols: 1, hidden: $isPerson || $isUndefined)
```

Pozn: Pokud `addCheckbox` neexistuje v `TabBuilder`, použij existující builder
pro boolean (např. `addInput` s typem boolean — viz vzor v jiných formulářích).
Ověř v `Shipard\Core\Form\TabBuilder`. Pokud chybí dedikovaný checkbox builder
a ostatní boolean sloupce v projektu používají něco jako `->addBoolean()` nebo
`->addInput()` s explicitním `inputType: 'checkbox'`, drž se zavedeného vzoru.

### `modules/base/persons/tables/base_persons_persons.md`

Aktualizovat sekce:

1. Do tabulky **Identifikace (identity)** přidat řádek za `vat_id`:

   ```
   | `court_registration` | varchar(250) | Zápis v obchodním rejstříku — typ "Městský soud v Praze, oddíl C, vložka 12345" |
   ```

2. Do tabulky **Stav (status)** přidat řádek za `closed_date`:

   ```
   | `is_own` | boolean | Příznak vlastní firmy — max 1 záznam v DS, jen pro firmy |
   ```

3. Do sekce **Obchodní logika (PersonDocument)** přidat novou pod-sekci za
   „Společné":

   ```markdown
   ### Vlastní firma (is_own)

   Flag `is_own = 1` označuje záznam jako "naši firmu" — z toho dokladový
   systém čerpá údaje pro snapshot dodavatele/odběratele při potvrzení
   dokladu.

   Validace:
   - Maximálně **jedna** osoba v DS smí mít `is_own = 1` (přes všechny
     aktivní stavy `docState != 90`).
   - Vlastní firma musí být typu `Company` (`person_type = 2`).

   Při instalaci nového DS uživatel ručně označí svou firmu — nelze vytvářet
   doklady, dokud vlastní firma není nastavená (kontrola v dokladovém modulu).
   ```

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde na existujícím i čerstvém DS bez chyb
- [ ] Tabulka `base_persons_persons` má sloupce `court_registration` (varchar(250) NULL)
      a `is_own` (boolean default 0)
- [ ] Editační formulář osoby zobrazuje pole `court_registration` jen pro firmy
- [ ] Editační formulář osoby zobrazuje checkbox `is_own` jen pro firmy
- [ ] Pokus uložit fyzickou osobu s `is_own = 1` vrátí chybu `is_own_not_company`
- [ ] Pokus uložit druhou firmu s `is_own = 1` vrátí chybu `is_own_duplicate`
- [ ] Změna `is_own` z 1 na 0 a uložení projde bez chyby (přepnutí na jinou
      firmu funguje)
- [ ] PHPUnit testy v `tests/Unit/Module/Base/Persons/PersonDocumentTest.php`
      pokrývají oba nové error kódy
- [ ] `tables/base_persons_persons.md` má aktualizované sekce

## Konvence

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v JSONC má `:cs` a `:en` variantu
- **PHP 8.3** strict_types
- **`%i`/`%s`** placeholdery v dibi dotazech (ne `?`)
- Po úpravě `module.jsonc`, JSONC tabulky nebo cfgItem volat `bin/shpd-ds ds-upgrade`
  (ne ručně `ALTER TABLE`)

## Doporučené pořadí

1. Nejdřív JSONC změny + `ds-upgrade` na testovacím DS → ověř, že sloupce vznikly
2. Pak `PersonDocument::validate` + testy → ověř, že validace odmítá chyby
3. Pak `PersonsForm` UI → ověř ve frontendu
4. Nakonec dokumentace `.md`

## Otevřené body (ne-blokující)

Tyto věci nejsou součástí tohoto tasku, ale stojí za to si je všimnout — řeší
se v navazujících fázích:

- Provisioner / wizard pro nastavení vlastní firmy při instalaci nového DS
  (zatím manuál: uživatel zakládá firmu a označí `is_own`)
- `OwnCompanyResolver` helper — patří do `docs.core` (Fáze 3)
- Validace, že vlastní firma má vyplněnou adresu sídla (`base_persons_addresses`
  s `address_type = 1`) — zatím benigní, kontrola se dělá až při sestavování
  snapshotu dokladu
