# Task: Exchange Format — Fáze 3a: Vizualizace canonical s PDF split-view

**Stav:** hotovo

## Kontext

Pokračujeme z **Fází 2** (`tasks/exchange-format-phase2.md` — hotovo).
AI analyzer produkuje canonical `shpd.docs.document.v1`, AnalysisController
validuje + volá `DocumentApplier::apply()` na tlačítko "Použít".

Dnes v UI detail panelu zprávy v tabu "Extrahované dokumenty" je
tlačítko "Detail", které otevírá Modal s `<pre>{formatJson(extracted_json)}</pre>`
— raw JSON dump. To je nepoužitelné pro běžnou kontrolu dokladu.

**Fáze 3a nahrazuje JSON dump vizualizovaným náhledem dokladu** ve
split-view layoutu s původním PDF na levé straně. Komponenta je
**read-only** — žádná interakce s `_resolve`, žádný edit hodnot.
Akce "Použít" / "Zamítnout" zůstávají v plné kompetenci Fáze 2
(`applyExtracted` volá applier s `autoCreateMode=safe`).

Fáze 3a je čistě UX zlepšení s minimální backend prací (jeden nový
endpoint).

Před implementací **přečti**:

- `docs/exchange-format.md` sekce 5–9 — struktura canonical, Party,
  Row, resolve stavy a `_resolve` payload (cíl frontend renderingu).
- `modules/core/exchange/README.md` — REST API přehled, error codes,
  curl příklady.
- `frontend/src/components/viewer/ViewerDetail.svelte` — kompletně,
  zejména sekce `activeContent?.type === 'extracted-documents'`
  (~ř. 150) a stávající "Detail" modal s JSON dumpem (~ř. 230).
- `frontend/src/components/ui/Modal.svelte` — existující Modal API
  (`title`, `open`, `onClose`, `width`, `footer` snippet). Posoudíme,
  zda stačí přidat `width="full"` variantu, nebo udělat nový
  `FullScreenModal`.
- `frontend/src/components/form/AttachmentPanel.svelte` — vzor
  Svelte 5 komponenty s API voláním, $state/$effect, error handling.
- `src/Api/Controller/AnalysisController.php` — kde přidat nový
  `previewExtracted` endpoint (vedle existujícího `applyExtracted` /
  `rejectExtracted`).
- `modules/core/attachments/src/AttachmentController.php` — kontroller
  pro streamování souborů; chceme zjistit URL pattern pro download
  PDF přílohy.
- Načti `/mnt/skills/public/frontend-design/SKILL.md` před psaním
  jakékoliv Svelte/CSS práce — definuje design tokens a styling
  constraints v tomto prostředí.

## Cíl Fáze 3a

Po dokončení této fáze platí:

- Klik na **"Detail"** v kartě extracted dokumentu otevře plnoobrazovkový
  (nebo wide) modal s **split-view**:
  - **Levá strana** — embed PDF původní přílohy (přes existující
    AttachmentController download endpoint, `<iframe>` nebo `<embed>`)
  - **Pravá strana** — vizualizovaný náhled dokladu (komponenta
    `DocumentExchangePreview`)
- Náhled dokladu zobrazuje:
  - Hlavičku (typ dokladu, čísla, popis, sumární status badge)
  - Strany — dodavatel + odběratel, každá s názvem, identifikátory
    (IČO, DIČ, VAT ID), adresou, kontaktem, bank účtem; každá označená
    visualization badge podle `_resolve.{supplier|customer}.status`
  - Datumy (vystaveno, splatnost, DUZP, DPPD, účetní)
  - Měna + DPH režim + platba
  - Tabulku řádků s per-row resolve statusem pro item/unit/vatCode
  - DPH rekapitulaci + totals
  - Seznam příloh s odkazy na download
  - Sekci "Issues" když `_resolve.issues` obsahuje warningy/chyby
- Pro **`ai_failed`** extracted documenty (status=70) komponenta
  rozpozná wrapper `{_validationError, _validationIssues, _rawOutput}`
  a renderuje speciální error view s explanací, co AI udělala špatně.
- **Status badges** vizualizují resolve výsledky:
  - `matched` ✓ zelený, tooltip "Napárováno na #X"
  - `canCreate` + žlutý, tooltip "Bude vytvořeno nové"
  - `ambiguous` ? oranžový, tooltip "X kandidátů — výběr ve Fázi 3b"
  - `notFound` ✗ červený, tooltip "Není nalezeno"
- **Tlačítka v modal footeru**:
  - "Zavřít" — close modal
  - "Použít" — stávající `applyExtracted` flow (Fáze 2)
  - "Zamítnout" — stávající `rejectExtracted` flow (Fáze 2)
- Mobile (≤768px viewport) fallback: tabs **"PDF" | "Náhled"** místo
  split-view.
- Žádné backend chování se nemění — applier dál běží s `autoCreateMode=safe`.
- Nový pomocný backend endpoint `POST /_mail/extracted-documents/{ndx}/preview`
  vrací `{canonical, ai_failed_wrapper?}` — frontend volá při otevření
  modalu, dostane enriched canonical s `_resolve`.

## Návaznost

- Závisí na: Fáze 1 a 2 (hotovo).
- **Fáze 3b** (následuje) — interakce s `_resolve`: clickable badges
  otevírají `EntityPicker` (komponenta, kterou v 3a postavíme jako
  univerzální nástroj, ale ještě ji nikam nevoláme), userActions se
  posílají do `applyExtracted`. Default `autoCreateMode` se přepne
  na `strict` ve Fázi 3b.

## Scope

### V rozsahu

#### Backend
- Nový endpoint `POST /api/v1/_mail/extracted-documents/{ndx}/preview`
  v `AnalysisController`:
  - Auth: `$auth->isAuthenticated`.
  - Load extracted_document.
  - **Pro `status = 70 (ai_failed)`**: parse `extracted_json`, pokud
    má klíč `_validationError`, vrátí `{aiFailed: true, wrapper:
    {_validationError, _validationIssues, _rawOutput}, attachments:
    [{ndx, filename, mime_type}]}`. Frontend renderuje special error
    view.
  - **Pro pending states (10/20/30)** a **applied (40)**: parse
    canonical, doplnit `source.extractedDoc = $extractedNdx`,
    `applyOptions = {autoCreateMode: "safe"}` (informativní; preview
    neprovádí), zavolat `DocumentApplier::preview($canonical)`,
    vrátit `{aiFailed: false, canonical: <enriched>, attachments:
    [{ndx, filename, mime_type}]}`.
  - **Pro `rejected (50)` / `superseded (60)`**: stejné jako pending —
    preview je informativní, neblokuje historické záznamy.
  - Routing v `Router::route()` a dispatch v `public/index.php`.

- **Rozšíření `AttachmentController::download` o `?inline=1` query**
  param — opt-in inline disposition (default zůstává `attachment`).
  Bez tohoto rozšíření prohlížeč soubor stáhne místo embed v iframe.
  Detail viz "Implementace → AttachmentController inline mode".

