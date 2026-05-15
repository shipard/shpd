# Task: Exchange Format — Fáze 3b: Interakce s `_resolve`

## Kontext

Pokračujeme z **Fáze 3a** (`tasks/exchange-format-phase3a.md` — hotovo).
`DocumentExchangePreview` renderuje canonical jako čitelnou fakturu se
status badges (matched ✓ / canCreate + / ambiguous ? / notFound ✗).
V 3a jsou badges **read-only** — uživatel jen vidí stav.

Také je hotový **`EntityPicker.svelte`** jako standalone komponenta
(univerzální search-and-pick z libovolné CRUD tabulky), v 3a postavený
a otestovaný v `EntityPickerDemo.svelte`, ale **nikde produkčně
nezapojený**.

**Fáze 3b promenuje status badges na interaktivní rozhodování:**

- Klik na badge `canCreate` / `ambiguous` / `notFound` otevře
  **rozhodovací popover** s akcemi: "Vytvořit nového" / "Vybrat existujícího"
  / "Přeskočit" (pro řádky).
- "Vybrat existujícího" otevře `EntityPicker` pro odpovídající tabulku.
- User rozhodnutí se akumuluje v `userActions` state v Preview komponentě.
- Tlačítko "Použít" v modálu pošle `userActions` jako součást
  `POST /_mail/extracted-documents/{ndx}/apply` body.
- Backend `applyExtracted` přijme `_resolve` overrides z body, mergne je
  do canonical, předá applieru s `autoCreateMode = "strict"`.
- Tlačítko "Použít" je disabled, dokud zůstávají nerozhodnuté reference
  (UX preemption — applier validation gate je backstop).

Před implementací **přečti**:

- `docs/exchange-format.md` sekce 8 (resolve), 9 (`_resolve` payload),
  10 (apply pipeline) — zejména `userAction` slovník.
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` —
  kompletně. Snippet `statusBadge(resolveBlock)` (~ř. 105) je hlavní
  bod zájmu — z `<span>` → `<button class="shpd-exchange__status">`
  podmínečně pro non-matched.
- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte` —
  kompletně. Drží `data` z `/preview`, sem přijde `userActions` state
  a `canApply` derivace s overhauled logikou.
- `frontend/src/components/ui/EntityPicker.svelte` — interface, filter
  syntax (`filter[<field>]=like:<term>`), API tvar response
  (`Array.isArray(result.data)`).
- `frontend/src/api/exchange.js` — `applyExtractedDocument` má už
  placeholder s `resolveOverrides` parametrem; sem se naváže.
- `src/Api/Controller/AnalysisController.php` — `applyExtracted()` se
  rozšíří o čtení `_resolve` z body.
- `modules/core/exchange/src/Document/DocumentApplier.php` — kompletně
  `reconcile()` a `resolveOne()`. Zejména pochop, jak `userAction`
  hodnoty `null` / `"useExisting:<id>"` / `"create"` / `"skip"`
  fungují per-status. **Žádné applier změny v 3b** — pouze ho voláme
  s vyplněnými userActions.

Vzorové soubory:

- `frontend/src/components/exchange/EntityPickerDemo.svelte` — vzor
  zapojení EntityPicker s mock onSelect callback (3a demo, nyní použijeme
  v produkci).
- `frontend/src/components/form/FormDialog.svelte` — vzor floating
  panel s click-outside / Escape handling. Pokud existuje
  `Popover.svelte` (ne, zatím ne — postavíme), vezmi z FormDialog
  patterns.

## Cíl Fáze 3b

Po dokončení této fáze platí:

- **Klik na status badge** s `status ∈ {canCreate, ambiguous, notFound}`
  otevře malý popover/panel se třemi akcemi:
  - "Vytvořit nového" (pro Party/Item; `userAction = "create"`)
  - "Vybrat existujícího…" → otevře `EntityPicker` → po výběru
    `userAction = "useExisting:<id>"`
  - "Přeskočit" (pouze pro `rows[i].item`; `userAction = "skip"`)
  - Pro `ambiguous` navíc seznam kandidátů — klik = `userAction = "useExisting:<id>"`
- Stav `userActions` se akumuluje v `DocumentExchangePreview` a propaguje
  do modal přes callback prop `onUserActionsChange`.
- Po každém rozhodnutí badge dostane vizuální feedback:
  - `userAction = "create"` → badge zůstává canCreate, ale dostane glyf "✓+"
    (rozhodnutí udělané)
  - `userAction = "useExisting:42"` → badge se vykreslí jako matched (zelený) s tooltipem
    "Bude použito #42"
  - `userAction = "skip"` → badge přepne na šedý/přeškrtnutý, tooltip "Přeskočeno"
- Tlačítko "Použít" v modal footeru:
  - **Aktivní** když všechny non-matched reference mají vyplněný userAction
  - **Disabled** s tooltipem "Některé reference vyžadují rozhodnutí" jinak
- Klik na "Použít" pošle `POST /_mail/extracted-documents/{ndx}/apply`
  s body `{_resolve: <userActions transformed>}`.
- Backend `applyExtracted` přijme body, merge `_resolve.{path}.userAction`
  z client do canonical, předá applieru. autoCreateMode přepne na
  `"strict"` když je `_resolve` v body, jinak `"safe"` (backward compat
  pro CLI / pre-3b klienty).
- Volitelně: `applyOptions` v body přepíše server defaults (`targetDocState`
  primárně, příp. `autoCreateMode` pokud chce klient explicit override).
- Po úspěšném apply: modal zavře, list refresh (jako 3a).
- Po `unresolved_required` z applieru (edge case — race condition mezi
  preview a apply): modal zůstává otevřený, error toast se zobrazí, user
  vidí aktuální preview state s nově nerozhodnutými refs.

## Návaznost

- Závisí na: Fáze 3a (hotovo). `EntityPicker` je už existing, jen ho
  produkčně nasadíme.
- **Fáze 3c (volitelně, později)** — edit canonical hodnot před apply.
  Aktuální plán: 3b interakce eliminují většinu potřeby edit; zbytek se
  řeší přes edit form po apply (Koncept doklad → standard FormEditor).
  3c by řešila pouze edge case "AI extrahovala špatně IČO, chci ho
  opravit před vytvořením partnera".

## Scope

### V rozsahu

#### Frontend — nové komponenty

