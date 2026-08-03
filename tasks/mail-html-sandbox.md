# Task: Sandboxovaný rendering HTML těla došlé pošty

**Stav:** hotovo

## Status / Cíl

Tělo HTML zprávy v detailu Došlé pošty se dnes renderuje přes
`{@html content.html}` v obyčejném `div` — bez sanitizace, bez izolace.
To má tři konkrétní projevy:

1. **Bezpečnost** — do DOM aplikace se vkládá HTML z nedůvěryhodného
   zdroje (e-mail od kohokoliv). Session token je v `localStorage`,
   takže spuštěný skript by ho mohl ukrást.
2. **CSS konflikty** — styly zprávy (globální selektory, kolize názvů
   tříd) rozbíjejí layout celé aplikace; a obráceně, tokeny aplikace
   rozbíjejí rendering e-mailu (tmavý režim vs. e-maily designované
   na bílou).
3. **Odkazy** — klik na odkaz ve zprávě naviguje aktuální tab,
   Shipard „zmizí".

Cíl: renderovat tělo zprávy v **sandboxovaném `<iframe srcdoc>`** —
stejný pattern jako Gmail/Fastmail. Sandbox bez `allow-scripts` řeší
(1) na úrovni prohlížeče, samostatný dokument řeší (2) oběma směry,
injektáž `<base target="_blank">` + popup sandbox flagy řeší (3).

`docs/mail/api-contract.md` §7 tohle chování slibuje od začátku
(„body_html se v UI renderuje sandboxovaně“) — implementace ale nikdy
nevznikla a komentář v `buildContentTab()` („Frontend ho renderuje
sanitovaně“) je nepravdivý. Raw HTML v DB zůstává beze změny (per
api-contract), mění se jen rendering.

## Návaznost

- `docs/mail/api-contract.md` §7 Bezpečnost — deklarovaný záměr,
  po implementaci aktualizovat formulaci na skutečný stav.
- `docs/frontend.md` — sekce **Formát detail panelu (`renderDetail()`)**,
  tabulka „Typy obsahu“: přibude typ `untrusted-html`.
- Samostatný follow-up (mimo tento task): anonymní přístup
  k `GET /_attachments/{id}/download|thumbnail` — vyžaduje diskuzi.

## Scope

### V rozsahu

- Nová sdílená komponenta `SandboxedHtml.svelte` (iframe + srcdoc +
  DOM úpravy + auto-height).
- Nový detail content type `untrusted-html`; backend ho použije pro
  `body_html` došlé pošty.
- Oprava nepravdivého komentáře v `IncomingMessagesViewer`.
- Aktualizace `docs/frontend.md` a `docs/mail/api-contract.md`.
- i18n klíč pro `title` atribut iframu.

### Mimo rozsah

- Server-side prefiltr HTML (strip `<script>`, `on*`, `javascript:`
  přes PHP DOM) — defense-in-depth, samostatný follow-up task.
  Sandbox je primární a dostatečná hranice.
- Blokování vzdálených obrázků (tracking pixely) s tlačítkem
  „Zobrazit obrázky“ — privacy follow-up.
- Inline `cid:` obrázky (embedded images) — nefungují ani dnes;
  vyžadovaly by ukládání MIME `Content-ID` u příloh
  (`core_attachments_files` ho nemá) a přepis `src` na attachment
  URL. Follow-up, pokud se ukáže potřeba na reálné poště.
- Plain-text fallback (`<pre>` s `htmlspecialchars`) — zůstává jako
  trusted `type: 'html'` inline; je escapovaný a bez CSS rizika.
- Ostatní `type: 'html'` payloady (prázdné stavy, interní snippety) —
  beze změny, jsou backend-generované a trusted.
- Attachment-auth nález — samostatná diskuze a task.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — `srcdoc`, ne endpoint.** Starý Shipard měl API endpoint
  vracející HTML + `iframe src`. Nový Shipard autentizuje výhradně
  přes `Authorization: Bearer` (token v `localStorage`, žádné
  cookies) — do `iframe src` requestu hlavička nedoteče, endpoint by
  musel být anonymní nebo přes podepsané URL. `body_html` navíc už
  v detail payloadu je. Proto `srcdoc` bez nového endpointu.
- ✓ **D2 — nový content type `untrusted-html`.** Stávající `html`
  zůstává pro trusted interní snippety. Nový typ je generický —
  reusable pro budoucí odeslanou poštu apod.
- ✓ **D3 — varianta A sandboxu:**
  `sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"`.
  Bez `allow-scripts` uvnitř nic neběží, takže `allow-same-origin`
  je bezpečné a umožňuje parentu přístup na `contentDocument`
  (fixace odkazů, auto-height).
  **KRITICKÁ INVARIANTA: nikdy nepřidat `allow-scripts`.**
  Kombinace `allow-scripts + allow-same-origin` u srcdoc = dokument
  sdílí origin aplikace a skript z e-mailu by přečetl Bearer token
  z `localStorage`. Musí být jako komentář přímo u `sandbox`
  atributu v komponentě.