#### Frontend — komponenty
- **`frontend/src/components/exchange/DocumentExchangePreview.svelte`** —
  hlavní vizualizační komponenta.
  - Props: `canonical` (object), `attachments` (array, pro link), `aiFailed`
    (bool), `wrapper` (object|null pro ai_failed).
  - Renderuje sekce: header, parties, dates, vat/payment,
    rows table, vatRecap, totals, attachments, issues.
  - Status badges per-reference (čistá vizualizace, žádné event handlery).
  - CSS s `shpd-exchange-` prefixem, používá design tokens z
    `variables.css`.

- **`frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`** —
  full-screen / wide modal wrapper.
  - Props: `open` (bool), `extractedNdx` (int), `onClose`, `onApply`,
    `onReject`, `onRefresh`.
  - Po otevření volá `POST /_mail/extracted-documents/{ndx}/preview`,
    drží `canonical`, `attachments`, `aiFailed`, `loading`, `error` v `$state`.
  - Renderuje split-view layout (desktop) nebo tabs (mobile).
  - Footer s "Zavřít" / "Použít" / "Zamítnout" tlačítky.
  - "Použít" / "Zamítnout" delegují na props `onApply` / `onReject`
    (rodič dál volá stávající endpointy).

- **`frontend/src/components/exchange/PdfViewerPanel.svelte`** —
  embed PDF přílohy.
  - Props: `attachments` (array s metadaty), `selectedNdx` (int|null;
    default first item).
  - Pokud víc příloh → switch tabs nahoře.
  - Pokud žádná příloha → empty state "K dispozici není PDF náhled".
  - Pokud non-PDF mime_type (např. JPG sken) → `<img>` místo iframe;
    pokud něco jiného → "Nepodporovaný náhled" + download link.
  - URL pro stream — viz "Architektonická rozhodnutí" sekce.

- **`frontend/src/components/ui/EntityPicker.svelte`** — univerzální
  picker pro výběr záznamu z libovolné tabulky.
  - Props: `open`, `tableName` (string, např. `'base_persons_persons'`),
    `searchPlaceholder`, `onSelect(row)`, `onClose`,
    `searchFields` (array — která pole hledat, např. `['name', 'company_id']`),
    `displayPattern` (function nebo template string — jak formátovat row).
  - Backend lookup: existující `GET /api/v1/{table}?search=<term>`
    endpoint (CrudController list).
  - Debounced search (300ms), zobrazí top 10 výsledků.
  - **Tento komponent se ve Fázi 3a NEPOUŽÍVÁ** — jen postavíme, otestujeme
    izolovaně přes Storybook-like demo / mock harness, a ve 3b ho
    zavoláme z DocumentExchangePreview.

#### Frontend — API klient
- `frontend/src/api/exchange.js` — nový soubor.
  - `previewExtractedDocument(extractedNdx)` →
    `POST /_mail/extracted-documents/{ndx}/preview`, vrací
    `{aiFailed, canonical|wrapper, attachments}`.
  - Pro budoucí 3b také připravit (i když ještě nevoláme):
    `applyExtractedDocument(extractedNdx, resolveOverrides)` — wrapper
    nad existujícím POST .../apply, který bude umět posílat
    `_resolve` body.

#### Frontend — úprava existujícího kódu
- `frontend/src/components/viewer/ViewerDetail.svelte`:
  - Odstranit lokální `detailModalDoc` state + stávající JSON dump Modal.
  - Místo `openDetailModal(doc)` přepnout state na `previewModalNdx`
    a otevřít nový `DocumentExchangePreviewModal`.
  - Tlačítko "Detail" zůstává, jen otevírá nový modal.
  - Po `onApply` / `onReject` z modalu zavolat `onRefresh?.()` (current
    behavior pro list refresh) a modal zavřít.

#### i18n
- Nové klíče v `frontend/src/i18n/cs.js` a `en.js` pod namespace
  `exchange.preview.*`. Seznam:

```
exchange.preview.title                "Náhled dokladu" / "Document preview"
exchange.preview.tabs.pdf             "PDF" / "PDF"
exchange.preview.tabs.preview         "Náhled" / "Preview"
exchange.preview.section.header       "Hlavička" / "Header"
exchange.preview.section.supplier     "Dodavatel" / "Supplier"
exchange.preview.section.customer     "Odběratel" / "Customer"
exchange.preview.section.dates        "Datumy" / "Dates"
exchange.preview.section.payment      "Platba" / "Payment"
exchange.preview.section.rows         "Řádky" / "Line items"
exchange.preview.section.vatRecap     "DPH rekapitulace" / "VAT recap"
exchange.preview.section.totals       "Součty" / "Totals"
exchange.preview.section.attachments  "Přílohy" / "Attachments"
exchange.preview.section.issues       "Upozornění" / "Issues"
exchange.preview.docType.invoiceReceived "Faktura přijatá" / "Invoice received"
exchange.preview.docType.invoiceIssued   "Faktura vydaná" / "Invoice issued"
exchange.preview.docType.creditNote      "Dobropis" / "Credit note"
exchange.preview.docType.other           "Jiný dokument" / "Other document"
exchange.preview.field.docNumber      "Číslo dokladu" / "Document number"
exchange.preview.field.docText        "Popis" / "Description"
exchange.preview.field.companyId      "IČO" / "Company ID"
exchange.preview.field.taxId          "DIČ" / "Tax ID"
exchange.preview.field.vatId          "VAT ID" / "VAT ID"
exchange.preview.field.address        "Adresa" / "Address"
exchange.preview.field.bankAccount    "Bankovní účet" / "Bank account"
exchange.preview.field.email          "E-mail" / "E-mail"
exchange.preview.field.phone          "Telefon" / "Phone"
exchange.preview.field.issueDate      "Vystaveno" / "Issued"
exchange.preview.field.dueDate        "Splatnost" / "Due"
exchange.preview.field.accountingDate "Účetní datum" / "Accounting date"
exchange.preview.field.taxPointDate   "DUZP" / "Tax point date"
exchange.preview.field.vatObligationDate "DPPD" / "VAT obligation date"
exchange.preview.field.currency       "Měna" / "Currency"
exchange.preview.field.paymentMethod  "Způsob platby" / "Payment method"
exchange.preview.field.variableSymbol "Variabilní symbol" / "Variable symbol"
exchange.preview.row.position         "#" / "#"
exchange.preview.row.item             "Položka" / "Item"
exchange.preview.row.quantity         "Množ." / "Qty"
exchange.preview.row.unit             "Jedn." / "Unit"
exchange.preview.row.unitPrice        "Cena/j" / "Price"
exchange.preview.row.discount         "Sleva" / "Discount"
exchange.preview.row.vat              "DPH" / "VAT"
exchange.preview.row.total            "Celkem" / "Total"
exchange.preview.totals.base          "Základ" / "Base"
exchange.preview.totals.vat           "DPH" / "VAT"
exchange.preview.totals.total         "Celkem" / "Total"
exchange.preview.totals.rounding      "Zaokrouhlení" / "Rounding"
exchange.preview.status.matched       "Napárováno na #{id}" / "Matched to #{id}"
exchange.preview.status.matched.created "Nově vytvořeno (#{id})" / "Newly created (#{id})"
exchange.preview.status.canCreate     "Bude vytvořeno nové" / "Will be created"
exchange.preview.status.ambiguous     "{count} kandidátů — výběr v dalším kroku" / "{count} candidates — pick in next step"
exchange.preview.status.notFound      "Nenalezeno" / "Not found"
exchange.preview.status.summary.ok    "Vše napárováno" / "All matched"
exchange.preview.status.summary.needsAttention "Vyžaduje pozornost" / "Needs attention"
exchange.preview.aiFailed.title       "AI extrakce selhala" / "AI extraction failed"
exchange.preview.aiFailed.message     "AI nevrátila platnou strukturu dokladu. Použij „Znova analyzovat" v hlavičce zprávy." / "AI did not return a valid document structure. Use \"Reanalyze\" in the message header."
exchange.preview.aiFailed.rawOutput   "Co AI vrátila" / "What AI returned"
exchange.preview.aiFailed.issues      "Problémy" / "Issues"
exchange.preview.pdf.empty            "K dispozici není PDF náhled" / "No PDF preview available"
exchange.preview.pdf.unsupported      "Nepodporovaný typ přílohy" / "Unsupported attachment type"
exchange.preview.pdf.download         "Stáhnout" / "Download"
exchange.preview.loading              "Načítám náhled…" / "Loading preview…"
exchange.preview.actions.close        "Zavřít" / "Close"
exchange.preview.actions.apply        "Použít" / "Apply"
exchange.preview.actions.reject       "Zamítnout" / "Reject"
```