- **`frontend/src/components/ui/Popover.svelte`** — univerzální floating
  panel.
  - Props: `open` (bool), `anchor` (HTMLElement | null — DOM element,
    relativně ke kterému se positionuje), `placement` ('bottom' | 'top' | 'right' | 'left' —
    default 'bottom'), `onClose`.
  - Slot: dětský content (decision panel uvnitř).
  - Floating logic: position absolute, computed offset z anchor's
    `getBoundingClientRect()`. Pokud by panel přetekl viewport, flip
    na opačnou stranu (basic).
  - Click outside zavírá popover. Escape klávesa také.
  - Focus management: po `open` focus first focusable element uvnitř;
    po close return focus to anchor.
  - Slot pro vlastní content (Svelte 5 default slot nebo `{#snippet}`
    children).

- **`frontend/src/components/exchange/ResolveDecisionPanel.svelte`** —
  obsah popoveru pro rozhodování o jedné referenci.
  - Props: `resolveBlock` (object — `{status, candidates?, ...}` z canonical
    `_resolve`), `referenceKind` ('party' | 'item' | 'bankAccount'),
    `entityTable` (string — `'base_persons_persons'` / `'economy_items'`
    / `'base_persons_bank_accounts'`), `entityDisplayPattern` (function),
    `currentUserAction` (string | null), `onDecide(userAction)`.
  - Render:
    - Pokud `status === 'ambiguous'` a `candidates.length > 0`: seznam
      kandidátů ("Použít #{id}: {name}"), klik = `onDecide("useExisting:<id>")`.
    - Vždy: tlačítko "Vytvořit nového" (jen pro Party/Item; ne pro
      `notFound` u VatCode/Unit, ty tady ani neřešíme).
    - Vždy: tlačítko "Vybrat existujícího…" → otevře EntityPicker (lokální
      `pickerOpen` state).
    - Pro `referenceKind === 'item'` (řádky) navíc: "Přeskočit řádek"
      → `onDecide("skip")`.
    - Pokud má `currentUserAction` ne-null hodnotu: zobrazit drobné
      "Vybráno: …" + "Zrušit" link který volá `onDecide(null)`.
  - `EntityPicker` se mounting přes podmíněný `{#if pickerOpen}` —
    zbytečně neinstaluje DOM tree dokud uživatel nekliknul "Vybrat".

#### Frontend — rozšíření existujících komponent

- **`DocumentExchangePreview.svelte`** — interaktivní rozhodování:
  - **Nová prop**: `userActions` (object) a `onUserActionsChange(next)`
    callback. Default `userActions = {}`, modal je naplňuje.
  - **`statusBadge` snippet rozšířit**: pokud `resolveBlock.status !== 'matched'`
    a komponenta má `onUserActionsChange`, render `<button>` místo
    `<span>`. Klik vyvolá lokální `openDecision(path, resolveBlock, kind, table)`.
  - Klik na badge nastaví lokální `$state` `decisionOpen` na
    `{anchor, path, resolveBlock, kind, table}`. Popover otevře.
  - `path` je tečková cesta v `_resolve` — `"supplier"`, `"customer"`,
    `"supplierBank"`, `"rows[0].item"`, atd. Slouží jako klíč pro
    `userActions` mapování.
  - Reaktivní výpočet "effective status" per reference:

```javascript
function effectiveStatus(path, resolveBlock) {
  const ua = userActions[path] ?? null;
  if (resolveBlock.status === 'matched') return 'matched';
  if (ua === null) return resolveBlock.status; // not yet decided
  if (ua.startsWith('useExisting:')) return 'matchedDecided';
  if (ua === 'create') return 'canCreateDecided';
  if (ua === 'skip') return 'skipped';
  return resolveBlock.status;
}
```

  - Badge CSS modifier `shpd-exchange__status--decided` (general),
    `--matchedDecided` (zelená s "+" overlay), `--canCreateDecided`
    (žlutá s "✓" overlay), `--skipped` (šedá s "⊘" glyfem).
  - Popover je single instance na úrovni komponenty — drží
    `decisionOpen` state, mountuje `<Popover>` + `<ResolveDecisionPanel>`
    jen když `decisionOpen !== null`.

- **`DocumentExchangePreviewModal.svelte`** — drží userActions, řídí
  apply flow:
  - **Nový state**: `let userActions = $state({})`.
  - **Predáno do `<DocumentExchangePreview userActions={userActions}
    onUserActionsChange={(next) => userActions = next} />`**.
  - **`canApply` derived** rozšířený:

```javascript
let canApply = $derived(() => {
  if (!data || data.aiFailed) return false;
  const resolve = data.canonical?._resolve;
  if (!resolve) return true;
  // Check all non-matched references have userAction
  return allDecided(resolve, userActions);
});
```

  - `allDecided` walks `_resolve` and verifies každý non-matched node
    má entry v `userActions` (s ne-null value). Detaily viz
    "Implementace → allDecided logic".
  - **`handleApply`** rozšířený: volá `applyExtractedDocument(extractedNdx, userActions)`,
    forwarduje do `onApply` (rodič — ViewerDetail — sleduje pak refresh).
  - Po `unresolved_required` error: zobrazit toast / message s
    detail + zachovat modal otevřený (uživatel vidí stávající state).
  - Reset `userActions = {}` při změně extractedNdx (otevření jiného
    dokumentu) — to už řeší existující `$effect` se nullováním `data`.

- **`frontend/src/api/exchange.js`** — `applyExtractedDocument`:

```javascript
export async function applyExtractedDocument(extractedNdx, userActions = null) {
  const body = userActions !== null && Object.keys(userActions).length > 0
    ? { _resolve: userActions }
    : {};
  return await post(`/_mail/extracted-documents/${extractedNdx}/apply`, body);
}
```

  - userActions tvar: `{ "supplier": "useExisting:42", "rows[0].item": "create", ... }`.
    Tj. **flat map**: klíč je tečková cesta, hodnota je userAction string.
  - Frontend transformuje na nested `_resolve.{path}.userAction` formát
    který server očekává (ne — server udělá split, viz "Backend"
    sekce). **Flat odeslání je preferované**, server expanduje.

#### Frontend — úprava `ViewerDetail.svelte`

- `handleApplyFromModal(extractedNdx, userActions)` — nový druhý
  parametr. Volá `applyExtractedDocument(extractedNdx, userActions)`
  místo dnešního `post(.../apply, {})`.
- Modal už drží userActions; předá je callbackem do `onApply`.
- Stejně tak `onReject` — žádné changes, dál volá reject endpoint.

#### Backend — `AnalysisController::applyExtracted` rozšíření

- Číst `_resolve` z request body. **Flat tvar**: `{path: userAction, ...}`.
  Pokud body obsahuje `_resolve` non-empty:
  - Validate formát: object, hodnoty jsou stringy nebo null.
  - Expand do nested struktury kompatibilní s applier `_resolve`
    formátem:

