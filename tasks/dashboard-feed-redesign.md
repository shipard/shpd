# Dashboard — redesign karet feedu (grid, strukturovaná hlavička, detail)

## Status

Navrženo a schváleno Annou (2026-07-16). Implementováno (2026-07-16) —
backend + frontend + testy + dokumentace hotové, PHPUnit i frontend build
zelené. Zbývá ruční kontrola v prohlížeči (bod 4 sekce Testy a ověření).

## Cíl

Vizuální a obsahový redesign feedu dashboardu podle nového návrhu
(screenshot z Claude Design, viz rozhodnutí níže):

- **Dvousloupcový grid** karet místo jednosloupcového seznamu.
- **Strukturovaná hlavička karty**: partner jako titulek, typ dokladu jako
  podtitulek, částka jako velké samostatné číslo, donut s jistotou vpravo
  nahoře.
- **Předmět e-mailu** jako samostatný řádek s ikonou obálky.
- **Rozbalovací detail** („Zobrazit detail") — číslo dokladu, splatnost,
  variabilní symbol; u Spisovny konec platnosti.
- **Stavový proužek nahoře** místo vlevo (jen dashboard karty).
- **Chip přílohy s ikonou typu** místo mini náhledu (hover náhled zůstává).
- **Restyle chip baru filtru** (počet uvnitř chipu bez závorek).

Vizuální náčrt návrhové karty (dva sloupce vedle sebe):

```
▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔  ← stavový proužek nahoře
 ✓  ČEZ a.s.                ◔94%
    Přijatá faktura
    4 200,00 CZK              ← velké číslo
    ✉ „Faktura 2026000123"
    [📄 Faktura.pdf] [+2]
    Zobrazit detail ▾
    ┌──────────────────────────┐   (rozbaleno)
    │ Číslo dokladu  2026000123│
    │ Splatnost      29. 4. 2026│
    │ Variabilní s.  2026000123│
    └──────────────────────────┘
    [Použít] [Zkontrolovat] [Zamítnout]
```

**Mimo scope**: AI shrnutí (`AiSummaryCard`) beze změny — čeká na
samostatnou revizi.

## Schválená rozhodnutí

1. **Donut jistoty** — jen u návrhových karet (extracted 10/20/30);
   chybové karty, „Není faktura" a alerty ho nemají.
2. **Titulek = partner, podtitulek = typ dokladu** (prohození proti dnešku).
   U karet bez partnera zůstává dnešní podoba title/subtitle.
3. **Ikona** zůstává sémantická ze serveru (check/question/warning/…),
   **bez** barevného kolečka/badge. Písmenkový badge ze screenshotu se nedělá.
4. **Stavový proužek nahoru** — jen na dashboardu (`.shpd-feed-card__bar`);
   viewery a globální `.docState_*` mechanismus beze změny. Musí zůstat
   snadno vratné na levou pozici (pořád čteme `--shpd-row-bar`, mění se
   jen CSS pozice pruhu).
5. **Obsah expanderu**: číslo dokladu (`docNumber`), splatnost
   (`dates.dueDate`), variabilní symbol (`payment.paymentReference`).
   IČO se nedává. U Spisovny „Platí do" (`registryValidTo`).
6. **Grid s prázdným místem** — CSS grid dá kartám v řádku stejnou výšku;
   rozbalený detail vedle sbalené karty nechá u sousedky volné místo dole.
   Schváleno, masonry se nedělá (rozbilo by prioritní pořadí).
7. **Pořadí** — row-major zleva doprava, zachovává serverové řazení
   (`sortAndCap`).
8. **Chip přílohy** — vždy ikona typu (`attachmentIcon(mime)`), mini
   náhled `thumbnailUrl(…, 64)` z chipu zmizí. Hover náhled (velký
   plovoucí popup) zůstává beze změny.

## Před implementací přečti

- `docs/dashboard.md` §4 (kartový kontrakt), §5.1 (MailSuggestionsSource),
  §8 (frontend komponenty)
- `docs/exchange-format.md` §5 — kanonická pole `docNumber`,
  `dates.dueDate`, `payment.paymentReference`
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` — celý; zejména
  `buildSuggestionCard()`, `cardTitle()`, `cardSubtitle()`,
  `registryCardTitle()`, `registryCardSubtitle()`, `registryValidTo()`,
  `counterpartyName()`, `formatAmount()`, `errorCards()`,
  `notInvoiceCards()`
- `frontend/src/components/dashboard/` — `Feed.svelte`, `FeedCard.svelte`,
  `FeedCardAttachment.svelte`, `FeedFilter.svelte`, `Dashboard.svelte`
- `frontend/src/styles/variables.css` — tokeny (existuje
  `--shpd-color-success`, `--shpd-color-warning`, state tokeny)
- `frontend/src/styles/base.css` — `.docState_*` třídy (pozor:
  `docState_done` je záměrně bez proužku, viz Backend bod 1e / Frontend bod 5a)
- `tests/Unit/Module/Core/Mail/Feed/MailSuggestionsSourceTest.php`
- `docs/design-system.md` — BEM konvence

## Backend

### 1. Rozšíření kartového kontraktu

Nová **volitelná** pole karty — aditivní, zpětně kompatibilní. Karta bez
nich (alerty, „…a další") renderuje jako dnes přes `title`/`subtitle`.

```json
{
  "headline": {
    "partnerName": "ČEZ a.s.",
    "typeLabel": "Přijatá faktura",
    "amountText": "4 200,00 CZK"
  },
  "confidencePct": 94,
  "emailSubject": "Faktura 2026000123",
  "details": [
    { "label": "Číslo dokladu", "value": "2026000123" },
    { "label": "Splatnost", "value": "29. 4. 2026" },
    { "label": "Variabilní symbol", "value": "2026000123" }
  ]
}
```

- **a) `headline`** — strukturovaná hlavička. `partnerName` povinný uvnitř
  objektu (bez partnera se `headline` neposílá a karta padá na
  title/subtitle fallback), `typeLabel` povinný, `amountText` volitelný
  (server-formátovaný string, reuse `formatAmount()`; registry karty ho
  nemají).
- **b) `confidencePct`** — int 0–100, jen návrhové karty s known
  confidence. (V `context.confidence` už je, ale `context` je
  zdrojově-specifický — frontend se na něj nemá vázat; top-level pole je
  součást kontraktu.)
- **c) `emailSubject`** — holý předmět zprávy (bez dnešního obalu
  „e-mail „…""), frontend přidá ikonu obálky a uvozovky. Posílají ho
  **všechny tři druhy mail karet** (i chybové a „Není faktura").
- **d) `details`** — pole `{label, value}`; label lokalizuje server dle
  `ctx->language` (konzistentní s dnešní lokalizací subtitle). Jen
  neprázdné hodnoty; prázdné pole se neposílá. Expander na frontendu se
  ukazuje jen když `details` existuje.
- **e) `subtitle` u karet s `headline` se přestává posílat** — všechna
  jeho dnešní data (částka, jistota, e-mail) jsou nově strukturovaná.
  Chybové karty a „Není faktura" si `title`/`subtitle` nechávají (plus
  nově `emailSubject` — subtitle chybové karty se přestane skládat
  z předmětu, viz bod 2c).

### 2. `MailSuggestionsSource`

Vše zůstává v `MailSuggestionsSource`; `DashboardController`, `FeedSource`
rozhraní ani `AlertsSource` se nemění.

- **a) Návrhové karty (docs target)** — `buildSuggestionCard()`:
  - `headline.partnerName` = `counterpartyName($canonical)`; když je
    `null`, `headline` se neposílá a `title` zůstává dnešní složený
    („{typ} — {partner}" bez partnera = jen typ).
  - `headline.typeLabel` = `docTypeLabel()`.
  - `headline.amountText` = `formatAmount($canonical)` (může být null →
    klíč vynechat).
  - `confidencePct` = `(int) round($confidence * 100)` když confidence
    není null.
  - `emailSubject` = `trim($subject)` když neprázdný.
  - `details` (v tomto pořadí, jen neprázdné):
    - `docNumber` → label cs „Číslo dokladu" / en „Document number"
    - `dates.dueDate` → „Splatnost" / „Due date"; formát data stejný
      vzor jako `registryValidTo()` (cs `j. n. Y`, en `Y-m-d`),
      nevalidní datum → řádek vynechat
    - `payment.paymentReference` → „Variabilní symbol" /
      „Payment reference"
  - `title` ponechat (dnešní složený) — fallback + použití mimo kartu;
    `subtitle` u headline karet vynechat (bod 1e).
- **b) Registry karty** — totéž s registry zdroji: `partnerName` =
  `party.name`, `typeLabel` = `docKindLabel()`, bez `amountText`;
  `details` = jeden řádek „Platí do" / „Valid until" z `registryValidTo()`
  (když není, `details` se neposílá).
- **c) Chybové karty a „Není faktura"** — bez `headline`/`details`/
  `confidencePct`; přidat `emailSubject`. Subtitle upravit tak, aby
  neduploval předmět: chybová karta → subtitle = `sender_name` (dnes je
  tam předmět s fallbackem na sender); „Není faktura" → z dnešního
  skládání (předmět · odesílatel) zůstane jen odesílatel.
- **d) Lokalizované labely** — privátní helper (match vzoru
  `emailSubjectLabel`), žádná nová infrastruktura.
- **e) Proužek ready karet** — `stateStyle=done` nemá v globálním CSS
  žádnou barvu proužku (záměr: „done je default"). Na dashboardu s horním
  proužkem by ready karty byly jediné bez barvy. Řeší **frontend**
  (bod 5a) dashboardovým override — backend se nemění.

### 3. Dokumentace

`docs/dashboard.md`: §4 rozšířit o nová pole (vzorový JSON + popisy),
§5.1 doplnit zdroje `headline`/`details` per druh karty, §8 aktualizovat
popis FeedCard/Feed/FeedFilter (grid, expander, horní proužek). Aktualizace
v rámci implementace, ne jako samostatný krok.

## Frontend

### 4. `Feed.svelte` — grid

- `.shpd-feed`: `display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: var(--shpd-space-md); align-items: stretch;`
- Na běžné šířce obsahu to dá 2 sloupce, na úzké 1, na velmi širokém
  monitoru 3 — přijatelné (šetří místo tím víc). Kdyby 3 sloupce vadily,
  přidá se `max-width` na feed, ne mediaquery.
- Pořadí = DOM pořadí (row-major) — serverové řazení zůstává.
- Empty stav beze změny.

### 5. `FeedCard.svelte` — nový layout

Struktura (shora dolů):

- **a) Stavový proužek nahoře** — `.shpd-feed-card__bar`:
  `top: 0; left: 0; right: 0; height: 4px;` + radius horních rohů;
  padding karty vrátit na symetrický a přidat horní offset o proužek.
  Dashboardový override pro ready karty (viz Backend 2e):
  `.shpd-feed-card.docState_done { --shpd-row-bar: var(--shpd-color-success); }`
  — jen v scoped CSS FeedCard, globální `.docState_done` se nemění.
  (Snadno odstranitelné, kdyby se ukázalo, že bezbarvá ready karta je
  lepší.)
- **b) Hlavička** — flex řádek: sémantická ikona (dnešní, bez kolečka),
  blok titulku, donut vpravo.
  - S `headline`: `partnerName` (font-weight 600) + `typeLabel`
    (font-size sm, text-secondary) pod ním.
  - Bez `headline`: dnešní `title` + `subtitle` (alerty, chybové karty,
    „…a další") — beze změny renderování.
- **c) Donut** — jen když je `confidencePct`. Malé inline SVG (~36 px):
  kruh `stroke-dasharray` dle procenta + číslo uprostřed
  (font-size ~0.7rem). Barva dle `card.kind`: `ready` →
  `--shpd-color-success`, `review` → `--shpd-color-warning`, jinak
  `--shpd-color-text-secondary`. Track kruhu `--shpd-color-border`.
  `aria-label` s procentem (i18n `dashboard.card.confidence`).
- **d) Částka** — `headline.amountText` jako samostatný řádek,
  `font-size: var(--shpd-font-size-xl); font-weight: 700;`.
- **e) Předmět e-mailu** — řádek s `iconMail` (icons.js `iconMail` už
  existuje) + „`{emailSubject}`" v uvozovkách, text-secondary, truncate
  ellipsis na jeden řádek, `title` atribut s plným předmětem.
- **f) Přílohy** — řada chipů beze změny umístění (pod předmětem).
- **g) Expander** — jen když `card.details?.length`:
  - Toggle jako textové tlačítko (link vzhled, `--shpd-color-primary`):
    „Zobrazit detail ▾" / „Skrýt detail ▴" — i18n
    `dashboard.card.showDetail` / `dashboard.card.hideDetail`
    (cs + en). Ikonu šipky řešit entitou/rotovaným chevronem dle toho,
    co už icons.js má.
  - Rozbaleno: panel `background: var(--shpd-color-bg-secondary);
    border-radius: var(--shpd-radius-sm); padding: var(--shpd-space-sm)`;
    řádky label (text-secondary, vlevo) / value (text, vpravo,
    font-weight 500) — grid nebo flex space-between.
  - Stav rozbalení je lokální `$state` v FeedCard — nepřežije refetch,
    to je OK (feed se po akci stejně mění).
- **h) Akce** — beze změny (řada tlačítek dole). „+N" chip beze změny.

### 6. `FeedCardAttachment.svelte` — ikona typu

- Z chipu odstranit větev `{#if inlineRenderable}<img …thumbnailUrl(64)…`
  — vždy `attachmentIcon(att.mime_type)` (PDF / obrázek / obecný soubor).
- `inlineRenderable` zůstává pro href (inline vs. download) a pro hover
  náhled — **hover popup se nemění**.

### 7. `FeedFilter.svelte` — restyle

- Počet uvnitř chipu **bez závorek** (jen číslo, mírně ztlumené —
  dnešní `__count` bez `(…)`).
- Mírně větší padding chipů (blíž screenshotu), aktivní chip zůstává
  vyplněný primary. Urgent tečka beze změny.

### 8. i18n

`frontend/src/i18n/cs.js` + `en.js`:

- `dashboard.card.showDetail` — „Zobrazit detail" / „Show detail"
- `dashboard.card.hideDetail` — „Skrýt detail" / „Hide detail"
- `dashboard.card.confidence` — „Jistota {pct} %" / „Confidence {pct} %"
  (aria-label donutu)

Labely `details` chodí lokalizované ze serveru — do frontend i18n se
nedávají.

## Testy a ověření

1. `php -l` změněných PHP souborů.
2. `vendor/bin/phpunit --filter 'MailSuggestionsSource'` — rozšířit test:
   - návrhová karta s plným canonicalem → `headline` (všechna tři pole),
     `confidencePct`, `emailSubject`, `details` (3 řádky ve správném
     pořadí), **bez** `subtitle`;
   - canonical bez partnera → bez `headline`, `title`/`subtitle` jako dnes;
   - canonical bez `docNumber`/`dueDate`/`paymentReference` → chybějící
     řádky `details` vynechané, prázdné `details` se neposílá;
   - registry karta → `headline` bez `amountText`, „Platí do" v `details`;
   - chybová karta a „Není faktura" → `emailSubject` přítomný, bez
     `headline`, subtitle bez duplikace předmětu.
3. `cd frontend && timeout 90 npm run build 2>&1 | tail -10`
   (`timeout_sec: 120`).
4. Ruční kontrola v prohlížeči (Anna): grid 2 sloupce, rozbalení detailu,
   donut, horní proužky vč. zelené ready, chipy příloh s ikonou, hover
   náhled, filtr, mobilní šířka (1 sloupec), alert karty (fallback layout).

## Akceptace

- [ ] Feed je grid (2 sloupce na desktopu, 1 na mobilu), pořadí karet
      odpovídá serverovému řazení zleva doprava po řádcích.
- [ ] Návrhová karta: partner nahoře tučně, typ dokladu pod ním, částka
      velkým, donut s jistotou vpravo, předmět e-mailu s ikonou obálky.
- [ ] „Zobrazit detail" rozbalí číslo dokladu / splatnost / variabilní
      symbol (jen vyplněné); registry karta ukazuje „Platí do".
- [ ] Rozbalená karta nechá u sousedky v řádku prázdné místo (žádný
      masonry přeskok).
- [ ] Stavový proužek je nahoře; ready karty mají zelený (dashboard
      override), viewery beze změny.
- [ ] Chip přílohy má ikonu dle typu (bez mini náhledu), hover náhled
      funguje jako dřív.
- [ ] Alert karty a „…a další" karta renderují korektně ve fallback
      layoutu (title/subtitle) uvnitř gridu.
- [ ] Filtr: počty bez závorek, aktivní chip vyplněný, urgent tečka
      funguje.
- [ ] `subtitle` se u headline karet neposílá; PHPUnit testy zelené;
      frontend build prochází.
- [ ] `docs/dashboard.md` aktualizován (§4, §5.1, §8).
