# Task: Restrukturalizace editačního formuláře položek

## Status / Cíl

Sladit editační formulář položky (`economy_items`) s ostatními formuláři
(Osoby, doklady) — dnes je jako jediný ještě v „starém" stavu, kde se
skupiny polí oddělují přes `separator()` a `code` sedí nahoře v Základních
údajích.

- Přidat tab **Nastavení** (na konec, za Přílohy) a přesunout do něj
  vstup `code`.
- V tabu **Základní údaje** nahradit `separator()` skupiny plnohodnotnými
  **sekcemi** (`section(title: …)`) — Klasifikace, Cena, Platnost — stejně
  jako to dělá `PersonsForm`. Vše zůstává v jednom sloupci.

## Návaznost

- `docs/edit-forms.md` — kompletní PRD editačních formulářů (`TableForm`,
  `TabBuilder`, sekce, tab Nastavení, velikost modalu). Sekce 6 (grid /
  sekce), 11 (`TableForm`), 21 (HeaderInfo).
- `tasks/persons-form-restructure.md` — kanonický vzor pro tenhle typ
  úpravy (sekce místo separátorů, tab Nastavení na konci). **Pozor:** ten
  task ještě používá `fullSize: true` ve `FormDefinition` — ten flag byl
  mezitím z celého pipeline odstraněn, v tomto tasku se NEpoužívá.
- `modules/economy/items/src/ItemsForm.php` — soubor, který se mění.

## Scope

### V rozsahu

- Přepis `ItemsForm::buildFormDefinition` — nová struktura tabů a sekcí.
- Pořadí tabů: `basic → description → attachments → settings`.

### Mimo rozsah

- `buildHeaderInfo` a `recalculate` — zůstávají **beze změny**, fungují
  nezávisle na layoutu.
- Změny DB / tabulkové definice — žádné nové sloupce, jen přerovnání UI.
  `ds-upgrade` není potřeba.
- Resolvery options (`resolveItemKindOptions`, `resolveUnitOptions`,
  `resolveItemTypeOptions`) a helpery hlavičky (`resolveItemKindName`,
  `formatValidityRange`, `formatHeaderDate`) — beze změny.