```php
private function expandUserActions(array $flat): array
{
    $expanded = [];
    foreach ($flat as $path => $action) {
        if (!is_string($action)) continue;
        // path examples: "supplier", "customer", "supplierBank",
        //   "rows[0].item", "rows[12].item"
        if (str_starts_with($path, 'rows[')) {
            // parse "rows[N].item" → rows[N].item.userAction
            $matches = [];
            if (preg_match('/^rows\[(\d+)\]\.(item|unit|vatCode)$/', $path, $matches)) {
                $idx = (int) $matches[1];
                $field = $matches[2];
                $expanded['rows'][$idx][$field]['userAction'] = $action;
            }
        } else {
            // top-level: supplier, customer, supplierBank, customerBank
            $expanded[$path]['userAction'] = $action;
        }
    }
    return $expanded;
}
```

  - Merge expanded do `$canonical['_resolve']`:

```php
$canonical['_resolve'] = $this->mergeUserActions(
    $canonical['_resolve'] ?? [],
    $expanded,
);
```

    `mergeUserActions` deeply merges, ale jen z `userAction` keys
    (status/candidates/etc. zůstanou autoritativně ze serveru — ale
    teď je applier preview overwrites stejně, takže to není kritické).
- **`autoCreateMode` rozhodnutí**:

```php
$applyOptionsBody = $body['applyOptions'] ?? [];
$autoCreateMode = $applyOptionsBody['autoCreateMode']
    ?? ($clientResolve !== null && $clientResolve !== [] ? 'strict' : 'safe');
$canonical['applyOptions'] = [
    'autoCreateMode' => $autoCreateMode,
    'targetDocState' => (int) ($applyOptionsBody['targetDocState'] ?? 10),
];
```

  - **Default chování zachová Fáze 2 kompatibilitu**: bez `_resolve`
    v body = `safe` (jako dnes).
  - **S `_resolve` v body** (= UI 3b flow) = `strict`.
  - Klient může explicit přepsat přes `applyOptions.autoCreateMode`.

#### i18n

Nové klíče v `frontend/src/i18n/cs.js` a `en.js`:

```
exchange.preview.decide.create        "Vytvořit nového" / "Create new"
exchange.preview.decide.createItem    "Vytvořit novou položku" / "Create new item"
exchange.preview.decide.createParty   "Vytvořit novou osobu" / "Create new party"
exchange.preview.decide.pickExisting  "Vybrat existujícího…" / "Pick existing…"
exchange.preview.decide.skip          "Přeskočit řádek" / "Skip row"
exchange.preview.decide.candidates    "Kandidáti:" / "Candidates:"
exchange.preview.decide.useCandidate  "Použít #{id}" / "Use #{id}"
exchange.preview.decide.selected      "Vybráno: {label}" / "Selected: {label}"
exchange.preview.decide.unselect      "Zrušit výběr" / "Clear selection"
exchange.preview.status.decided.create    "Bude vytvořeno nové (rozhodnuto)" / "Will be created (decided)"
exchange.preview.status.decided.useExisting "Bude použito #{id}" / "Will use #{id}"
exchange.preview.status.decided.skip      "Řádek přeskočen" / "Row skipped"
exchange.preview.apply.disabled       "Některé reference vyžadují rozhodnutí" / "Some references need a decision"
exchange.preview.apply.error.unresolved "Při ukládání se objevily další nerozhodnuté reference. Zobraz znovu a rozhodni je." / "Apply found additional unresolved references. Reopen and decide them."
```

#### Tests

- **Backend** `tests/Unit/Api/Controller/AnalysisControllerApplyExtractedTest.php` (rozšíření):
  - apply s `_resolve` body — `supplier: "useExisting:42"` → applier
    dostane canonical s `_resolve.supplier.userAction = "useExisting:42"`,
    autoCreateMode = strict, save uspěje napárováním na 42.
  - apply s `_resolve` body — `supplier: "create"` → applier vytvoří
    novou osobu z payload, save uspěje.
  - apply s `_resolve` body — `rows[0].item: "skip"` → applier
    zachází s row jako skipped, doklad uložen bez něho.
  - apply s **empty body** (žádný `_resolve`) → zachová Fáze 2 chování
    s autoCreateMode = safe.
  - apply s `applyOptions.autoCreateMode = "liberal"` v body → applier
    dostane liberal (explicit override).
  - apply s invalid `_resolve` shape (např. value je object místo string)
    → graceful skip toho key, ostatní zpracují, nebo 400 — viz "Architektonická
    rozhodnutí → Validate userActions".
- **Frontend** (manuální QA + případně Vitest):
  - Klik na canCreate badge otevře popover.
  - Popover zobrazí 3 možnosti pro Party (Create / Pick / žádný skip),
    4 pro Item (Create / Pick / Skip + skip).
  - "Vybrat existujícího" otevře EntityPicker.
  - Po výběru v EntityPicker se popover zavře, badge dostane decided
    state, userActions se aktualizuje.
  - Tlačítko "Použít" disabled dokud nejsou všechny non-matched rozhodnuty.
  - Po stisku "Použít" se POST .../apply spustí s body `{_resolve: {...}}`.
  - Race condition test: edit canonical v DB mezi preview a apply, klik
    "Použít" vrátí unresolved_required → modal zůstává otevřený s
    error toastem.

### Mimo rozsah

- **Edit canonical hodnot** v náhledu — odložené (potenciální 3c).
  Pokud uživatel zvolí "Vytvořit nového", entita vznikne s AI-extrahovanými
  daty. Pro úpravy: po apply otevři partnera v běžném edit formu.
- **Drag-resize handle** ve split-view modal (odložené z 3a).
- **Bulk decisions** — "vytvoř všechny canCreate" jedním klikem.
  Možná future enhancement, ne v 3b.
- **`Unit` a `VatCode` interakce** — `notFound` u jednotek a DPH kódů
  není v 3b actionable. Applier má fallback (default unit, default
  vatCode dle země); badge zůstane informativní. Pokud bude reálně
  potřeba, přidáme v navazujícím tasku.
- **Popover positioning advanced** — flip na opačnou stranu při overflow
  je v rozsahu (basic), ale ne plný floating-ui-style auto-placement.
- **Click-outside na nested EntityPicker** — když je `EntityPicker`
  otevřený **uvnitř** popoveru, klik mimo EntityPicker (ale uvnitř
  popoveru) by neměl zavřít popover. Detail viz "Architektonická
  rozhodnutí → Nested modal/popover handling".

## Architektonická rozhodnutí

### Flat vs. nested userActions tvar

Frontend drží `userActions` jako **flat map**:

