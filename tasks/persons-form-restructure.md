# Task: Restrukturalizace editačního formuláře osob

**Stav:** hotovo

## Status / Cíl

Zpřehlednit editační formulář osoby (`base_persons_persons`):

- Přidat tab **Nastavení** pro pole, která uživatel běžně nepotřebuje
  (kód osoby, DIČ pro DPH, zápis v OR, vlastní firma, splatnost).
- Zrušit tab **Kontaktní údaje** a jeho pole (email/phone/web) přesunout
  do tabu **Základní údaje** jako novou sekci „Kontakt" na konec.
- Některé sekce udělat se dvěma poli vedle sebe (přes `inline()`):
  IČO+DIČ, datum narození+rodné číslo, email+telefon.
- Skrýt pole „Celý název" u fyzické osoby (je dopočítané z first/last_name
  v `recalculate()`, uživatel ho nemá ručně editovat).
- Globálně rozšířit sub-modaly (Adresa, Kontakt, Bankovní účet) ze 720 px
  na 960 px, aby se vešly širší layouty (např. číslo popisné + orientační
  vedle sebe v Adrese).

## Návaznost

- `docs/edit-forms.md` — kompletní PRD editačních formulářů (`TableForm`,
  `TabBuilder`, sekce, `inline()`, velikosti modalu).
- `tasks/persons-is-own-extension.md` — předchozí rozšíření Osob
  (`is_own`, `court_registration`).
- `modules/docs/core/extensions/base_persons_persons.jsonc` — table extension
  přidávající `payment_term_days` (sloupec patří k Osobě, ale je vlastněný
  modulem `docs.core`).

## Scope

### V rozsahu

- Přepis `PersonsForm::buildFormDefinition` — nová struktura tabů a sekcí.
- Změna `SMALL_WIDTH` v `FormDialog.svelte` ze 720 px na 960 px.
- Aktualizace `docs/edit-forms.md` sekce 9 (popisy velikostí modalu).

### Mimo rozsah

- Změny v `JSONC` definicích sub-formulářů (Adresy, Kontakty, Bankovní
  účty) — vystačí si s tím, že se globálně rozšíří modal.
- Nový enum velikostí modalu (`small`/`medium`/`large`) — Anna preferuje
  jen rozšířit globální `SMALL_WIDTH`, ať je to všude stejné.
- Změny DB / tabulkové definice — pole zůstávají, jen se přerovnává UI.
- Refaktor `recalculate()` — `full_name` se u Person už dnes počítá
  z `first_name + last_name`, nic se na tom nemění.
- i18n — labely sekcí zůstávají hard-coded česky (jako dnes); jednotná
  i18n vrstva pro PHP texty je samostatný úkol.

## Výsledný layout

```
Tab 1: Základní údaje
  Sekce (bez titulu)
    person_type
    full_name                            ← hidden pro Person
  Sekce „Identifikace firmy"             ← jen Company
    inline(company_id, tax_id)
  Sekce „Jméno"                          ← jen Person
    title_before
    inline(first_name, middle_name, last_name)
    title_after
  Sekce „Osobní údaje"                   ← jen Person
    inline(birth_date, national_id)
    id_card_number
  Sekce „Kontakt"                        ← vždy
    inline(email, phone)
    web

Tab 2: Kontakty       (subtable, beze změny)
Tab 3: Adresy         (subtable, beze změny)
Tab 4: Bankovní účty  (subtable, beze změny)
Tab 5: Přílohy        (attachments, beze změny)

Tab 6: Nastavení                         ← nový, úplně na konci
  Sekce „Identifikace"
    person_id
  Sekce „Identifikace firmy - doplňující" ← jen Company
    vat_id
    court_registration
    is_own
  Sekce „Obchodní podmínky"              ← vždy
    payment_term_days
```

Pravidla viditelnosti zachovaná z dnešního formuláře:

- `$isPerson` ↔ `person_type === PersonType::Person`
- `$isCompany` ↔ `person_type === PersonType::Company`
- `$isUndefined` ↔ `person_type === null || PersonType::Undefined`
- Sekce „Identifikace firmy" a „Identifikace firmy - doplňující" mají
  `hidden: $isPerson || $isUndefined`.
- Sekce „Jméno" a „Osobní údaje" mají `hidden: $isCompany || $isUndefined`.
- `full_name` má `required: $isCompany, hidden: $isPerson` (u Person se
  dopočítá v `recalculate()`).

