# Task: Doklady — detail jako „textová faktura" (content type `document`)

**Stav:** hotovo

## Status / Cíl

Detail dokladu ve viewerech nad `docs_core_heads` (Doklady, Faktury vydané,
Faktury přijaté) dnes vrací chudý `properties` přehled (Identifikace / Datumy /
Součty / Stav). Cíl: detail vypadá jako **textová faktura** — po vzoru
„Zobrazit detail" u extrahovaných dokumentů (`DocumentExchangePreview.svelte`):

- hlavička s typem dokladu, číslem, textem a **stavem dokladu jako badge**
- dvě karty **Dodavatel / Odběratel** (název, IČO/DIČ, adresa, kontakt, banka)
- mřížka datumů + platebních údajů (VS/SS/KS, způsob platby, měna, kurz)
- tabulka **řádků dokladu**
- **DPH rekapitulace** po sazbách + blok součtů (vč. zaokrouhlení a přepočtu
  do domácí měny u cizoměnových dokladů)
- na konci **náhledy příloh** — zdrojové mailové přílohy (mají přednost,
  první) + vlastní přílohy dokladu

Nový vzhled **nahrazuje** stávající tab Přehled. Samostatný tab Přílohy
(sourceAttachments) se **ruší** — jeho obsah se přesouvá na konec detailu.

## Návaznost

- `tasks/docs-source-mail-attachments.md` — zavedl `sourceMessages()` /
  `sourceAttachmentGroups()` a tab `sourceAttachments`; tento task jeho UI
  část integruje do nového layoutu (backend helpery zůstávají).
- `tasks/docs-core-phase2.md` — snapshoty (`buildSnapshots`,
  `buildPersonSnapshot` v `DocDocument`).
- `tasks/exchange-format-phase3a.md` — `DocumentExchangePreview.svelte` jako
  vizuální vzor (NEpoužívá se přímo, viz Rozhodnutí).

## Před implementací přečti

- `modules/docs/core/src/DocsHeadsViewer.php` — `renderDetail()`,
  `sourceMessages()`, `sourceAttachmentGroups()`, formátovací helpery
- `modules/docs/core/src/DocDocument.php` — `buildSnapshots()`,
  `buildPersonSnapshot()` (řádky ~915–1065), `maintainSnapshots()`,
  mapování `trade_dir` z cfgItem `docs.core.docTypes`