```javascript
{
  "supplier": "useExisting:42",
  "customer": null,                  // resolved jako matched, žádné rozhodnutí potřeba
  "supplierBank": "create",
  "rows[0].item": "useExisting:18",
  "rows[1].item": "skip",
  "rows[2].item": "create"
}
```

Důvody:
1. Snadná update — `userActions = { ...userActions, [path]: action }`.
2. Snadné `allDecided` check — `Object.entries(...).every(...)`.
3. Server expanduje na nested formát pro applier; frontend se nestrachá
   o nested struct.

Server expanze je v `expandUserActions()` (viz "Implementace → Backend").
Vždy plochá → nested transformace, žádný opačný směr potřeba.

### Validace userActions na serveru

`expandUserActions` toleruje:
- Klíče které neodpovídají očekávané sadě (`supplier`, `customer`,
  `supplierBank`, `customerBank`, `rows[\d+].item`) — ignoruje (warning
  do ErrorLogger).
- Hodnoty které nejsou string — ignoruje (warning).
- `useExisting:` s ne-numeric id — projde dál, applier reconcile to
  zachytí (`409 conflict`).

Strict 400 odmítnutí jen pro: body není JSON object, `_resolve` není
object. Jinak best-effort — frontend bug nesmí zablokovat manual recovery.

### Popover nested s EntityPicker (Modal-in-popover)

`EntityPicker` je `Modal` (z 3a). Když user v popoveru klikne "Vybrat
existujícího", popover **NEzavírá** se — místo toho otevře vnitřní Modal
EntityPicker. Click-outside na popover **neměl** by se trigerovat, když
je modal otevřený.

Řešení v `Popover.svelte`: click-outside listener kontroluje, jestli
target je uvnitř `document.body > .shpd-modal` (modal portal). Pokud ano,
ignorovat. Pokud ne, zavřít popover.

Lepší (čistší): popover skryje sám sebe (visibility: hidden) když je
nested modal otevřený, pak ho odhalí po výběru / cancel. To je
implementační detail — volba na implementátorovi.

### Effective status — derivace pro UI

UI hodnota badge "effective status" je derivace:

```
canonical _resolve.path.status × userActions[path]
                                  ─────────────────────────────────
                                  null               → původní status
                                  "useExisting:<id>" → "matchedDecided"
                                  "create"           → "canCreateDecided" / "matchedDecided" (Item)
                                  "skip"             → "skipped"
```

CSS modifier mapování (přidat do `DocumentExchangePreview.svelte`
style):

```
.shpd-exchange__status--matchedDecided
   → green bg (same as matched) + "+" overlay glyph (created mark)
.shpd-exchange__status--canCreateDecided
   → yellow bg (same as canCreate) + "✓" overlay glyph (decided mark)
.shpd-exchange__status--skipped
   → gray bg + "⊘" glyph
```

`statusBadge` snippet upraven aby renderoval správný CSS modifier
podle effective status.

### `allDecided` logic

```javascript
function allDecided(resolve, userActions) {
  // Top-level refs
  for (const key of ['supplier', 'customer', 'supplierBank', 'customerBank']) {
    const block = resolve[key];
    if (!block) continue;
    if (block.status === 'matched') continue;
    if (userActions[key] !== undefined && userActions[key] !== null) continue;
    return false;
  }
  // Rows
  const rows = resolve.rows ?? [];
  for (let i = 0; i < rows.length; i++) {
    const itemBlock = rows[i]?.item;
    if (!itemBlock) continue;
    if (itemBlock.status === 'matched') continue;
    const path = `rows[${i}].item`;
    if (userActions[path] !== undefined && userActions[path] !== null) continue;
    return false;
  }
  // Unit & vatCode v 3b NEovlivňují canApply — applier má fallback default.
  return true;
}
```

Pozn.: Pokud applier vrací `_resolve.supplierBank` jen někdy (jen pokud
supplier má bank), `allDecided` to musí zvládnout — pokud block není
v `_resolve`, není reference. Same pro `customerBank`.

### Reset userActions při změně dokumentu

`DocumentExchangePreviewModal` reaguje na změnu `extractedNdx` přes
`$effect` (z 3a). V `loadPreview()` doplníme:

```javascript
async function loadPreview() {
  loading = true;
  error = null;
  userActions = {};         // ← reset
  const result = await previewExtractedDocument(extractedNdx);
  // ...
}
```

### Backward compatibility — Fáze 2 `safe` mode

CLI / pre-3b klienti volají `POST .../apply` **bez `_resolve` v body**.
Server detekuje absenci a zachová `autoCreateMode = "safe"`. To je
intencionální:

- Fáze 3b UI vždy posílá `_resolve` (i prázdné `{}` pokud user neudělal
  rozhodnutí — což by neměl, ale defenzivně). Pokud body obsahuje
  `_resolve: {}` (empty object), server treats it jako "klient
  3b-aware, ale žádná rozhodnutí" → `autoCreateMode = "strict"` (pre-empt
  any auto-creates).

To znamená rozlišovat `_resolve` chybí (= Fáze 2 client) vs. `_resolve: {}`
(= Fáze 3b client without decisions):

```php
$hasResolveKey = is_array($body) && array_key_exists('_resolve', $body);
$autoCreateMode = $hasResolveKey ? 'strict' : 'safe';
```

UX consequence: pokud user 3b otevře preview a stisk "Použít" bez
jakéhokoliv rozhodování (nebylo třeba — vše matched), server dostane
`{_resolve: {}}` → `autoCreateMode = strict`. Pro all-matched canonical
je strict ekvivalent safe (no canCreate refs to worry about), takže
chování stejné.

## Implementace

### `Popover.svelte`