Sekce „Kontakt" a tab „Nastavení" se zobrazují **vždy** (nezávisle na typu).

## Změny souborů

### 1. `modules/base/persons/src/PersonsForm.php`

Přepiš metodu `buildFormDefinition` tak, aby produkovala novou strukturu.
Header info (`buildHeaderInfo`) a `recalculate` zůstávají beze změny —
jen layout polí v `buildFormDefinition`.

Kanonická implementace (drž se přesně tohoto vzoru — pořadí sekcí,
`hidden` flagy, `inline()` skupiny):

```php
public function buildFormDefinition(array $data, bool $isNew): FormDefinition
{
    $personType  = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
    $isCompany   = $personType === PersonType::Company;
    $isPerson    = $personType === PersonType::Person;
    $isUndefined = $personType === null || $personType === PersonType::Undefined;

    $personTypeOptions = $this->resolvePersonTypeOptions();

    // ── Tab: Základní údaje ──────────────────────────────────────────────
    $basic = $this->tab('basic', 'Základní údaje')
        ->section()
            ->col()
                ->select(
                    'person_type',
                    options: $personTypeOptions,
                    triggers: 'reload',
                    required: true,
                )
                ->input(
                    'full_name',
                    required: $isCompany,
                    hidden: $isPerson,
                )

        ->section(title: 'Identifikace firmy', hidden: $isPerson || $isUndefined)
            ->col()
                ->inline()
                    ->input('company_id')
                    ->input('tax_id')
                ->endInline()

        ->section(title: 'Jméno', hidden: $isCompany || $isUndefined)
            ->col()
                ->input('title_before')
                ->inline()
                    ->input('first_name', required: $isPerson)
                    ->input('middle_name')
                    ->input('last_name', required: $isPerson)
                ->endInline()
                ->input('title_after')

        ->section(title: 'Osobní údaje', hidden: $isCompany || $isUndefined)
            ->col()
                ->inline()
                    ->date('birth_date')
                    ->input('national_id')
                ->endInline()
                ->input('id_card_number')

        ->section(title: 'Kontakt')
            ->col()
                ->inline()
                    ->input('email', inputType: 'email')
                    ->input('phone', inputType: 'tel')
                ->endInline()
                ->input('web', inputType: 'url')
        ->build();

    // ── Subtable a attachments taby ──────────────────────────────────────
    $contacts     = $this->subtableTab('contacts',      'Kontakty',      'base_persons_contacts',      'person', 'base.persons.contacts');
    $addresses    = $this->subtableTab('addresses',     'Adresy',        'base_persons_addresses',     'person', 'base.persons.addresses');
    $bankAccounts = $this->subtableTab('bank_accounts', 'Bankovní účty', 'base_persons_bank_accounts', 'person', 'base.persons.bank_accounts');

    // ── Tab: Nastavení (úplně na konci, za Přílohami) ───────────────────
    $settings = $this->tab('settings', 'Nastavení')
        ->section(title: 'Identifikace')
            ->col()
                ->input('person_id', required: true)

        ->section(title: 'Identifikace firmy - doplňující', hidden: $isPerson || $isUndefined)
            ->col()
                ->input('vat_id')
                ->input('court_registration')
                ->checkbox('is_own')

        ->section(title: 'Obchodní podmínky')
            ->col()
                ->number('payment_term_days')
        ->build();

    return new FormDefinition(
        table: $this->table,
        title: 'Osoba',
        titleNew: 'Nová osoba',
        tabs: [$basic, $contacts, $addresses, $bankAccounts, $this->attachmentsTab(), $settings],
        fullSize: true,
    );
}
```

Poznámky:

- Tab `Kontaktní údaje` (`$contact`) zmizí úplně — kód i pole se
  přesouvají jinam.
- `full_name` byl dřív `readOnly: $isPerson` (uživatel ho viděl, ale
  nemohl ho editovat). Teď je `hidden: $isPerson` — pole zůstává v DOM
  (formuláře vyžadují, aby data prošla validátorem), ale uživatel ho
  nevidí. `recalculate()` mu hodnotu dál dopočítá.
- `person_id` v Nastavení je `required: true` — stejně jako bylo
  v Základních údajích.
- `payment_term_days` (smallint, default 14, nullable) je table extension
  z `modules/docs/core/extensions/base_persons_persons.jsonc` — funguje
  jako kterýkoli jiný sloupec, žádné speciální zacházení.
