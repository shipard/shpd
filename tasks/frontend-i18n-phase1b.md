# Frontend i18n — Fáze 1B (překlad UI chrome + LoginScreen)

**Stav:** hotovo

## Status / Cíl fáze

Po Fázi 1A (kostra i18n) zůstává napříč Svelte komponentami **hardcoded
český text** — taby vieweru, prázdné stavy, dropdown sidebaru, modální
titulky, LoginScreen, confirm dialogy. Tato fáze projde všech zhruba 22
komponent a přepne hardcoded řetězce na `t('klíč')`.

Současně tato fáze řeší **přepínač jazyka na přihlašovací obrazovce** —
discreet picker v patce login karty (varianta B z diskuze, viz
*Rozhodnutí k designu*).

Po dokončení fáze:

- Veškerý UI chrome ve frontendu se přepíná spolu s volbou jazyka.
- Nepřihlášený uživatel může jazyk přepnout přímo na LoginScreen.
- České texty zůstávají jen tam, kde je generuje server — tlačítka
  v ViewerToolbaru (`Add`/`Open`), taby formulářů (`Kontakt`, `Obecné`),
  taby detailu vieweru (`Obsah`, `Přílohy`). Tyto případy řeší **Fáze 1C
  (backend i18n)**, mimo rozsah této fáze.

## Návaznost

- **Fáze 1A** (`tasks/frontend-i18n-phase1a.md`) — kostra je hotová:
  language store, ICU MessageFormat, `Accept-Language` header,
  anti-flash bootstrap, přepínač v sidebaru. V této fázi pouze
  **rozšiřujeme slovníky** a **přepisujeme komponenty**.
- Backend i18n běží správně — server-driven obsah (názvy modulů,
  tabulek, sloupců, viewerů z jsonc) se přepíná díky `Accept-Language`
  hlavičce, kterou už `api/client.js` posílá.

## Scope

### V rozsahu

- Překlad hardcoded řetězců v komponentách (seznam viz *Inventář
  textů* níže):
  - `components/layout/Sidebar.svelte` — všechny zbylé hardcoded texty
    v dropdown menu (sekce Vzhled, položky Nastavení účtu / Nastavení
    aplikace / Odhlásit, „Zpět do aplikace", tooltip Sbalit/Rozbalit,
    status „Načítám…" / „Nepodařilo se načíst navigaci")
  - `components/viewer/Viewer.svelte` — taby `Aktivní/Archív/Koš/Vše`,
    placeholder hledání, prázdné stavy, modální dialogy reanalyze
  - `components/viewer/ViewerDetail.svelte` — prázdné stavy, modální
    dialog reject, alerts
  - `components/auth/LoginScreen.svelte` — všechny texty + přepínač
    jazyka v patce karty
  - `components/ui/Modal.svelte` — `aria-label="Zavřít"`
  - `components/form/FormDialog.svelte` — confirm dialog při zavření
    s neuloženými změnami, fallback titulek „Načítám…"
  - `components/form/FormEditor.svelte` — error messages (fallbacky
    `'Nepodařilo se načíst formulář.'` apod.), titulek „Nový záznam",
    loading state
  - `components/form/FormStateBadge.svelte`,
    `components/form/FormStateBar.svelte`,
    `components/form/FormSubTable.svelte`,
    `components/form/AttachmentPanel.svelte`,
    `components/form/FormRenderer.svelte`
  - `components/browser/TableBrowser.svelte`
  - `components/layout/Header.svelte`,
    `components/layout/ContentArea.svelte`,
    `components/layout/TabBar.svelte`
  - `components/viewer/ViewerToolbar.svelte` — pouze pokud se v něm
    nakonec zachová nějaký text (v tuto chvíli nic český neobsahuje,
    jen ikon mapping)
  - `components/ui/Button.svelte`, `components/ui/Icon.svelte` —
    `aria-label`, fallback texty, error stringy
