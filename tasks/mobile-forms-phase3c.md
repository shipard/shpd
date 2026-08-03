# Task: Mobilní footer formulářů — kebab pro vedlejší akce (fáze 3c)

**Stav:** hotovo

## Status / Cíl

Doladění responzivních formulářů. Footer formuláře (`FormStateBar`) má
na mobilu Uložit + N přechodů dokladu (Potvrdit, Archivovat, Smazat,
Ukončit platnost…) vedle sebe. Na úzké obrazovce se nevejdou a uživatel
se k zadním nedostane (footer nescrolluje, resp. scroll je
neobjevitelný a u destruktivních akcí nebezpečný).

**Řešení**: na mobilu (≤ 768px) zůstanou ve footeru viditelná jen
**Uložit** + **hlavní (`done`) přechod**; zbytek jde do **kebab menu (⋮)**
— stejný vzor jako kebab ve vieweru (fáze 2), přes `Popover`. Destruktivní
akce (`archive`/`trash`/`cancelled`) jdou na mobilu do kebabu vždy
(bezpečnost — méně omylů palcem) a v kebabu se barví danger (červeně).

Na desktopu (> 768px) **beze změny** — všechny přechody jako tlačítka
vedle sebe, jak dnes. Kebab je čistě mobilní.

## Návaznost

- `mobile-forms-phase3a.md` — modál fullscreen. POZOR: v 3a jsme dali
  `.shpd-modal__footer > * { flex: 1 }` na mobilu. **`FormStateBar` NENÍ
  `shpd-modal__footer`** — je to samostatná komponenta uvnitř Modal body
  (sticky bottom). Takže `flex: 1` z 3a se jí netýká. (Footer modálu je
  u FormDialogu fakticky prázdný; tlačítka jsou ve `FormStateBar`.) 3a se
  tu needituje.
- `mobile-viewer-phase2.md` — kebab přes `Popover` v top baru. Tady stejný
  vzor, stejná komponenta. Konzistence: stejné `iconMore`, stejné chování
  Popoveru, stejné danger barvení destruktivních položek.
- `Popover.svelte` — klik-mimo, Esc, viewport clamp. Recyklujeme.
- `layout.svelte.js` — `isMobile`. Footer kebab je strukturní přepnutí
  (tlačítka → kebab), proto JS, ne CSS.
- Dokumentace: `docs/edit-forms.md` (FormStateBar / footer), `docs/
  doc-states.md` (přechody — zmínit mobilní zobrazení), `docs/frontend.md`.

## Klíčová zjištění z kódu

1. **`FormStateBar.svelte`** renderuje footer: `Uložit` (pokud
   `!docStates.read_only`) + `{#each transitions}` tlačítko za každý
   přechod. Přechod má `{ state, actionName, stateStyle, close_form }`.

2. **`stateStyle` nese význam** (funkce `variantForStyle`):
   - `'done'` → `primary` (modré) — postup dokladu vpřed (Potvrdit…).
   - `'archive'` / `'trash'` / `'cancelled'` → `danger` (červené) —
     destruktivní / ukončující.
   - ostatní → `secondary` — neutrální přechody.
   Tohle rozdělení JE ta „logika" pro kebab — nemusíme dělit svévolně.

3. **`transitions` může být prázdné** (prostý číselník bez doc_states) —
   pak je jen Uložit, žádný kebab.

4. **`done` přechodů může být víc než jeden** (vzácné, ale možné) — viz
   rozhodnutí níže (všechny `done` zůstanou viditelné, nebo jen první).

## Scope

### V rozsahu

- **`FormStateBar.svelte`** — na mobilu (`layoutStore.isMobile`):
  - Viditelně: Uložit + přechody se `stateStyle === 'done'`.
  - Kebab (⋮): všechny ostatní přechody (`archive`/`trash`/`cancelled`/
    `secondary` i jakýkoli neznámý styl).
  - Kebab přes `Popover`, položky barvené podle `variantForStyle`
    (destruktivní červeně).
  - Kebab se nerenderuje, pokud není co schovat (žádné ne-done přechody).
  - Desktop větev beze změny (všechna tlačítka vedle sebe).
- **Import** `layoutStore`, `Popover`, `iconMore` do `FormStateBar`.
- Nové i18n klíče: `form.moreActions` (aria-label kebabu).

### Mimo rozsah

- **Desktop footer** — beze změny.
- **`shpd-modal__footer` `flex: 1` z 3a** — netýká se (FormStateBar je
  jiná komponenta). Needitujeme.
- **Logika přechodů / `onTransition`** — beze změny, jen se mění, kde se
  tlačítko zobrazí (footer vs. kebab), ne co dělá.
- **`confirm` u destruktivních přechodů** — pokud dnes přechod má confirm,
  řeší se v `onTransition` (FormEditor `handleTransition`). Tady se na to
  nesahá — kebab položka volá stejný `onTransition`, takže confirm
  proběhne stejně. Ověřit v smoke testu.
- **Backend doc_states** — beze změny.
- **Scroll ve footeru** — zamítnutá alternativa, neimplementujeme.

## Datový tok

