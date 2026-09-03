# Sub-tabulky ve formulářích — fáze 2: dialog řádku (Přidat / Přidat a pokračovat, Předchozí/Další)

**Stav:** naplánováno — design schválen 2026-09-03 (issue #53), závisí na `subtable-phase1.md`

## Kontext / Cíl

Druhá fáze issue **shipard/shpd#53**. Po fázi 1 má sub-tabulka správné
sloupce a read-only režim; dialog řádku je ale stále obyčejný `FormDialog`
bez vazby na seznam řádků. Cíl fáze 2 — revize tlačítek a navigace
v dialogu sub-záznamu:

- u **nového** záznamu dvě akce: **Přidat** (uloží a zavře dialog) a
  **Přidat a pokračovat** (uloží, vyčistí formulář a rovnou zadávám další);
- u **existujícího** záznamu šipky **‹ ›** — procházení sousedních řádků
  bez zavírání dialogu, funkční i v read-only režimu (prohlížení);
- **žádné tlačítko Zavřít** — zavírá se křížkem, Esc a klikem mimo modal
  (platí pro všechny dialogy, i top-level).

Vše je univerzální — platí pro každý `FormSubTable` (řádky dokladu, kontakty,
adresy, účty, měsíce).

## Rozhodnutí k designu

- ✓ **Nový sub-záznam:** tlačítka **Přidat** (dnešní Uložit — uloží a
  dialog zavře) a **Přidat a pokračovat** (uloží → reset na nový záznam
  s `defaultData` → fokus na první pole). Název druhého tlačítka je i18n
  klíč (`form.saveAndContinue`), může se změnit bez zásahu do kódu.
- ✓ **Existující sub-záznam bez doc states** (řádky dokladu, měsíce):
  tlačítko **Uložit** — uloží a zavře (dnešní chování).
- ✓ **Existující sub-záznam s doc states** (Adresy, Kontakty, Bankovní
  účty — archivace je nutná): **beze změny** — Uložit nechá dialog
  otevřený, přechody stavů (Archivovat…) zůstávají vedle Uložit. Větev
  `hasDocStates` ve `FormSubTable.handleDialogSaved` tedy zůstává.
- ✓ **Šipky ‹ › jen u existujícího záznamu**, v hlavičce modalu; na kraji
  seznamu **šedé (disabled)**, ne skryté. U nového záznamu se šipky
  nerenderují.
- ✓ **Navigaci vlastní `FormSubTable`** (má seřazený seznam řádků), dialog
  jen dostane `navigation = { index, count, onPrev, onNext }` a zobrazí
  ovládání. `FormDialog` bez `navigation` vypadá jako dnes.
- ✓ **Předchozí/Další při rozeditovaném řádku:** zablokovat a zeptat se
  (`ConfirmDialog` „Neuložené změny…" — Zahodit / Zůstat), NE auto-uložit.
- ✓ **Žádné tlačítko Zavřít** — ani v top-level, ani v sub-dialozích.
- ✓ **Velikost modalu beze změny** — zůstává jednotná velikost dle
  `tasks/form-modal-unified-size.md` (vnořený dialog přes depth-shrink).
  Pokud se ukáže nepohodlná, řeší se samostatně později.
- ✓ **`window.confirm` ve `FormDialog.handleClose` nahradit `ConfirmDialog`**
  z fáze 1 (stejný dialog jako u Předchozí/Další).

## Před implementací přečti

- `tasks/subtable-phase1.md` — kontrakt endpointu, `ConfirmDialog`,
  `readOnly` propagace
- `frontend/src/components/form/FormSubTable.svelte` (po fázi 1) —
  `handleDialogSaved` s `hasDocStates` větví
- `frontend/src/components/form/FormDialog.svelte` — celý; `$effect` reset
  při `open`, `handleClose`, `handleSaved`, `Modal` props/sloty
- `frontend/src/components/form/FormEditor.svelte` — jak reaguje na změnu
  `recordId` (existuje `$effect` na `recordId`? ověř — bez něj se při
  Předchozí/Další formulář nepřenačte), `handleSave`, `closeForm` logika,
  `loadedDataSnapshot`, `defaultData`, `formTitle` (`title` vs `title_new`)
- `frontend/src/components/form/FormStateBar.svelte` — `showSave`, kde
  přidat druhé tlačítko; mobilní kebab (`inKebab`, `layoutStore.isMobile`)
- `frontend/src/components/ui/Modal.svelte` — `headerExtra`, `summary`
  sloty (kam umístit šipky), modal stack (klávesy jen na vrcholu)
- `docs/edit-forms.md` — kapitola o `FormDialog` a stavové liště

## Krok 1 — `FormDialog`: `navigation`, `onSaveAndContinue`

Nové props:

```ts
navigation?: {
  index: number;                           // 0-based; -1 = nový záznam → šipky se nerenderují
  count: number;
  onPrev: () => void;
  onNext: () => void;
} | null;
onSaveAndContinue?: () => void;            // po úspěšném uložení + resetu na nový
```

- Šipky v hlavičce modalu (snippet do `headerExtra` nebo nový slot
  `Modal.headerNav` — vyber to, co nekoliduje s `FormStateBadge` a
  `summary`): `‹` `3 / 12` `›`; disabled na krajích.
- Klávesy `Alt+←` / `Alt+→`, jen když je dialog na vrcholu stacku (vzor
  Esc v `Modal`).
- `handleClose` a přechod Předchozí/Další sdílejí `guardDirty(then)`:
  není-li dirty → `then()`; je-li → `ConfirmDialog` (Zahodit → `then()`,
  Zůstat → nic). `window.confirm` zmizí.
- `onSaveAndContinue` a `navigation` se předávají do `FormEditor`.

## Krok 2 — `FormEditor` + `FormStateBar`: Přidat / Přidat a pokračovat

- `FormEditor` prop `onSaveAndContinue?: () => void`. Nová akce
  `handleSaveAndContinue()`: `await handleSave()` → při úspěchu
  `resetToNew()` (`currentId = null`, načíst `meta` pro nový záznam
  s `defaultData`, nový snapshot, fokus prvního editovatelného pole) →
  `onSaveAndContinue()` (subtable refetchne řádky). Při validační chybě
  zůstat na záznamu s chybami, nic neresetovat.
- `FormStateBar` dostane `isNew`, `onSaveAndContinue`:
  - `isNew && onSaveAndContinue` → **Přidat** (primary, = `onSave`) +
    **Přidat a pokračovat** (secondary);
  - `isNew` bez `onSaveAndContinue` (top-level dialog) → **Uložit** jako
    dnes (žádná změna top-level dialogů);
  - existující záznam → **Uložit** + přechody stavů jako dnes.
  - Na mobilu Přidat a pokračovat do kebabu.
- i18n: `form.add` („Přidat"), `form.saveAndContinue` („Přidat
  a pokračovat"), `form.navPosition` („{index} / {count}").
- Po **Přidat** (nový záznam, sub-dialog) se dialog zavře i u formulářů
  s doc states — `hasDocStates` větev ve `FormSubTable` se týká jen
  existujících záznamů. Uprav podmínku: zavřít, když
  `!info.hasDocStates || info.wasNew`.

## Krok 3 — `FormSubTable`: propojení

- `navigation`: `index = rows.findIndex(r => r.id === editRecordId)`
  (−1 pro nový), `count = rows.length`; `onPrev/onNext` nastaví
  `editRecordId` na sousední id → `FormDialog` dostane nový `recordId` →
  `FormEditor` přenačte. Navigace jde přes **nefiltrovaný** seřazený
  seznam; filtr je jen pro tabulku (zapiš do komentáře).
- `onSaveAndContinue` → refetch řádků; dialog zůstává na novém záznamu.
- Po `onSaved` refetch; zavření dle pravidla výše.
- `readOnly={disabled}` (z fáze 1) — v read-only režimu žádné Přidat /
  Uložit, šipky ano.

**Commit 1:** `feat(forms): dialog sub-záznamu — Přidat a pokračovat, Předchozí/Další`

## Krok 4 — dokumentace

- `docs/edit-forms.md`: props `FormDialog` (`navigation`,
  `onSaveAndContinue`), pravidla tlačítek (nový / existující / doc states),
  klávesy, zavírání jen křížek + Esc + klik mimo.
- Hlavička tohoto tasku → `hotovo` ve stejném commitu.

**Commit 2:** `docs(forms): dialog sub-záznamu`

## Hotovo když (E2E na dev DS s fiktivními daty)

1. Faktura v konceptu, Řádky → Upravit 2. řádek: hlavička ukazuje `2 / N`,
   `›` přejde na 3., `‹` zpět; `Alt+→` funguje; na posledním je `›` šedé.
2. Změnit popis, kliknout `›` → `ConfirmDialog` Neuložené změny; Zůstat
   nechá formulář, Zahodit přejde a změna se ztratí.
3. Přidat → vyplnit → **Přidat a pokračovat**: řádek se objeví v tabulce,
   formulář je prázdný (jen FK), fokus v prvním poli, titulek „Nový
   řádek", šipky chybí; součty hlavičky přepočítané.
4. Přidat → vyplnit → **Přidat**: řádek v tabulce, dialog zavřený.
5. Upravit existující → Uložit: dialog se zavře (bez doc states).
6. Read-only faktura: Zobrazit → šipky fungují, Uložit / Přidat chybí.
7. Osoba → Adresy: nová adresa má Přidat / Přidat a pokračovat; existující
   adresa má Uložit + Archivovat, po Uložit zůstává otevřená (dnešní
   chování); šipky fungují.
8. Top-level dialogy (Osoba, Faktura z prohlížeče): žádné šipky, žádné
   Přidat a pokračovat, žádné Zavřít, velikost beze změny.
9. Křížek / Esc / klik mimo s neuloženými změnami → `ConfirmDialog`, ne
   `window.confirm`.
10. `npm run build` bez chyb; `tasks-index.py --check` a `check-sensitive.py`
    projdou.

## Pasti

- **Reakce `FormEditor` na změnu `recordId`.** Pokud dnes načítá jen při
  mountu, Předchozí/Další nic neudělá. Nejčistší je `$effect` na `recordId`
  s přenačtením a resetem snapshotu; pozor na dvojí načtení při otevření.
- **`$effect` ve `FormDialog` resetující stav při `open`** — při navigaci
  se `open` nemění, takže `savedHeaderInfo`/`currentDocStates` musí
  resetovat `onFormLoaded`, ne `open`. Ověř, že hlavička nezůstane
  z předchozího řádku.
- **Fokus po `resetToNew`:** první pole může být `select` s `triggers:
  'reload'` (`row_kind`) — fokus tam je správný, ale nesmí odpálit
  recalculate. Ověř `FormElement`.
- **Dirty po Přidat a pokračovat:** snapshot musí odpovídat novému prázdnému
  záznamu, jinak je nový formulář hned dirty a křížek se ptá zbytečně.
- **Kebab na mobilu** — ověř všechny tři layouty + mobil (viz issue #46).
- **Nepřidávej Zavřít** ani „pro jistotu" — rozhodnuto: křížek, Esc, klik
  mimo stačí.