EntityPicker i18n (separate namespace `picker.*`):

```
picker.search.placeholder            "Hledat…" / "Search…"
picker.results.empty                 "Žádné výsledky" / "No results"
picker.results.loading               "Hledám…" / "Searching…"
picker.actions.cancel                "Zrušit" / "Cancel"
picker.actions.select                "Vybrat" / "Select"
```

#### CSS a design tokens
- Komponenty používají **existující** tokens (`--shpd-color-state-done-bg`,
  `--shpd-color-state-edit-bg`, atd.). Žádné nové tokens nejsou potřeba.
- Per-resolve-status badge má dedikované třídy:

```
.shpd-exchange__status--matched      → done tokens (zelený)
.shpd-exchange__status--canCreate    → edit/concept tokens (žlutý)
.shpd-exchange__status--ambiguous    → warning/concept tokens (oranžový)
.shpd-exchange__status--notFound     → cancelled/error tokens (červený)
```

#### Testy
- **Vitest / Svelte testing-library** (pokud existuje stack); jinak
  manuální QA scénáře v poznámkách. *Z existující frontend struktury
  zjisti, jestli je test runner setup — pokud ne, neimplementuj v
  rámci 3a, ale dokumentuj v "Otevřené body".*
- Backend PHPUnit:
  - `tests/Unit/Api/Controller/AnalysisControllerPreviewExtractedTest.php`:
    - pending state s validním canonical → `aiFailed=false`, enriched
      canonical má `_resolve`
    - status=70 (ai_failed) → `aiFailed=true`, `wrapper` má
      `_validationError` + `_rawOutput`
    - status=50 (rejected) → také preview funguje (informativní)
    - corrupted JSON v DB → 500
    - nonexistent ndx → 404

### Mimo rozsah

- **Interakce s `_resolve`** — clickable status badges otevírající
  EntityPicker pro výběr existing entity nebo `userAction=create`.
  Patří do Fáze 3b.
- **EntityPicker v reálném použití** — postavíme komponentu jako
  univerzální nástroj a otestujeme izolovaně, ale **nepropojujeme** ji
  s `DocumentExchangePreview` ani jiným callerem. To je v 3b.
