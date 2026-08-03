# Task: Sjednocení velikosti editačních modalů

**Stav:** hotovo

## Status / Cíl

Všechny editační modaly mají dnes dvě velikosti řízené flagem `fullSize`
v `FormDefinition`:

- `fullSize: true` → **1200×900** (hlavní entity — Osoby, Doklady, …)
- `fullSize: false` → **960 × auto** (sub-záznamy, Úkoly, číselníky)

V praxi to vedlo k nekonzistencím — Úkoly (top-level záznam z hlavní
navigace) mají dnes malý modal, zatímco Faktury velký. Anna chce, aby
**všechny modaly měly stejnou velikost bez ohledu na počet polí**.

Cíl: odstranit `fullSize` flag z celé pipeline (JSONC → PHP → wire →
frontend) a sjednotit velikost top-level modalu na **1200×900**. Vnořené
modaly (Kontakt v Osobě, Řádek dokladu, dialog z `LookupInput`) zůstanou
vizuálně menší — řeší to **existující depth-shrink mechanismus**
v `Modal.svelte` (každá úroveň vnoření = −30 px na každé straně), žádná
nová logika.

## Návaznost

- `new-forms-01.md` (Fáze nového form layoutu) — `FormDialog`,
  `FormEditor`, `Modal` v současné podobě.
- `form-header-info.md` — Modal aktuálně má `width` a volitelnou `height`
  prop, depth-shrink je už implementovaný.
- Žádná backward compatibility — Nový Shipard je v aktivním vývoji
  (viz `docs/edit-forms.md` kap. 20: „Žádná backward compatibility — staré
  formy bylo nutné mechanicky portovat").

## Rozhodnutí k designu (potvrzená)

- ✓ **Jediná velikost top-level modalu: 1200×900.** Žádný flag, žádné
  rozhodování per-formulář.
- ✓ **Vnořené modaly přes existující depth-shrink.** Modal hloubky 1
  bude 1140×840, hloubky 2 bude 1080×780 atd. — automaticky, bez
  konfigurace.
- ✓ **`fullSize` se odstraňuje z celé pipeline** — `FormDefinition`,
  `JsoncFormLoader`, JSONC soubory, PHP form třídy, wire formát
  (`full_size` z toArray), testy, dokumentace.
- ✓ **`FormDialog` přestane načítat meta** — dnes ho fetchuje jen kvůli
  zjištění `fullSize` flagu. Bez něj nemá důvod. Modal se otevírá
  okamžitě s placeholder titulkem, FormEditor uvnitř načte meta
  asynchronně a přes `onFormLoaded` callback aktualizuje header. Bonus:
  o jeden round-trip míň při otevírání modalu.
- ✓ **`"fullSize"` klíč v JSONC se silently ignoruje.** Žádná
  deprecation chyba v `JsoncFormLoader` — klíč prostě přestane být
  čten. Ručně se smaže z 8 form souborů, kde dnes je.
- ✓ **Prázdný prostor pod krátkým formulářem (Úkol) je akceptovaný
  kompromis.** Konzistence > zaplněnost. Pokud bude vizuálně rušivý,
  vrátíme se k tomu jako samostatný follow-up (viz Mimo rozsah).

## Scope

### V rozsahu

- **Frontend:** `FormDialog.svelte` zjednodušený na fixní velikost,
  odpadá meta fetch.
- **Backend:** odstranění `fullSize` property z `FormDefinition`,
  loader, všech 11 PHP form tříd.
- **JSONC:** odstranění `"fullSize"` klíče z 8 form souborů.
- **Wire formát:** klíč `full_size` mizí z odpovědí `meta`, `save`,
  `recalculate`.
- **Testy:** úprava 6 testů, které kontrolují `$def->fullSize` nebo
  `$arr['full_size']`.
- **Docs:** `docs/edit-forms.md` (kap. 3, 9, 12, 16, 20),
  `docs/edit-forms-cookbook.md` (kap. 13 + zmínky jinde).

### Mimo rozsah

- **Optimalizace pro krátké formuláře** — vertikální vystředění obsahu
  v modalu, smrsknutí výšky na obsah pro short forms, různé layout
  varianty. Necháváme jako follow-up, pokud Anna v provozu zjistí, že
  prázdný prostor pod Úkolem ruší.
- **Změna depth-shrink logiky** — necháváme 30 px na úroveň. Žádná
  konfigurovatelnost.
- **Mobile / responzivní velikost** — modal má dnes
  `max-width: calc(100vw - 2*space-lg)`, fungovat to bude. Tablet/mobile
  layout je samostatné téma.

## Co je potřeba udělat

### 1. Frontend — `FormDialog.svelte`

**Soubor:** `frontend/src/components/form/FormDialog.svelte`

Změny:

1. Odstranit konstanty `LARGE_WIDTH`, `LARGE_HEIGHT`, `SMALL_WIDTH`.
2. Odstranit state `fullSize` a `metaLoaded`.
3. Odstranit funkci `checkFullSize()` a `import { get } from '../../api/client.js';`.
4. Zjednodušit `$effect` — už nevolá `checkFullSize`, jen resetuje
   stavy headeru a dirty:

   ```svelte
   $effect(() => {
     if (open) {
       currentTitle = '';
       currentDocStates = null;
       savedHeaderInfo = null;
       isDirty = false;
     }
   });
   ```

5. V `handleClose` odstranit `metaLoaded = false;` (proměnná zmizela).
6. V renderu změnit `{#if open && metaLoaded}` na `{#if open}` a předat
   Modalu fixní velikost:

   ```svelte
   <Modal
     title={headerTitle}
     open={true}
     onClose={handleClose}
     width="1200px"
     height="900px"
     subtitle={hasHeaderInfo ? subtitleSnippet : undefined}
     iconSlot={hasIcon ? iconSnippet : undefined}
     summary={hasSummary ? summarySnippet : undefined}
     headerExtra={headerExtraSnippet}
   >
     <FormEditor ... />
   </Modal>
   ```

7. Před `headerTitle` derived ponechat fallback na `t('common.loading')` —
   v krátkém okně, než FormEditor zavolá `onFormLoaded`, Modal zobrazí
   „Načítám…" jako titulek. To je správné chování (drží konzistenci s tím,
   jak se modal chová při recalculate / save reload).