- Rozšíření `frontend/src/i18n/cs.js` a `en.js` o všechny nové klíče.
- Logika ICU plurálů pro pár míst, kde je to přirozené (např. „1
  záznam / 2 záznamy / 5 záznamů"). Pokud žádné takové místo není, tato
  část odpadá.
- LoginScreen — přidat language picker v patce karty.
- Spustit `npm run check:i18n` na konci — slovníky musí být v synu.

### Mimo rozsah (Fáze 1C)

- **Tlačítka ve ViewerToolbar** (`Add`, `Open`, `Reanalyze`) — labels
  jdou ze serveru (`TableViewer::getToolbarActions()` v PHP). Frontend
  jen zobrazí `action.label`. Lokalizace je úloha backendu.
- **Záložky v editačních formulářích** (`Kontakt`, `Obecné`) — generuje
  `JsoncFormLoader` / `AutoFormBuilder`, čte `label` z jsonc bez i18n
  resolution. Backend.
- **Detail taby ve vieweru** (`Obsah`, `Přílohy`, `Analýzy`, `Originál`)
  — generují konkrétní Viewer třídy v PHP. Backend.
- **Validační hlášky ze serveru** — mapping `error.code → překlad`.
  Backend i frontend, ale závisí na backend částech, takže Fáze 1C.
- **Doplnění `name:en`/`label:en` v 12 jsonc souborech** — backend.
- **`preferred_language` v `core_system_users`** — odloženo (volba
  per-zařízení je dle dohody dostatečná).

## Rozhodnutí k designu (potvrzená)

- ✓ **LoginScreen má vlastní přepínač jazyka — varianta B (discreet
  picker v patce karty).** Důvod: B2B SaaS standard (Linear, Notion,
  GitLab to mají takto), pokrývá edge case prvního přihlášení
  z cizího zařízení / spolupracovníka z ciziny.
- ✓ **Žádné vlajky** — používají se jen text labels (`Čeština` /
  `English` / `Automaticky`). Vlajky jsou politicky problematické
  (čeština vs. „čeština = ČR", angličtina = která země?) a vizuálně
  rušivé na čistém login screenu. Stačí tučný text.
- ✓ **Endonyma jsou stejná v obou slovnících** — `Čeština` zůstává
  `Čeština` i v anglickém UI, `English` zůstává `English` i v českém
  UI. Konzistentní s rozhodnutím z Fáze 1A.
- ✓ **Šablona klíčů: `'oblast.komponenta.element'`** — oblast = funkční
  doména (`viewer`, `form`, `login`, `sidebar`, `common`, `error`),
  komponenta = co konkrétně, element = na úrovni textu (typicky
  `label`, `placeholder`, `title`, `empty`, `loading`, `error`).
  Příklady: `viewer.tab.active`, `viewer.search.placeholder`,
  `form.dialog.unsavedChanges`, `login.button.submit`.
- ✓ **Žádné pořadí slov v překladu přes konkatenaci** — nikdy ne
  `t('viewer.tab.label') + ' ' + count`. Vždy přes ICU placeholder
  `'{tab} ({count})'` s parametry. Slovanské jazyky mají jiné pořadí
  než angličtina (`Záznamů: 5` vs. `5 records`).
- ✓ **Pluralizace přes ICU MessageFormat** — pokud se v inventáři objeví
  místo s počtem (např. „1 záznam / 5 záznamů"), použije se
  `'{count, plural, one {# záznam} few {# záznamy} many {# záznamů} other {# záznamů}}'`.
- ✓ **Alerty (`alert('Nepodařilo se uložit: ' + msg)`) zůstávají
  prozatím jako alert** — refaktor na toast notifikace je out of scope.
  Jen přeložit text. `msg` z `result.error.message` zůstává v jazyce,
  v jakém přijde ze serveru (řeší Fáze 1C přes `error.code` mapping).

## Inventář textů (orientační — kompletní seznam je v gitu před touto fází)

Před začátkem implementace projít `frontend/src/` a najít všechny
hardcoded české řetězce. Spolehlivý postup:

```bash
cd frontend/src
grep -rnE "[áčďéěíňóřšťúůýž]" --include="*.svelte" --include="*.js" \
  | grep -v "^.*://" \
  | grep -v "^[^:]*:[0-9]*:\s*//" \
  | grep -v "^[^:]*:[0-9]*:\s*/\*" \
  | grep -v "^[^:]*:[0-9]*:\s*\*"
```

Filtruje protokoly, jednořádkové komentáře, hvězdičkové komentáře.
Zbylé řádky obsahují hardcoded text v JSX/HTML/JS literálech, které
je třeba přeložit.

**Hrubý odhad podle rychlé inventury (Fáze 1A)**:

| Komponenta                          | Počet míst |
|-------------------------------------|-----------|
| `components/form/FormEditor.svelte` | ~10–15    |
| `components/viewer/ViewerDetail.svelte` | ~15–20  |
| `components/layout/Sidebar.svelte`  | ~10       |
| `components/viewer/Viewer.svelte`   | ~10       |
| `components/form/FormDialog.svelte` | ~5        |
| ostatní (15 komponent)              | ~30       |
| **celkem**                          | **~80–100** klíčů |

Tato čísla jsou jen vodítko — skutečný inventář udělej před začátkem.

## Soubory

### Měněné

```
frontend/src/i18n/cs.js                       # rozšíření o 80–100 nových klíčů
frontend/src/i18n/en.js                       # rozšíření o 80–100 nových klíčů
frontend/src/components/auth/LoginScreen.svelte
frontend/src/components/layout/Sidebar.svelte
frontend/src/components/layout/Header.svelte
frontend/src/components/layout/ContentArea.svelte
frontend/src/components/layout/TabBar.svelte
frontend/src/components/viewer/Viewer.svelte
frontend/src/components/viewer/ViewerDetail.svelte
frontend/src/components/viewer/ViewerToolbar.svelte
frontend/src/components/viewer/ViewerRow.svelte
frontend/src/components/form/FormDialog.svelte
frontend/src/components/form/FormEditor.svelte
frontend/src/components/form/FormRenderer.svelte
frontend/src/components/form/FormElement.svelte
frontend/src/components/form/FormField.svelte
frontend/src/components/form/FormSubTable.svelte
frontend/src/components/form/FormStateBadge.svelte
frontend/src/components/form/FormStateBar.svelte
frontend/src/components/form/AttachmentPanel.svelte
frontend/src/components/form/FormTab.svelte
frontend/src/components/browser/TableBrowser.svelte
frontend/src/components/ui/Modal.svelte
frontend/src/components/ui/Button.svelte
frontend/src/components/ui/Icon.svelte
docs/frontend.md                              # update sekce Internacionalizace
```

### Nové

Žádné — všechna infrastruktura je z Fáze 1A.

## Implementační postup

### Krok 1 — inventář

Před začátkem nahrazování řetězců projít komponenty grep příkazem výše
a sestavit kompletní seznam míst. Strategický přínos: vyhneš se rozhází
implementace na pozdější objevy. Lepší je jednou projít všechno a pak
v jedné dávce nasadit klíče.

Inventář si zapiš jako dočasný TODO seznam — buď jako gist v komentáři
v `cs.js`, nebo jako poznámky v hlavičce každé komponenty (smazat na
konci).

### Krok 2 — slovníky

Doplň `cs.js` a `en.js` o všechny klíče identifikované v inventáři.
Konvence pojmenování:

```js
// cs.js
export default {
  // — Společné (rozšíření z 1A)
  'common.loading': 'Načítám…',
  'common.empty': 'Žádné záznamy',
  'common.error': 'Nastala chyba',
  'common.unknownError': 'Neznámá chyba',
  'common.confirm': 'Potvrdit',
  'common.yes': 'Ano',
  'common.no': 'Ne',

  // — Sidebar (zbylé položky)
  'sidebar.collapse': 'Sbalit menu',
  'sidebar.expand': 'Rozbalit menu',
  'sidebar.backToApp': 'Zpět do aplikace',
  'sidebar.accountSettings': 'Nastavení účtu',
  'sidebar.appSettings': 'Nastavení aplikace',
  'sidebar.logout': 'Odhlásit',
  'sidebar.appearance': 'Vzhled',
  'sidebar.appearance.light': 'Světlý',
  'sidebar.appearance.dark': 'Tmavý',
  'sidebar.appearance.auto': 'Auto',
  'sidebar.navigationLoadFailed': 'Nepodařilo se načíst navigaci',
  'sidebar.notAuthenticated': 'Nepřihlášen',

  // — Viewer
  'viewer.tab.active': 'Aktivní',
  'viewer.tab.archive': 'Archív',
  'viewer.tab.trash': 'Koš',
  'viewer.tab.all': 'Vše',
  'viewer.search.placeholder': 'Hledat…',
  'viewer.search.clear': 'Vymazat hledání',
  'viewer.empty': 'Žádné záznamy',
  'viewer.endOfList': 'To je všechno',
  'viewer.selectRecord': 'Vyberte záznam',
  'viewer.detail.empty': 'Žádné detaily',
  'viewer.reanalyze.title': 'Znovu analyzovat zprávu',
  'viewer.reanalyze.confirm': 'Spustit AI analýzu znovu? Existující extrahované dokumenty ve stavech {states} budou označeny jako nahrazené. Dokumenty, které jste již použili nebo zamítli, zůstanou beze změny.',
  // (dále...)

  // — Form
  'form.unsavedChanges': 'Máte neuložené změny. Opravdu chcete zavřít formulář?',
  'form.loading': 'Načítám…',
  'form.titleNew': 'Nový záznam',
  'form.loadFailed': 'Nepodařilo se načíst formulář.',
  'form.saveFailed': 'Nepodařilo se uložit záznam.',
  'form.saveAndClose': 'Uložit a zavřít',
  'form.saving': 'Ukládám…',
  // (dále...)

  // — LoginScreen
  'login.heading': 'Shipard',
  'login.username': 'Přihlašovací jméno',
  'login.password': 'Heslo',
  'login.submit': 'Přihlásit se',
  'login.submitting': 'Přihlašování…',
  'login.failed': 'Přihlášení se nezdařilo.',
  'login.languagePicker.label': 'Jazyk:',
};
```

Anglickou variantu drž v paritě 1:1.

### Krok 3 — komponenty (po jedné)

Pro každou komponentu:

1. `import { t } from '../../i18n/index.js';` (nebo jinou relativní cestu)
2. Najít každý hardcoded český řetězec
3. Nahradit `t('klíč')`
4. Pokud řetězec obsahuje hodnotu (např. error msg): `t('viewer.error.saveFailed', { msg })`
   v slovníku: `'Nepodařilo se uložit: {msg}'`
5. Spustit `npm run dev`, otevřít komponentu v prohlížeči, ověřit
   přepnutím jazyka přes dropdown v sidebaru.

**Upozornění na detail:** `tooltip` (`title=`) atributy a `aria-label`
se taky přepisují na `t()`.

**Pozor na `$derived` a reaktivitu** — funkce `t()` čte z `language.current`
(`$state`-derived hodnota), takže Svelte komponenta se rerenderuje
automaticky. Ale **při `location.reload()` (z `language.setMode`)** se
zachovává neuložený stav formulářů → ztráta dat. To je vědomé
omezení, viz Fáze 1A — uživatel se před přepnutím musí ujistit, že
nemá rozpracovaný formulář. Pokud tato fáze najde dobré místo na
warning, doplnit (např. v `setMode` zkontrolovat, zda je otevřený
nějaký FormDialog s dirty stavem, a pokud ano, zobrazit confirm).
Implementace warningu je **volitelná** — pokud je to složité, ponech
na samostatný refinement task.

### Krok 4 — LoginScreen language picker

Přidat patku do existující `LoginScreen.svelte` karty:

```svelte
<script>
  import { language, t } from '../../i18n/index.js';
  // ... ostatní existující importy

  const languageOptions = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function handleLanguageChange(e) {
    language.setMode(e.target.value);  // → location.reload()
  }
</script>

<div class="shpd-login">
  <div class="shpd-login__card">
    <!-- ... existující obsah karty ... -->

    <!-- Patka karty: language picker -->
    <div class="shpd-login__footer">
      <label class="shpd-login__lang-label" for="login-language">
        {t('login.languagePicker.label')}
      </label>
      <select
        id="login-language"
        class="shpd-login__lang-select"
        value={language.mode}
        onchange={handleLanguageChange}
      >
        {#each languageOptions as opt}
          <option value={opt.value}>{t(opt.labelKey)}</option>
        {/each}
      </select>
    </div>
  </div>
</div>
```

Styly v `<style>` sekci LoginScreen:

```css
.shpd-login__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--shpd-space-sm);
  margin-top: var(--shpd-space-lg);
  padding-top: var(--shpd-space-md);
  border-top: 1px solid var(--shpd-color-border);
}

.shpd-login__lang-label {
  font-size: var(--shpd-font-size-sm);
  color: var(--shpd-color-text-secondary);
}

.shpd-login__lang-select {
  padding: 4px 8px;
  font-size: var(--shpd-font-size-sm);
  font-family: inherit;
  color: var(--shpd-color-text);
  background-color: var(--shpd-color-bg);
  border: 1px solid var(--shpd-color-border);
  border-radius: var(--shpd-radius-sm);
  cursor: pointer;
}

.shpd-login__lang-select:focus {
  outline: none;
  border-color: var(--shpd-color-border-focus);
  box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
}
```

Důvod `<select>` (a ne dropdown jako v sidebaru): native select je
stejně dostupný (klávesnice, accessibility), nepotřebuje vlastní state
pro otevření/zavření, je ihned hotový. V sidebaru je dropdown nutný kvůli
sekcím (Vzhled / Jazyk) — tady stačí jeden picker.

### Krok 5 — kontrola

Po dokončení projít:

```bash
cd frontend
npm run check:i18n           # slovníky musí být v synu
npm run build 2>&1            # build musí projít bez chyb a varování
npm run dev                   # smoke test
```

V dev módu projít:

1. LoginScreen má v patce select „Jazyk: [Čeština / English / Automaticky]".
   Klik změní jazyk a reloadne login.
2. Po přihlášení sidebar dropdown nezůstal hardcoded česky — sekce
   „Vzhled" (`Světlý`/`Tmavý`/`Auto`) i „Jazyk" jsou přeložené.
3. Viewer taby: `Aktivní/Archív/Koš/Vše` se v `en` UI změní na
   `Active/Archive/Trash/All`. Placeholder hledání se přepne.
4. Modal dialog na reanalyze (Mail viewer): titulek a tlačítka se
   přepnou.
5. FormDialog confirm při zavření s dirty stavem se zobrazí v aktuálním
   jazyce.
6. **Hardcoded zbytky**: ve `viewer.toolbar` zůstávají `Add`/`Open`,
   v Persons FormDialog zůstávají taby `Kontakt`/`Obecné`. To je
   v pořádku — řeší Fáze 1C.

### Krok 6 — dokumentace

Update `docs/frontend.md` sekce **Internacionalizace** (vytvořená v Fázi
1A) — přidat:

- Inventář pokrytí (cca 80–100 klíčů, ~22 komponent).
- Konvence klíčů: `oblast.komponenta.element` se třemi tečkami max.
- Poznámka: server-driven labels (`Add`/`Open`, taby formulářů, taby
  detailu) se nepřekládají v `t()` — jejich lokalizaci řeší Fáze 1C.
- Příklad pluralizace přes ICU.
- Příklad parametrů (`{msg}`, `{count}`).

## Akceptační kritéria

1. `cd frontend && npm run check:i18n` — projde se zelenou.
2. `cd frontend && npm run build 2>&1` — projde bez chyb a varování.
3. **Manuální průchod aplikace v anglickém UI**: žádný český text
   v UI chrome. Server-driven obsah (názvy modulů, sloupců) je
   v angličtině. Server-driven tlačítka (`Add`, `Open`) a taby
   formulářů zůstávají česky/anglicky podle backendu (out of scope).
4. **Manuální průchod aplikace v českém UI**: vše funguje jako dřív,
   jen místo hardcoded řetězců prochází `t()`. Žádný regres v UX.
5. **LoginScreen**: v patce karty je picker jazyka. Klik na položku
   změní jazyk i přihlašovacího formuláře.
6. **Žádný hardcoded český řetězec ve `frontend/src/components/`** —
   ověřitelné přes:
   ```bash
   cd frontend/src
   grep -rnE "[áčďéěíňóřšťúůýž]" --include="*.svelte" \
     | grep -v "^.*//" \
     | grep -v "^.*/\*" \
     | grep -v "^.*\* "
   ```
   Mělo by zbýt jen pár drobností v komentářích (nebo nic). Komentáře
   v češtině jsou OK.

## Návazné fáze

- **Fáze 1C (backend i18n)**: lokalizace server-side generovaných
  labels — `TableViewer::getToolbarActions()` (`Add`, `Open`),
  `JsoncFormLoader` + `AutoFormBuilder` (taby formulářů, `Obecné`),
  individuální Viewer třídy (detail taby), error code mapping,
  doplnění `name:en` ve 12 jsonc, `getDefaultLanguage()`
  v `DataSourceConfig`.