- **Edit canonical hodnot** v náhledu (oprava IČO, doplnění data).
  Odložené (podle úmluvy "3b věci eliminují potřebu většiny editů,
  zbytek se řeší přes form-editor po apply").
- **Auto-preview při změně dat na pozadí** (websocket, polling) — modal
  se otevírá vždy s čerstvým stavem (`POST /preview` při open). Reaktivita
  není v rozsahu.
- **Side-by-side comparison s předchozí AI analýzou** (po reanalyze
  porovnat staré vs. nové extracted documents).
- **PDF anotace / highlighting** korespondujících polí mezi PDF a
  canonical (např. "zde v PDF je IČO, namapováno na supplier.companyId").
- **Drag-resize handle** mezi PDF a preview panely. Fixed 50/50 split.
- **Print / export PDF náhledu** vizualizace.

## Architektonická rozhodnutí

### Dedikovaný `previewExtracted` endpoint (ne přímé volání `/exchange/.../preview`)

Frontend nevolá `POST /api/v1/_exchange/docs/document/preview` přímo
s `extracted_json` v body. Důvody:

1. **Server-controlled injection** — `source.extractedDoc`,
   `applyOptions` jsou autoritativně server-side (stejný design jako
   `applyExtracted` ve Fázi 2). Frontend nemá důvod posílat věci, které
   server stejně přepíše.
2. **Symmetrie s `applyExtracted`** — UI flow je `preview` → `apply`,
   oba endpointy jsou per-extracted-document s identickou auth a
   state validation.
3. **Speciální handling ai_failed** — `/exchange/.../preview` by
   spadl na schema validation; nový endpoint rozpozná wrapper a vrátí
   strukturu vhodnou pro speciální UI render.
4. **Frontend držet jednoduchý** — `previewExtractedDocument(ndx)`
   je jednodušší než construct canonical na frontendu.

Pozn.: Existující `/api/v1/_exchange/docs/document/preview` zůstává
beze změny pro CLI / API klienty s vlastním canonical.

### Split-view layout — fixed 50/50, žádný drag handle

V 3a je split fixní 50/50 (desktop) nebo tabs (mobile). Drag-resize
handle je nice-to-have, ale komplikuje touch interakci a layout
edge cases. Přidat lze později.

Breakpoint: 768px (matchuje existující design system).

### Která příloha je "originál PDF"?

`core_mail_extracted_documents.source_attachments` je JSON array s
id-čky z `core_attachments_files`. UX:

- **1 příloha** → zobrazit ji rovnou (žádný switcher).
- **2+ příloh** → tab switcher v `PdfViewerPanel` (filename jako label).
- **PDF** (mime_type `application/pdf`) → `<iframe>` nebo `<embed>`.
- **Image** (`image/*`) → `<img>` (faktury jako sken).
- **Other** → "Nepodporovaný náhled" + download link.

URL pro download: stávající `GET /api/v1/_attachments/{id}/download`
(viz `AttachmentController`). Auth: stejná session jako frontend, žádné
extra token.

### Modal velikost — rozšířit existující Modal o `width="full"`

Spíš než nový `FullScreenModal` přidat do existující `Modal.svelte`
podporu `width="full"` (95vw, 95vh) nebo `width="xl"` (1200px max-width).
Důvod: minimalizovat duplikaci, držet konzistentní close/footer chování.

Pokud existující Modal toto nedokáže bez zásahu do core, udělej drobnou
úpravu — projde i s existujícím použitím (`width="800px"`, `"480px"`
zůstávají funkční).

### Status visualization — read-only badges, žádné dropdowny

V 3a jsou status badges **čisté zobrazení**, žádné `onclick`. CSS pointer
zůstává `default`. Tooltip se zobrazí přes `title` atribut nebo lehkou
tooltip variantu (záleží na designu).

Ve 3b se badge stane `<button>` s `onclick` který otevře `EntityPicker`
pro `ambiguous` / `canCreate` reference. Příprava: classnames budou
nominálně stejné v 3a i 3b, aby přechod nepotřeboval restyle.

### Issues sekce

Pokud `_resolve.issues` obsahuje warningy/chyby, komponenta renderuje
sekci "Upozornění" pod totals:

```
⚠ totals.totalAmount — Deklarovaná částka 12500.00 neodpovídá vypočtené 12499.50
✗ dates.issueDate — Datum vystavení je povinné
```

Severity → ikona (✗ error, ⚠ warning, ℹ info). Tato sekce zobrazuje
informaci, **neblokuje** "Použít" tlačítko. Server v `applyExtracted`
rozhoduje, zda apply selže (validation gate v applieru).

### ai_failed render

Pro `aiFailed=true` komponenta `DocumentExchangePreview` přepne na
error view:

```
┌────────────────────────────────────────────────────────────┐
│  ⚠  AI extrakce selhala                                   │
│                                                            │
│  AI nevrátila platnou strukturu dokladu. Použij           │
│  „Znova analyzovat" v hlavičce zprávy.                   │
│                                                            │
│  Problémy:                                                 │
│   • format — povinné pole chybí                           │
│   • supplier.country — minLength porušeno                 │
│                                                            │
│  ── Co AI vrátila ─────────────────────────────────────── │
│  { /* raw output preformatted */ }                        │
└────────────────────────────────────────────────────────────┘
```

Pravá strana split-view obsahuje tento panel; levá strana (PDF) funguje
normálně. Tlačítka v footer: "Zavřít" funkční, "Použít" disabled
s tooltipem "Nelze aplikovat — AI selhala", "Zamítnout" funkční.

### EntityPicker isolated build & test

Komponentu postavíme jako stand-alone v 3a, ale nikam ji ve 3a nevoláme.
Testovací harness: drobný `EntityPickerDemo.svelte` v
`frontend/src/components/exchange/` (nebo `frontend/scripts/`), který
otevírá picker s mock `tableName="base_persons_persons"`. Po manuální
verifikaci se demo soubor smaže/přesune do testů (záleží na frontend
test infra).

Důvod pro postavení v 3a: jistota že komponenta funguje izolovaně,
a 3b ji pak jen propojí — méně rizika, kratší 3b task.

## Implementace

### Backend: `AnalysisController::previewExtracted`

Pseudo-kód metody:

```php
public function previewExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
{
    if (!$auth->isAuthenticated) {
        return Response::error('UNAUTHORIZED', 'Authentication required', 401);
    }

    $existing = $this->db->fetchRow(
        'SELECT * FROM %n WHERE id = %i',
        self::EXTRACTED_TABLE, $extractedNdx,
    );
    if ($existing === null) {
        return Response::error('NOT_FOUND', "Extracted document {$extractedNdx} not found", 404);
    }

    $extractedJson = json_decode((string) $existing['extracted_json'], true);
    if (!is_array($extractedJson)) {
        return Response::error('CORRUPTED_DATA', 'extracted_json cannot be parsed', 500);
    }

    // Resolve associated attachments (always — for PDF panel)
    $attachmentNdxs = $this->parseSourceAttachments((string) $existing['source_attachments']);
    $attachments = $this->loadAttachmentsMeta($attachmentNdxs);

    // Detect ai_failed wrapper (Fáze 2 wrapper shape)
    if ((int) $existing['status'] === ExtractedDocumentDocument::STATUS_AI_FAILED
        && isset($extractedJson['_validationError'])
    ) {
        return Response::success([
            'aiFailed' => true,
            'wrapper' => $extractedJson, // _validationError, _validationIssues, _rawOutput
            'attachments' => $attachments,
            'extractedNdx' => $extractedNdx,
            'messageNdx' => (int) $existing['message'],
            'status' => (int) $existing['status'],
        ]);
    }

    // Normal canonical → call applier preview
    $canonical = $extractedJson;
    $canonical['source'] = is_array($canonical['source'] ?? null) ? $canonical['source'] : [];
    $canonical['source']['extractedDoc'] = $extractedNdx;
    // applyOptions are informative; preview does not enforce them
    $canonical['applyOptions'] = [
        'autoCreateMode' => 'safe',
        'targetDocState' => 10,
    ];

    $result = $this->applier->preview($canonical);
    if (!$result->success) {
        // preview() should always return success=true (it tolerates resolve
        // issues by surfacing them in _resolve.issues), but defensive fallback:
        return Response::error(
            $result->errorCode ?? 'INTERNAL_ERROR',
            $result->errorMessage ?? 'Preview failed',
            $result->statusCode ?? 422,
            ['canonical' => $result->canonical],
        );
    }

    return Response::success([
        'aiFailed' => false,
        'canonical' => $result->canonical,
        'attachments' => $attachments,
        'extractedNdx' => $extractedNdx,
        'messageNdx' => (int) $existing['message'],
        'status' => (int) $existing['status'],
    ]);
}

/**
 * @return int[]
 */
private function parseSourceAttachments(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter(array_map('intval', $decoded), fn($x) => $x > 0));
}

/**
 * @return array<int, array{ndx: int, filename: string, mime_type: string, size_bytes: int}>
 */
private function loadAttachmentsMeta(array $ndxs): array
{
    if ($ndxs === []) return [];
    $rows = $this->db->fetchAll(
        'SELECT id, name, mime_type, file_size FROM %n
          WHERE id IN %in AND %n = %i AND is_deleted = %i
          ORDER BY id ASC',
        self::ATTACHMENTS_TABLE,
        $ndxs,
        'table_id', self::MAIL_TABLE_ID,
        0,
    );
    return array_map(static fn($r) => [
        'ndx' => (int) $r['id'],
        'filename' => (string) $r['name'],
        'mime_type' => (string) $r['mime_type'],
        'size_bytes' => (int) $r['file_size'],
    ], $rows);
}
```

### Routing

V `src/Api/Router.php`, kde jsou registrovány `applyExtracted` a
`rejectExtracted` routy, přidat:

```php
if ($method === 'POST' && preg_match('#^/api/v1/_mail/extracted-documents/(\d+)/preview$#', $path, $m)) {
    return new Route('analysis', 'previewExtracted', ['extractedNdx' => (int) $m[1]]);
}
```

V dispatch loop v `public/index.php`:

```php
'analysis' => match ($route->action) {
    // ...
    'previewExtracted' => $analysisController->previewExtracted($auth, $request, $route->params['extractedNdx']),
    // ...
},
```

### Backend: AttachmentController inline mode

`AttachmentController::sendFile()` dnes posílá
`Content-Disposition: attachment; filename=...` — prohlížeč soubor
stáhne, neudelá inline preview. PDF iframe `src=...` proto současný
endpoint nezvládne.

Úprava: přidat `?inline=1` query parameter do `download` endpointu, který
přepne disposition na `inline`. Default zůstává `attachment` —
backward compat pro existující volání.

```php
public function download(int $id, Request $request): Response
{
    $attachment = $this->service->getAttachment($id);
    if ($attachment === null) {
        return Response::error('NOT_FOUND', 'Příloha nenalezena', 404);
    }

    $filePath = $this->service->getFilePath($attachment);
    if (!file_exists($filePath)) {
        return Response::error('NOT_FOUND', 'Soubor nenalezen na disku', 404);
    }

    $params = $request->getQueryParams();
    $disposition = (($params['inline'] ?? '0') === '1') ? 'inline' : 'attachment';

    // Restrict inline mode to safe types (PDF, images) — prevents serving
    // arbitrary HTML/SVG inline which could XSS via the same-origin iframe.
    $mimeType = (string) $attachment['mime_type'];
    $inlineSafe = $mimeType === 'application/pdf'
        || str_starts_with($mimeType, 'image/');
    if ($disposition === 'inline' && !$inlineSafe) {
        $disposition = 'attachment';
    }

    $this->sendFile(
        $filePath,
        $mimeType,
        $attachment['name'],
        (int) $attachment['file_size'],
        cacheForever: false,
        disposition: $disposition,
    );

    return Response::success(null, 204);
}

private function sendFile(
    string $filePath,
    string $mimeType,
    ?string $displayName,
    ?int $fileSize,
    bool $cacheForever = false,
    string $disposition = 'attachment',
): never {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);

    if ($displayName !== null) {
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $displayName);
        header(
            "Content-Disposition: {$disposition}; filename=\"{$asciiName}\";"
            . " filename*=UTF-8''" . rawurlencode($displayName),
        );
    }
    // ... rest unchanged
}
```

Signatura `download` se mění (​​přidání `Request $request`) — dispatch
v `public/index.php` musí též předat request:

```php
'attachments' => match ($route->action) {
    'download' => $attachmentController->download($route->params['id'], $request),
    // ...
},
```

Frontend (`PdfViewerPanel.svelte`) volá `download?inline=1` pro PDF/image
view, plain `download` pro "Stáhnout" link (non-inline-safe typy).

### Frontend: `src/api/exchange.js`

```javascript
import { post } from './client.js';

/**
 * Načte preview pro extracted document — vrací enriched canonical
 * s _resolve, nebo ai_failed wrapper pro status=70.
 *
 * @param {number} extractedNdx
 * @returns {Promise<{
 *   success: boolean,
 *   data?: {
 *     aiFailed: boolean,
 *     canonical?: object,
 *     wrapper?: object,
 *     attachments: Array<{ndx: number, filename: string, mime_type: string, size_bytes: number}>,
 *     extractedNdx: number,
 *     messageNdx: number,
 *     status: number,
 *   },
 *   error?: object,
 * }>}
 */
export async function previewExtractedDocument(extractedNdx) {
  return await post(`/_mail/extracted-documents/${extractedNdx}/preview`, {});
}

/**
 * Future use (Fáze 3b) — apply s userAction overrides v _resolve.
 * Placeholder API; v 3a nevoláno.
 *
 * @param {number} extractedNdx
 * @param {object} [resolveOverrides] - { supplier: {userAction: ...}, ... }
 */
export async function applyExtractedDocument(extractedNdx, resolveOverrides = null) {
  const body = resolveOverrides !== null ? { _resolve: resolveOverrides } : {};
  return await post(`/_mail/extracted-documents/${extractedNdx}/apply`, body);
}
```

### Frontend: `DocumentExchangePreview.svelte`

**Strukturní layout** (read-only render canonical):

```svelte
<script>
  import { t } from '../../i18n/index.js';

  let { canonical = null, aiFailed = false, wrapper = null } = $props();

  // Derived helpers
  let docType = $derived(canonical?.docType ?? null);
  let docTypeLabel = $derived(docType ? t(`exchange.preview.docType.${docType}`) : '');
  let resolve = $derived(canonical?._resolve ?? null);
  let summary = $derived(resolve?.summary ?? null);
  let issues = $derived(resolve?.issues ?? []);

  function statusLabel(status, matchedId = null, candidateCount = 0) {
    if (status === 'matched' && matchedId !== null) {
      return t('exchange.preview.status.matched', { id: matchedId });
    }
    if (status === 'canCreate')   return t('exchange.preview.status.canCreate');
    if (status === 'ambiguous')   return t('exchange.preview.status.ambiguous', { count: candidateCount });
    if (status === 'notFound')    return t('exchange.preview.status.notFound');
    return '';
  }

  function formatMoney(value, currency) {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat('cs-CZ', {
      style: 'currency', currency: currency ?? 'CZK',
      minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value);
  }

  function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('cs-CZ').format(new Date(iso));
  }

  function formatAddress(addr) {
    if (!addr) return '—';
    return addr.displayLine ?? [
      [addr.street, addr.houseNumber].filter(Boolean).join(' '),
      [addr.zip, addr.city].filter(Boolean).join(' '),
    ].filter(Boolean).join(', ');
  }
</script>

{#if aiFailed && wrapper}
  <!-- ai_failed special view -->
  <div class="shpd-exchange shpd-exchange--ai-failed">
    <div class="shpd-exchange__ai-failed-header">
      <span class="shpd-exchange__ai-failed-icon">⚠</span>
      <h2>{t('exchange.preview.aiFailed.title')}</h2>
    </div>
    <p>{t('exchange.preview.aiFailed.message')}</p>
    {#if wrapper._validationIssues?.length > 0}
      <h3>{t('exchange.preview.aiFailed.issues')}</h3>
      <ul>
        {#each wrapper._validationIssues as issue}
          <li><code>{issue.path}</code> — {issue.message}</li>
        {/each}
      </ul>
    {/if}
    <details>
      <summary>{t('exchange.preview.aiFailed.rawOutput')}</summary>
      <pre>{JSON.stringify(wrapper._rawOutput, null, 2)}</pre>
    </details>
  </div>
{:else if canonical}
  <div class="shpd-exchange">
    <!-- Header section -->
    <header class="shpd-exchange__header">
      <div class="shpd-exchange__type-badge">{docTypeLabel}</div>
      <h2 class="shpd-exchange__title">
        {canonical.docNumber ?? '—'}
      </h2>
      {#if canonical.docText}
        <p class="shpd-exchange__subtitle">{canonical.docText}</p>
      {/if}
      {#if summary}
        <span class="shpd-exchange__summary-badge shpd-exchange__summary-badge--{summary.status}">
          {summary.status === 'ok'
            ? t('exchange.preview.status.summary.ok')
            : t('exchange.preview.status.summary.needsAttention')}
        </span>
      {/if}
    </header>

    <!-- Parties section -->
    <section class="shpd-exchange__parties">
      <PartyCard
        label={t('exchange.preview.section.supplier')}
        party={canonical.supplier}
        resolve={resolve?.supplier}
      />
      <PartyCard
        label={t('exchange.preview.section.customer')}
        party={canonical.customer}
        resolve={resolve?.customer}
      />
    </section>

    <!-- Dates + Payment grid -->
    <section class="shpd-exchange__meta-grid">
      <DateRow label={t('exchange.preview.field.issueDate')} value={canonical.dates?.issueDate} />
      <DateRow label={t('exchange.preview.field.dueDate')} value={canonical.dates?.dueDate} />
      <DateRow label={t('exchange.preview.field.accountingDate')} value={canonical.dates?.accountingDate} />
      <DateRow label={t('exchange.preview.field.taxPointDate')} value={canonical.dates?.taxPointDate} />
      <DateRow label={t('exchange.preview.field.vatObligationDate')} value={canonical.dates?.vatObligationDate} />
      <Field label={t('exchange.preview.field.currency')} value={canonical.currency} />
      <Field label={t('exchange.preview.field.paymentMethod')} value={canonical.payment?.method} />
      <Field label={t('exchange.preview.field.variableSymbol')} value={canonical.payment?.variableSymbol} />
    </section>

    <!-- Rows table -->
    <section>
      <h3>{t('exchange.preview.section.rows')}</h3>
      <table class="shpd-exchange__rows">
        <thead>
          <tr>
            <th>#</th>
            <th>{t('exchange.preview.row.item')}</th>
            <th class="num">{t('exchange.preview.row.quantity')}</th>
            <th>{t('exchange.preview.row.unit')}</th>
            <th class="num">{t('exchange.preview.row.unitPrice')}</th>
            <th>{t('exchange.preview.row.vat')}</th>
            <th class="num">{t('exchange.preview.row.total')}</th>
          </tr>
        </thead>
        <tbody>
          {#each canonical.rows ?? [] as row, i}
            <tr>
              <td>{row.orderPos ?? i + 1}</td>
              <td>
                {row.item?.name ?? '—'}
                {#if row.item?.supplierCode}
                  <span class="shpd-exchange__row-code">{row.item.supplierCode}</span>
                {/if}
                <StatusBadge resolve={resolve?.rows?.[i]?.item} />
              </td>
              <td class="num">{row.quantity ?? '—'}</td>
              <td>
                {row.unit ?? '—'}
                <StatusBadge resolve={resolve?.rows?.[i]?.unit} />
              </td>
              <td class="num">{formatMoney(row.unitPrice, canonical.currency)}</td>
              <td>
                {row.vat?.pct ?? '—'}%
                <StatusBadge resolve={resolve?.rows?.[i]?.vatCode} />
              </td>
              <td class="num">{formatMoney(row.totalPrice, canonical.currency)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </section>

    <!-- VAT recap + totals -->
    <section class="shpd-exchange__totals">
      {#if canonical.vatRecap?.length > 0}
        <table>
          {#each canonical.vatRecap as r}
            <tr>
              <td>{r.vatPct}%</td>
              <td class="num">{formatMoney(r.base, canonical.currency)}</td>
              <td class="num">{formatMoney(r.tax, canonical.currency)}</td>
              <td class="num">{formatMoney(r.total, canonical.currency)}</td>
            </tr>
          {/each}
        </table>
      {/if}
      <div class="shpd-exchange__totals-summary">
        <div>{t('exchange.preview.totals.base')}: <strong>{formatMoney(canonical.totals?.totalBase, canonical.currency)}</strong></div>
        <div>{t('exchange.preview.totals.vat')}: <strong>{formatMoney(canonical.totals?.totalVat, canonical.currency)}</strong></div>
        <div class="shpd-exchange__total">{t('exchange.preview.totals.total')}: <strong>{formatMoney(canonical.totals?.totalAmount, canonical.currency)}</strong></div>
      </div>
    </section>

    <!-- Issues -->
    {#if issues.length > 0}
      <section class="shpd-exchange__issues">
        <h3>{t('exchange.preview.section.issues')}</h3>
        <ul>
          {#each issues as issue}
            <li class="shpd-exchange__issue shpd-exchange__issue--{issue.severity}">
              <code>{issue.path}</code> — {issue.message}
            </li>
          {/each}
        </ul>
      </section>
    {/if}
  </div>
{/if}
```

`PartyCard`, `StatusBadge`, `Field`, `DateRow` lze udělat jako lokální
inline snippets nebo malé subkomponenty ve stejném adresáři. Volba na
implementátorovi (Svelte 5 podporuje `{#snippet}` syntaxi v rámci
souboru).

### Frontend: `DocumentExchangePreviewModal.svelte`

```svelte
<script>
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import DocumentExchangePreview from './DocumentExchangePreview.svelte';
  import PdfViewerPanel from './PdfViewerPanel.svelte';
  import { previewExtractedDocument } from '../../api/exchange.js';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    extractedNdx = null,
    onClose = () => {},
    onApply = () => {},
    onReject = () => {},
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let data = $state(null);

  $effect(() => {
    if (open && extractedNdx !== null) {
      loadPreview();
    } else {
      data = null;
      error = null;
    }
  });

  async function loadPreview() {
    loading = true;
    error = null;
    const result = await previewExtractedDocument(extractedNdx);
    if (result?.success) {
      data = result.data;
    } else {
      error = result?.error?.message ?? 'Unknown error';
    }
    loading = false;
  }

  let canApply = $derived(data && !data.aiFailed);
</script>

<Modal title={t('exchange.preview.title')} {open} {onClose} width="full">
  {#if loading}
    <div class="shpd-exchange-modal__loading">{t('exchange.preview.loading')}</div>
  {:else if error}
    <div class="shpd-exchange-modal__error">{error}</div>
  {:else if data}
    <div class="shpd-exchange-modal__split">
      <div class="shpd-exchange-modal__pdf">
        <PdfViewerPanel attachments={data.attachments} />
      </div>
      <div class="shpd-exchange-modal__preview">
        <DocumentExchangePreview
          canonical={data.canonical}
          aiFailed={data.aiFailed}
          wrapper={data.wrapper}
        />
      </div>
    </div>
  {/if}

  {#snippet footer()}
    <Button label={t('exchange.preview.actions.close')} variant="secondary" onclick={onClose} />
    <Button
      label={t('exchange.preview.actions.reject')}
      variant="danger"
      onclick={() => onReject(extractedNdx)}
    />
    <Button
      label={t('exchange.preview.actions.apply')}
      variant="success"
      disabled={!canApply}
      onclick={() => onApply(extractedNdx)}
    />
  {/snippet}
</Modal>

<style>
  .shpd-exchange-modal__split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
    height: 100%;
    min-height: 70vh;
  }

  @media (max-width: 768px) {
    .shpd-exchange-modal__split {
      grid-template-columns: 1fr;
      /* Mobile: tabs handled in PdfViewerPanel + parent control */
    }
  }

  .shpd-exchange-modal__pdf,
  .shpd-exchange-modal__preview {
    overflow-y: auto;
  }
</style>
```

Mobile tabs: nahradit grid za tab switcher s state. Implementuj jako
inline state v modal komponentě nebo v podkomponentě
`ResponsiveSplitView`. Záleží na čistotě řešení.

### Frontend: `PdfViewerPanel.svelte`

```svelte
<script>
  import { t } from '../../i18n/index.js';
  import { getApiBaseUrl } from '../../api/config.js';

  let { attachments = [] } = $props();
  let selectedNdx = $state(attachments[0]?.ndx ?? null);

  let selected = $derived(
    attachments.find(a => a.ndx === selectedNdx) ?? attachments[0] ?? null
  );

  // For PDF / images we want inline rendering (iframe / img tag).
  // The download endpoint accepts ?inline=1 — see
  // "Backend: AttachmentController inline mode" above.
  let inlineUrl = $derived(
    selected ? `${getApiBaseUrl()}/_attachments/${selected.ndx}/download?inline=1` : null
  );
  let downloadUrl = $derived(
    selected ? `${getApiBaseUrl()}/_attachments/${selected.ndx}/download` : null
  );

  function isPdf(mime) { return mime === 'application/pdf'; }
  function isImage(mime) { return typeof mime === 'string' && mime.startsWith('image/'); }
</script>

{#if attachments.length === 0}
  <div class="shpd-pdf-panel__empty">{t('exchange.preview.pdf.empty')}</div>
{:else}
  {#if attachments.length > 1}
    <div class="shpd-pdf-panel__tabs">
      {#each attachments as att (att.ndx)}
        <button
          class="shpd-pdf-panel__tab"
          class:shpd-pdf-panel__tab--active={att.ndx === selectedNdx}
          onclick={() => selectedNdx = att.ndx}
        >
          {att.filename}
        </button>
      {/each}
    </div>
  {/if}

  {#if selected && isPdf(selected.mime_type)}
    <iframe class="shpd-pdf-panel__iframe" src={inlineUrl} title={selected.filename}></iframe>
  {:else if selected && isImage(selected.mime_type)}
    <img class="shpd-pdf-panel__img" src={inlineUrl} alt={selected.filename} />
  {:else if selected}
    <div class="shpd-pdf-panel__unsupported">
      {t('exchange.preview.pdf.unsupported')}
      <a href={downloadUrl} download>{t('exchange.preview.pdf.download')} ({selected.filename})</a>
    </div>
  {/if}
{/if}
```

### Frontend: `EntityPicker.svelte` (standalone, nepoužitá ve 3a)

```svelte
<script>
  import Modal from './Modal.svelte';
  import { get } from '../../api/client.js';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    tableName = '',
    searchFields = ['name'],
    displayPattern = (row) => row.name ?? `#${row.id}`,
    onSelect = () => {},
    onClose = () => {},
  } = $props();

  let searchTerm = $state('');
  let results = $state([]);
  let loading = $state(false);
  let debounceTimer = null;

  $effect(() => {
    if (debounceTimer) clearTimeout(debounceTimer);
    if (!open) {
      results = [];
      return;
    }
    debounceTimer = setTimeout(() => doSearch(), 300);
    return () => debounceTimer && clearTimeout(debounceTimer);
  });

  async function doSearch() {
    loading = true;
    const params = new URLSearchParams();
    if (searchTerm.trim() !== '') params.set('search', searchTerm.trim());
    params.set('limit', '10');
    const result = await get(`/${tableName}?${params}`);
    results = result?.success ? (result.data?.rows ?? []) : [];
    loading = false;
  }