```svelte
<script>
  // Universal floating panel anchored to a DOM element.
  //
  // Props:
  //   open      boolean
  //   anchor    HTMLElement | null
  //   placement 'bottom' | 'top' | 'right' | 'left'
  //   onClose   () => void
  //
  // Children: default slot (Svelte 5 children prop)
  let {
    open = false,
    anchor = null,
    placement = 'bottom',
    onClose = () => {},
    children,
  } = $props();

  let panelEl = $state(null);
  let position = $state({ top: 0, left: 0 });

  // Reposition when open or anchor changes.
  $effect(() => {
    if (!open || !anchor || !panelEl) return;
    const r = anchor.getBoundingClientRect();
    const pr = panelEl.getBoundingClientRect();
    let top, left;
    switch (placement) {
      case 'top':
        top = r.top - pr.height - 8;
        left = r.left;
        break;
      case 'right':
        top = r.top;
        left = r.right + 8;
        break;
      case 'left':
        top = r.top;
        left = r.left - pr.width - 8;
        break;
      case 'bottom':
      default:
        top = r.bottom + 8;
        left = r.left;
        break;
    }
    // Basic viewport-flip: if panel overflows bottom and placement was
    // 'bottom', try 'top'.
    if (placement === 'bottom' && top + pr.height > window.innerHeight) {
      top = r.top - pr.height - 8;
    }
    // Clamp horizontally to viewport with 8px margin.
    left = Math.max(8, Math.min(left, window.innerWidth - pr.width - 8));
    position = { top, left };
  });

  // Click-outside + Escape close.
  function handleDocClick(event) {
    if (!open || !panelEl) return;
    // Don't close when click is inside the panel or inside any open Modal
    // (EntityPicker is rendered as a Modal portal child of body).
    if (panelEl.contains(event.target)) return;
    const modalAncestor = event.target.closest?.('.shpd-modal');
    if (modalAncestor) return;
    onClose();
  }

  function handleKey(event) {
    if (event.key === 'Escape' && open) onClose();
  }

  $effect(() => {
    if (open) {
      document.addEventListener('click', handleDocClick, true);
      document.addEventListener('keydown', handleKey);
      return () => {
        document.removeEventListener('click', handleDocClick, true);
        document.removeEventListener('keydown', handleKey);
      };
    }
  });
</script>

{#if open}
  <div
    class="shpd-popover"
    bind:this={panelEl}
    style="top: {position.top}px; left: {position.left}px"
    role="dialog"
  >
    {@render children?.()}
  </div>
{/if}

<style>
  .shpd-popover {
    position: fixed;
    z-index: 1000;
    min-width: 240px;
    max-width: 360px;
    background: var(--shpd-color-surface);
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    box-shadow: var(--shpd-shadow-lg, 0 4px 12px rgba(0, 0, 0, 0.15));
    padding: var(--shpd-space-sm);
  }
</style>
```

### `ResolveDecisionPanel.svelte`

```svelte
<script>
  import EntityPicker from '../ui/EntityPicker.svelte';
  import { t } from '../../i18n/index.js';

  let {
    resolveBlock,
    referenceKind = 'party',  // 'party' | 'item' | 'bankAccount'
    entityTable = 'base_persons_persons',
    entityDisplayPattern = (row) => row.name ?? row.full_name ?? `#${row.id}`,
    entitySearchFields = ['full_name'],
    currentUserAction = null,
    onDecide = (action) => {},
  } = $props();

  let pickerOpen = $state(false);

  function chooseCreate() { onDecide('create'); }
  function chooseSkip()   { onDecide('skip'); }
  function chooseUnselect() { onDecide(null); }
  function chooseCandidate(id) { onDecide(`useExisting:${id}`); }

  function handlePickerSelect(row) {
    onDecide(`useExisting:${row.id}`);
    pickerOpen = false;
  }

  let createLabel = $derived(
    referenceKind === 'item'
      ? t('exchange.preview.decide.createItem')
      : t('exchange.preview.decide.createParty'),
  );

  let candidates = $derived(resolveBlock?.candidates ?? []);
</script>

