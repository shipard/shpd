# Task: Review modal — rychlé vytvoření osoby z registru (Issue #28)

**Stav:** hotovo — implementováno a ověřeno na alfě 17. 8. 2026

## Cíl

V review modalu přijaté faktury (`DocumentExchangePreviewModal` →
`DocumentExchangePreview`) jde dnes nespárovaného dodavatele vyřešit jen
ručně — lookup nad DB nebo ruční formulář. Cíl (GitHub Issue #28 + komentář):

1. **Předkontrola na pozadí** — když se vytěžilo IČO a strana není
   spárovaná, ověřit na pozadí proti ARES registru, že IČO existuje.
2. **Quick-add na jeden klik** — při nálezu zobrazit přímo na kartě strany
   tlačítko „Vytvořit z registru: {název}" (bez hledacího okna); klik osobu
   vytvoří a rovnou nastaví `useExisting:{id}`.
3. **Fallback plné hledání** — v `ResolveDecisionPanel` tlačítko
   „Hledat v registru…", které otevře znovupoužitý `RegistryImportWizard`
   s předvyplněným dotazem.

Čistě frontendová práce — žádná změna PHP, žádný nový endpoint.

## Návaznost

- GitHub Issue: https://github.com/shipard/shpd/issues/28
- Staví na existujících endpointech wizardu z Osob
  (`frontend/src/api/personsRegistry.js`): `GET /persons/registry?q=`,
  `GET /persons/registry/{country}/{id}`,
  `POST /_exchange/persons/person/apply`.
- Precedent pro DB zápis před apply zprávy: `ResolveDecisionPanel` už dnes
  u „+ Vytvořit" ukládá reálný záznam přes `FormDialog` a vrací
  `useExisting:{newId}` — quick-add je stejná sémantika, jiný zdroj dat.
- Precedent pro mode-prop na wizardu: `asOwn` (docs/ds-setup.md §5.4).

## Potvrzená designová rozhodnutí (Anna)

- **D1** Předkontrola běží ve **frontendu** po načtení preview dat
  (asynchronně na pozadí), ne v preview endpointu ani v analyzeru.
  Výpadek registru nic nerozbije — quick-add se prostě neobjeví.
- **D2** Quick-add tlačítko **na kartě strany i v ResolveDecisionPanelu**
  (check běží jednou v `DocumentExchangePreview`, výsledek se propíše
  do obou míst).
- **D3** Fallback = **reuse `RegistryImportWizard`** s novým propem
  `initialQuery`; `onSaved(personId)` → `useExisting:{personId}`.
- **D4** Když registry search vrátí `existsInDb = true` (resolver přitom
  nematchnul — např. neaktivní docState), quick-add **nezobrazovat**;
  uživatel osobu najde stávajícím lookupem. Bez hintu v první verzi.
- **D5** Mechanismus obecně pro `referenceKind === 'party'`
  (supplier i customer), žádný hardcode na dodavatele.
- **Jeden klik**: quick-add na kartě rovnou vytváří (s loading stavem na
  tlačítku), neotevírá panel.

## Rozsah

### V rozsahu

1. `frontend/src/api/personsRegistry.js` — helper předkontroly.
2. `frontend/src/components/exchange/DocumentExchangePreview.svelte` —
   spuštění checku, quick-add na kartě, hosting wizardu, quick-add logika.
3. `frontend/src/components/exchange/ResolveDecisionPanel.svelte` —
   quick-add + „Hledat v registru…" pro party.
4. `frontend/src/components/registry/RegistryImportWizard.svelte` —
   prop `initialQuery`.
5. i18n: `frontend/src/i18n/cs.js` + `frontend/src/i18n/en.js`.

### Mimo rozsah

- Jakákoli změna PHP (resolver, preview endpoint, applier).
- Předkontrola v AI analyzeru / ukládání registry hitů do DB.
- Hint u `existsInDb = true`.
- Re-sync existující osoby z registru („Aktualizovat z registru").
- `RegistryExtractedPreview` (Spisovna) — nemá resolve panel, netýká se.

## Datový tok

```
previewMessage(ndx) → data.canonical._resolve.{supplier,customer}
  status ≠ matched && createPayload.company_id ≠ ''
    → (pozadí) searchRegistry(company_id)
    → přesná shoda companyId, !existsInDb, ideálně isValid
    → registryHits[path] = {country, companyId, fullName, …}

[karta strany] „Vytvořit z registru: {fullName}"  ─┐
[panel]        totéž tlačítko nahoře               ─┤ klik
                                                    ▼
  fetchRegistryPerson(hit.country, hit.companyId)
  → canonical + applyOptions {mergeStrategy:'createOnly', targetDocState:40}
  → applyRegistryPerson(...) → savedPersonId
  → userActions[path] = `useExisting:${savedPersonId}`

[panel] „Hledat v registru…" → RegistryImportWizard(initialQuery)
  → onSaved(personId) → userActions[path] = `useExisting:${personId}`
```

## Co je potřeba udělat

### 1. `api/personsRegistry.js` — helper `findRegistryQuickHit`

```js
/**
 * Předkontrola pro quick-add v review modalu: najdi v registru přesnou
 * shodu na IČO. Vrací řádek vhodný pro jednoklikové vytvoření, jinak null.
 * Chyby polyká (vrací null) — předkontrola nesmí rušit review flow.
 */
export async function findRegistryQuickHit(companyId) {
  const id = (companyId ?? '').trim();
  if (id === '') return null;
  try {
    const res = await searchRegistry(id);
    if (!res?.success) return null;
    const exact = (res.data?.results ?? [])
      .filter(r => r.companyId === id && !r.existsInDb);
    if (exact.length === 0) return null;
    // Preferuj platný subjekt; při více platných shodách (teoreticky
    // CZ+SK kolize) quick-add nenabízej — fallback je wizard.
    const valid = exact.filter(r => r.isValid);
    const pool = valid.length > 0 ? valid : exact;
    return pool.length === 1 ? pool[0] : null;
  } catch {
    return null;
  }
}
```

### 2. `DocumentExchangePreview.svelte` — check + karta + quick-add logika

**Stav:**

```js
// path ('supplier' | 'customer') → SearchResultRow z registru, nebo null.
let registryHits = $state({});
// path → true během fetch+apply (loading na tlačítku, single-flight).
let quickAddBusy = $state({});
// path → lokalizovaná chybová hláška posledního pokusu, nebo null.
let quickAddError = $state({});
// Wizard fallback: null | { path, initialQuery }
let registrySearchOpen = $state(null);
```

**Spuštění checku** — `$effect` reagující na `resolve` (tj. nová preview
data). Pro každou stranu z `['supplier', 'customer']`:

- `block = resolve?.[path]`; přeskoč pokud `!block` nebo
  `block.status === 'matched'`.
- `companyId = block.createPayload?.company_id`; přeskoč pokud prázdné.
- Interaktivita: check spouštěj jen když `onUserActionsChange !== null`
  (read-only režim quick-add nemá).
- Zavolej `findRegistryQuickHit(companyId)` a výsledek zapiš do
  `registryHits[path]`. Použij request token / porovnání identity
  `resolve` po awaitu — při přepnutí zprávy v modalu zahoď starou odpověď
  a `registryHits` resetuj na `{}` (spolu s `quickAddBusy`,
  `quickAddError`).

**Quick-add handler** (jediné místo logiky, sdílené kartou i panelem):

```js
async function handleRegistryQuickAdd(path) {
  const hit = registryHits[path];
  if (!hit || quickAddBusy[path]) return;
  quickAddBusy = { ...quickAddBusy, [path]: true };
  quickAddError = { ...quickAddError, [path]: null };
  try {
    const fetched = await fetchRegistryPerson(hit.country, hit.companyId);
    if (!fetched?.success) { /* → quickAddError, return */ }
    const canonical = {
      ...fetched.data,
      applyOptions: { mergeStrategy: 'createOnly', targetDocState: 40 },
    };
    const applied = await applyRegistryPerson(canonical);
    if (applied?.success) {
      decideForPath(path, `useExisting:${applied.data?.savedPersonId}`);
      return;
    }
    // Souběh: osobu mezitím někdo vytvořil → applier vrací person_exists
    // s matchedId v _resolve.header — použij ho místo chyby.
    const matchedId = applied?.error?.code === 'person_exists'
      ? applied?.data?.canonical?._resolve?.header?.matchedId ?? null
      : null;
    if (matchedId != null) {
      decideForPath(path, `useExisting:${matchedId}`);
      return;
    }
    quickAddError = { ...quickAddError, [path]: translateError(applied?.error) };
  } finally {
    quickAddBusy = { ...quickAddBusy, [path]: false };
  }
}
```

`decideForPath(path, action)` — vyfaktorovat z `handleDecide` (ten dnes
bere path z `decisionOpen`; nová funkce bere path parametrem, `handleDecide`
ji volá). Po rozhodnutí quick-add na kartě zmizí (viz podmínka níže).

**Karta strany** — do snippetu `partyCard` pod `party-body` přidat blok:

```svelte
{#if onUserActionsChange !== null
     && registryHits[path]
     && (userActions[path] ?? null) === null
     && partyResolve?.status !== 'matched'}
  <div class="shpd-exchange__party-registry">
    <button type="button" ... onclick={() => handleRegistryQuickAdd(path)}
            disabled={quickAddBusy[path]}>
      ➕ {t('exchange.preview.registry.quickAdd', { name: registryHits[path].fullName })}
    </button>
    {#if quickAddError[path]}<span class="…--error">{quickAddError[path]}</span>{/if}
  </div>
{/if}
```

Pozor: `partyCard` dostává `path` jako 4. parametr (`'supplier'` /
`'customer'`) — sedí s klíči `resolve`, žádné mapování není potřeba.

**Hosting wizardu** — na konec šablony (vedle Popoveru):

```svelte
<RegistryImportWizard
  open={registrySearchOpen !== null}
  initialQuery={registrySearchOpen?.initialQuery ?? ''}
  onClose={() => (registrySearchOpen = null)}
  onSaved={(personId) => {
    if (registrySearchOpen && personId != null) {
      decideForPath(registrySearchOpen.path, `useExisting:${personId}`);
    }
    registrySearchOpen = null;
  }}
/>
```

Otevření z panelu: `initialQuery` = IČO z `createPayload.company_id`,
fallback název z `createPayload.full_name`. Otevření wizardu zároveň
zavře popover (`closeDecision()`).

### 3. `ResolveDecisionPanel.svelte` — nové propy + dvě tlačítka

Nové propy:

```js
registryHit = null,          // SearchResultRow | null (jen party)
registryBusy = false,
onRegistryQuickAdd = null,   // () => void
onOpenRegistrySearch = null, // () => void — otevře wizard, zavře popover
```

Render (jen `referenceKind === 'party'`):

- **Nahoře** (nad search inputem, pod `__current`): když `registryHit`,
  tlačítko ve stylu `__create` s loading stavem —
  `➕ {t('exchange.preview.registry.quickAdd', { name: registryHit.fullName })}`.
- **V `__actions`** (vedle „+ Vytvořit novou osobu"): když
  `onOpenRegistrySearch`, tlačítko
  `t('exchange.preview.registry.search')` („Hledat v registru…").

Napojení v `DocumentExchangePreview` (render Popoveru):

```svelte
registryHit={decisionOpen.kind === 'party' ? registryHits[decisionOpen.path] ?? null : null}
registryBusy={quickAddBusy[decisionOpen.path] ?? false}
onRegistryQuickAdd={() => handleRegistryQuickAdd(decisionOpen.path)}
onOpenRegistrySearch={decisionOpen.kind === 'party' ? () => { /* naplň registrySearchOpen, closeDecision() */ } : null}
```

Quick-add z panelu po úspěchu zavře popover (rozhodnutí padlo →
`decideForPath` + `closeDecision()` — decide z quick-add handleru popover
nezavírá sám, zavření řeší volající; na kartě není co zavírat).

### 4. `RegistryImportWizard.svelte` — prop `initialQuery`

```js
let { open = false, asOwn = false, initialQuery = '', onClose, onSaved } = $props();
```

V `$effect` při `open`: po `resetAll()` když `initialQuery.trim() !== ''`:
`query = initialQuery; searchLoading = true; runSearch(query.trim())` —
tj. spustit hledání rovnou, bez čekání na input event (debounce se týká
jen psaní). Autofocus inputu zůstává.

Stávající použití (`Viewer.svelte:912`, `DsSetup.svelte:370`) prop
nepředávají → default `''`, chování beze změny.

### 5. i18n — `cs.js` + `en.js`

Sekce `exchange.preview.*`, nový podklíč `registry`:

```js
'exchange.preview.registry.quickAdd': 'Vytvořit z registru: {name}',
'exchange.preview.registry.search': 'Hledat v registru…',
'exchange.preview.registry.creating': 'Vytvářím…',
```

(en: `'Create from registry: {name}'`, `'Search registry…'`,
`'Creating…'`.) Chybové hlášky jdou přes existující `translateError`.

## Akceptační kritéria

1. Přijatá faktura s vytěženým IČO, dodavatel není v DB, subjekt je
   v ARES: po otevření review modalu se (po doběhnutí checku) na kartě
   dodavatele objeví „Vytvořit z registru: {název}". Klik → tlačítko
   v loading stavu → osoba vznikne (docState 40) → badge přejde do
   `matchedDecided`, tlačítko zmizí, „Použít" se odemkne (pokud nebrání
   jiné reference).
2. Totéž tlačítko je vidět nahoře v popoveru po kliku na badge; funguje
   stejně a zavře popover.
3. „Hledat v registru…" v popoveru otevře wizard s předvyplněným IČO
   a rovnou spuštěným hledáním; výběr + Uložit ve wizardu nastaví
   `useExisting:{id}` a vrátí uživatele do review modalu.
4. IČO nevytěženo / v registru není / registry nedostupné: karta vypadá
   jako dnes (žádné tlačítko, žádná chyba), fallback „Hledat v registru…"
   v popoveru je dostupný vždy.
5. `existsInDb = true` → quick-add se nenabízí.
6. Read-only režim (`onUserActionsChange === null`) → nic z tohoto se
   nerenderuje ani nespouští check.
7. Přepnutí na jinou zprávu v modalu → žádný „prosáklý" hit z předchozí
   zprávy (request token).
8. `cd frontend && timeout 90 npm run build` projde bez chyb
   (`timeout_sec: 120`).
9. `python3 scripts/tasks-index.py --check` sedí (po změně stavu
   regenerovat).

### E2E ověření (alpha)

Na alpě najít přijatou zprávu s `_resolve.supplier.status = canCreate`
a vytěženým IČO (SQL přes `claude_ro` nad `core_mail_extracted_documents`
/ preview API), projít flow 1–3. Mutace (vytvoření osoby) je součást
testu — provést na testovacím DS po odsouhlasení, IČO reálného subjektu
z veřejného registru je v pořádku, ale do task filu ani commitů nepsat
identifikátory z reálné pošty partnerů.

## Pasti

- **`createPayload.company_id` je `''`, ne `null`** —
  `buildPersonCreatePayload` normalizuje přes `?? ''`. Testuj na
  neprázdný trim, ne na null.
- **`country` z výsledku searche, nikdy nehádat `'cz'`** — fetch je
  `/{country}/{companyId}`; registr obsluhuje i SK (RPO).
- **`registryHits` klíčovat přes `path`** (`'supplier'`/`'customer'`),
  stejně jako `userActions` — žádné vlastní mapování stran.
- **Svelte 5 reaktivita objektů**: přiřazuj nový objekt
  (`quickAddBusy = { ...quickAddBusy, [path]: true }`), viz vzor
  `userActions` v modalu — mutace klíče in-place se nemusí propsat.
- **Ambiguous status**: quick-add nabízet i pro `ambiguous` (v DB jsou
  jmenní kandidáti, ale IČO nematchnulo — registr je legitimní volba).
  Nenabízet jen pro `matched` a po padlém rozhodnutí.
- **`person_exists` ze souběhu**: tvar odpovědi apply endpointu při chybě
  ověř v kódu (`_exchange` controller / `PersonApplier`) — snippet výše
  předpokládá `error.code` + canonical s `_resolve.header.matchedId`;
  přizpůsob realitě, netipuj.
- **Modal ve modalu**: wizard (Modal width=full) se otevírá nad review
  modalem (taky full). `FormDialog` z popoveru už dnes nad modalem funguje
  — ověř z-index/stacking i pro druhý full Modal; kdyby kolidoval, řeš
  v Modal.svelte, ne hackem ve wizardu.
- **Escape/close pořadí**: Escape ve wizardu nesmí zavřít i review modal
  pod ním (event nesmí probublat).
- **`resetAll()` ve wizardu** maže `query` — `initialQuery` aplikuj až po
  něm, jinak se prefill ztratí.
- **Nespouštět check pro `aiFailed`** — `resolve` je pak stejně prázdný,
  ale ať effect nepadá na null.
- **Debounce vs. přímé volání ve wizardu**: `runSearch` nastavuje
  `searchLoading = false` jen při shodě tokenu — při prefillu inkrementuj
  token stejně jako `handleInput`, ať se loading nezasekne.

## Poznámky k implementaci (2026-08-17)

Odchylky od zadání zjištěné ověřením v kódu:

1. **`person_exists` ze souběhu**: `ExchangeController::respond()` balí
   canonical při chybě do `error.details`, ne do `data`. Správná cesta je
   `applied.error.details.canonical._resolve.header.matchedId` — snippet
   v zadání (`applied.data.canonical…`) byl špatně.
2. **IČO pro předkontrolu se čte z `canonical[path].companyId`**, ne
   z `createPayload.company_id` — `ambiguous` blok `createPayload` nenese
   (`ResolveResult::ambiguous()` má jen candidates), a past v zadání
   quick-add pro ambiguous vyžaduje. Hodnoty jsou identické (payload se
   z canonical staví); bonus: pokryje i `notFound` s vytěženým IČO bez
   názvu. Totéž pro `initialQuery` wizardu (fallback `canonical[path].name`).
3. **Modal-in-modal a Escape**: žádná práce — `Modal.svelte` má modulový
   stack (Esc zavírá jen vrchní modal, vnořený se zmenšuje o 30 px/úroveň).
4. Navíc oproti zadání: prop `registryError` na `ResolveDecisionPanel`
   (bez něj by padlý quick-add v popoveru neměl žádnou zpětnou vazbu)
   a aktualizace `help/posta/kontrola-vytezeni.md` +
   `help/osoby/zalozeni-osoby.md` (konvence: help ve stejném commitu).
5. Známý roh pro E2E: u `ambiguous` strany může apply skončit 422
   `unresolved_required` — `PersonResolver` matchuje i podle názvu
   a oficiální název z registru se může trefit do týchž jmenných
   kandidátů. Chyba se zobrazí u tlačítka, nic se nerozbije. Stejné
   chování má už dnes wizard i headless `RegistryPersonImporter`
   (žádný nepředává `matchStrategy: identifiersOnly`) — případná
   oprava patří do `PersonApplier` pro všechny tři cesty, ne do
   quick-addu.

## Před implementací přečti

- `frontend/src/components/exchange/DocumentExchangePreview.svelte`
  (celý — snippet `partyCard`, `openDecision`, `handleDecide`, Popover)
- `frontend/src/components/exchange/ResolveDecisionPanel.svelte`
- `frontend/src/components/registry/RegistryImportWizard.svelte`
- `frontend/src/api/personsRegistry.js`
- `docs/registry-mvp.md` (endpointy, canonical, merge politika)
- GitHub Issue #28 + komentář
