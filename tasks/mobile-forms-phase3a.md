# Task: Mobilní formuláře — modál fullscreen (fáze 3a)

## Status / Cíl

Třetí oblast responzivního designu: editační formuláře na telefonu
(~380px). Tato pod-fáze (3a) řeší **modál jako kontejner** — na mobilu
se z plovoucí karty stane fullscreen plocha. Vnitřní layout polí
(label nad input, zarovnání) řeší samostatná fáze 3b.

Dnešní stav: `FormDialog` otevírá `Modal` s
`width="clamp(1200px, 80vw, 1700px)"` a `height="clamp(720px, 88vh,
1100px)"`. Na 380px je `clamp` minimum 1200px × 720px → modál chce být
mnohem větší než obrazovka, narazí na `max-width: calc(100vw - 2*lg)`
a vznikne rozbitý layout (karta s okraji, vnitřek přeteklý). Cíl: na
mobilu (≤ 768px) je každý `Modal` fullscreen — 100vw × 100vh, bez
zaoblení, bez overlay okrajů, footer tlačítka na plnou šířku.

Týká se **všech** modálů postavených přes `Modal.svelte` (FormDialog,
reanalyze, reject, Exchange preview, …). Pevné `width`/`height` props
se na mobilu ignorují (media query je přebije). `window.confirm()`
(nativní prohlížečový dialog, např. „Uložit změny?") to neřeší — ten
zůstává nativní.

Na desktopu (> 768px) **beze změny** — clamp i pevné šířky fungují jak
dnes, včetně depth-shrink u vnořených modálů.

## Návaznost

- `form-modal-unified-size.md` — unifikace modálů na 1200×900, odstranění
  `fullSize`. Tahle fáze staví na tom (clamp width/height v FormDialog).
- `mobile-app-chrome-phase1.md` — breakpoint 768px (`MOBILE_BREAKPOINT`
  v `layout.svelte.js` + literál v `@media`). Stejný literál použít tady.
- `form-header-info.md` — strukturovaná hlavička (icon/title/subtitle/
  badge/summary). Summary blok se na mobilu skrývá (viz rozhodnutí).
- Dokumentace: `docs/edit-forms.md` (modál sizing), `docs/design-system.md`
  (layout konvence) aktualizovat.
- **Návaznost dopředu**: fáze 3b (inputy pod sebe) — NENÍ součástí.
  Tady jen kontejner.

## Scope

### V rozsahu

- **`Modal.svelte`** — `@media (max-width: 768px)` blok, který přepíše
  kartu na fullscreen: 100vw × 100vh, `border-radius: 0`, `max-width`/
  `max-height` na 100%. Přebije inline `cardStyle` (pozor — inline styly
  mají vyšší specificitu než třída; viz implementační poznámka).
- **Depth-shrink vypnout na mobilu** — vnořený modál je taky fullscreen
  (překryje rodiče), žádné 30px odsazení. Stack a Esc/zavírání beze změny.
- **Header summary skrýt na mobilu** — pravý blok shrnutí
  (`.shpd-modal__header-summary`) `display: none` na ≤ 768px. Hlavička
  zůstane kompaktní (ikona + titul + badge + subtitle + ✕).
- **Footer tlačítka na plnou šířku** — na mobilu `.shpd-modal__footer`
  tlačítka roztáhnout (každé `flex: 1` nebo stack), ať se dobře tisknou
  palcem. Detail viz krok 3.
- Breakpoint 768px jako literál v `@media` (ladí s `MOBILE_BREAKPOINT`).

### Mimo rozsah

- **Vnitřní layout polí** (label nad input, zarovnání doleva, label
  text-align) — fáze 3b. Tady se polí NEDOTÝKÁME. `FormColumn`,
  `FormFieldRow`, `FormInline` zůstávají. (Pozn.: `FormSection` už má
  vlastní `@media (max-width: 700px)` pro sloupce → na mobilu se sekce
  skládají pod sebe; to je předexistující a ponecháváme.)
- **`clamp()` v FormDialog** — beze změny. Řeší desktop/tablet nad 768px.
  Na mobilu ho fullscreen media query v Modalu přebije. Nevětvíme
  FormDialog.
- **`window.confirm()` dialogy** — nativní prohlížečové, netýká se jich.
- **Opt-out z fullscreenu pro konkrétní malý dialog** — zatím nepotřeba.
  Pokud se po nasazení ukáže, že nějaký dialog působí fullscreen divně,
  přidá se prop (`fullscreenOnMobile={false}`) později. Nepředbíhat.
- **Scroll uvnitř fullscreen modálu** — `.shpd-modal__body` už má
  `overflow-y: auto`, funguje. Neřešíme znovu.
- **Animace** (slide-up místo fade) — fade zůstává. Slide-up sheet
  animace je možný budoucí refinement, ne teď.

## Datový tok

Žádný — čistě CSS. `Modal.svelte` dostane `@media` blok, který se
aktivuje podle viewportu. Nepotřebuje `layoutStore.isMobile` (CSS
breakpoint stačí, je to čistě vizuální přepnutí, ne změna chování).

Pozn. k volbě CSS vs. JS: na rozdíl od vieweru (kde `isMobile` řídí
*chování* — list/detail) je tady jen *vzhled* (karta → fullscreen).
Proto čisté CSS media query, žádné čtení storu. Konzistentní se
strategií B z fáze 1.

## Co je potřeba udělat

Všechny změny v jednom souboru: `frontend/src/components/ui/Modal.svelte`.
Plus dokumentace.

### 1. Fullscreen karta na mobilu

Problém specificity: `cardStyle` je **inline** `style` atribut na
`.shpd-modal__card` (počítá width/max-width/height z `depth` a `width`
prop). Inline styly mají vyšší specificitu než třídní CSS pravidla, takže
obyčejné `@media` pravidlo na třídu je nepřebije. Řešení: použít
`!important` v media query (legitimní případ — přebití inline stylu pro
responsivitu).

Přidat na konec `<style>` bloku v `Modal.svelte`:

```css
/* ============================================================
 * Mobilní fullscreen
 * ------------------------------------------------------------
 * Na ≤ 768px je každý modál fullscreen (100vw × 100vh), bez
 * zaoblení a okrajů. Přebíjí inline cardStyle (width/height/
 * max-* počítané z depth a width prop) — proto !important.
 *
 * Breakpoint 768px ladí s MOBILE_BREAKPOINT v layout.svelte.js.
 *
 * Depth-shrink (odsazení vnořených modálů) se tím ruší: všechny
 * hloubky dostanou stejný fullscreen rozměr, vnořený modál
 * překryje rodiče. Stack/Esc/zavírání funguje beze změny.
 * ============================================================ */
@media (max-width: 768px) {
  .shpd-modal {
    /* Overlay okraje pryč — karta vyplní celou plochu. */
    align-items: stretch;
    justify-content: stretch;
  }

  .shpd-modal__card {
    width: 100vw !important;
    max-width: 100vw !important;
    height: 100vh !important;
    max-height: 100vh !important;
    border-radius: 0 !important;
  }

  /* Summary blok v hlavičce (shrnutí cen u dokladů) se na mobilu
     skrývá — redundantní s obsahem formuláře, hlavička musí zůstat
     kompaktní na úzké obrazovce. */
  .shpd-modal__header-summary {
    display: none;
  }
}
```

Pozn.: `100vh` má známý problém s mobilními prohlížeči (adresní lišta
ukrajuje výšku). Pokud se ukáže jako problém (footer mimo obrazovku pod
lištou), zvážit `100dvh` (dynamic viewport height) — moderní prohlížeče
ho podporují. Pro fázi 3a začni s `100vh`; pokud smoke test na reálném
telefonu ukáže ořez, přepni na `100dvh` s fallbackem:

```css
    height: 100vh !important;
    height: 100dvh !important;  /* moderní prohlížeče — přebije předchozí */
```

(Druhá deklarace přebije první v prohlížečích, co `dvh` znají; staré ji
ignorují a použijí `100vh`.)

### 2. Footer tlačítka na plnou šířku

Dnešní footer: `justify-content: flex-end`, tlačítka vedle sebe vpravo.
Na mobilu palcem se lépe trefují velká tlačítka. Přidat do stejného
`@media` bloku:

```css
  /* Footer tlačítka roztáhnout na plnou šířku (lepší pro dotyk).
     Tlačítka jsou přímé děti footeru (Button komponenty renderují
     <button>). flex: 1 je rozloží rovnoměrně. */
  .shpd-modal__footer {
    /* Místo zarovnání vpravo je na celou šířku. */
    justify-content: stretch;
  }

  .shpd-modal__footer > :global(*) {
    flex: 1;
  }
```

Pozn. k `:global()` — tlačítka ve footeru emituje volající komponenta
(FormEditor, reanalyze dialog…) přes footer snippet, ne Modal sám. Svelte
scoped CSS by je minul, proto `:global()`. Stejný vzor jako už existující
`.shpd-modal__header-summary > :global(...)` pravidla v tomto souboru.

Ověřit, že to nerozbije footery s jedním tlačítkem (roztáhne se na plnou
šířku — to je OK, dokonce žádoucí) a s textovými tlačítky různé délky
(flex: 1 je srovná na stejnou šířku — OK).

### 3. Smoke test

**Desktop** (> 768px):

- FormDialog (Osoba, Faktura) — modál je plovoucí karta s clamp
  rozměry, depth-shrink u vnořeného Kontaktu funguje (rodič vykukuje).
  Žádná regrese.
- Reanalyze / reject dialog — pevné šířky (520/480px), karta, beze změny.

**Mobil** (≤ 768px, ideálně reálný telefon nebo DevTools ~380px):

- Otevři FormDialog (Přidat osobu z vieweru) → modál je fullscreen,
  100vw × 100vh, bez zaoblení, bez ztmavených okrajů kolem. Hlavička
  nahoře (ikona + titul + badge + subtitle), ✕ vpravo. Tělo scrolluje.
  Footer dole, tlačítka (Uložit / Zrušit) na plnou šířku.
- Faktura s header summary → summary blok (ceny) v hlavičce NENÍ vidět
  (skryto). Titul + badge + subtitle vidět. Hlavička kompaktní.
- Vnořený modál — z formuláře Osoby otevři subdialog (např. Kontakt) →
  taky fullscreen, překryje rodiče (žádné odsazení). ✕ / Esc zavře jen
  vnořený, vrátí na rodiče (stack funguje). Druhé Esc zavře rodiče.
- Reanalyze dialog (Došlá pošta) na mobilu → taky fullscreen (pevná
  520px šířka přebita). Obsah (select profilu) nahoře, footer tlačítka
  plná šířka. Působí OK? (Pokud rušivě, poznač pro budoucí opt-out —
  ale needituj teď.)
- Body scroll lock — pod fullscreen modálem nejde scrollovat pozadím
  (už funguje přes `document.body.style.overflow = 'hidden'`).
- Zavři modál → vrátíš se na viewer, žádný vizuální artefakt.
- **100vh test na reálném telefonu**: otevři dlouhý formulář, scrolluj
  dolů — je footer dosažitelný a není schovaný pod adresní lištou
  prohlížeče? Pokud ano (schovaný), přepni `height` na `100dvh` variantu
  (viz krok 1).
- Přepni okno mobil → desktop (rozšiř) s otevřeným modálem → modál se
  vrátí na plovoucí kartu s clamp rozměry. Žádný zaseklý fullscreen.

**Light i dark** — fullscreen modál, hlavička, footer čitelné v obou
(tokeny beze změny, jen rozměry).

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů.
- `vendor/bin/phpunit 2>&1` projde (žádné PHP změny).
- Smoke test (sekce 3) projde, desktop bez regrese.
- `docs/edit-forms.md` a `docs/design-system.md` aktualizovány.

## Rozhodnutí k designu (potvrzená)

- ✓ **Fullscreen na mobilu** — 100vw × 100vh, bez zaoblení, bez okrajů.
  Vybráno proti „skoro fullscreen sheet s okrajem nahoře".
- ✓ **Všechny modály přes `Modal.svelte`** — fullscreen platí univerzálně
  (FormDialog i malé dialogy). Pevné width/height props přebity media
  query. Jednodušší a konzistentní než větvit podle velikosti. `window.
  confirm()` se netýká (nativní). Opt-out prop pro konkrétní dialog se
  případně přidá později, nepředbíhat.
- ✓ **Depth-shrink vypnut na mobilu** — vnořený modál je taky fullscreen,
  překryje rodiče. Na fullscreenu není kam „vykukovat". Stack/zavírání
  beze změny.
- ✓ **Header summary skryto na mobilu** — ceny u dokladů jsou redundantní
  s obsahem, hlavička musí zůstat kompaktní na 380px.
- ✓ **Footer tlačítka plná šířka** — lepší pro dotyk palcem. `flex: 1`.
- ✓ **`clamp()` v FormDialog beze změny** — řeší desktop/tablet > 768px,
  na mobilu přebito. Nevětvíme FormDialog.
- ✓ **Čisté CSS, žádný `isMobile` ze storu** — je to jen vzhled (karta →
  fullscreen), ne změna chování. Konzistentní se strategií B (CSS na
  vzhled, JS jen na chování).
- ✓ **`100vh` start, `100dvh` fallback** — pokud reálný telefon ukáže
  ořez footeru pod adresní lištou, přepnout na `100dvh`.
- ✓ **Tablet (≤ 768px) taky fullscreen** — jeden breakpoint, tablet
  v mobilním režimu (konzistentní s fází 1/2). Clamp tablet otázka
  z dřívějška se tím rozpouští.

## Mimo rozsah / nezasahujeme

- **Vnitřní layout polí** (label/input pod sebe, zarovnání) — fáze 3b.
- **`FormColumn` / `FormFieldRow` / `FormInline` / `FormSection`** —
  needitujeme (FormSection má vlastní 700px media query, ponecháno).
- **`FormDialog` clamp** — beze změny.
- **`FormEditor`** — beze změny.
- **`window.confirm()` dialogy** — nativní, netýká se.
- **Backend** — žádné změny.
- **Slide-up animace, opt-out prop, dvh všude** — odložené / podmíněné.