```
layoutStore.isMobile
   │
   ▼
FormStateBar:
   desktop: [Uložit] [přechod1] [přechod2] … (vše vedle sebe, DNES)

   mobil:
     visibleTransitions = transitions.filter(t => t.stateStyle === 'done')
     kebabTransitions   = transitions.filter(t => t.stateStyle !== 'done')

     [Uložit] [done přechody…]  [⋮ (pokud kebabTransitions.length > 0)]
                                  │
                                  └─ Popover:
                                     {#each kebabTransitions}
                                        položka (danger barva pokud
                                        archive/trash/cancelled)
```

## Co je potřeba udělat

Jediný soubor: `frontend/src/components/form/FormStateBar.svelte`.

### 1. Script — import + rozdělení přechodů

```js
import Button from '../ui/Button.svelte';
import Popover from '../ui/Popover.svelte';
import Icon from '../ui/Icon.svelte';
import { iconMore } from '../../icons.js';
import { layoutStore } from '../../stores/layout.svelte.js';
import { t } from '../../i18n/index.js';

let {
  docStates = null,
  saving = false,
  onSave,
  onTransition,
} = $props();

const showSave = $derived(!docStates || !docStates.read_only);
const transitions = $derived(docStates?.transitions ?? []);

function variantForStyle(stateStyle) {
  if (stateStyle === 'done') return 'primary';
  if (['archive', 'trash', 'cancelled'].includes(stateStyle)) return 'danger';
  return 'secondary';
}

// Mobil: 'done' přechody (postup vpřed) zůstanou viditelné vedle Uložit,
// zbytek (destruktivní + neutrální) jde do kebabu. Na desktopu se
// nepoužije — tam se renderují všechny přechody vedle sebe.
const visibleTransitions = $derived(
  layoutStore.isMobile
    ? transitions.filter(tr => tr.stateStyle === 'done')
    : transitions
);
const kebabTransitions = $derived(
  layoutStore.isMobile
    ? transitions.filter(tr => tr.stateStyle !== 'done')
    : []
);

let kebabOpen = $state(false);
let kebabAnchor = $state(null);

function openKebab(e) {
  kebabAnchor = e.currentTarget;
  kebabOpen = true;
}
function closeKebab() { kebabOpen = false; }

function runTransition(tr) {
  closeKebab();
  onTransition?.(tr.state, tr.close_form ?? false);
}
```

### 2. Markup

```svelte
<div class="shpd-form-state-bar">
  {#if showSave}
    <Button
      label={t('common.save')}
      variant="primary"
      loading={saving}
      disabled={saving}
      onclick={onSave}
    />
  {/if}

  {#each visibleTransitions as tr (tr.state)}
    <Button
      label={tr.actionName}
      variant={variantForStyle(tr.stateStyle)}
      disabled={saving}
      onclick={() => onTransition?.(tr.state, tr.close_form ?? false)}
    />
  {/each}

  {#if kebabTransitions.length > 0}
    <button
      type="button"
      class="shpd-form-state-bar__kebab-btn"
      onclick={openKebab}
      disabled={saving}
      aria-label={t('form.moreActions')}
    >
      <Icon icon={iconMore} size="md" />
    </button>
  {/if}
</div>

{#if kebabOpen}
  <Popover open={true} anchor={kebabAnchor} placement="top" onClose={closeKebab}>
    <div class="shpd-form-state-bar__kebab-menu">
      {#each kebabTransitions as tr (tr.state)}
        <button
          type="button"
          class="shpd-form-state-bar__kebab-item"
          class:shpd-form-state-bar__kebab-item--danger={variantForStyle(tr.stateStyle) === 'danger'}
          onclick={() => runTransition(tr)}
        >
          {tr.actionName}
        </button>
      {/each}
    </div>
  </Popover>
{/if}
```

Pozn. `placement="top"` — footer je dole, kebab se musí otevřít nahoru.
Ověřit, že `Popover` placement="top" funguje (viewport clamp by to měl
zvládnout i bez explicitního placementu, ale top je správný hint).

### 3. CSS — kebab tlačítko + menu

Stávající `.shpd-form-state-bar` zůstává. Přidat:

```css
  .shpd-form-state-bar__kebab-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    color: var(--shpd-color-text);
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
    transition: background-color 0.15s;
  }
  .shpd-form-state-bar__kebab-btn:hover {
    background-color: var(--shpd-color-bg-hover);
  }
  .shpd-form-state-bar__kebab-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Kebab menu — položky v Popoveru (světlé pozadí). Stejný vzor jako
     kebab ve vieweru (fáze 2). */
  .shpd-form-state-bar__kebab-menu {
    display: flex;
    flex-direction: column;
    min-width: 180px;
    padding: 4px 0;
  }
  .shpd-form-state-bar__kebab-item {
    text-align: left;
    padding: 10px 14px;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }
  .shpd-form-state-bar__kebab-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }
  /* Destruktivní přechody (Archivovat, Smazat, Ukončit platnost) červeně —
     uživatel si uvědomí závažnost i bez velkého tlačítka. */
  .shpd-form-state-bar__kebab-item--danger {
    color: var(--shpd-color-danger);
  }
```

