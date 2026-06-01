# Task: Exchange UI — rebuild rozhodování o canCreate/ambiguous/notFound + smart totals heuristika

## Kontext

Pokračujeme po **Fázi 3b** (`tasks/exchange-format-phase3b.md` — hotovo).
Aktuálně máme funkční preview modal pro extrahované dokumenty (PDF
split-view + vizualizace canonical), ale UI rozhodování o non-matched
referencích je nefunkční:

- Popover s tlačítky „Vytvořit novou" / „Vybrat existující" se otevírá,
  ale „Vytvořit novou" jen poznačí `userAction='create'` (žádný viditelný
  feedback, žádný formulář pro vyplnění chybějících údajů).
- „Vybrat existující" otevře `EntityPicker` modal, který volá generický
  CRUD endpoint (`GET /{table}?filter[full_name]=like:...`). Hledání
  v praxi nefunguje a modal jde špatně zavřít (kolize Popover + vnořený
  Modal).
- Tlačítko **„Použít"** zůstává proto neaktivní — `canApply` vyžaduje,
  aby byly všechny non-matched reference rozhodnuty, ale uživatel
  prakticky nemůže udělat funkční rozhodnutí.

Současně backend `DocumentValidator::checkTotalsCoherence()` vyrábí
**falešný warning `totals_mismatch`** pro většinu faktur s DPH: porovnává
sumu `row.totalPrice` (bez DPH) s `totals.totalAmount` (s DPH), protože
AI typicky neposílá `row.computed.vatTotal`.

Tento task řeší obojí najednou — UI rozhodování je teď architektonický
problém (potřebujeme přepis, ne flick) a heuristika totals je čistě
backend záležitost.

Před implementací **přečti**:

- `tasks/exchange-format-phase3b.md` — kompletní spec předchozí fáze,
  zejména sekce o `_resolve` shape, `userActions` flat map, applier
  reconcile + autoCreateMode.