- Pořadí tabů: `basic → contacts → addresses → bank_accounts → attachments → settings`.
  Nastavení je úmyslně **za** Přílohami (Anna potvrdila).

`buildHeaderInfo` a `recalculate` ponechej beze změny — fungují
nezávisle na layoutu.

### 2. `frontend/src/components/form/FormDialog.svelte`

Změň konstantu `SMALL_WIDTH` ze 720 px na 960 px:

```ts
// Velikosti modalu pro hlavní (full_size: true) a sub (full_size: false) formuláře.
const LARGE_WIDTH = '1200px';
const LARGE_HEIGHT = '900px';
const SMALL_WIDTH = '960px';   // ← bylo '720px'
```

Žádné další změny v souboru.

### 3. `docs/edit-forms.md`

V sekci **9. fullSize flag — velikost modalu** aktualizuj popis hodnot.
Najdi blok:

```
- `true` → velký modal: šířka `1200px`, výška `min(900px, 90vh)`. Pro hlavní entity (Osoby, Faktury…).
- `false` → malý modal: šířka `720px`, výška dle obsahu (max `90vh`). Pro sub-záznamy (Kontakt, Adresa…).
```

Změň na:

```
- `true` → velký modal: šířka `1200px`, výška `min(900px, 90vh)`. Pro hlavní entity (Osoby, Faktury…).
- `false` → malý modal: šířka `960px`, výška dle obsahu (max `90vh`). Pro sub-záznamy (Kontakt, Adresa, Bankovní účet…).
```

Pokud je šířka 720 px zmíněna i jinde v `docs/edit-forms.md`, oprav i tam.
Grep pro jistotu:

```bash
grep -n "720" docs/edit-forms.md
```

## Hotovo když

- [ ] `cd frontend && npm run build 2>&1` projde bez chyb a warningů
- [ ] `vendor/bin/phpunit 2>&1` projde bez selhání
- [ ] Formulář osoby otevřený nad existující firmou ukazuje:
  - Tab **Základní údaje** se sekcemi: bez titulu (person_type), Identifikace
    firmy (IČO + DIČ vedle sebe), Kontakt (email + telefon vedle sebe, web pod tím)
  - Tab **Nastavení** se sekcemi: Identifikace (kód osoby), Identifikace firmy -
    doplňující (DIČ pro DPH, OR, Vlastní firma), Obchodní podmínky (splatnost)
  - Žádný tab **Kontaktní údaje**
- [ ] Formulář osoby nad existující fyzickou osobou ukazuje:
  - Tab **Základní údaje** se sekcemi: bez titulu (person_type, **bez** full_name),
    Jméno, Osobní údaje (datum narození + rodné číslo vedle sebe, doklad pod tím),
    Kontakt
  - Tab **Nastavení** se sekcemi: Identifikace, Obchodní podmínky (Identifikace
    firmy - doplňující je skryta)
- [ ] Přepnutí `person_type` (recalculate) správně mění viditelnost sekcí
  ve všech tabech (sekce „Identifikace firmy" a „Identifikace firmy - doplňující"
  se objeví/zmizí podle typu).
- [ ] Modal Adresy je teď 960 px široký, čísla popisné a orientační se vejdou
  na jeden řádek (`inline(house_number, orientation_number)` v
  `forms/base_persons_addresses.jsonc`).
- [ ] Modaly Kontakt a Bankovní účet se otevírají v širším formátu (960 px)
  a obsah uvnitř nepřetéká.
- [ ] `docs/edit-forms.md` má aktualizovanou sekci 9 (šířka 960 px).

## Rozhodnutí k designu (potvrzená)

- ✓ **Nový tab „Nastavení" na konec** — úplně za Přílohami. Drží
  málo používaná pole (kód osoby, DIČ pro DPH, OR, vlastní firma,
  splatnost), aby Základní údaje zůstaly přehledné.
- ✓ **Tab Kontaktní údaje zrušen** — email/phone/web přesunuty do
  nové sekce „Kontakt" v Základních údajích (zobrazená vždy, tj.
  i u Company jde za Identifikaci firmy, u Person za Osobní údaje).
- ✓ **`payment_term_days` patří do Nastavení** — viditelný vždy, i pro
  fyzickou osobu (např. OSVČ). Sekce „Obchodní podmínky".
- ✓ **Dvojkolonné rozložení přes `inline()`**, ne přes `->col()->col()`.
  První pole má velký label vlevo, ostatní mini-labely vedle inputu.
  Pole, která mají stát samostatně (id_card_number, web), jsou
  v jednosloupcové sekci pod inline skupinou jako běžný `input()`.
