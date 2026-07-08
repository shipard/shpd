# Dashboard — tlačítka příloh na mail kartách feedu

## Status

Navrženo a schváleno Annou (2026-07-08). Připraveno k implementaci.

## Cíl

Mail karty ve feedu dashboardu dostanou vodorovnou řadu tlačítek příloh —
umístěnou **mezi podtitulek** (částka · jistota · e-mail) **a akční tlačítka**
(Použít / Zkontrolovat / Zamítnout). Každé tlačítko:

- **Klik** → otevře přílohu v nové záložce prohlížeče (PDF/obrázky inline,
  ostatní typy download).
- **Hover** → po krátké prodlevě zobrazí plovoucí náhled (zvětšený thumbnail
  + název + velikost); zmizí při odjetí myši. Na mobilu (bez hoveru) tap
  rovnou otevírá.

Use-case: rychlé rozhodnutí, co s poštou — typicky u karet „Není faktura",
kde si uživatel chce přílohu prohlédnout, než zprávu pošle do Koše/Archivu.
Tlačítka ale dostávají **všechny mail karty** (viz rozhodnutí níže).

Vizuální náčrt karty:

```
▌ 🟢  Přijatá faktura — ČEZ a.s.
▌     4 200,00 CZK · jistota 94 % · e-mail „Faktura 2026000123"
▌     [📄 Faktura.pdf] [🖼 scan-001.jpg] [+2]
▌     [Použít] [Zkontrolovat] [Zamítnout]
```

## Schválená rozhodnutí

1. **Rozsah**: všechny tři druhy mail karet.
   - **Návrhové karty** (extracted 10/20/30): jen přílohy z
     `core_mail_extracted_documents.source_attachments` (JSON pole ndx) —
     uživatel vidí přímo přílohu, ze které doklad vznikl. Fallback na všechny
     obsahové přílohy zprávy, když je pole prázdné/nevalidní.
   - **Chybové karty** (`analysis_state=70`) a **karty „Není faktura"**:
     všechny obsahové přílohy zprávy (bez raw `.eml`, viz níže).
   - Alert karty pole nemají — nic se u nich nekreslí.
2. **Strop**: max **3** tlačítka příloh na kartu; při přetečení se přidá
   tlačítko `+N`, které otevře zprávu v došlé poště (syntetická akce
   `open_viewer` na `core.mail.incoming` + `recordId=messageNdx`).
3. **Umístění**: vodorovná řada mezi podtitulkem a akcemi (viz náčrt).
4. **Kontrakt**: nové **volitelné** top-level pole karty — zpětně
   kompatibilní rozšíření (žádná verze API, žádný breaking change).

## Před implementací přečti

- `docs/dashboard.md` §4 (kartový kontrakt), §5.1 (MailSuggestionsSource),
  §8 (frontend komponenty)