- `docs/exchange-format.md` sekce 9 — `_resolve` state, candidate /
  userAction slovník.
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` —
  kompletně. Klíčové sekce: `statusBadge` snippet, `openDecision`,
  `handleDecide`, `decisionOpen` state, render popoveru se starým
  `ResolveDecisionPanel` (od ~ř. 250).
- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
  — drží `userActions` state a `canApply` derived. Funkce `allDecided`
  zůstává beze změn.
- `frontend/src/components/exchange/ResolveDecisionPanel.svelte` —
  **bude celý nahrazen** novou implementací (viz §2.1).
- `frontend/src/components/ui/EntityPicker.svelte` — **bude smazán**.
  Funkce ho nahradí inline lookup ve stylu LookupInput.
- `frontend/src/components/ui/LookupInput.svelte` — **referenční vzor**.
  Z LookupInput si vezmeme: dropdown UI, klávesnice (Arrow/Enter/Esc),
  debounce, fetch token cancellation, `/_ui/lookup/{table}/search`
  endpoint. Z LookupInput **NEbereme**: bindable `value` (resolve flow
  drží stav v userActions, ne v `value`), `resolved` prop (nepotřebujeme
  rendrovat „vybrané"), `edit_form` (jen create).
- `frontend/src/components/form/FormDialog.svelte` — komponenta pro
  „Vytvořit novou …". API: `table`, `recordId=null` pro create,
  `defaultData` pro pre-fill z `createPayload`, `onSaved(record)` →
  record má `record.id` (nový záznam) nebo `record.data.id`.
- `frontend/src/components/ui/Popover.svelte` — kompletně. Klíčové:
  click-outside je portal-aware (ignoruje kliky uvnitř `.shpd-modal`).
  Po rebuildu se popover otevírá s **rovnou inline obsahem** (search
  + akce), ne vnořeným modalem — drastickou nested-modal kolizi tím
  odstraníme.
- `frontend/src/api/client.js` — `get()`, `post()` helpers.
- `frontend/src/i18n/cs.js`, `en.js` — namespace `exchange.preview.*`
  je zavedený, navážeme.
- `modules/base/persons/src/PersonsLookup.php` — backend lookup. Hledá
  přes `full_name`, `company_id`, `person_id`, primary = full_name,
  secondary = `IČO 12345678` nebo `Datum narození 1.1.1990`.
- `modules/economy/items/src/ItemsLookup.php` — backend lookup pro
  položky. Primary = `code — name`, secondary = null.
- `modules/base/persons/src/BankAccountsLookup.php` — backend lookup
  pro bankovní účty. **POZOR**: BankAccount je tightly bound na
  `person` FK — viz §2.5 pro speciální chování create flow.
- `modules/core/exchange/src/Document/DocumentValidator.php` —
  `checkTotalsCoherence()` se rewritne podle §3.
- `modules/core/exchange/tests/Unit/Document/DocumentValidatorTest.php`
  (pokud existuje) — rozšířit o nové test cases pro smart heuristiku.
- `/mnt/skills/public/frontend-design/SKILL.md` — design tokens, CSS
  proměnné, styling constraints. Načti před psaním jakékoliv Svelte/CSS
  práce.

## 1. Scope

**V rozsahu:**

- Frontend rebuild rozhodovacího popoveru — vyhodit `EntityPicker`,
  přepsat `ResolveDecisionPanel` jako inline lookup + FormDialog.
- Backend smart heuristika pro `totals_mismatch` warning.
- i18n klíče (cs + en).
- Unit testy `DocumentValidator` pro nové scénáře.
- Manuální QA scenarios pro frontend flow.

**Mimo rozsah:**

- Změny v `DocumentExchangePreviewModal` `canApply` / `allDecided`
  logice — ta zůstává beze změny, jen se přestane „zasekávat" díky
  funkčnímu rozhodování.
- Změny v backendu `AnalysisController::expandUserActions` ani
  `DocumentApplier::reconcile` — `userAction` slovník zůstává:
  `null`, `"useExisting:<id>"`, `"create"`, `"skip"`.
- Edit existující entity z popoveru — v této fázi neřešíme.
- Rebuild `EntityPicker` jako samostatné komponenty pro jiné use-cases
  — `LookupInput` už existuje a stačí.
- Změny v `DocumentApplier` ohledně totals — applier žádné totals
  nevaliduje, jen `DocumentValidator`.

## 2. Frontend rebuild

### 2.1. Nový `ResolveDecisionPanel.svelte`

Cíl: nahradit current Popover obsah inline panelem, který má:

- Header: krátký label „Co s tímhle?" + (volitelně) seznam ambiguous
  kandidátů jako tlačítka.
- Search input s debounce 250 ms, hledá přes
  `GET /_ui/lookup/{table}/search?q=...`. Výsledky se renderují pod
  inputem jako klikatelné položky (max 8 viditelných, scroll).
- Klávesnice: ArrowDown/Up navigace, Enter = vybrat aktivní, Escape =
  zavřít popover (delegovat na onClose z parent).
- Tlačítko „Vytvořit novou …" (label dle `referenceKind`) ve spodní
  části — vždy viditelné, otevírá `FormDialog` pro cílovou tabulku.
- Pro `referenceKind === 'item'`: navíc tlačítko „Vynechat řádek" →
  `onDecide('skip')`.
- Pokud `currentUserAction !== null`: nahoře zobrazit „Vybráno: …" +
  „Zrušit výběr" (volá `onDecide(null)`).

**Props (kompatibilita s `DocumentExchangePreview.svelte`):**

```js
let {
  resolveBlock,                            // _resolve.{path} block
  referenceKind = 'party',                  // 'party' | 'item' | 'bankAccount'
  entityTable = 'base_persons_persons',
  createPayload = null,                     // resolveBlock.createPayload (pre-fill)
  currentUserAction = null,
  onDecide = () => {},
} = $props();
```

Pozn.: `entitySearchFields` a `entityDisplayPattern` z původního
`EntityPicker` jsou pryč — backend `LookupController` rozhoduje co
hledá a jak se to renderuje (primary + secondary).

**Hledání:**

```js
async function runSearch(term) {
  if (!entityTable) return;
  const myToken = ++currentFetchToken;
  loading = true;
  lastError = null;
  const url = `/_ui/lookup/${entityTable}/search?q=${encodeURIComponent(term)}&limit=8`;
  const res = await get(url);
  if (myToken !== currentFetchToken) return; // stale
  loading = false;
  if (!res?.success) {
    lastError = res?.error?.message ?? t('common.unknownError');
    results = [];
    return;
  }
  results = res.data?.items ?? [];
  activeIndex = results.length > 0 ? 0 : -1;
}
```

Při otevření panelu (mount) zavolat `runSearch('')` — uživatel vidí
prvních N záznamů bez psaní. Při změně `searchTerm` debounce 250 ms.

**Renderování položky:**

```svelte
{#each results as item, i (item.id)}
  <button
    type="button"
    class="shpd-resolve__item"
    class:shpd-resolve__item--active={i === activeIndex}
    onmouseenter={() => (activeIndex = i)}
    onclick={() => onDecide(`useExisting:${item.id}`)}
  >
    <span class="shpd-resolve__item-primary">{item.primary}</span>
    {#if item.secondary}
      <span class="shpd-resolve__item-secondary">{item.secondary}</span>
    {/if}
  </button>
{/each}
```

**Tlačítko Vytvořit (pseudo):**

```svelte
<button type="button" class="shpd-resolve__create" onclick={openCreateDialog}>
  + {createLabel}
</button>

<FormDialog
  table={entityTable}
  recordId={null}
  open={createDialogOpen}
  defaultData={createDefaults}
  onClose={() => (createDialogOpen = false)}
  onSaved={handleCreated}
/>
```

`createDefaults` = `mapCreatePayloadToFormData(referenceKind, createPayload)`
— viz §2.3.

`handleCreated(record)`:

```js
const newId = record?.id ?? record?.data?.id;
if (newId != null) {
  createDialogOpen = false;
  onDecide(`useExisting:${newId}`);  // NE 'create' — máme reálné id
}
```

**Důležité**: výsledkem flow „Vytvořit" je teď **`useExisting:<id>`**,
ne `'create'`. To je velký zjednodušení backendu — `DocumentApplier`
nemusí řešit autoCreate, protože v okamžiku /apply už entita existuje
v DB a má id.

`'create'` jako userAction zůstává validní pro **bankAccount** (viz
§2.5), kde FormDialog by byl příliš složitý (vyžadoval by FK na
osobu, která možná ještě sama neexistuje).

### 2.2. Změny v `DocumentExchangePreview.svelte`

Současný kód:

```svelte
{#if decisionOpen !== null}
  <Popover ...>
    <ResolveDecisionPanel
      ...
      entityTable={decisionOpen.table}
      entitySearchFields={decisionOpen.searchFields}
      entityDisplayPattern={decisionOpen.displayPattern}
      ...
    />
  </Popover>
{/if}
```

Po rebuildu:

```svelte
{#if decisionOpen !== null}
  <Popover ...>
    <ResolveDecisionPanel
      resolveBlock={decisionOpen.resolveBlock}
      referenceKind={decisionOpen.kind}
      entityTable={decisionOpen.table}
      createPayload={decisionOpen.resolveBlock?.createPayload ?? null}
      currentUserAction={userActions[decisionOpen.path] ?? null}
      onDecide={handleDecide}
    />
  </Popover>
{/if}
```

`entityConfigForKind` se zjednoduší — pryč `searchFields`,
`displayPattern`:

```js
function entityConfigForKind(kind) {
  if (kind === 'party')       return { table: 'base_persons_persons' };
  if (kind === 'item')        return { table: 'economy_items' };
  if (kind === 'bankAccount') return { table: 'base_persons_bank_accounts' };
  return null;
}
```

Šířku popoveru zvětšit — search input + 8 výsledků potřebuje místo.
V `Popover.svelte` styles: `min-width: 320px` (z 240) a `max-width:
480px` (z 360). Pokud bys cítil odpor přidávat do Popoveru styl, který
zasahuje i jiné uživatele Popoveru (dropdown menu v ViewerDetail), pak
přidej do Popoveru prop `width` s defaultem 280 a v
`ResolveDecisionPanel` použité instanci nastav `width="400px"`. **Druhá
varianta je preferovaná** — méně regresí.

### 2.3. Pre-fill formuláře z `createPayload`

`PartyResolver`, `ItemResolver` a `BankAccountResolver` při statusu
`canCreate` vracejí `createPayload` — strukturovaný snippet, kterým
applier vytváří novou entitu. Pro „Vytvořit z UI" potřebujeme stejný
payload předat do `FormDialog` jako `defaultData`.

Vytvoř pomocnou funkci v `DocumentExchangePreview.svelte` nebo
v `ResolveDecisionPanel.svelte` (lepší, blíž použití):

```js
function mapCreatePayloadToFormData(kind, payload) {
  if (!payload || typeof payload !== 'object') return {};
  // payload má již shape pro daný table → vrať as-is, FormEditor si vezme
  // co potřebuje a ostatní ignoruje. Schémata se shodují, protože resolver
  // staví payload právě pro odpovídající Document (PersonDocument,
  // ItemDocument, …).
  return { ...payload };
}
```

Tj. de facto pass-through. Pokud Claude Code zjistí, že tvar
`createPayload` se rozchází se shape, který FormEditor očekává (např.
různé case naming jako `companyId` vs `company_id`), je nutné to tady
ošetřit. PartyResolver staví payload pro Person Document → mělo by být
přímo kompatibilní (snake_case sloupce tabulky), ověř.

### 2.4. Smazání `EntityPicker.svelte`

`frontend/src/components/ui/EntityPicker.svelte` smaž. Žádný jiný
caller v repu by neměl být (poslední byl `ResolveDecisionPanel`, ten po
rebuildu nepotřebuje). Před smazáním ověř:

```bash
grep -rn "EntityPicker" frontend/src/
```

Pokud najdeš jiný použití než `ResolveDecisionPanel`, **netvor**
EntityPicker — pouze refaktoruj `ResolveDecisionPanel` a EntityPicker
zachovej. (Aktuální stav: žádný jiný caller.)

### 2.5. Bank account — speciální chování

Bank account má FK `person`, který musí existovat před uložením. Když
uživatel klikne `+` na supplierBank badge a chce „Vytvořit nový":

- Pokud `resolve.supplier.status === 'matched'` (osoba už existuje) →
  FormDialog se otevře normálně, `defaultData.person` se předvyplní
  na `resolve.supplier.matchedId`.
- Pokud osoba ještě není matched (canCreate, ambiguous, notFound) →
  FormDialog **netvor**. Místo toho ve `ResolveDecisionPanel` skryj
  tlačítko „Vytvořit nový" a zobraz nápovědu: „Nejdřív vyber nebo
  vytvoř dodavatele." Tlačítko „Vybrat existující" zůstává viditelné
  (uživatel může napárovat na již existující účet bez ohledu na
  osobu).
- Alternativně lze přes `onDecide('create')` přenést rozhodnutí na
  applier — ten v `runSideCreates` umí navázat účet na nově vytvořenou
  osobu. Tj. pro bankAccount **necháme `'create'` jako validní
  userAction** (na rozdíl od party/item).

Implementace:

```js
function canOpenCreateDialog() {
  if (referenceKind !== 'bankAccount') return true;
  // Pro bank potřebujeme buď matched supplier, nebo necháme backend
  // udělat create přes runSideCreates.
  // FormDialog otvíráme jen když máme person FK.
  return supplierMatchedId !== null;
}
```

Tuhle informaci dostaneme do `ResolveDecisionPanel` přes novou prop
`parentMatchedId` (předanou z `DocumentExchangePreview`). Pokud
`referenceKind === 'bankAccount'` a `parentMatchedId === null`, render
„Vytvořit přes applier" tlačítko, které volá `onDecide('create')`.

Pro `party` a `item` `parentMatchedId` je null a tlačítko Vytvořit otvírá
FormDialog vždy.

### 2.6. i18n klíče

Přidej do `frontend/src/i18n/cs.js` a `en.js` v namespace
`exchange.preview.decide.*`:

```
exchange.preview.decide.searchPlaceholder    "Hledat…" / "Search…"
exchange.preview.decide.loading              "Načítám…" / "Loading…"
exchange.preview.decide.empty                "Nic nenalezeno" / "Nothing found"
exchange.preview.decide.errorPrefix          "Chyba: " / "Error: "
exchange.preview.decide.createParty          "Vytvořit novou osobu" / "Create new person"
exchange.preview.decide.createItem           "Vytvořit novou položku" / "Create new item"
exchange.preview.decide.createBankAccount    "Vytvořit nový účet" / "Create new account"
exchange.preview.decide.skipRow              "Vynechat řádek" / "Skip row"
exchange.preview.decide.selected             "Vybráno: {label}" / "Selected: {label}"
exchange.preview.decide.unselect             "Zrušit výběr" / "Clear selection"
exchange.preview.decide.bankRequiresSupplier "Nejdřív vyber nebo vytvoř dodavatele." / "Pick or create supplier first."
exchange.preview.decide.candidates           "Kandidáti" / "Candidates"
exchange.preview.decide.useCandidate         "Použít #{id}" / "Use #{id}"
```

Existující klíče `pickExisting`, `useCandidate`, `create`, `skip`,
`unselect`, `selected` ponech a používej.

### 2.7. CSS

Drž se BEM konvence `shpd-resolve__*`. Inspirace v
`LookupInput.svelte` — `shpd-lookup__*` třídy. Klíčové tokeny:

- `var(--shpd-color-bg)`, `--shpd-color-border`, `--shpd-color-primary`,
  `--shpd-color-primary-soft`, `--shpd-color-text-secondary`,
  `--shpd-color-danger`.
- `var(--shpd-space-xs|sm|md)`, `var(--shpd-radius-sm|md)`.
- Aktivní položka v seznamu: `background-color:
  var(--shpd-color-primary-soft)`.
- Hover na položce: `background-color:
  var(--shpd-color-bg-secondary)`.

Hotovo má vypadat jako kompaktní typeahead — input nahoře, výsledky
pod ním ve scrollovatelném seznamu (max-height ~280px), pak akce na
spodu (Vytvořit, případně Skip).

## 3. Backend — smart `totals_mismatch` heuristika

### 3.1. Současný stav

`DocumentValidator::checkTotalsCoherence()`:

```php
$computed = 0.0;
foreach ($rows as $row) {
    $rowTotal = $row['computed']['vatTotal'] ?? $row['totalPrice'] ?? null;
    if ($rowTotal !== null) {
        $computed += (float) $rowTotal;
    }
}
$computed = round($computed, 2);
$declaredF = round((float) $declared, 2);
if (abs($computed - $declaredF) > 0.01) {
    // warning
}
```

Problém: AI typicky neposílá `row.computed.vatTotal`, takže fallback
na `row.totalPrice` (bez DPH) → suma bez DPH se porovnává s
`totalAmount` (s DPH) → systematický mismatch na fakturách s DPH.

### 3.2. Cílová logika

Zkusit **tři varianty** výpočtu a brát mismatch jen pokud ani jedna
nesedí (tolerance 0,01):

1. **Suma `row.totalPrice`** (bez DPH) — sedí pokud doklad nemá DPH
   nebo `totalAmount` je v base bez DPH (atypické, ale možné).
2. **Suma s vypočteným DPH per řádek** — pokud má řádek `row.vat.pct`,
   vynásobit `(1 + pct/100)`; jinak fallback na samotný `totalPrice`
   (řádek bez DPH).
3. **Suma `vatRecap[].total`** — pokud má canonical vyplněnou
   `vatRecap`, sečíst `total` z každé sazby. Nejvěrohodnější varianta,
   protože vatRecap je per-rate breakdown s vyplněným total (= base +
   tax).

Pokud sedí kterákoliv → žádný warning.
Pokud nesedí ani jedna → warning s message obsahující všechny tři
hodnoty pro debug.

### 3.3. Implementace

```php
private function checkTotalsCoherence(array $canonical, array &$issues): void
{
    $totals = $canonical['totals'] ?? null;
    $rows = $canonical['rows'] ?? null;
    if (!is_array($totals) || !is_array($rows)) {
        return;
    }

    $declared = $totals['totalAmount'] ?? null;
    if ($declared === null) {
        return;
    }

    $declaredF = round((float) $declared, 2);

    // (1) Sum of row.totalPrice (base, no VAT)
    $sumBase = 0.0;
    // (2) Sum of row.totalPrice * (1 + vatPct/100) (with VAT)
    $sumWithVat = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $rowBase = $row['totalPrice'] ?? null;
        if ($rowBase === null) continue;
        $rowBaseF = (float) $rowBase;
        $sumBase += $rowBaseF;

        $pct = $row['vat']['pct'] ?? null;
        if ($pct !== null && is_numeric($pct)) {
            $sumWithVat += $rowBaseF * (1.0 + ((float) $pct) / 100.0);
        } else {
            $sumWithVat += $rowBaseF;
        }
    }
    $sumBase     = round($sumBase, 2);
    $sumWithVat  = round($sumWithVat, 2);

    // (3) Sum of vatRecap[].total
    $sumVatRecap = null;
    if (is_array($canonical['vatRecap'] ?? null) && count($canonical['vatRecap']) > 0) {
        $acc = 0.0;
        $hasAny = false;
        foreach ($canonical['vatRecap'] as $r) {
            if (is_array($r) && isset($r['total']) && is_numeric($r['total'])) {
                $acc += (float) $r['total'];
                $hasAny = true;
            }
        }
        if ($hasAny) {
            $sumVatRecap = round($acc, 2);
        }
    }

    $tolerance = 0.01;
    $matchBase    = abs($sumBase - $declaredF) <= $tolerance;
    $matchWithVat = abs($sumWithVat - $declaredF) <= $tolerance;
    $matchRecap   = $sumVatRecap !== null && abs($sumVatRecap - $declaredF) <= $tolerance;

    if ($matchBase || $matchWithVat || $matchRecap) {
        return;
    }

    $detail = "totalPrice součet: {$sumBase}; s DPH per řádek: {$sumWithVat}";
    if ($sumVatRecap !== null) {
        $detail .= "; vatRecap: {$sumVatRecap}";
    }
    $issues[] = [
        'severity' => 'warning',
        'path'     => 'totals.totalAmount',
        'code'     => 'totals_mismatch',
        'message'  => "Deklarovaná částka {$declaredF} neodpovídá žádné vypočtené variantě ({$detail}).",
    ];
}
```

Tolerance 0,01 je dostatečná pro běžné fakturní noise. Pro velmi velké
částky by se mohla použít relativní tolerance, ale 0,01 řeší 99 %
realných případů — nezavádíme to teď.

### 3.4. Unit testy

`modules/core/exchange/tests/Unit/Document/DocumentValidatorTest.php`
— rozšířit:

- ✅ Faktura s DPH, totalPrice je base, totalAmount s DPH, sedí
  per-row VAT calc → no warning.
- ✅ Faktura s vatRecap (declared = sum vatRecap.total) → no warning.
- ✅ Doklad bez DPH (žádný row.vat.pct), totalPrice == totalAmount →
  no warning.
- ❌ totalAmount opravdu rozbitý (žádná z variant nesedí) → warning
  s message obsahující všechny tři hodnoty.
- ✅ Hraniční tolerance — diff 0,005 → no warning. Diff 0,02 (a
  žádná varianta nesedí) → warning.

Pokud existující test pro `totals_mismatch` (zlatý fixture) předpokládá
starou logiku, uprav fixture tak, aby reflektoval novou heuristiku.

## 4. Hotovo když

### Frontend

- [ ] `EntityPicker.svelte` smazán z `frontend/src/components/ui/`.
- [ ] `ResolveDecisionPanel.svelte` přepsán dle §2.1.
- [ ] `DocumentExchangePreview.svelte` aktualizován dle §2.2 — žádné
      `searchFields` / `displayPattern` v `entityConfigForKind`.
- [ ] `Popover.svelte` má novou volitelnou prop `width` (default 280px).
- [ ] Klik na `+` badge u party canCreate → otevře popover s search +
      list + tlačítko „Vytvořit novou osobu".
- [ ] Klik na položku v seznamu → onDecide(`useExisting:<id>`) →
      popover se zavře, badge se přebarví na matchedDecided (zelený
      s outline).
- [ ] Klik na „Vytvořit novou osobu" → otevře FormDialog pro
      `base_persons_persons` předvyplněný z `createPayload`.
- [ ] Uložení v FormDialogu → onSaved → popover se zavře,
      userActions[path] = `useExisting:<newId>`, badge matchedDecided.
- [ ] Klik na `+` badge u item canCreate → otevře popover s search +
      list + „Vytvořit novou položku" + „Vynechat řádek".
- [ ] Klik na „Vynechat řádek" → onDecide('skip'), badge skipped.
- [ ] Klik na `+` badge u supplierBank canCreate, **supplier matched** →
      otevře popover s search + list + „Vytvořit nový účet" → otevře
      FormDialog pro `base_persons_bank_accounts` s předvyplněnou
      `person = supplier.matchedId`.
- [ ] Klik na `+` badge u supplierBank canCreate, **supplier NOT
      matched** → otevře popover s search + list + „Vytvořit přes
      applier" (alternativa: nápověda „Nejdřív vyber osobu"). Klik
      na „Vytvořit přes applier" → onDecide('create').
- [ ] Klávesa ↓ ↑ v search inputu — aktivní položka se posouvá.
- [ ] Enter na aktivní položce → vybere.
- [ ] Escape → popover se zavře.
- [ ] Klik mimo popover (ale ne v FormDialog) → popover se zavře.
- [ ] Klik **uvnitř** FormDialog otevřeného z popoveru → popover
      zůstává otevřený (portal-aware click-outside ve `Popover.svelte`
      už existuje, ověř funkčnost po rebuildu).
- [ ] Současné rozhodnutí (`currentUserAction !== null`) se zobrazí
      nahoře v popoveru s tlačítkem „Zrušit výběr".
- [ ] Tlačítko „Použít" v hlavním modalu se rozsvítí, jakmile jsou
      všechny non-matched reference rozhodnuty.
- [ ] cs + en překlady kompletní v `i18n/cs.js` a `en.js`.
- [ ] Frontend build (`npm run build`) projde bez chyb a warningů.
- [ ] Žádný residuální `EntityPicker` import nebo reference v repu
      (`grep -rn "EntityPicker" frontend/src/` vrací prázdno).

### Backend

- [ ] `DocumentValidator::checkTotalsCoherence()` přepsán dle §3.3.
- [ ] Nové unit testy dle §3.4 procházejí.
- [ ] Existující PHPUnit testy procházejí (`composer test` nebo
      ekvivalent).
- [ ] Manuální QA na reálné faktuře:
  - faktura s DPH, totalPrice base, totalAmount s DPH → žádný
    totals_mismatch warning v _resolve.issues.
  - faktura s vatRecap (deklarovaný total sedí na sum vatRecap.total)
    → žádný warning.
  - faktura s totalAmount uměle posunutý (např. +100 oproti všem třem
    variantám) → warning se vyrobí.

### E2E

- [ ] Vezmi reálnou extrahovanou fakturu, kde předtím nešlo „Použít":
  - Otevři Detail.
  - Klikni `+` u supplier (canCreate).
  - Vyber existující osobu z hledání → badge matchedDecided.
  - Klikni „Použít" → modal se zavře, dokument je uložen, doc se
    posune do stavu Applied.
- [ ] Vezmi další fakturu, kde supplier neexistuje:
  - Otevři Detail.
  - Klikni `+` u supplier → klikni „Vytvořit novou osobu".
  - FormDialog se otevře předvyplněný z createPayload (IČO,
    full_name, …).
  - Uložím → popover se zavře, badge matchedDecided.
  - Klik „Použít" → doc uložen, nová osoba v `base_persons_persons`,
    doc napárován na ni.

## 5. Konvence

- **Svelte 5** s `$state`, `$derived`, `$effect`, `$props`. Žádné
  Svelte 4 stores ad-hoc.
- **Plain JavaScript** (no TypeScript) — per CLAUDE.md. Výjimka:
  `Modal.svelte`, `FormDialog.svelte` už jsou v TS — pokud Claude Code
  nějaké z nich edituje, ponechat TS lang.
- Komentáře v kódu **anglicky**. UI text **česky** (přes i18n).
- BEM třídy `shpd-resolve__*` pro nový panel.
- Žádné console.log committed. Při debug iteraci OK, před PR pryč.
- Žádné inline styly — vše přes CSS proměnné a tokeny.

## 6. Architektonická rozhodnutí

### 6.1. Proč nepřepisovat `EntityPicker` na inline lookup, ale rovnou ho zahodit?

EntityPicker měl jediného uživatele (`ResolveDecisionPanel`), a ten
po rebuildu chce inline UX, ne modal. Kdybychom EntityPicker zachovali
pro „možné budoucí použití", vznikla by dead code zone. Když ho
budeme příště potřebovat někde jinde, vyřežeme příslušný kus
z `ResolveDecisionPanel` jako sdílenou komponentu.

### 6.2. Proč `useExisting:<id>` po Vytvořit, ne `'create'`?

`'create'` na backendu jde do `applier::reconcile` → `runSideCreates`,
což vyžaduje `createPayload` v `_resolve.{path}.createPayload`. Tj.
i kdybychom poslali `userAction = 'create'`, applier by potřeboval
buďto resolved createPayload (= AI dat), nebo nedoplněný payload od
uživatele.

Když místo toho v UI **rovnou vytvoříme osobu/položku** přes
FormDialog, dostaneme reálné id, applier ten side-create nemusí dělat,
flow je čistší a robustní. Uživatel navíc vidí, co přesně se
vytváří, a může upravit cokoliv před uložením.

Cena: jeden API request navíc (FormDialog save). Trivial.

`'create'` zůstává jako fallback pro bankAccount, kde FormDialog dialog
otevřít neumíme (vyžadoval by transakci s ještě nevytvořenou osobou).

### 6.3. Smart heuristika vs strict tolerance

Mohli bychom totals_mismatch úplně zrušit pro doklady s DPH, ale to
by skrylo i legitimní problémy (chyba v AI rozpoznání). Smart
heuristika dává nejlepší poměr signál/šum: u 95 % faktur s DPH zmizí
falešný warning, u opravdu rozbitých zůstává.

### 6.4. Šířka popoveru — `width` prop vs globální zvětšení

Globální zvětšení by ovlivnilo i jiné uživatele Popoveru (dropdown
menu v ViewerDetail), kde užší popover dává smysl. Optional `width`
prop je nejméně invazivní.

## 7. Files touched (orientační)

```
+++ frontend/src/components/exchange/ResolveDecisionPanel.svelte   [rewrite]
--- frontend/src/components/ui/EntityPicker.svelte                  [delete]
 M  frontend/src/components/exchange/DocumentExchangePreview.svelte
 M  frontend/src/components/ui/Popover.svelte                       [+ width prop]
 M  frontend/src/i18n/cs.js
 M  frontend/src/i18n/en.js
 M  modules/core/exchange/src/Document/DocumentValidator.php
 M  modules/core/exchange/tests/Unit/Document/DocumentValidatorTest.php
```

## 8. Po dokončení

Nahlas Davidovi:

- Diff seznam změněných souborů.
- Sumár rebuilded UX flow (jedna věta na referenceKind).
- Případné nečekané edge cases nebo rozhodnutí, kde se odchýlilo od
  spec.
- Manuální QA výsledky (které ze scenárů §4 byly otestovány).
