# Chat — markdown tabulky, nadpisy a kopírování tabulek

**Stav:** hotovo

## Status / Cíl


Chat renderuje odpovědi modelu vlastním bezpečným markdown parserem
(`frontend/src/components/chat/markdown.js`), který nezná GFM pipe tabulky ani
nadpisy. Tabulkové výsledky (např. z `documents_aggregate`) se tak slepí do
jednoho odstavce s ASCII rourami — nečitelné. Cíl:

1. **Tabulky** — parser + renderer podporují GFM pipe tabulky, render do
   nativního `<table>` (D1).
2. **Kopírování** — tlačítko u každé tabulky, dual-format clipboard:
   `text/html` (skutečná `<table>` pro paste do Excelu/Wordu/Gmailu) +
   `text/plain` jako TSV (D2).
3. **Nadpisy** — parser + renderer podporují `#`–`######`, vizuálně
   zastropované, ať nerozbíjí bubliny (D3).
4. **Systémový prompt** — explicitní instrukce, že tabulková data má model
   vracet jako GFM tabulku (D5).

Bezpečnostní model se **nemění**: žádné `{@html}` z výstupu modelu, všechny
buňky/nadpisy jsou textově bindované Svelte elementy (viz `docs/chat.md` §7–8).

Streaming (D4): parser běží nad celým akumulovaným textem při každé deltě;
rozepsaná tabulka se krátce ukáže jako odstavec a „přeskočí" do tabulky po
doručení delimiter řádku. Přijaté v1 chování, žádný speciální kód.

## Návaznost

- `tasks/chat-phase3-ui.md` — původní chat UI (Markdown.svelte, markdown.js)
- `tasks/mcp-server-04-aggregate.md` — `documents_aggregate`, hlavní producent
  tabulkových výsledků
- `docs/chat.md` §7 (frontend), §8 (bezpečnost) — po implementaci aktualizovat

## Scope

**In:** parser (tabulky, nadpisy), renderer, `TableBlock.svelte` s copy
tlačítkem, i18n klíče, systémový prompt (cfgItem + built-in fallback), unit
testy parseru, aktualizace `docs/chat.md`.

**Out:** výběr/řazení sloupců, export do souboru (CSV download), horizontální
virtualizace velkých tabulek, speciální handling neúplné tabulky při streamu,
syntax highlighting code bloků.

## Změny po souborech

### 1. `frontend/src/components/chat/markdown.js`

**Nový blokový token `table`:**

```
{ type: 'table',
  header: Span[][],                          // buňky hlavičky
  rows:   Span[][][],                        // datové řádky → buňky → spany
  align:  ('left'|'center'|'right'|null)[] } // z delimiteru, null = default
```

Detekce (v hlavní smyčce `parseMarkdown`, **před** testem na list — řádek
tabulky nesmí spolknout list parser, a před paragraph):

- řádek `i` obsahuje `|` a řádek `i+1` je delimiter:
  `^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$` (min. jedna pomlčka na buňku),
- split buněk: ořež volitelné krajní `|`, rozděl podle `|` (v1 bez podpory
  escapovaného `\|` — modely ho v praxi negenerují; případný literál `|`
  v buňce je akceptovaná limitace),
- počet sloupců určuje hlavička; datové řádky doplnit prázdnými buňkami /
  oříznout přebytek,
- alignment z delimiteru: `:---` left, `:---:` center, `---:` right, `---` null,
- každá buňka → `parseInline()` (bold/code v buňkách funguje),
- konzumuj datové řádky, dokud řádek obsahuje `|` a není prázdný,
- řádek s `|` **bez** následujícího delimiteru není tabulka → propadne do
  paragraph (stávající chování, žádná regrese).

**Nový blokový token `heading`:**

```
{ type: 'heading', level: number, spans: Span[] }   // level 1–6
```

- regex `^(#{1,6})\s+(.*)$`, obsah přes `parseInline()`,
- detekce před list/paragraph.

Aktualizovat doc-comment se seznamem podporovaných konstrukcí.

### 2. `frontend/src/components/chat/TableBlock.svelte` (nový)

Props: `{ header, rows, align }` (token z parseru).

- Render: `<div class="shpd-md-table">` wrapper (pozicování tlačítka +
  `overflow-x: auto` pro širší tabulky v úzkém bočním panelu) → `<table>` /
  `<thead>` / `<tbody>`, buňky přes stejný span-switch jako ostatní bloky
  (strong/em/code/text, čistě textový binding),
- `text-align` per sloupec z `align` (inline style s whitelistovanou hodnotou
  z parseru — ne z textu modelu),
- **copy tlačítko** v pravém horním rohu wrapperu: viditelné na `:hover` /
  `:focus-within`, na dotykových zařízeních trvale (media query
  `(hover: none)`),
- klik →
  1. TSV: řádky spojené `\n`, buňky `\t`, obsah buňky = konkatenace
     `span.text` (bez formátování); tabulátory/nové řádky uvnitř buňky
     nahradit mezerou,
  2. HTML: `<table>` postavená přes `document.createElement` +
     `textContent` (žádná string konkatenace s neescapovaným textem),
  3. `navigator.clipboard.write([new ClipboardItem({'text/html': …,
     'text/plain': …})])`; catch/nedostupnost → fallback
     `navigator.clipboard.writeText(tsv)`,