- ✓ **`full_name` u Person `hidden: true`** — místo dnešního `read_only`.
  Pole zůstává v DOM (validátor ho potřebuje), uživatel ho nevidí,
  `recalculate()` mu dál hodnotu dopočítá z `first_name + last_name`.
- ✓ **Sub-modaly globálně 960 px** — místo 720 px. Řeší se v jednom
  místě (`FormDialog.svelte` konstanta `SMALL_WIDTH`), ovlivní všechny
  sub-formuláře (Adresy, Kontakty, Bankovní účty + budoucí). Nepřidává
  se nový enum velikostí.
- ✓ **Název sekce „Identifikace firmy - doplňující"** — ASCII pomlčka
  (ne en-dash), aby case-edity v PHP nestrádaly na exotických znacích.

## Smoke test (po implementaci)

Manuální test ve frontend SPA:

1. Otevři viewer Osoby, otevři existující **firmu** ve formuláři.
2. Ověř, že vidíš taby v pořadí: Základní údaje, Kontakty, Adresy,
   Bankovní účty, Přílohy, Nastavení.
3. V Základních údajích uvidíš sekce: (bez titulu) s `person_type`
   a `full_name`, Identifikace firmy s IČO + DIČ vedle sebe, Kontakt
   s email + telefon vedle sebe a web pod nimi.
4. V Nastavení uvidíš sekce: Identifikace (kód osoby), Identifikace
   firmy - doplňující (DIČ pro DPH, OR, Vlastní firma), Obchodní
   podmínky (splatnost).
5. Přepni `person_type` na **Fyzická osoba** (přes select v Základních
   údajích, který má `triggers: 'reload'`). Po recalculate ověř:
   - Sekce Identifikace firmy zmizí.
   - Objeví se Jméno a Osobní údaje.
   - `full_name` zmizí (hidden).
   - V Nastavení sekce Identifikace firmy - doplňující zmizí; zbyde
     jen Identifikace a Obchodní podmínky.
6. V tabu Adresy klikni Přidat. Ověř, že modal je 960 px široký a
   pole `house_number` + `orientation_number` jsou na jednom řádku.
7. V tabu Kontakty klikni Přidat. Ověř, že modal je 960 px (širší než
   předtím) a obsah uvnitř není rozhozený.
8. Vyplň novou firmu od začátku, ulož přes „V pořádku". Ověř, že záznam
   se uloží a formulář se zavře (nebo zobrazí v read-only podle
   doc-state transition).

## Doporučené pořadí prací

1. Nejdřív `PersonsForm.php` — přepiš `buildFormDefinition`.
   `vendor/bin/phpunit 2>&1` po každé větší změně. Existující testy
   by měly projít beze změny (recalculate logika se neměnila).
2. Pak `FormDialog.svelte` — jednořádková změna konstanty.
   `cd frontend && npm run build 2>&1`.
3. Manuální smoke test ve frontendu.
4. Dokumentace `docs/edit-forms.md`.
5. Commit. Doporučená commit message:

   ```
   Restructure persons edit form: Settings tab, merged Contact section, wider sub-modals

   - Persons form: drop "Contact details" tab, merge email/phone/web into "Contact"
     section at the end of Basic details (always visible).
   - New "Settings" tab (last, after Attachments) holds person_id, VAT/court
     registration, is_own flag, and payment_term_days.
   - Two-column layouts via inline(): company_id + tax_id, birth_date +
     national_id, email + phone, plus the existing inline name group.
   - Hide full_name for Person type (computed in recalculate from first_name +
     last_name; user-facing fields are first/middle/last_name).
   - Widen sub-modals globally from 720px to 960px so Address fields fit
     (house_number + orientation_number on one row, etc).
   - Update docs/edit-forms.md section 9 to reflect new SMALL_WIDTH.
   ```

## Konvence

- PHP 8.3 strict_types, snake_case v JSONC / wire formátu, camelCase
  v `TabBuilder` API.
- UI texty česky, kód a komentáře anglicky.
- Po úpravě JSONC tabulky volat `bin/shpd-ds ds-upgrade` (v tomto úkolu
  ale neměníme tabulky, jen layout — `ds-upgrade` netřeba).
- `dibi` placeholdery `%i`/`%s`, ne `?` (pro tento task irelevantní —
  netáhneme nové dotazy).
