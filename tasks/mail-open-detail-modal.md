# Task: Dashboard — „Otevřít e-mail" jako read-only náhled zprávy v modalu (Issue #30)

**Stav:** hotovo

## Cíl

Akce **„Otevřít e-mail"** na dashboard kartách dnes otevírá editační
formulář (`open_form` → `IncomingMessagesForm`). U HTML-only zpráv
(`body_plain` prázdné, `body_html` plné) je modal prázdný — formulář
renderuje jen `body_plain` (textarea) a panel příloh navíc nevylučuje raw
`.eml`. Jádro problému: **čtecí potřeba obsluhovaná editační plochou.**

Cíl (GitHub Issue #30, varianta V3):

1. **Nový generický action kind `open_detail`** — dashboard karta otevře
   modal s read-only detailem záznamu z existujícího viewer detail
   endpointu (`GET /_ui/viewer/{viewerId}/detail/{id}`).
2. **Akce `openMail` přepnout** z `open_form` na `open_detail`
   (3 výskyty v `MailSuggestionsSource`).
3. **Nezávislý fix (D4):** vyloučit raw `.eml` z panelu příloh formuláře
   došlé zprávy (`FormAttachmentsView`) — konzistence s viewerem a feedem.

Uživatel zůstává na dashboardu (D1); tělo zprávy se renderuje stejně jako
v Došlá pošta → tab Obsah (HTML přes SandboxedHtml, fallback plain).

## Návaznost

- GitHub Issue: https://github.com/shipard/shpd/issues/30
- Endpoint `GET /_ui/viewer/{viewerId}/detail/{id}` už existuje
  (`src/Api/Controller/ViewerController.php` ~ř. 188, `Router.php` ~ř. 1126)
  a je nezávislý na stavu vieweru — vrací `{toolbar, detail}`.
- `ViewerDetail.svelte` má čistý props kontrakt `{detail, loading,
  onRefresh, onAction}` a už dnes má dva hostitele (inline panel ve
  `Viewer.svelte`, `ViewerDetailDrawer.svelte`). Modal je třetí hostitel
  téhož vzoru.
- `IncomingMessagesViewer::buildContentTab` je referenční chování:
  `body_html` → blok `untrusted-html` (SandboxedHtml), fallback
  `body_plain` v `<pre>`, `fetchContentAttachments` vylučuje raw `.eml`.
- Viewer id došlé pošty: `core.mail.incoming` (`modules/core/mail/module.jsonc`).

## Potvrzená designová rozhodnutí (Anna, 2026-08-19)

- **D1** (z Issue) Uživatel zůstává na dashboardu — **modal**, žádná
  navigace do Došlé pošty.
- **D2** Modal zobrazuje **pouze tab Obsah**. Bez záměru budoucího
  rozšíření na další taby (Analýzy/Návrh/Originál) — akce v tabu Návrh
  by duplikovaly review flow dashboardu.
- **D3** Toolbar akce detailu (`fileToRegistry`, `reanalyze`) se
  v modalu **nezobrazují** — modal ignoruje `result.data.toolbar`.
  Dashboard má vlastní akce na kartě.
- **D4** Vyloučení raw `.eml` z panelu příloh formuláře řešit
  **client-side**: form předá do komponenty `attachmentsView` param
  `exclude_attachment_id` z dat záznamu (`raw_source_attachment`),
  `FormAttachmentsView` přílohu odfiltruje.

### Mechanismus D2 (implementační design)

Kind `open_detail` zůstává generický; omezení na jeden tab nese target:

```
['id' => 'openMail', 'kind' => 'open_detail',
 'target' => ['viewerId' => 'core.mail.incoming', 'recordId' => $messageNdx, 'tabId' => 'content']]
```

Modal: když `tabId` je zadán, z `detail.tabs` ponechá jen tento tab;
bez `tabId` zobrazí všechny. Hlavička detailu (title/subtitle/badges/icon)
zůstává vždy — u zprávy nese předmět, odesílatele, schránku, doručeno
a badges, což je pro orientaci v náhledu přesně to pravé.

## Rozsah

### V rozsahu

1. `modules/core/mail/src/Feed/MailSuggestionsSource.php` — 3× akce
   `openMail`: `open_form` → `open_detail` (~ř. 296, 355, 429).
2. `frontend/src/components/viewer/ViewerDetailModal.svelte` — **nová**
   komponenta (Modal + fetch detailu + `<ViewerDetail>`).
3. `frontend/src/components/viewer/ViewerDetail.svelte` — nový prop
   `hideSingleTabBar = false`: skrýt tab lištu, když je tabů ≤ 1
   a prop je true. Existující hostitelé beze změny chování.
4. `frontend/src/components/dashboard/Dashboard.svelte` — stav
   `detailModal`, větev `case 'open_detail'` v `handleCardAction`,
   mount `<ViewerDetailModal>`.
5. `modules/core/mail/src/IncomingMessagesForm.php` — do
   `->component('attachmentsView', params: [...])` přidat
   `exclude_attachment_id` z `$data['raw_source_attachment']` (D4).
6. `frontend/src/components/form/FormAttachmentsView.svelte` — filtr
   podle `params.exclude_attachment_id` (D4).
7. i18n: `frontend/src/i18n/cs.js` + `en.js` — klíče modalu
   (zavření/loading/chyba načtení), pokud je Modal nevyžaduje z generiky.

### Mimo rozsah

- Jakákoli změna `IncomingMessagesForm` layoutu (V2 zamítnuta) — kromě
  bodu 5 (param komponenty).
- Zobrazování dalších tabů detailu v modalu (D2).
- Toolbar/detail akce v modalu (D3) — `onAction` se do `ViewerDetail`
  nepředává (tab Obsah žádné akce nemá; pojistka do budoucna).
- Změny viewer detail endpointu nebo `IncomingMessagesViewer` — žádné
  nejsou potřeba.
- Server-side `exclude` parametr na `GET /_attachments` (D4 varianta b).

## Datový tok

```
karta „Otevřít e-mail" → action {kind: 'open_detail',
    target: {viewerId: 'core.mail.incoming', recordId, tabId: 'content'}}
  ▼ Dashboard.handleCardAction
detailModal = {open: true, viewerId, recordId, tabId}
  ▼ ViewerDetailModal (onMount / $effect)
GET /_ui/viewer/core.mail.incoming/detail/{recordId}
  → result.data.detail  (toolbar se ignoruje — D3)
  → tabId zadán → detail.tabs = jen tab 'content'
  ▼
<ViewerDetail detail={trimmed} hideSingleTabBar />
  → hlavička (předmět · odesílatel · schránka · doručeno + badges)
  → tělo: untrusted-html → SandboxedHtml / plain <pre>
  → přílohy: attachment-grid (raw .eml už vyloučen backendem)
```

## Co je potřeba udělat

### 1. `MailSuggestionsSource.php` — přepnout akci

Na všech třech místech (chyba analýzy 2×, „Není faktura — ostatní"):

```php
['id' => 'openMail', 'kind' => 'open_detail', 'target' => [
    'viewerId' => 'core.mail.incoming',
    'recordId' => $messageNdx,
    'tabId'    => 'content',
]],
```

Klíč `table` z targetu zmizí (patřil `open_form`). Label akce zůstává
`dashboard.card.action.openMail` („Otevřít e-mail") — beze změny.

### 2. `ViewerDetailModal.svelte` — nová komponenta

Props: `{ open, viewerId, recordId, tabId = null, onClose }`.

- Fetch přes existující `get()` z `api/client.js`:
  `get(`/_ui/viewer/${viewerId}/detail/${recordId}`)` — stejný vzor jako
  `Viewer.svelte::fetchDetail` (~ř. 243). `toolbar` z odpovědi ignorovat.
- Po fetchi: `tabId` zadán → `detail = {...detail, tabs:
  detail.tabs.filter(t => t.id === tabId)}`.
- Render: `<Modal>` (existující UI komponenta — stejná, jakou používá
  dashboard pro ostatní modaly) obalující
  `<ViewerDetail {detail} {loading} hideSingleTabBar />`.
  `onAction`/`onRefresh` nepředávat (D3).
- Loading/error stav: během fetchu `loading` prop, při neúspěchu krátká
  hláška + zavřít lze vždy.
- Reaktivita na změnu `recordId` (přepnutí karty bez zavření): refetch,
  reset starého detailu; guard proti out-of-order odpovědím (request
  token — vzor viz `DocumentExchangePreview`).

### 3. `ViewerDetail.svelte` — `hideSingleTabBar`

```js
let { detail = null, loading = false, onRefresh, onAction = null,
      hideSingleTabBar = false } = $props();
```

Tab lišta (`.shpd-detail__tabs`, ~ř. 238) se nerenderuje, když
`hideSingleTabBar && detail.tabs.length <= 1`. Výběr aktivního tabu
(`activeTabId`, ~ř. 44–56) funguje beze změny. Default `false` →
stávající hostitelé (Viewer inline panel, ViewerDetailDrawer) nezmění
vzhled ani u jednotabových detailů.

### 4. `Dashboard.svelte` — větev a mount

```js
let detailModal = $state({ open: false, viewerId: '', recordId: null, tabId: null });
```

V `handleCardAction`:

```js
case 'open_detail':
  detailModal = {
    open: true,
    viewerId: target.viewerId,
    recordId: target.recordId,
    tabId: target.tabId ?? null,
  };
  return;
```

Mount vedle ostatních modalů; `onClose` → `detailModal = {open: false, …}`.
Zavření modalu **nevolá** `load()` — čtení nic nemění, refresh feedu
není potřeba (na rozdíl od `handleFormClose`).

### 5.–6. D4 fix — `exclude_attachment_id`

`IncomingMessagesForm::buildFormDefinition`:

```php
->component('attachmentsView', params: [
    'table_id' => $tableId,
    'exclude_attachment_id' => isset($data['raw_source_attachment'])
        ? (int) $data['raw_source_attachment']
        : null,
])
```

`FormAttachmentsView.svelte`: po fetchi
`attachments.filter(a => a.id !== params?.exclude_attachment_id)`
(pozor na typy — `id` z API je number, param z PHP int/null; při
`null` nefiltrovat).

### 7. i18n

Podle toho, co `Modal` generika nepokrývá — pravděpodobně jen chybová
hláška načtení detailu, např. `dashboard.detailModal.loadFailed`.
Zkontrolovat existující klíče `viewer.*` (drawer má `viewer.drawer.close`)
a nevyrábět duplicitní.

## Testy

- PHP: `php -l` na oba měněné soubory;
  `vendor/bin/phpunit --filter MailSuggestionsSource` pokud testy
  existují (ověřit `grep -rl MailSuggestionsSource tests/`) — akce
  `openMail` v nich může assertovat `open_form` → aktualizovat.
- Frontend: `cd frontend && timeout 90 npm run build 2>&1 | tail -10`.

## Ověření E2E (alfa)

1. Dashboard → karta „Není faktura — ostatní" u **HTML-only** zprávy →
   „Otevřít e-mail" → modal zobrazí hlavičku (předmět, odesílatel,
   schránka, doručeno, badges) + renderované HTML tělo v sandboxovaném
   iframe. Žádná tab lišta, žádné toolbar akce, žádná `.eml` příloha.
2. Totéž u zprávy s `body_plain` (plain fallback v `<pre>`).
3. Karta „Chyba analýzy e-mailu" → „Otevřít e-mail" → tentýž modal.
4. Zpráva s PDF přílohou → příloha viditelná v gridu, raw `.eml` ne.
5. Regrese: Došlá pošta → detail (inline i drawer) beze změny vzhledu;
   dashboard `open_form` větev (alert karty, vystavená faktura po apply)
   funguje dál.
6. D4: Došlá pošta → záznam → Otevřít (formulář) → panel příloh v tabu
   „Zpráva" **bez** raw `.eml`; tab „Přílohy" (správa) beze změny —
   filtr patří jen do `FormAttachmentsView`, ne do attachments tabu.

## Pasti

- **`renderContent` je interní snippet `ViewerDetail`** — nerefaktorovat
  ho ven, znovupoužití jde přes celou komponentu (trimmed detail +
  `hideSingleTabBar`). Extrakce snippetu je zbytečně velký zásah.
- **SandboxedHtml se nesmí obejít** — tělo renderovat výhradně přes
  existující blok `untrusted-html` uvnitř `ViewerDetail`. Nikdy
  nevkládat `body_html` přímo do DOM modalu.
- **`hideSingleTabBar` musí být opt-in** (default false) — existují
  detaily s jedním tabem i jinde a jejich vzhled se nesmí změnit.
- **Nezaměnit `open_detail` s `open_viewer`** — `open_viewer` naviguje
  do vieweru (opouští dashboard), `open_detail` zůstává v modalu.
  Obě větve v `handleCardAction` žijí vedle sebe.
- **`toolbar` z endpointu obsahuje `create`/`edit`/`fileToRegistry`/
  `reanalyze`** — modal ho musí ignorovat celý (D3), ne filtrovat.
- **Esc stacking**: `Modal.svelte` řeší pořadí vrstev s drawerem
  (`isModalOpen`) — použít standardní `Modal`, nevymýšlet vlastní
  keydown handling.
- **Typy u `exclude_attachment_id`**: PHP posílá int/null, JS porovnává
  s `a.id` (number). Striktní `!==` s `Number(...)` guardem, `null`
  → žádný filtr.
- **PHPUnit fixtures**: testy feed karet mohou assertovat přesný tvar
  akce `openMail` (kind/target) — po změně aktualizovat očekávání,
  ne obcházet.
- **`recordId` reaktivita v modalu**: bez request tokenu může pomalá
  odpověď staré zprávy přepsat novou. Vzor: uložit token před await,
  po await porovnat.

## Před implementací přečti

- GitHub Issue #30
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` (akce karet)
- `modules/core/mail/src/IncomingMessagesViewer.php::buildContentTab`
  + `fetchContentAttachments` (referenční chování, beze změny)
- `frontend/src/components/viewer/ViewerDetail.svelte` (celý — snippet
  `renderContent`, tab lišta, props kontrakt)
- `frontend/src/components/viewer/ViewerDetailDrawer.svelte` (vzor
  druhého hostitele)
- `frontend/src/components/viewer/Viewer.svelte::fetchDetail` (~ř. 243)
- `frontend/src/components/dashboard/Dashboard.svelte`
  (`handleCardAction`, vzor `formModal`)
- `frontend/src/components/form/FormAttachmentsView.svelte`
- `frontend/src/components/ui/Modal.svelte` (Esc stacking)