Pozn. — `position: sticky; bottom: 0` na `.shpd-form-state-bar` zůstává.
Na mobilu (fullscreen modál z 3a) to drží footer u spodního okraje, což
je správně. Kebab Popover se otevře nad něj (placement top).

### 4. i18n

`cs.js`: `'form.moreActions': 'Další akce',`
`en.js`: `'form.moreActions': 'More actions',`

`npm run check:i18n` musí projít.

### 5. Dokumentace

`docs/edit-forms.md` — u `FormStateBar` / footer přidat:

```
Na mobilu (≤ 768px) footer zobrazuje jen Uložit + hlavní (`done`)
přechod; ostatní přechody (destruktivní `archive`/`trash`/`cancelled`
i neutrální) jdou do kebab menu (⋮) přes Popover. Destruktivní položky
se v kebabu barví červeně. Na desktopu zůstávají všechny přechody jako
tlačítka vedle sebe. Řídí `layout.svelte.js` (`isMobile`).
```

`docs/doc-states.md` — krátká poznámka, že `stateStyle` určuje i mobilní
umístění (done = viditelné, ostatní = kebab).

### 6. Smoke test

**Desktop** (> 768px):

- Faktura s více přechody (Potvrdit, Archivovat, Storno…) → všechna
  tlačítka vedle sebe ve footeru, jak dnes. Žádný kebab. Žádná regrese.

**Mobil** (≤ 768px):

- Faktura (fullscreen modál) → footer dole: Uložit + Potvrdit (done)
  viditelně. Vpravo ⋮ kebab.
- Klik na ⋮ → Popover se otevře NAD footerem (ne pod, neutéká z obrazovky),
  obsahuje Archivovat / Storno / Ukončit platnost. Archivovat a Storno
  červeně (danger).
- Klik na položku v kebabu (např. Archivovat) → kebab se zavře, přechod
  proběhne (stejně jako tlačítko na desktopu). Pokud má přechod confirm,
  confirm se zobrazí.
- Doklad bez `done` přechodu (jen destruktivní) → viditelně jen Uložit,
  vše ostatní v kebabu.
- Prostý číselník bez transitions → jen Uložit, žádný kebab (⋮ se
  nerenderuje).
- Read-only doklad (`docStates.read_only`) → žádné Uložit; přechody (pokud
  jsou) podle stejného pravidla (done viditelně, zbytek kebab). Ověřit,
  že prázdný footer (žádné Uložit, žádné done, jen kebab) vypadá OK.
- saving stav → Uložit má loading, ostatní tlačítka i kebab ⋮ disabled.
- Přepni mobil → desktop (rozšiř okno) → kebab zmizí, všechny přechody
  zase vedle sebe. Žádný zaseklý stav.

**Light i dark** — kebab tlačítko (border), Popover menu, danger položky
čitelné v obou.

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů.
- `npm run check:i18n` projde (`form.moreActions`).
- `vendor/bin/phpunit 2>&1` projde (žádné PHP změny).
- Smoke test (sekce 6) projde, desktop bez regrese.
- `docs/edit-forms.md`, `docs/doc-states.md`, `docs/frontend.md`
  aktualizovány.

## Rozhodnutí k designu (potvrzená)

- ✓ **Kebab, ne scroll** — scroll ve footeru je neobjevitelný a u
  destruktivních akcí nebezpečný. Kebab je explicitní.
- ✓ **Viditelně: Uložit + `done` přechod** — nejčastější akce (uložit +
  postup vpřed). Počet viditelných tlačítek řízen významem (`stateStyle`),
  ne fixním číslem — vejde se vždy (Uložit + obvykle jeden done).
- ✓ **Kebab: zbytek** — destruktivní (`archive`/`trash`/`cancelled`) +
  neutrální (`secondary`) přechody.
- ✓ **Destruktivní vždy do kebabu (na mobilu)** — bezpečnost, méně omylů
  palcem. V kebabu barveny červeně (danger), ať je závažnost jasná.
- ✓ **Jen mobil** — desktop beze změny (je tam místo). Kebab je čistě
  mobilní (`isMobile`).
- ✓ **`Popover`, placement top** — recykluje vzor z vieweru (fáze 2),
  otevírá se nad footerem (footer je dole).
- ✓ **JS (`isMobile`), ne CSS** — strukturní přepnutí (tlačítka → kebab),
  ne jen vzhled. Konzistentní s viewerem / 3b.
- ✓ **`done` přechody všechny viditelné** — pokud by jich bylo víc (vzácné),
  zůstanou všechny venku (jsou to postupy vpřed, primární). Nelimitujeme
  na první; v praxi bývá jeden.

## Mimo rozsah / nezasahujeme

- **Desktop footer** — beze změny.
- **`onTransition` / `handleTransition` logika** — beze změny.
- **`confirm` u přechodů** — beze změny (kebab volá stejný handler).
- **Backend, doc_states** — beze změny.
- **Scroll footeru** — zamítnuto.
- **`shpd-modal__footer` z 3a** — jiná komponenta, netýká se.