- `modules/docs/core/src/OwnCompanyResolver.php`
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` —
  vizuální vzor (party karty, meta grid, rows, vat recap, totals)
- `frontend/src/components/viewer/ViewerDetail.svelte` — dispatch content
  typů; blok `type === 'attachments'` (grid náhledů, který se vytahuje do
  sdílené komponenty); helpery `attachmentIcon()` / `attachmentHasThumbnail()`
- `frontend/src/api/attachments.js` — `thumbnailUrl()`, `downloadUrl()`,
  `formatFileSize()`
- `docs/frontend.md` sekce 7 (viewer systém, `renderDetail()` formát),
  `docs/design-system.md` (state palety `--shpd-color-state-*`)
- `modules/core/attachments/src/AttachmentService.php` — `listAttachments()`

## Scope

### V rozsahu

1. Sdílená služba `PersonSnapshotBuilder` (extrakce z `DocDocument`)
2. Nový detail content type `document` — backend kontrakt + render
   v `DocsHeadsViewer::renderDetail()` (dědí všechny per-typ viewery)
3. Frontend `DocumentDetail.svelte` + sdílená `AttachmentGrid.svelte`
4. i18n klíče `viewer.document.*` (cs + en)
5. Zrušení tabu `sourceAttachments` v detailu dokladů

### Mimo rozsah

- Detaily jiných viewerů (Osoby, …) — explicitně odloženo
- PDF výstup / tisk dokladu
- Jakékoli změny editačního formuláře dokladu
- Změny detailu došlé pošty (`IncomingMessagesViewer`) — jeho tab Přílohy
  dál používá stávající `attachments` content type (jen přes novou sdílenou
  grid komponentu)

## Kontrakt — content type `document`

`renderDetail()` vrací jediný tab `overview` s obsahem:

```jsonc
{
    "type": "document",
    "header": {
        "docTypeLabel": "Faktura přijatá",      // z cfgItem docs.core.docTypes
        "docNumber": "!0000000016",
        "docText": "testtest",                   // nullable
        "state": {"name": "Koncept", "style": "concept"}
    },
    "supplier": { /* party blok, viz níže */ },  // nullable
    "customer": { /* party blok */ },            // nullable
    "meta": {
        // hodnoty pre-formátované serverem (stringy), null = pole se nerenderuje
        "issue_date": "28. 5. 2026",
        "due_date": "11. 6. 2026",
        "accounting_date": "28. 5. 2026",
        "vat_duzp": "28. 5. 2026",
        "currency": "CZK",
        "exchange_rate": null,                   // jen u cizí měny, "24,500"
        "payment_method": "Převodem",            // label z cfgItem, ne kód
        "variable_symbol": "16",
        "specific_symbol": null,
        "constant_symbol": null
    },
    "rows": [
        {"order_pos": 1, "kind": 1,              // kind: 0 = textový řádek
         "description": "Konzultace",
         "quantity": "10", "unit": "hod",
         "unit_price": "600,00", "vat_pct": "21",
         "total_price": "6 000,00"}
    ],
    "vat_recap": [
        {"vat_pct": "21", "base": "6 000,00", "tax": "1 260,00", "total": "7 260,00"}
    ],
    "totals": {
        "currency": "CZK",
        "base": "6 000,00", "vat": "1 260,00", "amount": "7 260,00",
        "rounding": null,                        // jen když nenulové
        "dom": null                              // {currency, base, vat, amount} jen u cizí měny
    },
    "attachments": {
        "groups": [
            {"kind": "mail", "message_id": "…", "message_ndx": 12,
             "received_at": "27. 5. 2026", "sourceViewerId": "core.mail.incoming",
             "attachments": [{"id": 1, "name": "faktura.pdf",
                              "mime_type": "application/pdf", "file_size": 12345}]},
            {"kind": "doc",
             "attachments": [ /* stejný tvar */ ]}
        ]
    }
}
```

Party blok (tvar = uložený snapshot, snake_case):

```jsonc
{
    "name": "Česká Tech, s.r.o.",
    "company_id": "12345678", "tax_id": "CZ12345678", "vat_id": null,
    "address": {"street": "…", "house_number": "…", "city": "…", "zip": "…",
                "country": "…", "display_line": "…"},
    "contact": {"email": "…", "phone": "…"},
    "bank_account": {"name": "…", "account_number": "…", "iban": "…", "bic": "…"}
}
```

Konvence: **server formátuje** (datumy `j. n. Y`, částky
`number_format(…, 2, ',', ' ')`), frontend jen skládá layout. Statické
labely (Dodavatel, Množství, Základ, …) řeší frontend přes `t()` — hodnoty
závislé na konfiguraci (typ dokladu, stav, způsob platby) posílá server
lokalizované.

## Co je potřeba udělat

### 1. `PersonSnapshotBuilder` — sdílená služba

Nová třída `modules/docs/core/src/PersonSnapshotBuilder.php`
(`Shipard\Module\Docs\Core`):

- Konstruktor: `(Dibi\Connection $db)`
- `public function build(int $personId, mixed $addressId, mixed $bankAccountId, mixed $vatRegistrationId): array`
  — přesun těla `DocDocument::buildPersonSnapshot()` beze změny tvaru
  výstupu (snapshoty v DB musí zůstat kompatibilní!)
- `DocDocument::buildPersonSnapshot()` zůstává jako tenká delegace na
  službu (lazy factory vzor jako `ownCompanyResolver()`), aby se nerozbily
  existující testy a potomci

### 2. `DocsHeadsViewer::renderDetail()` — přepis

- **Strany dokladu:**
  - `supplier_snapshot` / `customer_snapshot` neprázdné → `json_decode`
    a použít (zmrazený stav po Potvrzení — věcně správně)
  - jinak (Koncept) → živé sestavení přes `PersonSnapshotBuilder`:
    - `trade_dir` z cfgItem `docs.core.docTypes` (1 = my dodavatel,
      jinak my odběratel) — stejné mapování jako `DocDocument::buildSnapshots()`
    - partner: `build(partner, partner_address, null, null)`
    - vlastní firma: `OwnCompanyResolver` (person + HQ adresa) +
      `bank_account` / `vat_registration` z hlavičky
    - chybějící vlastní firma (`getOwnPersonId() === null`) → strana je
      `null`, žádná výjimka (detail nesmí spadnout na nenakonfigurovaném DS)
- **Řádky:** `SELECT` z `docs_core_rows WHERE doc_head = %i ORDER BY
  order_pos ASC, id ASC`; `description`, formátované `quantity`,
  `unit_price`, `total_price`, `vat_pct`; `kind` = `row_kind`
  (0 = textový řádek — frontend renderuje jen popis přes celou šířku)
- **Rekapitulace:** `docs_core_vat_recap WHERE doc_head = %i ORDER BY
  order_pos ASC` — jen ne-souhrnné řádky po sazbách (sloupce
  `vat_pct/base/tax/total`); souhrn (`sum_*`) jde do `totals`
- **Totals:** `rounding` jen když `total_rounding` nenulové; `dom` blok jen
  když `doc_currency !== home_currency` (z `*_dom` sloupců + `exchange_rate`
  do `meta`)
- **Přílohy:** mailové skupiny z existujícího `sourceAttachmentGroups()`
  (doplnit `kind: 'mail'` + `sourceViewerId` přímo do skupin), pak vlastní
  přílohy `AttachmentService::listAttachments(401, $recordId)` jako skupina
  `kind: 'doc'` (jen ne-smazané; pokud prázdné, skupinu vynech). Celý blok
  `attachments` vynech, když nejsou žádné skupiny.
- **Smazat** tab `sourceAttachments` (UI část; metody `sourceMessages()` /
  `sourceAttachmentGroups()` zůstávají). Klíč `sourceAttachments` v
  `modules/docs/core/config/viewerDetailLabels.jsonc` odstranit, pokud ho
  nic jiného nepoužívá.

### 3. Frontend — `AttachmentGrid.svelte`

Extrakce grid náhledů z `ViewerDetail.svelte` (blok uvnitř
`type === 'attachments'`: karta s thumbnail/ikonou, název, velikost) do
`frontend/src/components/viewer/AttachmentGrid.svelte`:

- Props: `attachments` (list)
- Přesunout i helpery `attachmentIcon()` / `attachmentHasThumbnail()`
- `ViewerDetail` (typ `attachments` — pošta) i nový `DocumentDetail` ji
  používají; vizuál se nesmí změnit (stejné BEM třídy lze přejmenovat na
  `shpd-attgrid__*`)

### 4. Frontend — `DocumentDetail.svelte`

`frontend/src/components/viewer/DocumentDetail.svelte`, props: `content`
(payload typu `document`). Layout po vzoru `DocumentExchangePreview`
(BEM prefix `shpd-docdetail__*`, žádné sdílení tříd s exchange):

- Hlavička: type badge + `docNumber` + `docText`; vpravo **state badge** —
  barvy `var(--shpd-color-state-{style}-bg/-text)` podle `header.state.style`
- Strany: grid 2 sloupce (≤600px 1 sloupec); `null` strana → „—"
- Meta grid: `auto-fit minmax(180px, 1fr)`; null hodnoty se nerenderují
- Řádky: tabulka (Poz. / Popis / Množství / MJ / Cena za MJ / DPH / Celkem),
  `kind === 0` → `<td colspan>` jen s popisem; sekci vynech, když řádky nejsou
- Rekapitulace + součty: vedle sebe (flex, wrap) jako v exchange preview;
  řádek Zaokrouhlení jen když přijde; `dom` přepočet drobným písmem pod Celkem
- Přílohy: na konci; skupina `kind === 'mail'` má titulek s odkazem
  `#message_id` (`navigationStore.navigateToViewer(sourceViewerId,
  message_ndx)`) + datum — převzít ze stávajícího `attachments` bloku;
  skupina `kind === 'doc'` má titulek `t('viewer.document.attachments.doc')`

V `ViewerDetail.svelte` přidat větev
`{:else if activeContent?.type === 'document'} <DocumentDetail content={activeContent} />`.

### 5. i18n

Nové klíče v `frontend/src/i18n/cs.js` + `en.js` (parita!), namespace
`viewer.document.*`: sekce (supplier/customer/rows/recap/attachments.doc),
meta labely (issueDate, dueDate, accountingDate, taxPointDate, currency,
exchangeRate, paymentMethod, variableSymbol, specificSymbol,
constantSymbol), sloupce řádků, totals (base/vat/total/rounding),
party pole (companyId/taxId/vatId/bankAccount).

### 6. Ověření

```bash
php -l modules/docs/core/src/DocsHeadsViewer.php
php -l modules/docs/core/src/PersonSnapshotBuilder.php
vendor/bin/phpunit --filter 'Docs'
cd frontend && npm run check:i18n && timeout 90 npm run build 2>&1 | tail -4
```

Pozn.: pre-existing failures `Opis\JsonSchema\Validator not found`
(Exchange/Mail testy) jsou baseline šum, netýkají se této změny.

## Akceptace

- [ ] Detail Faktury přijaté (Koncept, bez snapshotů) ukazuje Dodavatele
      (partner) i Odběratele (vlastní firma) složené živě
- [ ] Detail potvrzeného dokladu ukazuje strany ze snapshotů (nemění se
      při pozdější editaci osoby)
- [ ] DS bez vlastní firmy: detail se vyrenderuje, strana „my" je „—"
- [ ] Řádky, DPH rekapitulace a součty odpovídají dokladu; textový řádek
      (kind 0) bez částek přes celou šířku
- [ ] Cizoměnový doklad: kurz v meta, přepočet do domácí měny pod součty
- [ ] Zaokrouhlení viditelné jen u dokladů s nenulovým `total_rounding`
- [ ] Přílohy na konci: mailové skupiny první (s odkazem na zprávu),
      pak Přílohy dokladu; tab `sourceAttachments` už neexistuje
- [ ] Detail došlé pošty (tab Přílohy) vypadá beze změny (sdílená
      `AttachmentGrid`)
- [ ] Stav dokladu jako badge v hlavičce detailu, barvy podle stateStyle
- [ ] `npm run check:i18n` prochází, build prochází, `phpunit --filter Docs`
      prochází
- [ ] Mobil (≤768px): detail čitelný, strany pod sebou

## Rozhodnutí k designu (potvrzená)

- ✓ Nový content type `document` + vlastní komponenta `DocumentDetail.svelte`
  — `DocumentExchangePreview` se nepoužívá přímo (provázanost s `_resolve`,
  interaktivními badgi a `exchange.*` klíči); přebírá se jen vizuální jazyk
- ✓ Nový vzhled **nahrazuje** properties tab Přehled (ne vedle něj)
- ✓ Přílohy: obojí — zdrojové mailové (přednost, první) + vlastní přílohy
  dokladu; samostatný tab `sourceAttachments` se ruší
- ✓ Platí pro všechny viewery nad `docs_core_heads` (implementace
  v `DocsHeadsViewer`, dědí Doklady / FV / FP)
- ✓ DPH rekapitulace po sazbách je součástí detailu
- ✓ Stav dokladu jako badge v hlavičce detailu
- ✓ Strany: snapshot pokud existuje (Potvrzeno+), jinak živé sestavení
  přes sdílenou `PersonSnapshotBuilder` (extrakce z `DocDocument`)
- ✓ Cizí měna: přepočet do domácí měny drobně pod Celkem; kurz v meta
- ✓ Zaokrouhlení jako řádek součtů jen když nenulové
- ✓ Textové řádky (kind 0) v tabulce řádků jako popis přes celou šířku
- ✓ Server formátuje hodnoty (datumy, částky); statické labely frontend `t()`