<div class="shpd-decision">
  {#if currentUserAction !== null}
    <div class="shpd-decision__current">
      {t('exchange.preview.decide.selected', { label: currentUserAction })}
      <button type="button" class="shpd-decision__unselect" onclick={chooseUnselect}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
    <hr class="shpd-decision__sep" />
  {/if}

  {#if resolveBlock?.status === 'ambiguous' && candidates.length > 0}
    <div class="shpd-decision__candidates">
      <div class="shpd-decision__heading">{t('exchange.preview.decide.candidates')}</div>
      <ul class="shpd-decision__candidate-list">
        {#each candidates as c (c.id)}
          <li>
            <button type="button" class="shpd-decision__candidate" onclick={() => chooseCandidate(c.id)}>
              {t('exchange.preview.decide.useCandidate', { id: c.id })}: {c.name ?? '—'}
            </button>
          </li>
        {/each}
      </ul>
      <hr class="shpd-decision__sep" />
    </div>
  {/if}

  <div class="shpd-decision__actions">
    <button type="button" class="shpd-decision__action" onclick={chooseCreate}>
      {createLabel}
    </button>
    <button type="button" class="shpd-decision__action" onclick={() => pickerOpen = true}>
      {t('exchange.preview.decide.pickExisting')}
    </button>
    {#if referenceKind === 'item'}
      <button type="button" class="shpd-decision__action shpd-decision__action--danger" onclick={chooseSkip}>
        {t('exchange.preview.decide.skip')}
      </button>
    {/if}
  </div>
</div>

<EntityPicker
  open={pickerOpen}
  tableName={entityTable}
  searchFields={entitySearchFields}
  displayPattern={entityDisplayPattern}
  onSelect={handlePickerSelect}
  onClose={() => pickerOpen = false}
/>

<style>
  .shpd-decision {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: 0.875rem;
  }

  .shpd-decision__current {
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-decision__unselect {
    background: transparent;
    border: 0;
    text-decoration: underline;
    color: inherit;
    cursor: pointer;
    font-size: 0.75rem;
  }

  .shpd-decision__heading {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-muted);
    letter-spacing: 0.5px;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-decision__candidate-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-decision__candidate {
    width: 100%;
    text-align: left;
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: 4px;
    cursor: pointer;
    color: var(--shpd-color-text);
    font-size: 0.8125rem;
  }

  .shpd-decision__candidate:hover {
    background: var(--shpd-color-primary-soft);
  }

  .shpd-decision__sep {
    border: 0;
    border-top: 1px solid var(--shpd-color-border);
    margin: var(--shpd-space-xs) 0;
  }

  .shpd-decision__actions {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-decision__action {
    background: var(--shpd-color-primary-soft);
    border: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: 4px;
    cursor: pointer;
    color: var(--shpd-color-text);
    font-size: 0.875rem;
    text-align: left;
  }

  .shpd-decision__action:hover {
    background: var(--shpd-color-primary);
    color: var(--shpd-color-on-primary, white);
  }

  .shpd-decision__action--danger {
    background: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
  }

  .shpd-decision__action--danger:hover {
    background: var(--shpd-color-danger);
    color: white;
  }
</style>
```

### `DocumentExchangePreview.svelte` — clíčové změny

V `<script>`:

```javascript
import Popover from '../ui/Popover.svelte';
import ResolveDecisionPanel from './ResolveDecisionPanel.svelte';

let {
  canonical = null,
  aiFailed = false,
  wrapper = null,
  userActions = {},
  onUserActionsChange = null,
} = $props();

let decisionOpen = $state(null);
// decisionOpen = { anchor: HTMLElement, path: string, resolveBlock, kind, table, searchFields, displayPattern }

function openDecision(event, path, resolveBlock, kind) {
  if (!onUserActionsChange) return; // 3a read-only mode
  event.preventDefault();
  event.stopPropagation();
  let table, searchFields, displayPattern;
  if (kind === 'party') {
    table = 'base_persons_persons';
    searchFields = ['full_name'];
    displayPattern = (row) => row.full_name ?? row.name ?? `#${row.id}`;
  } else if (kind === 'item') {
    table = 'economy_items';
    searchFields = ['name'];
    displayPattern = (row) => `${row.code ?? '—'} — ${row.name ?? row.code ?? '#' + row.id}`;
  } else if (kind === 'bankAccount') {
    table = 'base_persons_bank_accounts';
    searchFields = ['iban'];
    displayPattern = (row) => row.iban ?? row.account_number ?? `#${row.id}`;
  }
  decisionOpen = {
    anchor: event.currentTarget,
    path,
    resolveBlock,
    kind,
    table,
    searchFields,
    displayPattern,
  };
}

function handleDecide(action) {
  if (!decisionOpen) return;
  const next = { ...userActions };
  if (action === null) {
    delete next[decisionOpen.path];
  } else {
    next[decisionOpen.path] = action;
  }
  onUserActionsChange?.(next);
  decisionOpen = null;
}

function effectiveStatusCssModifier(path, resolveBlock) {
  const sk = statusKey(resolveBlock.status);
  if (resolveBlock.status === 'matched') return sk;
  const ua = userActions[path] ?? null;
  if (ua === null) return sk;
  if (ua === 'create') return resolveBlock.status === 'canCreate' ? 'canCreateDecided' : sk;
  if (ua === 'skip') return 'skipped';
  if (typeof ua === 'string' && ua.startsWith('useExisting:')) return 'matchedDecided';
  return sk;
}

function effectiveStatusLabel(path, resolveBlock) {
  const ua = userActions[path] ?? null;
  if (ua === null) return statusLabel(resolveBlock); // original
  if (ua === 'create') return t('exchange.preview.status.decided.create');
  if (ua === 'skip') return t('exchange.preview.status.decided.skip');
  if (typeof ua === 'string' && ua.startsWith('useExisting:')) {
    const id = ua.slice('useExisting:'.length);
    return t('exchange.preview.status.decided.useExisting', { id });
  }
  return statusLabel(resolveBlock);
}
```

`statusBadge` snippet update:

```svelte
{#snippet statusBadge(resolveBlock, path = null, kind = null)}
  {#if resolveBlock && statusKey(resolveBlock.status)}
    {@const interactive = onUserActionsChange !== null
      && resolveBlock.status !== 'matched'
      && path !== null && kind !== null}
    {@const modifier = path !== null
      ? effectiveStatusCssModifier(path, resolveBlock)
      : statusKey(resolveBlock.status)}
    {@const label = path !== null
      ? effectiveStatusLabel(path, resolveBlock)
      : statusLabel(resolveBlock)}

    {#if interactive}
      <button
        type="button"
        class="shpd-exchange__status shpd-exchange__status--{modifier} shpd-exchange__status--interactive"
        title={label}
        onclick={(e) => openDecision(e, path, resolveBlock, kind)}
      >
        <span class="shpd-exchange__status-glyph">{statusGlyph(modifier)}</span>
      </button>
    {:else}
      <span
        class="shpd-exchange__status shpd-exchange__status--{modifier}"
        title={label}
      >
        <span class="shpd-exchange__status-glyph">{statusGlyph(modifier)}</span>
      </span>
    {/if}
  {/if}
{/snippet}
```

`statusGlyph` rozšířený:

```javascript
function statusGlyph(s) {
  if (s === 'matched') return '✓';
  if (s === 'canCreate') return '+';
  if (s === 'ambiguous') return '?';
  if (s === 'notFound') return '✗';
  if (s === 'matchedDecided') return '✓+';
  if (s === 'canCreateDecided') return '+✓';
  if (s === 'skipped') return '⊘';
  return null;
}
```

Použití `statusBadge` v partyCard a rows:

```svelte
{@render statusBadge(partyResolve, 'supplier', 'party')}
{@render statusBadge(partyResolve, 'customer', 'party')}
{@render statusBadge(resolve?.rows?.[i]?.item, `rows[${i}].item`, 'item')}
```

Pro unit a vatCode v 3b neposíláme `path`/`kind` (badge zůstává read-only):

```svelte
{@render statusBadge(resolve?.rows?.[i]?.unit)}
{@render statusBadge(resolve?.rows?.[i]?.vatCode)}
```

Vykreslení popoveru na konci komponenty:

```svelte
{#if decisionOpen}
  <Popover
    open={true}
    anchor={decisionOpen.anchor}
    placement="bottom"
    onClose={() => decisionOpen = null}
  >
    <ResolveDecisionPanel
      resolveBlock={decisionOpen.resolveBlock}
      referenceKind={decisionOpen.kind}
      entityTable={decisionOpen.table}
      entitySearchFields={decisionOpen.searchFields}
      entityDisplayPattern={decisionOpen.displayPattern}
      currentUserAction={userActions[decisionOpen.path] ?? null}
      onDecide={handleDecide}
    />
  </Popover>
{/if}
```

CSS pro decided modifiers (add to component `<style>`):

```css
.shpd-exchange__status--interactive {
  cursor: pointer;
  border: 0;
  /* button reset */
}

.shpd-exchange__status--matchedDecided {
  background: var(--shpd-color-state-done-bg);
  color: var(--shpd-color-state-done-text);
  /* small "+" overlay to distinguish from plain matched */
}

.shpd-exchange__status--canCreateDecided {
  background: var(--shpd-color-state-concept-bg);
  color: var(--shpd-color-state-concept-text);
  /* "✓" overlay to indicate decided */
  outline: 2px solid var(--shpd-color-state-done-text);
  outline-offset: -2px;
}

.shpd-exchange__status--skipped {
  background: var(--shpd-color-text-muted, #888);
  color: white;
}
```

### `DocumentExchangePreviewModal.svelte` — klíčové změny

```javascript
let userActions = $state({});

function handleUserActionsChange(next) {
  userActions = next;
}

let canApply = $derived(() => {
  if (!data || data.aiFailed) return false;
  return allDecided(data.canonical?._resolve ?? {}, userActions);
});

function allDecided(resolve, ua) {
  for (const key of ['supplier', 'customer', 'supplierBank', 'customerBank']) {
    const block = resolve[key];
    if (!block) continue;
    if (block.status === 'matched') continue;
    if (ua[key] !== undefined && ua[key] !== null) continue;
    return false;
  }
  for (let i = 0; i < (resolve.rows ?? []).length; i++) {
    const block = resolve.rows[i]?.item;
    if (!block) continue;
    if (block.status === 'matched') continue;
    const p = `rows[${i}].item`;
    if (ua[p] !== undefined && ua[p] !== null) continue;
    return false;
  }
  return true;
}

function handleApplyClick() {
  // canApply already enforces UX; just forward.
  onApply(extractedNdx, userActions);
}
```

V template `<DocumentExchangePreview>` použití:

```svelte
<DocumentExchangePreview
  canonical={data.canonical}
  aiFailed={data.aiFailed}
  wrapper={data.wrapper}
  {userActions}
  onUserActionsChange={handleUserActionsChange}
/>
```

Footer tlačítko "Použít":

```svelte
<Button
  label={t('exchange.preview.actions.apply')}
  variant="success"
  disabled={!canApply}
  title={canApply ? null : t('exchange.preview.apply.disabled')}
  onclick={handleApplyClick}
/>
```

Reset userActions na začátku `loadPreview`:

```javascript
async function loadPreview() {
  loading = true;
  error = null;
  userActions = {};   // ← add
  // ...rest unchanged
}
```

### `ViewerDetail.svelte` — úprava handleApplyFromModal

```javascript
import { applyExtractedDocument } from '../../api/exchange.js';

async function handleApplyFromModal(extractedNdx, userActions = null) {
  actionInFlightNdx = extractedNdx;
  try {
    const result = await applyExtractedDocument(extractedNdx, userActions);
    if (result?.success) {
      closePreviewModal();
      onRefresh?.();
    } else {
      // Possibly unresolved_required from race; show toast and keep modal open.
      alert(
        result?.error?.code === 'unresolved_required'
          ? t('exchange.preview.apply.error.unresolved')
          : t('viewer.detail.applyFailed', { msg: translateError(result?.error) }),
      );
    }
  } finally {
    actionInFlightNdx = null;
  }
}
```

### Backend `AnalysisController::applyExtracted` — úprava

V handleru, hned po načtení `extracted_document`:

```php
$body = $request->getBody();
$body = is_array($body) ? $body : [];
$clientResolveFlat = is_array($body['_resolve'] ?? null) ? $body['_resolve'] : null;
$applyOptionsBody = is_array($body['applyOptions'] ?? null) ? $body['applyOptions'] : [];

// ... existing checks (status, ai_failed, corrupted) ...

$canonical = json_decode((string) $existing['extracted_json'], true);
// ... existing canonical merges (source.extractedDoc, source.kind) ...

if ($clientResolveFlat !== null) {
    $expanded = $this->expandUserActions($clientResolveFlat);
    $canonical['_resolve'] = $this->mergeUserActions(
        is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [],
        $expanded,
    );
}

$autoCreateMode = $applyOptionsBody['autoCreateMode']
    ?? ($clientResolveFlat !== null ? 'strict' : 'safe');
$targetDocState = (int) ($applyOptionsBody['targetDocState'] ?? 10);

$canonical['applyOptions'] = [
    'autoCreateMode' => $autoCreateMode,
    'targetDocState' => $targetDocState,
];

$result = $this->applier->apply($canonical);
// ... rest unchanged
```

Nové helper metody (private):

```php
/**
 * Expand flat {"supplier": "create", "rows[0].item": "useExisting:42"}
 * into nested {"supplier": {"userAction": "create"},
 *               "rows": [{"item": {"userAction": "useExisting:42"}}]}.
 */
private function expandUserActions(array $flat): array
{
    $expanded = [];
    foreach ($flat as $path => $action) {
        if (!is_string($action)) {
            // tolerate bad shapes silently — applier rejects unresolved
            continue;
        }
        if (preg_match('/^rows\[(\d+)\]\.(item|unit|vatCode)$/', $path, $m)) {
            $idx = (int) $m[1];
            $field = $m[2];
            $expanded['rows'][$idx][$field]['userAction'] = $action;
        } elseif (in_array($path, ['supplier', 'customer', 'supplierBank', 'customerBank'], true)) {
            $expanded[$path]['userAction'] = $action;
        }
        // else: unknown path → ignored
    }
    return $expanded;
}

/**
 * Merge userAction overrides into existing _resolve (from canonical).
 * Only `userAction` keys are merged; status/candidates/etc. stay from
 * the canonical _resolve (which the applier will overwrite during fresh
 * resolve anyway, but this preserves expected shape if applier is bypassed).
 */
private function mergeUserActions(array $existing, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if ($key === 'rows' && is_array($value)) {
            $existing['rows'] = $existing['rows'] ?? [];
            foreach ($value as $idx => $rowOverride) {
                if (!is_array($rowOverride)) continue;
                $existing['rows'][$idx] = $existing['rows'][$idx] ?? [];
                foreach ($rowOverride as $field => $fieldOverride) {
                    if (!isset($fieldOverride['userAction'])) continue;
                    $existing['rows'][$idx][$field] = $existing['rows'][$idx][$field] ?? [];
                    $existing['rows'][$idx][$field]['userAction'] = $fieldOverride['userAction'];
                }
            }
        } elseif (is_array($value) && isset($value['userAction'])) {
            $existing[$key] = $existing[$key] ?? [];
            $existing[$key]['userAction'] = $value['userAction'];
        }
    }
    return $existing;
}
```

## Hotovo když

- [ ] Klik na canCreate badge v Preview otevře popover se 2 nebo 3
      tlačítky (Vytvořit / Vybrat existujícího / [Skip pro item]).
- [ ] Klik na ambiguous badge zobrazí seznam kandidátů + Vytvořit /
      Vybrat existujícího.
- [ ] "Vybrat existujícího" otevře EntityPicker s odpovídající tabulkou
      (`base_persons_persons` pro Party, `economy_items` pro Item).
- [ ] Po výběru v EntityPicker se popover automaticky zavře a badge
      přepne na decided state.
- [ ] Klik na matched badge **neotevře** popover (read-only, žádný `<button>`).
- [ ] Klik na unit/vatCode badge **neotevře** popover (3b mimo rozsah).
- [ ] Status badge po decided rozhodnutí má vizuálně rozlišený stav
      (matchedDecided = zelený s "+", canCreateDecided = žlutý s "✓",
      skipped = šedý s "⊘").
- [ ] Klik na popovered badge se selectovaným userAction zobrazí
      "Vybráno: …" sekci s "Zrušit výběr".
- [ ] Klik mimo popover ho zavře. Escape klávesa také.
- [ ] Klik **uvnitř** EntityPicker modalu (otevřeného z popoveru)
      **nezavře** popover.
- [ ] Tlačítko "Použít" je disabled dokud zůstávají non-matched
      references bez userAction (kromě unit/vatCode).
- [ ] Klik na "Použít" pošle `POST /_mail/extracted-documents/{ndx}/apply`
      s body `{_resolve: <flat userActions>}`.
- [ ] Backend: applyExtracted s body `{_resolve: {"supplier": "useExisting:42"}}`
      předá applieru canonical s `_resolve.supplier.userAction =
      "useExisting:42"` a `autoCreateMode = "strict"`.
- [ ] Backend: applyExtracted s body `{}` (žádný `_resolve` klíč)
      zachová Fáze 2 chování — `autoCreateMode = "safe"`.
- [ ] Backend: applyExtracted s body `{_resolve: {}}` (prázdný objekt)
      předá `autoCreateMode = "strict"`.
- [ ] Backend: applyExtracted s body `{applyOptions: {"autoCreateMode": "liberal"}}`
      respektuje explicit override.
- [ ] Backend: applyExtracted s body `{_resolve: {"rows[0].item": "skip"}}`
      → applier správně odešle s řádkem [0] jako skipped.
- [ ] Backend: applyExtracted s neznámým path v `_resolve` (např.
      `"rows[99].item"` mimo rozsah) → graceful skip; applier neselže.
- [ ] Po úspěšném apply modal zavře a list se refreshne (jako 3a).
- [ ] Po `unresolved_required` z applieru (race condition) modal
      zůstává otevřený s alert s lokalizovanou hláškou.
- [ ] PHPUnit testy procházejí.
- [ ] Frontend build (`npm run build`) projde bez chyb a warningů.
- [ ] Manual QA: e2e flow — AI extrahuje fakturu s neznámým partnerem
      → admin v UI klikne Detail → klikne canCreate badge u supplier
      → zvolí "Vybrat existujícího" → najde v EntityPicker → vrátí se
      do Preview → badge je teď matchedDecided → klikne "Použít" →
      doklad uložen s napárováním na vybranou osobu.
- [ ] Manual QA: AI fixture s 3 řádky, dva canCreate items → admin
      jeden vytvoří (create), druhý napáruje (useExisting), třetí
      přeskočí (skip) → po Použít doklad uložen se 2 řádky
      (skipped vynechán).

## Konvence

- **Svelte 5** runes everywhere.
- **Plain JavaScript** (no TypeScript).
- **CSS** `shpd-popover`, `shpd-decision` prefixed, používá design
  tokens. Pro decided badge state žádné nové tokeny — jen kombinace
  existing background/text + outline jako "decided" indicator.
- **i18n** — všechny user-facing texty přes `t()`.
- **A11y** — popover má `role="dialog"`, focus management on open/close,
  Escape klávesa zavírá.
- **Backward compat** — Fáze 2 CLI klienti (žádný `_resolve` v body)
  dál fungují s `autoCreateMode = "safe"`. Fáze 3a frontend (před
  upgradem) by také fungoval — modal v 3a má `userActions` undefined,
  v `applyExtractedDocument(extractedNdx, undefined)` se posílá body
  `{}`, server detekuje absenci `_resolve` → safe mode.

## Doporučené pořadí implementace

1. **Backend `applyExtracted` rozšíření** + `expandUserActions` +
   `mergeUserActions` helpers. PHPUnit testy pro různé tvary body.
   Backward compat test: `{}` body = safe mode.
2. **Frontend API klient** `applyExtractedDocument` — naplnit
   placeholder z 3a tělem požadavku.
3. **`Popover.svelte`** standalone — manual test s mock anchor,
   ověřit positioning, click-outside, Escape.
4. **`ResolveDecisionPanel.svelte`** — render decision options,
   integrace s EntityPicker. Manual test izolovaně s mock resolveBlock.
5. **`DocumentExchangePreview.svelte`** rozšíření — interactive badge
   button, openDecision, effectiveStatus, statusBadge snippet upgrade.
   Manual test s fixture canonical mající canCreate references.
6. **`DocumentExchangePreviewModal.svelte`** rozšíření — userActions
   state, allDecided, canApply, reset on extractedNdx change.
7. **`ViewerDetail.svelte`** — handleApplyFromModal s userActions
   parametrem, error handling pro unresolved_required.
8. **i18n** — vyplnit všechny nové klíče v cs.js + en.js.
9. **E2E manuální test** — AI fixture, klik na canCreate, EntityPicker,
   apply, ověř v DB. Plus race condition simulation (smaž useExisting
   target mezi preview a apply v jiném tabu).

## Otevřené body

- **Popover z-index** — defaultní `z-index: 1000` v CSS. Pokud
  EntityPicker modal má vyšší, je v pořádku (modal překryje popover
  vizuálně, ale popover stále existuje pro click-outside detekci).
  Verify po build.

- **EntityPicker filter podpora pro item code** — pro Item lookup
  ideálně chceme search v `code` OR `name` (uživatel zná dodavatelský
  kód nebo název). Aktuální EntityPicker podporuje jen `searchFields[0]`.
  Pro 3b nasaď s `searchFields = ['name']`; pokud QA ukáže nedostatek,
  follow-up: rozšíření CrudController o multi-field OR filter (`filter[code|name]=like:...`)
  + EntityPicker o iteraci.

- **Multi-row bulk decisions** — pokud má doklad 20 řádků se stejným
  partner-supplied kódem, řešit každý zvlášť je zbytečné. Future
  enhancement: detect duplicates v UI a nabídnout "Použít pro všechny
  podobné". Mimo rozsah 3b.

- **EntityPicker pro bankAccount** — `base_persons_bank_accounts`
  search field default `iban`. Filtrace podle `person_id` (= jen
  účty patřící resolved supplierovi) by byla užitečná, ale CrudController
  nemusí mít AND multi-filter na FK. Pro 3b nasaď bez person filtru
  (UX: user vidí všechny účty napříč osobami, vybere podle IBAN).

- **`statusGlyph` overlay rendering** — "✓+" a "+✓" jako kombinované
  glyfy se nemusí dobře vykreslit v 18px badge. Volba: použít CSS
  pseudo-element `::after` s overlay glyfem (lepší) nebo nechat
  inline glyfy jak je v skeletonu (rychleji). Doladi vizuálně, design
  rozhodnutí na implementátorovi.

- **Race condition resilience** — pokud po `unresolved_required` user
  re-fetches preview (volitelně přes refresh tlačítko v modalu) a stav
  se změnil, userActions z předchozí session jsou již neaktuální.
  Aktuální implementace `loadPreview` resetuje userActions — to může
  být frustrující pro uživatele, který už něco rozhodl. Možné
  vylepšení: zachovat userActions a re-validovat proti novému resolve.
  Mimo rozsah 3b basic; možné jako follow-up.