- feedback: po úspěchu tlačítko na ~2 s přepne na „Zkopírováno" (i18n),
  pak zpět; `aria-label` na tlačítku,
- styly: design tokeny (`--shpd-color-border`, `--shpd-space-*`,
  `--shpd-radius-*`), zebra řádky přes `--shpd-color-bg-secondary`,
  hlavička tučně, `font-size: var(--shpd-font-size-sm)`.

### 3. `frontend/src/components/chat/Markdown.svelte`

- větev `{:else if block.type === 'table'}` → `<TableBlock {...block} />`,
- větev `{:else if block.type === 'heading'}` → render s vizuálním stropem:
  level 1–2 → `<h3>`, 3 → `<h4>`, 4–6 → `<h5>`; styly v `.shpd-md`
  (kompaktní marginy, velikosti max ~1.1em, ať nadpis nerozbije bublinu),
- (styly tabulky žijí v `TableBlock.svelte`).

### 4. `frontend/src/i18n/cs.js`, `frontend/src/i18n/en.js`

Nové klíče (názvy dle stávající konvence `chat.*`):

- `chat.copyTable` — „Kopírovat tabulku" / "Copy table"
- `chat.copied` — „Zkopírováno" / "Copied"

### 5. `modules/core/chat/config/settings.jsonc`

Do `systemPrompt` doplnit větu (znění může Claude Code jemně doladit):

> „Tabulková data (přehledy, žebříčky, srovnání) vracej jako markdown tabulku
> (GFM pipe syntax s řádkem oddělovače); delší strukturovaný text členi
> markdown nadpisy."

**Pozn.:** změna cfgItem vyžaduje rebuild compiled configu
(`config/configuration/compiled.{lang}.json`), jinak ji `cfgItem()` nevidí.

### 6. `src/Api/Controller/ChatController.php`

Stejnou větu doplnit do `SYSTEM_PROMPT_FALLBACK` (konzistence fallbacku
s cfgItem).

### 7. `docs/chat.md`

§7: rozšířit popis `Markdown` komponenty o tabulky (+ `TableBlock` a copy
mechanismus) a nadpisy; zmínit streaming chování (D4).

## Testy

`frontend/tests/Unit/markdown.test.mjs` — rozšířit:

- základní tabulka (hlavička + 2 řádky, krajní `|`),
- tabulka bez krajních `|`,
- alignment (`:---`, `:---:`, `---:`, `---` → left/center/right/null),
- **bold v buňce** (span struktura),
- nekonzistentní počet buněk (kratší řádek doplněn, delší oříznut),
- řádek s `|` bez delimiteru → paragraph (ne tabulka),
- tabulka následovaná odstavcem / listem (správné ukončení bloku),
- nadpisy `#`–`######` (level + spany), `#bez mezery` → paragraph,
- nadpis s inline formátováním (`## **Tučný** nadpis`).

Spouštění: stávající node test runner (`node --test` přes npm skript);
**PATH injekce** `PATH=/home/sebik/.nvm/versions/node/v24.14.0/bin:$PATH`.

Clipboard logika: čistá funkce `tableToTsv(token)` exportovaná
z `TableBlock.svelte` modulu nebo malého helperu (`clipboardTable.js`),
aby šla unit-testovat bez DOM; `ClipboardItem` část bez testu (browser API).

## Commit strategie

1. `feat(chat): GFM tables and headings in markdown parser + renderer`
   (markdown.js, Markdown.svelte, TableBlock.svelte bez copy, testy)
2. `feat(chat): copy-to-clipboard button on chat tables` (copy logika,
   i18n klíče)
3. `feat(chat): system prompt instructs GFM tables for tabular data`
   (settings.jsonc, SYSTEM_PROMPT_FALLBACK, compiled cfg rebuild)
4. `docs(chat): document table rendering and copy behaviour` (docs/chat.md)

## Hotovo když

- [ ] `parseMarkdown` vrací `table` a `heading` tokeny dle specifikace,
      všechny nové unit testy zelené (`node --test`)
- [ ] Tabulka z modelu se v chatu (sekce Chat i boční panel) vykreslí jako
      nativní `<table>` se zebra řádky a horizontálním scrollem
- [ ] Copy tlačítko: paste do Excelu/Google Sheets zachová buňky, paste do
      plain-text editoru dá TSV; feedback „Zkopírováno" funguje
- [ ] Nadpisy `#`/`##`/`###` se vykreslí zastropované (h3–h5), nerozbíjí
      layout bublin
- [ ] Žádné `{@html}` z výstupu modelu — bezpečnostní invariant zachován
- [ ] `npm run check:i18n` z `frontend/` prochází
- [ ] Systémový prompt (cfgItem i fallback) obsahuje instrukci pro GFM
      tabulky; compiled config rebuildnutý
- [ ] `docs/chat.md` §7 aktualizován