### 2. Backend — `src/Core/Form/FormDefinition.php`

Z konstruktoru odstranit parametr `bool $fullSize = false`. Z metody
`toArray()` odstranit řádek:

```php
'full_size'   => $this->fullSize,
```

Není potřeba doplňovat žádný default, klíč prostě zmizí z wire formátu.

### 3. Backend — `src/Core/Form/JsoncFormLoader.php`

Z volání `new FormDefinition(...)` odstranit:

```php
fullSize: $data['fullSize'] ?? false,
```

Klíč `"fullSize"` v JSONC se nadále silently ignoruje (žádná detekce,
žádný warning). PHPDoc komentář s ukázkou JSONC (řádek 18) — odstranit
`"fullSize": false,` z příkladu.

### 4. Backend — PHP form třídy (11 souborů)

V každé třídě najít volání konstruktoru `new FormDefinition(...)` a
smazat řádek `fullSize: true,` nebo `fullSize: false,`.

Soubory:

```
modules/base/persons/src/PersonsForm.php
modules/core/mail/src/IncomingMessagesForm.php
modules/core/units/src/UnitsForm.php
modules/economy/codebooks/src/FiscalYearsForm.php
modules/economy/codebooks/src/VatRegistrationsForm.php
modules/economy/items/src/ItemKindsForm.php
modules/economy/items/src/ItemsForm.php
modules/docs/core/src/NumberSeriesForm.php
modules/docs/core/src/DocRowsForm.php
modules/docs/core/src/DocsHeadsFormBase.php
modules/tasks/core/src/TasksForm.php
```

### 5. JSONC formy (8 souborů)

V každém souboru smazat řádek `"fullSize": false,` (případně `true`).

