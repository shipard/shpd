# Task: Mobilní formuláře — inline skupiny pod sebe (fáze 3b)

## Status / Cíl

Pokračování responzivních formulářů. Fáze 3a udělala modál fullscreen.
Tato pod-fáze (3b) řeší jediný zbylý layout problém polí na mobilu:
**inline skupiny** (víc polí na jednom řádku — „Platnost od / do",
„Číslo popisné / orientační") se na úzké obrazovce nevejdou vedle sebe.

**Důležité — co 3b NEdělá**: label zůstává **vedle** inputu i na mobilu
(grid `max-content 1fr` v `FormColumn` se nemění). Po ověření na reálném
telefonu se potvrdilo, že label-vedle funguje dobře a label-nad-input by
zbytečně prodloužil dlouhé formuláře (faktury). Takže běžná pole zůstávají
beze změny. Jediná změna je inline skupina.

**Řešení (varianta B2, rozhodnuto z dat)**: na mobilu (≤ 768px) se inline
skupina **rozpadne na samostatná pole pod sebou**, každé se svým labelem
vlevo vedle inputu — splyne s běžnými poli. Na desktopu zůstává inline
vedle sebe beze změny.

Proč B2 a ne wrap mini-labelů: ověření JSONC + `JsoncFormLoader` ukázalo,
že **každý inline element dostane plnohodnotný label** z TableDefinition
(`$label = $elData['label'] ?? $col->name`, JsoncFormLoader ~ř. 211).
Tedy `valid_from` → „Platnost od", `valid_to` → „Platnost do" — dva
rovnocenné labely, ne „hlavní + dodatek". Rozpad na normální pola tomu
významově odpovídá: každé pole má svůj label, jako by inline nikdy nebylo.

## Návaznost

- `mobile-forms-phase3a.md` — modál fullscreen. 3b je nezávislá, ale
  logicky navazuje (stejná oblast).
- `mobile-app-chrome-phase1.md` — `layout.svelte.js` (`isMobile`).
  3b ho použije v `FormInline` (strukturní přepnutí, viz níže).
- `mobile-viewer-phase2.md` — precedent pro „JS řídí strukturu, ne jen
  vzhled" (list/detail). Inline rozpad je stejný typ: mění se markup,
  ne jen CSS, proto `isMobile` ze storu, ne media query.
- Dokumentace: `docs/edit-forms.md` (inline elementy), `docs/frontend.md`
  (form komponenty) aktualizovat.

## Klíčová zjištění z kódu (kontext pro implementaci)

1. **`FormColumn`** je `display: grid; grid-template-columns: max-content
   1fr`. Label a input jsou dva *samostatní grid sourozenci* (ne zabaleni),
   takže grid plní řádky: label→track1, input→track2, další label→track1…
   Tím se všechny labely ve sloupci zarovnají na šířku nejširšího.

2. **`FormFieldRow`** emituje přesně dva sourozence: `<label
   class="shpd-form-field-row__label">` + `<div
   class="shpd-form-field-row__input">`. Nic je neobaluje.

3. **`FormInline`** dnes emituje taky dva sourozence: jeden `<label>`
   (z `elements[0].label`) + jeden `<div class="shpd-form-inline">`
   (flex skupina všech polí, kde `elements[1+]` mají mini-labely uvnitř).
   Takže celá inline skupina zabírá v gridu **jeden řádek** (label + flex).

4. **Label `text-align: right`** je definovaný `:global(.shpd-form-field-
   row__label)` ve `FormFieldRow` (global kvůli tomu, že FormInline ho
   taky používá). Na mobilu ho NEMĚNÍME (label zůstává vedle, vpravo
   zarovnaný — jak na desktopu).

5. **Inline elementy nemají `kind: dropdown` ani nic složitého** —
   v JSONC jsou to prosté `input`/`select`/`date`/`number`. Rozpad na
   FormFieldRow je tedy bezpečný (FormFieldRow zvládne všechny tyto typy,
   FormElement už to renderuje).

## Scope

### V rozsahu

- **`FormInline.svelte`** — na mobilu (`layoutStore.isMobile`) renderovat
  místo [jeden label + flex skupina] sérii [label + input] dvojic, jednu
  za každý inner element, jako samostatné grid sourozence. Tím grid
  `FormColumn` naskládá pole pod sebe, každé se svým labelem vlevo.
  Na desktopu beze změny (flex skupina vedle sebe).
- **Import `layoutStore`** do `FormInline`.
- Žádná změna `FormColumn`, `FormFieldRow`, `FormElement`, `FormSection`,
  backendu. Jen `FormInline`.

### Mimo rozsah

- **Label nad input** — zamítnuto (label zůstává vedle, viz cíl).
- **Běžná (ne-inline) pole** — beze změny, fungují na mobilu už dnes
  (FormSection skládá sloupce pod sebe přes vlastní 700px media query).
- **Dlouhé labely** („Datum povinnosti přiznat daň") — to je problém
  délky textu labelu, řeší se zkrácením v definici (i na desktopu), ne
  layoutem. Mimo 3b.
- **Checkbox řádky, separatory, html bloky** — beze změny (mají
  `grid-column: 1/-1`, fungují).
- **`text-align` labelů** — beze změny (vpravo, jak na desktopu).
- **Mini-labely** — na mobilu po rozpadu zanikají (každé pole má velký
  label). Na desktopu zůstávají (flex skupina beze změny).

## Datový tok

```
layoutStore.isMobile  (z fáze 1)
   │
   ▼
FormInline:
   isMobile === false (desktop):
      [<label> elements[0]] [<div.inline> flex: všechna pole + mini-labely]
      → jeden grid řádek, pole vedle sebe (DNEŠNÍ chování)

   isMobile === true (mobil):
      pro každý inner element i:
        [<label> inner.label] [<div.input> input pro inner]
      → N grid řádků, pole pod sebou, každé se svým labelem
        (jako by to byla běžná FormFieldRow pole)
```

Pozn.: na mobilu se `FormInline` chová, jako by inline elementy byly
samostatná pole. Grid `FormColumn` je naskládá automaticky — žádný nový
grid ani `grid-column` trik. Využívá se přesně stávající mechanismus
(label→track1, input→track2, opakovaně).

## Co je potřeba udělat

Jediný soubor: `frontend/src/components/form/FormInline.svelte`.

### 1. Import layoutStore

```js
import { layoutStore } from '../../stores/layout.svelte.js';
```

### 2. Přepsat markup na dvě větve

Stávající markup (jeden label + flex skupina) zůstává jako **desktop
větev**. Přidat **mobil větev**, kde se každý inner element vyrenderuje
jako samostatná label+input dvojice — strukturně shodná s tím, co
emituje `FormFieldRow` (label se třídou `shpd-form-field-row__label`,
input v `<div class="shpd-form-field-row__input">`), aby splynula
s běžnými poli v gridu.

```svelte
{#if !element.hidden}
  {#if layoutStore.isMobile}
    <!-- MOBIL: inline skupina se rozpadne na samostatná pole pod sebou.
         Každý inner element = jedna label+input dvojice, emitovaná jako
         dva grid sourozenci (stejně jako FormFieldRow), takže grid
         FormColumn je naskládá pod sebe a labely zarovná. -->
    {#each element.elements as inner, i (inner.column ?? i)}
      <label class="shpd-form-field-row__label" for={i === 0 ? id : innerId(i)}>
        {inner.label ?? ''}{#if inner.required}<span class="shpd-form-field-row__required">*</span>{/if}
      </label>
      <div class="shpd-form-field-row__input">
        {#if inner.type === 'select'}
          <Select
            id={i === 0 ? id : innerId(i)}
            bind:value={formData[inner.column]}
            options={inner.options ?? []}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
            onchange={() => handleChange(inner)}
          />
        {:else if inner.input_type === 'date'}
          <DateInput
            id={i === 0 ? id : innerId(i)}
            bind:value={formData[inner.column]}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
          />
        {:else if inner.input_type === 'number'}
          <NumberInput
            id={i === 0 ? id : innerId(i)}
            bind:value={formData[inner.column]}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
          />
        {:else}
          <Input
            id={i === 0 ? id : innerId(i)}
            type={inner.input_type ?? 'text'}
            bind:value={formData[inner.column]}
            placeholder={inner.placeholder}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
          />
        {/if}
      </div>
    {/each}
  {:else}
    <!-- DESKTOP: beze změny — jeden velký label + flex skupina. -->
    <label class="shpd-form-field-row__label" for={id}>
      {element.elements[0].label ?? ''}
    </label>
    <div class="shpd-form-inline">
      {#each element.elements as inner, i (inner.column ?? i)}
        <span class="shpd-form-inline__item">
          {#if i > 0}<span class="shpd-form-inline__mini-label">{inner.label ?? ''}</span>{/if}

          {#if inner.type === 'select'}
            <Select
              id={i === 0 ? id : innerId(i)}
              bind:value={formData[inner.column]}
              options={inner.options ?? []}
              required={inner.required ?? false}
              disabled={disabled || inner.read_only === true}
              error={fieldErrors[inner.column] ?? null}
              onchange={() => handleChange(inner)}
            />
          {:else if inner.input_type === 'date'}
            <DateInput
              id={i === 0 ? id : innerId(i)}
              bind:value={formData[inner.column]}
              required={inner.required ?? false}
              disabled={disabled || inner.read_only === true}
              error={fieldErrors[inner.column] ?? null}
            />
          {:else if inner.input_type === 'number'}
            <NumberInput
              id={i === 0 ? id : innerId(i)}
              bind:value={formData[inner.column]}
              required={inner.required ?? false}
              disabled={disabled || inner.read_only === true}
              error={fieldErrors[inner.column] ?? null}
            />
          {:else}
            <Input
              id={i === 0 ? id : innerId(i)}
              type={inner.input_type ?? 'text'}
              bind:value={formData[inner.column]}
              placeholder={inner.placeholder}
              required={inner.required ?? false}
              disabled={disabled || inner.read_only === true}
              error={fieldErrors[inner.column] ?? null}
            />
          {/if}
        </span>
      {/each}
    </div>
  {/if}
{/if}
```

Pozn. k duplikaci: input-typový `{#if}` blok se opakuje v obou větvích.
To je vědomé — větve se liší obalem (grid dvojice vs. flex item
s mini-labelem). Pokud Claude Code uzná za vhodné, lze input vytáhnout
do lokálního Svelte snippetu `{#snippet inputFor(inner, i)}` a volat
v obou větvích, ať se neduplikuje. To je čistší, ale ověř, že snippet
správně předá `bind:value` (Svelte 5 snippety + bind — otestovat na
buildu). Pokud by bind dělal problém, nechat duplikaci (funkční jistota
před elegancí).

### 3. CSS — beze změny

`shpd-form-inline` a `shpd-form-inline__item` styly zůstávají (používá je
desktop větev). Mobil větev používá `shpd-form-field-row__*` třídy, které
jsou už `:global` (z FormFieldRow), takže fungují i emitované z FormInline
— stejný princip, na kterém dnes stojí velký label inline skupiny.

Žádné nové CSS. (Pokud build hlásí nepoužité selektory, není to tento
soubor — `shpd-form-inline*` se pořád používá v desktop větvi.)

### 4. Dokumentace

`docs/edit-forms.md` — u popisu inline elementů přidat poznámku:

```
Na mobilu (≤ 768px) se inline skupina rozpadne na samostatná pole pod
sebou — každý prvek skupiny dostane svůj label vedle inputu, jako běžné
pole. Na desktopu zůstává skupina na jednom řádku (první prvek má velký
label vlevo, další mají mini-labely mezi poli). Řídí `layout.svelte.js`
(`isMobile`) — je to strukturní přepnutí markupu, ne jen CSS, protože
mini-labely a flex skupina vs. grid řádky je rozdíl ve struktuře.
```

`docs/frontend.md` — u `FormInline` v sekci form komponent zmínit totéž
stručně.

### 5. Smoke test

**Desktop** (> 768px):

- Formulář Adresy (Osoby → adresa) — inline „Číslo popisné / orientační"
  vedle sebe na jednom řádku, velký label vlevo, mini-label u druhého.
  Beze změny.
- Formulář s „Platnost od / do" (adresy mají valid_from/valid_to inline)
  — vedle sebe, beze změny.
- Žádná regrese v žádném inline.

**Mobil** (≤ 768px, ~380px):

- Stejný formulář Adresy → inline skupiny se rozpadly: „Číslo popisné"
  na svém řádku (label vlevo, input vedle), „Číslo orientační" na dalším
  řádku (label vlevo, input vedle). Zarovnané s ostatními poli sloupce.
- „Platnost od" / „Platnost do" — dva samostatné řádky, každý se svým
  labelem. Žádný mini-label, žádná flex skupina.
- Labely zarovnané vpravo (jako ostatní), inputy vedle nich, plná zbylá
  šířka. Splývá s běžnými poli — nepoznáš, že to bývalo inline.
- Bind funguje — zápis do „Platnost od" uloží do `valid_from`, „do" do
  `valid_to` (ID mapování `i===0 ? id : innerId(i)` zachováno).
- Required hvězdička (pokud inline pole required) se zobrazí u labelu.
- Validační chyba u inline pole (např. povinné „Číslo popisné" prázdné)
  se zobrazí správně u toho pole.
- Přepni mobil → desktop (rozšiř okno) v otevřeném formuláři → inline
  se vrátí na jeden řádek vedle sebe. Žádný zaseklý rozpad.

**Light i dark** — labely, inputy čitelné v obou (beze změny tokenů).

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů.
- `vendor/bin/phpunit 2>&1` projde (žádné PHP změny).
- Smoke test (sekce 5) projde, desktop bez regrese.
- `docs/edit-forms.md` a `docs/frontend.md` aktualizovány.

## Rozhodnutí k designu (potvrzená)

- ✓ **Label zůstává vedle inputu na mobilu** — grid `max-content 1fr`
  beze změny. Ověřeno na reálném telefonu: vedle funguje, nad-input by
  prodloužilo dlouhé formuláře (faktury). Běžná pole se nemění.
- ✓ **Jediná změna 3b: inline skupiny** — rozpad na pole pod sebou na
  mobilu.
- ✓ **Varianta B2** (rozpad na samostatná pole), ne wrap mini-labelů.
  Rozhodnuto z dat: každý inline element má plnohodnotný label
  z TableDefinition, takže rozpad významově sedí (dva rovnocenné labely,
  ne hlavní + dodatek).
- ✓ **JS přepínání (`isMobile`), ne CSS** — je to strukturní změna
  markupu (flex skupina + mini-labely → grid řádky + velké labely), ne
  jen vzhled. Legitimní použití storu, stejný typ jako viewer list/detail.
  Vědomý odklon od čistě-CSS přístupu 3a.
- ✓ **Mobil větev emituje `shpd-form-field-row__*` třídy** — splyne
  s běžnými poli, využije existující `:global` styly a grid mechanismus.
- ✓ **Desktop větev beze změny** — flex skupina + mini-labely zachovány.
- ✓ **Dlouhé labely mimo rozsah** — to je délka textu, ne layout; řeší
  se v definici sloupce, i na desktopu.

## Mimo rozsah / nezasahujeme

- **`FormColumn`, `FormFieldRow`, `FormElement`, `FormSection`** — beze
  změny. Jen `FormInline`.
- **Label nad input, text-align labelů** — zamítnuto / beze změny.
- **Běžná pole, checkboxy, separatory** — beze změny.
- **Backend, JSONC, TableDefinition** — beze změny (labely se už plní
  automaticky, využíváme to).
- **Dlouhé labely zkrácení** — samostatná drobnost, ne teď.
- **Picker inputy / compact forms** (z roadmapy) — nesouvisí, jindy.