- Hint u `code` — dnešní hint („Necháte-li prázdné, kód se vygeneruje
  automaticky.") se **zahazuje úplně** (Anna potvrdila: bez hintu).
- i18n — labely sekcí zůstávají hard-coded česky (jako dnes).
- Bug s prázdnou první volbou v select dropdownech — řešen samostatně.

## Výsledný layout

```
Tab 1: Základní údaje
  Sekce (bez titulu)
    name                         ← code už tu NENÍ (přesunut do Nastavení)
  Sekce „Klasifikace"
    item_kind                    (required, triggers: reload)
    item_type                    (readOnly)
    unit                         (required)
  Sekce „Cena"
    sales_price_no_vat
  Sekce „Platnost"
    valid_from
    valid_to

Tab 2: Popis                     (beze změny — textarea description)
Tab 3: Přílohy                   (attachments, beze změny)

Tab 4: Nastavení                 ← nový, úplně na konci
  Sekce „Identifikace"
    code                         (bez hintu, bez required)
```

Vše v tabu Základní údaje zůstává **v jednom sloupci** (`->col()`), jen
se separátory mění na sekce. Žádné `inline()` skupiny — položky mají málo
polí a jedno pod druhým je čitelné.

## Změny souborů

### 1. `modules/economy/items/src/ItemsForm.php`

Přepiš **pouze** metodu `buildFormDefinition`. Zbytek třídy
(`buildHeaderInfo`, `recalculate`, všechny `resolve*` a `format*` helpery)
ponech beze změny.

Kanonická implementace nové metody (drž se přesně tohoto vzoru):

```php
public function buildFormDefinition(array $data, bool $isNew): FormDefinition
{
    // Default unit = pcs ("ks") for new records when nothing was prefilled
    if ($isNew && empty($data['unit']) && $this->db !== null) {
        $row = $this->db->fetchRow(
            "SELECT id FROM core_units WHERE system_code = 'pcs'",
        );
        if ($row !== null) {
            $data['unit'] = (int) $row['id'];
        }
    }

    $itemKindOptions = $this->resolveItemKindOptions();
    $unitOptions = $this->resolveUnitOptions();
    $itemTypeOptions = $this->resolveItemTypeOptions();

    // ── Tab: Základní údaje ──────────────────────────────────────────────
    $basic = $this->tab('basic', 'Základní údaje')
        ->section()
            ->col()
                ->input('name', required: true)

        ->section(title: 'Klasifikace')
            ->col()
                ->select('item_kind',
                    options: $itemKindOptions,
                    required: true,
                    triggers: 'reload',
                )
                ->select('item_type',
                    options: $itemTypeOptions,
                    readOnly: true,
                )
                ->select('unit',
                    options: $unitOptions,
                    required: true,
                )

        ->section(title: 'Cena')
            ->col()
                ->number('sales_price_no_vat')

        ->section(title: 'Platnost')
            ->col()
                ->date('valid_from')
                ->date('valid_to')
        ->build();

    // ── Tab: Popis ───────────────────────────────────────────────────────
    $description = $this->tab('description', 'Popis')
        ->section()
            ->col()
                ->textarea('description')
        ->build();

    // ── Tab: Nastavení (úplně na konci, za Přílohami) ───────────────────
    $settings = $this->tab('settings', 'Nastavení')
        ->section(title: 'Identifikace')
            ->col()
                ->input('code')
        ->build();

    return new FormDefinition(
        table: $this->table,
        title: 'Položka',
        titleNew: 'Nová položka',
        tabs: [$basic, $description, $this->attachmentsTab(), $settings],
    );
}
```

Poznámky:

- `code` byl dřív první pole v Základních údajích s `hint:`. Teď je
  v Nastavení **bez hintu** a **bez required** (kód se generuje automaticky,
  když je prázdný — to řeší `beforeSave` v Document vrstvě, ne hint).
- `name` zůstává jako jediné pole v úvodní beztitulkové sekci.
- Sekce Klasifikace / Cena / Platnost odpovídají 1:1 původním
  separátorům — stejná pole, stejné pořadí, stejné flagy.
- Pořadí tabů: `basic → description → attachments → settings`.
  Nastavení je úmyslně **za** Přílohami (konzistentně s `PersonsForm`).
- `FormDefinition` se konstruuje **bez** `fullSize` — ten parametr už
  v signatuře není (velikost modalu se škáluje globálně přes clamp,
  viz `docs/edit-forms.md` kap. 9).

## Hotovo když

- [ ] `php -l modules/economy/items/src/ItemsForm.php` projde bez chyb
- [ ] `cd frontend && npm run build 2>&1` projde bez chyb a warningů
- [ ] `vendor/bin/phpunit --filter Items 2>&1` projde (pokud existují
      testy pro Items; pre-existující selhání Exchange/Mail ignorovat)
- [ ] Formulář položky otevřený nad existujícím záznamem ukazuje taby
      v pořadí: **Základní údaje, Popis, Přílohy, Nastavení**
- [ ] Tab **Základní údaje** ukazuje sekce (karty s titulkem):
  - bez titulu: `name`
  - Klasifikace: item_kind, item_type (readOnly), unit
  - Cena: sales_price_no_vat
  - Platnost: valid_from, valid_to
- [ ] Pole `code` **není** v Základních údajích — je v tabu **Nastavení**,
      sekce Identifikace, bez hintu
- [ ] Změna `item_kind` (recalculate) dál správně dopočítá `item_type`
      (readOnly select se aktualizuje) — chování `recalculate` se neměnilo
- [ ] Hlavička formuláře (ikona box, název, Druh / Kód / Platí) funguje
      jako dřív — `buildHeaderInfo` se neměnil

## Rozhodnutí k designu (potvrzená)

- ✓ **Do Nastavení jen `code`** — žádná další pole se nepřesouvají.
  Platnost (`valid_from`/`valid_to`) zůstává v Základních údajích jako
  vlastní sekce.
- ✓ **Bez hintu u `code`** — dnešní hint o automatickém generování se
  zahazuje. (Auto-generování kódu se děje v `beforeSave`, ne kvůli hintu.)
- ✓ **Sekce místo separátorů** — Klasifikace / Cena / Platnost se z
  `separator()` mění na `section(title: …)`. Vizuálně karty s pozadím,
  konzistentní s Osobami a doklady.
- ✓ **Jeden sloupec, žádné `inline()`** — položka má málo polí, jedno
  pod druhým je nejčitelnější. Žádné dvojkolonné rozložení.
- ✓ **Tab Nastavení za Přílohami** — stejné umístění jako u `PersonsForm`,
  drží konzistentní UX.

## Smoke test (po implementaci)

Manuální test ve frontend SPA:

1. Otevři viewer Položky, otevři existující položku ve formuláři.
2. Ověř taby v pořadí: Základní údaje, Popis, Přílohy, Nastavení.
3. V Základních údajích uvidíš sekce (karty): bez titulu (jen `name`),
   Klasifikace (druh + typ + jednotka), Cena (prodejní cena bez DPH),
   Platnost (od + do).
4. `code` v Základních údajích **není**.
5. Otevři tab Nastavení — uvidíš sekci Identifikace s polem `code`
   (kód položky), bez hintu pod ním.
6. Změň `item_kind` (select má `triggers: 'reload'`). Po recalculate
   ověř, že se `item_type` (readOnly) dopočítal podle druhu.
7. Vytvoř novou položku od začátku: `name` vyplň, `code` nech prázdný,
   ulož přes „V pořádku". Ověř, že se kód vygeneroval automaticky a
   záznam se uložil.
8. Ověř hlavičku: ikona krabice, název položky, v subtitle Druh / Kód /
   Platí (pokud jsou vyplněné).

## Doporučené pořadí prací

1. Přepiš `buildFormDefinition` v `ItemsForm.php`.
   `php -l modules/economy/items/src/ItemsForm.php` po zápisu.
2. `vendor/bin/phpunit --filter Items 2>&1` (recalculate logika se
   neměnila, testy by měly projít).
3. `cd frontend && npm run build 2>&1`.
4. Manuální smoke test ve frontendu.
5. Commit. Doporučená commit message:

   ```
   feat(items): restructure edit form with sections and Settings tab

   - Replace separator() groups (Klasifikace, Cena, Platnost) with proper
     section(title:) cards, matching PersonsForm and document forms.
   - Move `code` input to a new Settings tab (last, after Attachments),
     dropping the auto-generation hint (handled in beforeSave anyway).
   - Tab order: basic, description, attachments, settings.
   - buildHeaderInfo and recalculate unchanged.
   ```

## Konvence

- PHP 8.3 strict_types, snake_case v JSONC / wire formátu, camelCase
  v `TabBuilder` API.
- UI texty česky, kód a komentáře anglicky.
- Netáhneme nové DB dotazy ani neměníme tabulky → `ds-upgrade` netřeba.
- Mění se jediný soubor (`ItemsForm.php`), jediná metoda
  (`buildFormDefinition`).