Soubory:

```
modules/base/persons/forms/base_persons_addresses.jsonc
modules/base/persons/forms/base_persons_bank_accounts.jsonc
modules/base/persons/forms/base_persons_contacts.jsonc
modules/core/alerts/forms/core_alerts_alerts.jsonc
modules/economy/codebooks/forms/economy_codebooks_bank_accounts.jsonc
modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc
modules/economy/codebooks/forms/economy_codebooks_cost_centers.jsonc
modules/economy/codebooks/forms/economy_codebooks_fiscal_months.jsonc
modules/economy/codebooks/forms/economy_codebooks_vat_periods.jsonc
modules/economy/codebooks/forms/economy_codebooks_warehouses.jsonc
```

(Pozn.: výše uvedený výčet je 10 položek — `grep` v plánovací fázi
vrátil 8, ale do scope patří všechny soubory, které dnes obsahují
`"fullSize"`. Před implementací udělej fresh `grep -rln '"fullSize"'
modules/ --include="*.jsonc"`, ať máš aktuální seznam.)

### 6. Testy (6 souborů)

Soubory + co odebrat:

- **`tests/Unit/Core/Form/FormDefinitionTest.php`** — z testovacího
  konstruktoru odebrat `fullSize: true,`. Odstranit assertion
  `$this->assertTrue($arr['full_size']);` a další test cases,
  které explicitně testují `full_size` v `toArray()` výstupu.

- **`tests/Unit/Core/Form/JsoncFormLoaderTest.php`** — z testovací
  JSONC fixture odebrat `"fullSize": false,`. Odebrat assertion
  `$this->assertFalse($def->fullSize);`.

- **`tests/Unit/Core/Form/AutoFormBuilderTest.php`** — odebrat
  `$this->assertFalse((new AutoFormBuilder())->build($def)->fullSize);`.

- **`tests/Unit/Module/Base/Persons/PersonsFormTest.php`** — odebrat
  `$this->assertTrue($def->fullSize);`.

- **`tests/Unit/Module/Docs/Core/DocRowsFormTest.php`** — odebrat
  `$this->assertFalse($def->fullSize);`.

- **`tests/Unit/Module/Docs/Core/DocsHeadsFormTest.php`** — odebrat
  `$this->assertTrue($def->fullSize);`.

Po úklidu zkontrolovat, jestli neztratíme cele testovací case (např.
test, který kontroloval **jen** chování flagu). Pokud ano, test
celý smazat — nemá co testovat.

### 7. Dokumentace — `docs/edit-forms.md`

**Kap. 3 — Kořenová struktura (JSON):**

- Z příkladu JSON odstranit `"full_size": true,`.
- Z tabulky polí odstranit řádek `| full_size | bool | ... |`.
- Z poznámky „Všechny klíče jsou snake_case" odstranit `full_size`
  z výčtu.

**Kap. 9 — `fullSize` flag — velikost modalu:**