</script>

<Modal title={t('picker.search.placeholder')} {open} {onClose} width="600px">
  <input
    type="text"
    placeholder={t('picker.search.placeholder')}
    bind:value={searchTerm}
    class="shpd-picker__input"
    autofocus
  />

  {#if loading}
    <div class="shpd-picker__status">{t('picker.results.loading')}</div>
  {:else if results.length === 0}
    <div class="shpd-picker__status">{t('picker.results.empty')}</div>
  {:else}
    <ul class="shpd-picker__results">
      {#each results as row (row.id)}
        <li class="shpd-picker__item">
          <button onclick={() => { onSelect(row); onClose(); }}>
            {displayPattern(row)}
          </button>
        </li>
      {/each}
    </ul>
  {/if}
</Modal>
```

### Úpravy `ViewerDetail.svelte`

V `<script>`:
- Odstranit `detailModalDoc`, `openDetailModal`, `closeDetailModal`, `formatJson`.
- Přidat:

```javascript
import DocumentExchangePreviewModal from '../exchange/DocumentExchangePreviewModal.svelte';

let previewModalNdx = $state(null);

function openPreviewModal(doc) { previewModalNdx = doc.ndx; }
function closePreviewModal()    { previewModalNdx = null; }

async function handleApplyFromModal(extractedNdx) {
  // Reuse existing applyDocument logic
  actionInFlightNdx = extractedNdx;
  try {
    const result = await post(`/_mail/extracted-documents/${extractedNdx}/apply`, {});
    if (result?.success) {
      closePreviewModal();
      onRefresh?.();
    } else {
      alert(t('viewer.detail.applyFailed', { msg: translateError(result?.error) }));
    }
  } finally {
    actionInFlightNdx = null;
  }
}

function handleRejectFromModal(extractedNdx) {
  // Open existing reject dialog by setting rejectDialogDoc
  const doc = (detail?.tabs ?? [])
    .flatMap(t => t.content?.documents ?? [])
    .find(d => d.ndx === extractedNdx);
  if (doc) {
    closePreviewModal();
    openRejectDialog(doc);
  }
}
```

V template:
- "Detail" tlačítko volá `openPreviewModal(doc)` místo `openDetailModal(doc)`.
- Existující JSON dump Modal úplně odstranit.
- Přidat:

```svelte
<DocumentExchangePreviewModal
  open={previewModalNdx !== null}
  extractedNdx={previewModalNdx}
  onClose={closePreviewModal}
  onApply={handleApplyFromModal}
  onReject={handleRejectFromModal}
/>
```

### `Modal.svelte` — `width="full"` rozšíření

Aktuální Modal přijímá `width` jako CSS hodnotu (`"480px"`, `"800px"`).
Přidat support pro klíčové slovo `"full"`:

```javascript
let widthValue = $derived(width === 'full' ? '95vw' : width);
```

Plus volitelně `height="full"` (95vh) — záleží jak je dnes spravována
výška.

## Hotovo když

- [ ] `POST /api/v1/_mail/extracted-documents/{ndx}/preview` na pending
      extracted document vrátí 200 s `{aiFailed: false, canonical:
      {_resolve: {...}}, attachments: [...]}`.
- [ ] Stejný endpoint na `status=70 (ai_failed)` vrátí 200 s
      `{aiFailed: true, wrapper: {_validationError, _validationIssues,
      _rawOutput}, attachments: [...]}`.
- [ ] Endpoint na neexistující ndx vrátí 404.
- [ ] Endpoint na corrupted `extracted_json` v DB vrátí 500.
- [ ] Klik na "Detail" v UI extracted dokumentu otevře full-screen
      modal se split-view (desktop) nebo tabs (mobile <768px).
- [ ] Modal zobrazí vlevo PDF přílohy v iframe, vpravo vizualizaci
      canonical jako čitelnou fakturu.
- [ ] Pro 0 příloh PDF panel zobrazí "K dispozici není PDF náhled".
- [ ] Pro 2+ příloh PDF panel zobrazí tab switcher.
- [ ] Pro non-PDF mime_type (image/jpeg) PDF panel zobrazí `<img>`.
- [ ] Pro neznámý mime_type PDF panel zobrazí "Nepodporovaný náhled" +
      download link.
- [ ] Vizualizace dokladu obsahuje: hlavičku (typ+číslo+popis), supplier
      + customer karty se status badges, datumy grid, řádky tabulku se
      per-row resolve badges (item, unit, vatCode), vatRecap + totals,
      sekci issues (pokud existují).
- [ ] Pro ai_failed extracted document vizualizace zobrazí error view
      s `_validationError`, seznamem `_validationIssues`, expandable
      `_rawOutput`.
- [ ] Tlačítko "Použít" je disabled pro ai_failed, aktivní jinak.
- [ ] Klik na "Použít" v modalu spustí stejný flow jako dnes
      (`applyExtracted` Fáze 2), po úspěchu modal zavře a refresh list.
- [ ] Klik na "Zamítnout" otevře stávající reject dialog (z modal
      bezprostředně, modal se předtím zavře).
- [ ] Status badges používají správné design tokens
      (`shpd-color-state-done-*` pro matched, atd.).
- [ ] Cs i en překlady kompletní v `i18n/cs.js` a `en.js`.
- [ ] `EntityPicker.svelte` postaven a v izolovaném demo (např.
      `frontend/scripts/entity-picker-demo.html` nebo dev route) funguje
      pro `tableName="base_persons_persons"` — search + výběr fungují,
      `onSelect` callback dostane row. **EntityPicker není zapojen do
      žádné produkční komponenty ve 3a.**
- [ ] Stávající JSON dump modal je úplně odstraněn z `ViewerDetail.svelte`.
- [ ] Frontend build (`npm run build`) projde bez chyb a warningů.
- [ ] PHPUnit backend testy procházejí.
- [ ] Manual QA: e2e flow — AI extrahuje fakturu → admin v UI klikne
      Detail → uvidí PDF + vizualizovaný náhled → klikne Použít →
      doklad v `docs_core_heads`, modal se zavře, list refresh.

## Konvence

- **Svelte 5** s `$state`, `$derived`, `$effect`, `$props`. Žádný
  Svelte 4 stores ad-hoc.
- **Plain JavaScript** (no TypeScript) — per CLAUDE.md.
- **CSS** s `shpd-exchange-` BEM-like prefixem (shpd-exchange__header,
  shpd-exchange__row-code, atd.) a CSS custom properties z
  `variables.css`. **Žádné CSS frameworky.**
- **i18n** — všechny user-facing texty přes `t()`, žádné hardcoded
  české/anglické řetězce ve template.
- **A11y** — `<button>` pro klikatelné, `aria-label` kde label není
  zřejmý, focus management v modalu (existing Modal komponenta to
  asi řeší — pokud ne, dokumentuj jako follow-up).
- **Iframe sandboxing** — `<iframe src="...pdf">` má `sandbox=""`
  attribute? Záleží jestli existující attachment endpoint vrací PDF
  s vhodnými security hlavičkami. Pokud ne, dokumentuj follow-up.

## Doporučené pořadí implementace

1. **Backend `previewExtracted` endpoint** + routing + dispatch.
   PHPUnit test pro pending / ai_failed / 404 / corrupted cases.
2. **Backend `AttachmentController::download` `?inline=1` mode** —
   signature update (`Request $request` parametr) + dispatch update.
   Backward compat check: volání bez query param dál vrací
   `Content-Disposition: attachment`.
3. **Frontend API klient** `src/api/exchange.js` + smoke test (curl
   přes browser dev tools).
4. **`Modal.svelte`** rozšíření o `width="full"` (pokud potřeba).
   Backward compat check (existující 480px / 800px volání stále fungují).
5. **`PdfViewerPanel.svelte`** — izolovaně, testovat manuálně s
   mock attachments array.
6. **`DocumentExchangePreview.svelte`** — read-only render canonical.
   Postavit nejdřív bez status badges, pak doplnit. Manual test s
   `tests/Fixtures/Exchange/invoiceReceived_happy.json` (importovat
   přes mock atd.).
7. **`DocumentExchangePreviewModal.svelte`** — wrapper combining 5 + 6
   s API voláním.
8. **`ViewerDetail.svelte`** — odstranit starý JSON dump, zapojit nový
   modal.
9. **i18n** — vyplnit všechny klíče v cs.js + en.js.
10. **`EntityPicker.svelte`** standalone + demo harness. Manual QA že
    search funguje proti `base_persons_persons`.
11. **End-to-end manuální test** — AI fixture pošli přes mock AnalysisController
    do extracted_documents, pak v UI klikni Detail → Použít → ověř v DB.

## Otevřené body

- **Frontend test runner** — pokud projekt nemá Vitest/testing-library
  setup, zatím netříkat — manuální QA scénáře v "Hotovo když" stačí.
  Dokumentuj v follow-up pokud test infra ano.

- **Modal close při změně tabu zprávy** — pokud user otevře preview
  modal, pak ve viewer detailu klikne jiný extracted document, modal
  by se měl zavřít / refreshnout. Triviálně řešitelné v ViewerDetail
  reset hookem; ne-kritický edge case.

- **EntityPicker pagination** — 10 výsledků hard limit ve 3a. Pokud
  uživatel potřebuje scrollnout přes víc, dokumentuj v "Limity Fáze 3a"
  v README modulu.

- **`displayPattern` v EntityPicker** — Svelte 5 podporuje passing
  funkce jako prop. Pokud projekt preferuje template strings místo
  funkcí, doladi syntaxi. (Funkce je čistší pro různé entity, ale
  template string je deklarativnější.) Volba na implementátorovi.