- `docs/attachments.md` §4 (endpointy download/thumbnail, `?inline=1`)
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` — celý (3 SELECTy,
  build metody karet)
- `modules/core/mail/src/IncomingMessagesViewer.php`
  `fetchContentAttachments()` (~ř. 446) — **referenční vzor** výběru
  obsahových příloh (table_id=303, vyloučení `raw_source_attachment`,
  řazení `att_order ASC, name ASC`)
- `src/Api/Controller/AttachmentController.php` `computeDisposition()`
  (~ř. 137) — inline whitelist = `application/pdf` + `image/*`
- `frontend/src/api/attachments.js` — `thumbnailUrl()`, `downloadUrl()`,
  `inlineUrl()`, `formatFileSize()`
- `frontend/src/components/viewer/AttachmentGrid.svelte` — vzor
  `attachmentIcon()`, `isInlineRenderable()`, užití `inlineUrl` v
  `<a target="_blank">` a `thumbnailUrl` v `<img>` (bez tokenu — funguje)
- `frontend/src/components/dashboard/FeedCard.svelte` — cílová komponenta
- `tests/Unit/Module/Core/Mail/Feed/MailSuggestionsSourceTest.php` —
  existující test k rozšíření

## Backend

### 1. Rozšíření kartového kontraktu

Nová volitelná pole karty (jen mail karty s ≥1 přílohou):

```json
{
  "attachments": [
    { "id": 12, "name": "Faktura.pdf", "mime_type": "application/pdf", "file_size": 245760 }
  ],
  "attachmentsTotal": 5
}
```

- `attachments` — max 3 položky (strop dělá server, ne frontend; menší
  payload). Struktura položky = stejná jako `fetchContentAttachments()`
  ve vieweru (`id`, `name`, `mime_type`, `file_size`).
- `attachmentsTotal` — celkový počet příloh karty **před** stropem.
  Frontend kreslí `+N`, když `attachmentsTotal > attachments.length`.
- Karty bez příloh pole **vůbec nemají** (ne prázdné pole) — konzistentní
  s ostatními volitelnými poli kontraktu.

### 2. `MailSuggestionsSource` — batchované obohacení

Vše zůstává v `MailSuggestionsSource` (mail-specifická logika,
`source_attachments`); `DashboardController` ani `FeedSource` rozhraní se
nemění.

**a) Rozšíření stávajících SELECTů** (žádné nové řádkové dotazy):

- `suggestionCards()`: přidat `e.source_attachments` a
  `m.raw_source_attachment`.
- `errorCards()` a `notInvoiceCards()`: přidat `raw_source_attachment`.

**b) Batch dotaz na přílohy** — po sestavení řádků, před buildem karet
(nebo po buildu jako enrichment — nech na implementaci, ale **jeden**
dotaz na celý collect):

```sql
SELECT `id`, `record_id`, `name`, `file_name`, `mime_type`, `file_size`
FROM `core_attachments_files`
WHERE `table_id` = 303 AND `record_id` IN %in AND `is_deleted` = 0
ORDER BY `att_order` ASC, `name` ASC
```

- `303` = tableId `core_mail_incoming_messages` — zavést konstantu
  `MESSAGES_TABLE_ID` (viewer používá literál 303; nesjednocovat teď,
  jen komentářem odkázat).
- `record_id IN` = množina `messageNdx` ze všech tří sad karet
  (deduplikovaná). Když je množina prázdná, dotaz vůbec nespouštět.
- Výsledek groupnout podle `record_id`; per zpráva vyloučit
  `raw_source_attachment` (hodnota z rozšířených SELECTů výše).
- `name` fallback na `file_name` (vzor viewer).

**c) Přiřazení kartám**:

- Návrhová karta: `json_decode(source_attachments)` → pole int ndx →
  filtrovat groupnutý seznam zprávy na tyto ndx (pořadí zachovat dle
  batch dotazu, tj. `att_order`). Prázdné/nevalidní pole nebo žádný
  průnik → fallback: všechny obsahové přílohy zprávy.
- Chybová karta / „Není faktura": všechny obsahové přílohy zprávy.
- Strop: `attachments = array_slice(..., 0, 3)`,
  `attachmentsTotal = count(...)`. Konstanta
  `MAX_CARD_ATTACHMENTS = 3`.
- 0 příloh → pole do karty nepřidávat.

Pozn. k výkonu: batch běží nad ≤ 3× `maxCards` zprávami, jeden dotaz —
srovnatelné s existujícími `json_decode` v podtitulcích, v pořádku.

## Frontend

### 3. Nová subkomponenta `FeedCardAttachment.svelte`

`frontend/src/components/dashboard/FeedCardAttachment.svelte` —
jedno tlačítko přílohy (chip):

- Kořen je `<a target="_blank" rel="noopener">`:
  - `href = inlineUrl(att.id)` pro inline-renderovatelné typy
    (PDF + `image/*` — helper zrcadlí `isInlineRenderable` z
    AttachmentGrid), jinak `downloadUrl(att.id)`.
  - `title={att.name}`.
- Obsah chipu: mini náhled `thumbnailUrl(att.id, 64)` (PDF/obrázky,
  `loading="lazy"`) nebo MIME ikona (`iconFilePdf` / `iconFileImage` /
  `iconFile` — vzor `attachmentIcon` z AttachmentGrid) + název přílohy
  (truncate ellipsis, max šířka ~`12em`).
- **Hover náhled** (jen PDF/obrázky):
  - Aktivace pouze na zařízeních s hoverem —
    `window.matchMedia('(hover: hover)')` jednou při mountu.
  - `mouseenter` → timeout ~300 ms → zobraz popup; `mouseleave` /
    `click` / unmount → zruš timeout a skryj. Prodleva brání blikání
    při přejíždění myší přes řadu chipů.
  - Popup: `position: fixed` (nesmí ho oříznout karta), pozice
    z `getBoundingClientRect()` chipu — preferovaně nad chipem,
    při nedostatku místa pod ním; clamp do viewportu vodorovně.
  - Obsah: `<img src={thumbnailUrl(att.id, 480)}>` (šířka ~320 px,
    výška auto), pod ním název + `formatFileSize(att.file_size)`.
  - Bez interakce uvnitř popupu (čistě informační) —
    `pointer-events: none`, žádný focus trap.
  - Přístupnost: zobrazit i na `focusin`, skrýt na `focusout`
    (chip je `<a>`, je fokusovatelný).
- Styly BEM `shpd-feed-att` (`__chip`, `__thumb`, `__name`, `__preview`,
  `__preview-caption`), design tokeny (`--shpd-color-border`,
  `--shpd-radius-sm`, `--shpd-font-size-sm`, `--shpd-space-*`), výška
  chipu srovnatelná s `Button size="sm"`.

### 4. `FeedCard.svelte` — řada příloh

Mezi blok podtitulku a `.shpd-feed-card__actions`:

```svelte
{#if card.attachments?.length}
  <div class="shpd-feed-card__attachments">
    {#each card.attachments as att (att.id)}
      <FeedCardAttachment {att} />
    {/each}
    {#if (card.attachmentsTotal ?? 0) > card.attachments.length}
      <button
        class="shpd-feed-att__more"
        onclick={() => onAction({
          id: 'openMail',
          kind: 'open_viewer',
          target: { viewerId: 'core.mail.incoming', recordId: card.context?.messageNdx },
        })}
      >+{card.attachmentsTotal - card.attachments.length}</button>
    {/if}
  </div>
{/if}
```

- Řada: `display: flex; flex-wrap: wrap; gap: var(--shpd-space-sm)` —
  na mobilu se chipy zalamují, nic nepřetéká.
- `+N` je syntetická `open_viewer` akce — `Dashboard.svelte` ji už umí
  (stejný handler jako alert akce), **žádná změna Dashboard.svelte**.
- Karty bez `attachments` → beze změny vzhledu (zpětná kompatibilita).

### 5. i18n

Chip nese název přílohy (data, ne překlad) — nové i18n klíče nejsou
potřeba. Volitelně `aria-label` pro `+N`:
`dashboard.card.attachments.more` („Další přílohy ({n})" /
„More attachments ({n})") — přidat do `cs.json` + `en.json`, pokud
klíče používáme; jinak stačí `title` s prostým `+N`.

## Testy a verifikace

1. **PHPUnit** — rozšířit
   `tests/Unit/Module/Core/Mail/Feed/MailSuggestionsSourceTest.php`:
   - karta s přílohami: pole `attachments` má správnou strukturu a řazení;
   - vyloučení raw `.eml` (`raw_source_attachment` se v poli neobjeví);
   - návrhová karta filtruje dle `source_attachments`; fallback při
     prázdném/nevalidním JSON;
   - strop: 5 příloh → 3 v poli + `attachmentsTotal=5`;
   - 0 příloh → klíč `attachments` v kartě není;
   - batch dotaz se nespustí, když nejsou žádné karty (mock DB).
2. **Verifikační sekvence**: `php -l` na změněné soubory →
   `vendor/bin/phpunit --filter 'MailSuggestionsSource'` →
   `cd frontend && timeout 90 npm run build 2>&1 | tail -10`.
   (Pre-existing `Opis\JsonSchema\Validator` failures v Exchange/Mail
   testech jsou baseline noise.)
3. **Manuálně**: dashboard s mail kartami — klik otevře PDF inline v nové
   záložce; hover ukáže/skryje náhled; `+N` otevře zprávu v došlé poště;
   mobilní šířka — chipy se zalamují, tap otevírá bez popupů.

## Dokumentace

- `docs/dashboard.md`:
  - §4 — doplnit `attachments` + `attachmentsTotal` do příkladu kontraktu
    a popisu polí (volitelná, jen mail karty, strop 3);
  - §5.1 — odstavec o obohacení příloh (zdroj per druh karty, batch dotaz,
    vyloučení raw `.eml`);
  - §8 — přidat `FeedCardAttachment.svelte` do stromu komponent.

## Commit

Dva commity (backend / frontend mají oddělené git rooty):

- backend: `feat(dashboard): tlačítka příloh na mail kartách feedu`
- frontend: `feat(dashboard): chipy příloh s hover náhledem ve FeedCard`

Commit message přes `.git/COMMIT_MSG_TMP` + `git commit -F`,
`Co-Authored-By: Claude` footer. Push dělá Anna.