Kompletně přepsat. Nový obsah (název kapitoly: **„Velikost modalu"**):

```markdown
## 9. Velikost modalu

`FormDialog.svelte` vždy renderuje formulář v Modal komponentě
(centrovaný popup nad tmavým overlayem) s pevnou velikostí
**1200 × 900 px** (max 90vh na nízkých obrazovkách). Žádný flag,
žádné rozhodování per-formulář — všechny top-level modaly mají
stejnou velikost bez ohledu na počet polí.

### Chování modalu

- **Header** — Modal vlastní header s titulkem (`formDef.title` /
  `formDef.title_new` / `header_info.title`), `FormStateBadge`
  (přes `headerExtra` snippet) a tlačítkem `×` vpravo nahoře.
  FormEditor vlastní header nemá.
- **Body skroluje** — header a `FormStateBar` zůstávají fixní,
  skroluje pouze tělo formuláře. Pro krátké formuláře (Úkol) zůstává
  prázdný prostor pod posledním polem — záměrný kompromis pro
  konzistenci napříč aplikací.
- **Zavření** — `Esc` nebo klik na overlay (mimo kartu modalu) nebo
  tlačítko `×`. Všechny tři způsoby volají stejný `onClose` callback.
- **Body scroll lock** — modal blokuje scrollování stránky pod sebou.

### Vrstvení modalů (Esc handling a depth shrink)

`Modal.svelte` používá module-level stack otevřených modalů. Slouží
dvěma účelům:

**Esc handling** — Esc handler reaguje pouze na modal na vrcholu stacku.
Bez tohoto by Esc v subdialogu Kontaktu zavřel současně Kontakt
i nadřazenou Osobu (oba modaly poslouchají window keydown). Klik na
overlay tento problém nemá — overlay každého modalu zachytí jen kliky
na vlastní plochu. Tlačítko `×` je per-modal element. Esc je ale
globální event, proto vyžaduje stack.

**Depth-based shrink** — každý modal si při `pushModal()` zjistí svoji
hloubku ve stacku (0 = kořenový, 1 = vnořený, atd.). Podle hloubky se
`cardStyle` zmenší o 30 px na každé straně (60 px celkem na šířku
i výšku). Vnořený modal je tak vycentrovaný a všechny strany
rodičovského modalu rovnoměrně vyčnívají — uživatel vidí hierarchii.
Funguje pro libovolnou hloubku vnoření (Doklad → Řádek → Položka =
depth 2 → položka modal je o 120 px užší/nižší než doklad).

Konkrétní velikosti:

| Depth | Šířka × Výška     |
|-------|-------------------|
| 0     | 1200 × 900        |
| 1     | 1140 × 840        |
| 2     | 1080 × 780        |
| 3     | 1020 × 720        |

Mechanismus je generický na úrovni `Modal.svelte` — žádný kontext
o tom, kdo je rodič/dítě. Funguje pro všechny vnořené modaly
(FormSubTable child rows, LookupInput edit/create dialog, budoucí
scénáře).

### Detekce neuložených změn (dirty state)

[zachovat stávající podsekci beze změny]

### Force close — bypass dirty kontroly

[zachovat stávající podsekci beze změny]
```

**Kap. 12 — Deklarativní JSONC definice:**

- Z příkladu JSONC odstranit `"fullSize": false,`.
- Z věty „JSONC source používá **camelCase** klíče (`titleNew`,
  `fullSize`, `readOnly`, …)" odstranit `fullSize`.

**Kap. 16 — Svelte komponenty (tabulka):**

V řádku `FormDialog.svelte` upravit popis. Dnes:

```
| `FormDialog.svelte` | Orchestrátor — načte meta, vybere velikost modalu (large/small), poskytuje header (titulek + badge), drží dirty stav, zobrazí confirm při zavření |
```

Nově:

```
| `FormDialog.svelte` | Orchestrátor — Modal s pevnou velikostí (1200×900), poskytuje header (titulek + badge + subtitle + summary), drží dirty stav, zobrazí confirm při zavření. Meta načítá FormEditor uvnitř. |
```

**Kap. 20 — Historie / Migrace:**

Přidat na konec bullet listu:

```markdown
- **`fullSize` flag odstraněn.** Všechny modaly mají pevnou velikost
  1200×900, vnořené přes existující depth-shrink mechanismus
  (kap. 9). Žádný flag v JSONC ani PHP, žádný `full_size` ve wire
  formátu.
```

**Kap. 21 — HeaderInfo (volitelně):**

Pokud někde zmiňuje `full_size`, smazat zmínku. Při psaní tasku
takový výskyt v kap. 21 nevidím — kontrolu udělej `grep -n
'full_size\|fullSize' docs/edit-forms.md`, ať nic nezbude.

### 8. Dokumentace — `docs/edit-forms-cookbook.md`

**Kap. 13 — `fullSize` — velikost modalu:**

Smazat celou kapitolu. Místo ní krátká poznámka v sekci „Co se
neřeší per-formulář" (jestli existuje, jinak v kapitole o JSONC
základech):