- ✓ **D4 — defense-in-depth (prefiltr, blokování obrázků) jako
  follow-up**, ne součást MVP.

## Co je potřeba udělat

### 1. Frontend — `frontend/src/components/ui/SandboxedHtml.svelte` (nový)

Props: `{ html, title }` (`title` = accessibility popisek iframu).

Markup (jádro):

```svelte
<iframe
  bind:this={frame}
  {title}
  class="shpd-sandboxed-html"
  {/* BEZPEČNOST: nikdy nepřidávat allow-scripts — v kombinaci
     s allow-same-origin by skript z e-mailu měl přístup
     k localStorage aplikace (Bearer token). Viz task
     mail-html-sandbox.md, D3. */ null}
  sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
  referrerpolicy="no-referrer"
  srcdoc={html}
  onload={prepareDocument}
></iframe>
```

Pozn.: Svelte hodnotu atributu `srcdoc` escapuje sám — `html` se
předává tak, jak přišel z API. `body_html` může být fragment i celý
`<html>` dokument; prohlížeč obojí normalizuje do plnohodnotného
dokumentu, DOM průchod níže funguje pro oba případy.

`prepareDocument()` — po `load` eventu (spustí se i při změně
`srcdoc`), pracuje nad `frame.contentDocument`:

1. **`<base target="_blank">`** vložit jako první element `<head>`
   (pokud tam `base` už není). Řeší default cíl všech odkazů.
2. **Defaultní styly** — vložit `<style>` s nulovou specificitou
   (`:where()`), aby vlastní styly e-mailu vždy vyhrály:

   ```css
   :where(html) { background: #fff; }
   :where(body) {
     margin: 8px;
     font-family: system-ui, -apple-system, sans-serif;
     font-size: 14px;
     line-height: 1.5;
     color: #111;
     overflow-wrap: break-word;
   }
   :where(img) { max-width: 100%; height: auto; }
   ```

   Záměrně **vždy světlé pozadí** — e-maily jsou designované na
   bílou, tmavý režim aplikace se dovnitř nepropaguje.
3. **Odstranit `meta[http-equiv="refresh" i]`** — zabránit
   auto-navigaci iframu pryč ze srcdoc obsahu.
4. **Průchod `a[href]`:**
   - `a.protocol` mimo whitelist `http:`, `https:`, `mailto:`,
     `tel:` → odstranit `href` atribut (pokrývá `javascript:`,
     `data:`, `vbscript:`; kontrola přes `protocol` property je
     robustní — prohlížeč už normalizoval whitespace/entity triky
     v raw atributu).
   - `href` začínající `#` (in-page kotva) → `target="_self"`
     (jinak by ji `<base>` poslal do nového tabu).
   - ostatní → `rel="noopener noreferrer"` (target dodá `<base>`).
5. **Auto-height:** změřit
   `max(doc.documentElement.scrollHeight, doc.body?.scrollHeight ?? 0)`
   a nastavit `frame.style.height`. Znovu změřit na `load`/`error`
   každého `<img>` v dokumentu (obrázky doskakují asynchronně).

Dále v komponentě: `ResizeObserver` na samotném iframe elementu
(změna šířky panelu → reflow obsahu → nová výška) — registrace
v `$effect`, disconnect v cleanup.

Styly komponenty:

```css
.shpd-sandboxed-html {
  display: block;
  width: 100%;
  min-height: 60px;          /* placeholder než doběhne load */
  border: 1px solid var(--shpd-color-border);
  border-radius: var(--shpd-radius-md);
  background: #fff;          /* bílá i před loadem — žádný flash */
}
```

Rámeček + radius záměrně — v tmavém režimu bílý blok vypadá jako
záměrná „karta zprávy“, ne jako chyba.

### 2. Frontend — `frontend/src/components/viewer/ViewerDetail.svelte`

Import komponenty a nová větev v `renderContent` snippetu, hned za
stávající větví `content?.type === 'html'`:

```svelte
{:else if content?.type === 'untrusted-html'}
  <SandboxedHtml html={content.html} title={t('viewer.detail.mailBody')} />
```

Stávající `html` větev zůstává beze změny.

### 3. Frontend — i18n

Přidat klíč `viewer.detail.mailBody` („Tělo zprávy“ / „Message body“)
do obou jazykových souborů v `frontend/src/i18n/`. Ověřit
`npm run check:i18n`.

### 4. Backend — `modules/core/mail/src/IncomingMessagesViewer.php`

V `buildContentTab()`:

```php
// Tělo: preferujeme HTML, fallback na plain. HTML je nedůvěryhodný
// vstup (e-mail) — frontend ho renderuje v sandboxovaném iframe
// (SandboxedHtml.svelte), do DB se ukládá raw (api-contract §7).
$bodyContent = null;
if ($bodyHtml !== '') {
    $bodyContent = ['type' => 'untrusted-html', 'html' => $bodyHtml];
} elseif ($bodyPlain !== '') {
    // beze změny — escapovaný <pre> zůstává trusted 'html'
```

Jediná funkční změna backendu = hodnota `type`. Ostatní `type: 'html'`
místa v souboru (prázdné stavy) neměnit.

### 5. Dokumentace

- `docs/frontend.md`, tabulka **Typy obsahu**: nový řádek
  `untrusted-html` — „HTML z nedůvěryhodného zdroje (tělo e-mailu);
  renderuje se v sandboxovaném `<iframe srcdoc>` bez `allow-scripts`
  (`SandboxedHtml.svelte`): izolace skriptů i CSS, odkazy do nového
  tabu, auto-height. Nikdy nerozšiřovat sandbox o `allow-scripts`.“
  U stávajícího řádku `html` doplnit „pouze pro trusted,
  backend-generovaný obsah — pro cizí HTML použít `untrusted-html`“.
- `docs/mail/api-contract.md` §7: větu „body_html se v UI renderuje
  sandboxovaně (iframe nebo prefiltrace)“ přepsat na skutečný stav:
  sandboxovaný iframe přes content type `untrusted-html`
  (`SandboxedHtml.svelte`), raw HTML v DB beze změny.

## Commit strategie

Jeden atomický commit — backend změna typu a frontend větev na sobě
závisí (samotný backend switch by detail vykreslil prázdný, samotná
FE větev by byla mrtvý kód):

```
mail: sandboxovaný rendering HTML těla zprávy (untrusted-html + iframe)
```

## Smoke test

Ručně na dev prostředí nad reálnými/fake zprávami
(`FakeIncomingMessageGenerator` umí vygenerovat testovací zprávy):

- HTML zpráva se vykreslí ve viditelně ohraničeném bílém bloku,
  výška odpovídá obsahu (žádný vnitřní scrollbar), obsah pod ní
  (Přílohy, Technické údaje) navazuje.
- Zpráva, která dřív rozbíjela layout aplikace (CSS kolize) —
  aplikace zůstává nedotčená, zpráva vypadá dle svého CSS.
- Testovací zpráva se `<script>alert(1)</script>` a
  `<img src=x onerror=alert(1)>` v těle — nic se nespustí
  (konzole bez chyb typu blocked script je OK — sandbox je hlásí
  jako blokované, to je očekávané).
- Odkaz `https://…` → otevře se v novém tabu, Shipard zůstává.
- Odkaz `javascript:alert(1)` → není klikatelný (href odstraněn).
- `mailto:` odkaz → otevře mail klienta, žádná navigace aplikace.
- Zúžení/rozšíření detail panelu → výška iframu se přepočítá.
- Plain-text zpráva → beze změny (escapovaný `<pre>` inline).
- Zprávy s obrázky → po donačtení obrázků výška doskočí správně.
- Přepnutí mezi zprávami v seznamu → srcdoc se vymění, výška se
  přepočítá, žádný pozůstatek předchozí zprávy.
- Ostatní taby (AI analýzy, Extrahované dokumenty, Originál) —
  žádná regrese.

## Hotovo když

- [x] `SandboxedHtml.svelte` existuje, sandbox přesně
      `allow-same-origin allow-popups allow-popups-to-escape-sandbox`,
      s bezpečnostním komentářem (invarianta D3) u atributu.
- [x] `ViewerDetail.svelte` renderuje `untrusted-html` přes novou
      komponentu; typ `html` beze změny.
- [x] `buildContentTab()` posílá tělo HTML jako `untrusted-html`,
      nepravdivý komentář opraven.
- [x] i18n klíč doplněn v obou jazycích, `npm run check:i18n` projde.
- [x] `cd frontend && npm run build 2>&1` bez chyb a warningů
      (2 pre-existing warningy v LoginScreen/SetPasswordScreen, mimo
      rozsah tasku).
- [x] `cd frontend && npm test` projde (stávající testy, žádná regrese).
- [x] `vendor/bin/phpunit 2>&1` projde (mail testy i celá Unit suite;
      Integration jen 4 známé pre-existing failures).
- [ ] Smoke test (sekce výše) projde všemi body — ruční průchod v
      prohlížeči; testovací zprávy naseedované na 4l3j
      (`FakeIncomingMessageGenerator` nově generuje ~50 % zpráv
      s `body_html` vč. payloadů ze smoke checklistu).
- [x] `docs/frontend.md` a `docs/mail/api-contract.md` aktualizovány.