```markdown
### Velikost modalu

Velikost modalu se neřídí per-formulář. Všechny top-level modaly
mají 1200×900, vnořené se automaticky zmenšují přes depth-shrink
(viz `docs/edit-forms.md` kap. 9).
```

**Ostatní kapitoly cookbooku:**

- Smazat `"fullSize": true,` z příkladu kolem řádku 47 a 332.
- Smazat `fullSize: true,` z PHP příkladu kolem řádku 485.
- Ve větě „v JSONC piš `camelCase` (`titleNew`, `inputType`,
  `fullSize`, `foreignKey`, …)" (řádek 506) odstranit `fullSize`.

### 9. `CLAUDE.md`

Pokud `CLAUDE.md` v kořeni zmiňuje `fullSize` nebo `full_size`,
smazat. Při psaní tasku v něm zmínku nevidím, ale udělej
`grep -n 'fullSize\|full_size' CLAUDE.md` před commitem.

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb a warningů
- `vendor/bin/phpunit 2>&1` projde
- `grep -rn 'fullSize\|full_size' src/ modules/ frontend/src/ docs/ tests/ CLAUDE.md` nevrátí žádný výskyt (kromě case-insensitive matchů v komentářích o git historii, pokud nějaké jsou)
- Manuální smoke test (`http://{ip}/{ds-id}/app/`):
  - **Úkoly** — Přidat → modal má 1200×900 (jako Faktury), pole vyplní jen horní část, dolu pod posledním polem prázdný prostor → konzistentní s ostatními
  - **Přijaté faktury** — modal stále 1200×900, žádný regres
  - **Osoby** → Kontakt (FormSubTable) — Kontakt modal je menší než Osoba modal (depth-shrink), všechny strany rodiče rovnoměrně vyčnívají
  - **Doklad** → Řádek → Item (LookupInput edit/create) — třetí úroveň modalu je ještě menší, hierarchie je vizuálně čitelná
  - **Adresa, Bankovní účet** (sub-záznamy osoby) — taky se renderují přes depth-shrink, žádný vizuální regres
  - **Esc handling** — v třetí úrovni Esc zavře jen poslední modal, rodičovské zůstanou
  - **Confirm při zavření** — dirty formulář (Faktura s neuloženou změnou) zobrazí confirm při Esc / overlay click / `×` (existující chování, neměnit)
- Otevírání modalu je o jeden round-trip rychlejší (žádný meta fetch ve FormDialog) — kontrolovatelné v DevTools Network panelu: dnes 2 GET na `/_ui/form/{table}/meta/{id}`, po změně 1

## Implementační pořadí (doporučené)

1. Backend (FormDefinition, JsoncFormLoader, PHP form classes) — sjednoceně v jednom commitu, ať testy padají konzistentně
2. JSONC soubory — sjednoceně v jednom commitu
3. Testy — opravit
4. Frontend (FormDialog) — vlastní commit
5. Smoke test v browseru
6. Docs (`edit-forms.md`, `edit-forms-cookbook.md`, případně `CLAUDE.md`) — poslední commit

Doc update jako poslední, aby odrážel skutečně implementovaný stav,
ne plán.

## Mimo rozsah / nezasahujeme

- **Velikost vnořeného modalu** — nechává se na depth-shrink (30 px /
  úroveň). Žádná konfigurovatelnost.
- **Optimalizace pro krátké formuláře** — vystředění obsahu,
  smrsknutí výšky na obsah, jiný layout. Sleduje se manuálně po
  releasu; pokud bude vizuálně rušivý, přidá se follow-up task.
- **Mobile / responzivní layout** — `Modal.svelte` má
  `max-width: calc(100vw - 2*space-lg)`, na úzkém viewportu se
  zachová. Tablet/mobile UX je separátní téma.
- **Backward compat pro `fullSize` v JSONC** — silently ignorováno.
  Žádný deprecation warning, žádná detekce v `JsoncFormLoader`. Po
  cleanupu form souborů v kroku 5 v žádném JSONC `"fullSize"` nebude.
