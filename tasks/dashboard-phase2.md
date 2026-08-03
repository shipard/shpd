# Task: Dashboard — fáze 2 (domovský feed)

**Stav:** hotovo

## Status / Cíl fáze

Přestavět domovskou obrazovku z pasivní mřížky tří widgetů (fáze 1) na
**prioritizovaný feed akčních karet**. První ostrý řez kokpitu: uživatel po
loginu vidí, co má řešit, a **provede akci přímo z feedu** — bez procházení
viewerů.

Fáze 2 pokrývá **jeden tok: došlá pošta → doklad**, plus deterministické
alerty jako druhý zdroj. Celá apply/review/reject/resolve mašinérie už existuje
(viz Návaznost) — tento task ji **skládá a zviditelňuje**, nová je jen:
feed backend (2 zdroje + kontrakt), feed frontend (nahrazuje widget mřížku)
a **undo endpoint**.

AI shrnutí (`summary.aiText`) je **mimo tento task** — samostatná fáze 2b.

Designový dokument: [`docs/dashboard-feed.md`](../docs/dashboard-feed.md)
(rozhodnutí D1–D10).

## Návaznost

- `tasks/dashboard-phase1.md` — widget MVP (`DashboardController`, `Dashboard.svelte`,
  `WidgetCard`/`WidgetRow`, `api/dashboard.js`, navigace, `pendingRecordId`).
  **Tuto fázi z velké části přepisujeme**; tasks widget a `WidgetCard`/`WidgetRow`
  se reusnou pro sekundární tasks blok.
- `tasks/exchange-format-phase3a.md`, `exchange-format-phase3b.md` — kanonický
  `shpd.docs.document.v1`, `DocumentApplier`, `_resolve`, `applyOptions`
  (`autoCreateMode` safe/strict), apply/preview/reject nad vytěženými doklady.
- `tasks/exchange-resolve-decision-ui.md` — `DocumentExchangePreviewModal` +
  `ResolveDecisionPanel` + `api/exchange.js`. **Feed montuje tentýž modal.**
- `tasks/mail-phase3a.md` — AI analyzer, `core_mail_extracted_documents`,
  `extractedDocStates` (10/20/30 pending, 40/50/60 terminal, 70 AI failed),
  auto-transition zprávy 30→40.
- `tasks/mcp-server-02-read-tools.md` — `MailListPendingTool` (dotaz per-zpráva
  agregující stav analýzy + počet čekajících dokladů; vzor pro mail feed zdroj).
- `tasks/alerts-01.md`–`03.md` — `core.alerts`, `actions[]` schéma
  (`open_form`/`open_viewer`), `alert_state` (10 Active), severity.

Tento task **nezavádí novou datovou vrstvu** — agreguje existující stavy do
nového pohledu a přidává jednu vratnou operaci.

## Před implementací přečti

- **`docs/dashboard-feed.md`** — celý (spec této fáze; kartový kontrakt §4,
  zdroje §5, akce §6 včetně undo §6.4, API §7, otevřené body §12)
- `docs/dashboard.md` — MVP, který nahrazujeme (API tvar, komponenty)
- `docs/alerts.md` — sekce **9** (`actions[]` schéma — přebíráme beze změny),
  sekce **7** (alert_state)
- `docs/exchange-format.md` — kanonický doklad, `_resolve`, `applyOptions`,
  `autoCreateMode` **safe vs strict** (klíčové pro D8 jednoklik)
- `docs/mail/api-contract.md` — sekce **9.5–9.9** (result / apply / reject /
  reanalyze) + stavy
- `src/Api/Controller/DashboardController.php` — **cíl přepisu**; zachovej
  `renderRowToWidgetItem`/`flattenTextField`/`countActiveByDocState`/
  `buildTasksWidget` (reuse pro tasks blok)
- `src/Api/Controller/AnalysisController.php` — handlery apply/reject/reanalyze;
  **sem přidat `unapply` endpoint**. Zjisti **error kód/tvar, který vrací apply
  v `safe` módu, když referenci nelze bezpečně vyřešit** — na něj frontend
  otevře modal (D8 fall-through)
- `modules/core/mail/src/ExtractedDocumentApplier.php` — apply core; **sem
  přidat `unapply()`**. `writeStatusTransition()` je znovupoužitelná primitiva
- `modules/core/mail/src/ExtractedDocumentDocument.php` — STATUS_* konstanty,
  `afterPersist` reconcile zprávy 30↔40, `target_row_ndx`
  (= id vytvořeného Konceptu, klíč pro undo)
- `modules/core/mail/src/Mcp/MailListPendingTool.php` — SQL vzor pro mail zdroj
- `modules/core/mail/config/extractedDocStates.jsonc`,
  `extractedDocTypes.jsonc` — stavy a lokalizované labely typů dokladů
- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte` —
  props (`open`, `extractedNdx`, `onClose`, `onApply(ndx, ua)`, `onReject(ndx)`),
  `canApply`
- `frontend/src/components/viewer/ViewerDetail.svelte` — **existující wiring**:
  `handleApplyFromModal`, `handleRejectFromModal`, reject-reason dialog přes
  sdílený `Modal` (`#reject-reason`, `viewer.detail.rejectReasonLabel`).
  Feed reusne stejný pattern → vytáhnout do sdílené komponenty
- `frontend/src/api/exchange.js` — `previewExtractedDocument`,
  `applyExtractedDocument(ndx, ua)`, `rejectExtractedDocument(ndx, reason)`,
  `attachmentUrl(ndx, inline)`
- `frontend/src/components/dashboard/*.svelte`, `frontend/src/api/dashboard.js`
  — fáze 1 komponenty (Dashboard přepisujeme, WidgetCard/WidgetRow reuse pro tasks)
- `frontend/src/stores/navigation.svelte.js` — `navigateToViewer`,
  `pendingRecordId`
- `docs/design-system.md` — sekce **4** (doc-state `stateStyle` / `docState_*`),
  **5** (badge)

## Scope

### V rozsahu

**Backend**
- Lehké rozhraní `FeedSource` + dvě implementace (`MailSuggestionsSource`,
  `AlertsSource`), registrované napevno v `DashboardController` (D10)
- Přepis `DashboardController::dashboard()` na feed-first tvar
  `{ generatedAt, summary, cards[], tasks }` (tasks blok reuse fáze 1)
- Nový endpoint **`POST /_mail/extracted-documents/{ndx}/unapply`** +
  `ExtractedDocumentApplier::unapply()`

**Frontend**
- Přepis `Dashboard.svelte`: feed nahoře + tasks widget pod ním
- Nové `Feed.svelte`, `FeedCard.svelte`
- `RejectReasonPrompt.svelte` — vytažení reject-reason dialogu (sdílí Feed i
  ViewerDetail)
- Optimistický jednoklik apply s **fall-through do modalu** (D8) + toast s undo
- `api/dashboard.js` update na nový tvar; `api/exchange.js` doplnit
  `unapplyExtractedDocument`

**i18n**: nové klíče (kind labely, akce, toast/undo, prázdné stavy), lint pass

**Dokumentace**: sloučit `docs/dashboard-feed.md` do `docs/dashboard.md`
(nahradit MVP kapitolu), update `docs/architecture.md` (nový endpoint),
`docs/mail/api-contract.md` (unapply), `CLAUDE.md`.
**`docs/README.md` needá — David spravuje sám.**

### Mimo rozsah

- **AI shrnutí** (`summary.aiText`) — fáze 2b. V 1a `aiText=null`,
  `AiSummaryCard` ukazuje county dle kind (staticky).
- **Module-driven feed zdroje** přes `module.jsonc` — odloženo, napevno (D10).
- **Tasks jako zdroj karet** — zůstává sekundární widget (D6b); jako kind zdroj
  až s assignee.
- **Vizuální seskupení multi-doc karet** (N dokladů z jednoho e-mailu) —
  otevřený bod, ne blokátor; ve fázi 2 N samostatných karet.
- **Příkazový řádek / chat / týmový chat** — celé mimo (viz „balast" v diskusi).
- **Snooze mail návrhů** — reject je terminální „dismiss"; „teď ne" = karta
  prostě zůstane. Snooze má jen alerts (přes vlastní viewer, ne z feedu ve 2).
- **Alert akce snooze/dismiss z karty** — alert karta jen naviguje
  (`open_viewer`/`open_form`); snooze/dismiss zůstává ve viewer detailu.
- **Alert check „nezpracovaná pošta čeká dlouho"** — samostatný task později.
- **Auto-refresh / polling / SSE** — jen fetch při mountu + manuální refresh.

## Architektura

```
        GET /_ui/dashboard
               │
               ▼
     ┌───────────────────────────┐
     │ DashboardController        │  napevno reg. zdroje (D10)
     │ ::dashboard()              │
     └────────────┬──────────────┘
        ┌──────────┴───────────┐
        ▼                      ▼
 MailSuggestionsSource     AlertsSource        buildTasksWidget()  ← beze změny
 collectCards(ctx)         collectCards(ctx)   (fáze 1)
        │                      │                     │
        └──────────┬───────────┘                     │
                   ▼ map kind, seřaď (§4.2 design)    │
              cards[]  ─────────────────────────────► tasks
                   ▼
              Feed.svelte  ── FeedCard × N
                   │              │
                   │              ├─ apply_extracted  → apply(ndx) → toast+undo
                   │              │      └─ nevyřešeno → otevři modal (fall-through)
                   │              ├─ review_extracted → DocumentExchangePreviewModal
                   │              ├─ reject_extracted → RejectReasonPrompt → reject
                   │              ├─ reanalyze        → reanalyze(msgNdx)
                   │              └─ open_viewer/open_form → navigace
                   ▼
              TasksWidget (WidgetCard reuse) pod feedem
```

## Kartový kontrakt (D2)

### Karta

```json
{
  "id": "mail_extracted:456",
  "source": "mail",
  "kind": "ready",
  "icon": "check",
  "stateStyle": "done",
  "title": "Přijatá faktura — ČEZ a.s.",
  "subtitle": "4 200 Kč · jistota 94 % · e-mail „Faktura 2026000123\"",
  "timestamp": "2026-06-28T10:00:00Z",
  "context": { "messageNdx": 123, "extractedNdx": 456, "confidence": 0.94 },
  "actions": [
    { "id": "apply",  "label": "Použít",       "kind": "apply_extracted",  "target": { "extractedNdx": 456 }, "primary": true },
    { "id": "review", "label": "Zkontrolovat", "kind": "review_extracted", "target": { "extractedNdx": 456 } },
    { "id": "reject", "label": "Zamítnout",    "kind": "reject_extracted", "target": { "extractedNdx": 456 } }
  ]
}
```

### `kind` a řazení (D7)

Sestupný žebříček; uvnitř pásma `timestamp` DESC. **Řadí server**, frontend
jen renderuje.

| `kind` | Pásmo | Zdroj → mapování |
|---|---|---|
| `urgent` | 🔴 | alert `error`; zpráva `docState=70` |
| `review` | 🟡 | extracted 20/30; alert `warning` |
| `ready` | 🟢 | extracted 10 |
| `info` | ℹ️ | alert `info` |

### Slovník `kind` akcí (chování odvozuje frontend)

| Action `kind` | Chování | Cíl |
|---|---|---|
| `apply_extracted` | inline apply + fall-through (§ backend/FE) | `{extractedNdx}` |
| `review_extracted` | otevři `DocumentExchangePreviewModal` | `{extractedNdx}` |
| `reject_extracted` | `RejectReasonPrompt` → reject | `{extractedNdx}` |
| `reanalyze` | inline `reanalyze(messageNdx)`, refetch | `{messageNdx}` |
| `open_viewer` | navigace | `{viewerId, recordId?}` |
| `open_form` | otevři form | `{table, recordId?/preset?}` |

## API kontrakt

### `GET /_ui/dashboard` (překlopený tvar)

**Auth**: Bearer (beze změny).

**Response**:
```json
{
  "success": true,
  "data": {
    "generatedAt": "2026-06-28T08:42:11Z",
    "summary": { "aiText": null, "counts": { "urgent": 1, "review": 4, "ready": 3 } },
    "cards": [ /* seřazené dle žebříčku, strop MAX_CARDS ~30 */ ],
    "tasks": { /* stávající tasks widget block z fáze 1, beze změny */ }
  }
}
```

- `widgets[]` z fáze 1 **zaniká**; alerts+mail → `cards[]`, tasks → `tasks`.
- `summary.aiText=null` v 1a (naplní 2b). `counts` = počet karet dle kind
  (jen actionable: urgent/review/ready).
- Přetečení stropu: karty se ořežou; přidat závěrečnou info kartu
  „…a další nezpracovaná pošta" s `open_viewer` na `core.mail.incoming`
  (jen když ořezáno).

### `POST /_mail/extracted-documents/{ndx}/unapply` (nový)

**Auth**: běžný uživatelský token (jako apply/reject).

**Logika** (transakčně, `ExtractedDocumentApplier::unapply()`):
1. Extracted musí být `status=40` (applied) s `target_row_ndx > 0`,
   jinak `409 INVALID_STATE`.
2. Cílový doklad (`target_row_ndx`) musí být **stále Koncept `docState=10`**;
   jinak `409 DOC_ADVANCED` (uživatel řeší ručně).
3. Cílový doklad → **Koš `docState=90`** (ne hard-delete — vratné) přes
   `DocDocument` stavovou cestu.
4. Extracted → `status=20` (pending_review — vždy, ať se před re-apply znovu
   zkontroluje), vynulovat `target_row_ndx`, `applied_at`, `applied_by`.
5. Zpráva `docState 40→30` (reconcile sourozenců — obráceně než apply).

**Odpovědi**: `200 { ndx, status, messageNdx, trashedDocId }`,
`409 INVALID_STATE` / `409 DOC_ADVANCED`, `404 NOT_FOUND`, `500 INTERNAL_ERROR`.

**K ověření (Otevřené body):** přesný mechanismus „do koše" v `DocDocument`
(existuje `docState=90` cesta?); zda apply do Konceptu spotřebuje číslo dokladu
a co s ním při unapply.

## Změny souborů — backend

### 1. `src/Core/Feed/FeedSource.php` — **nové** (umístění dle konvence Core)

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Feed;

/** Zdroj karet domovského feedu. Bezstavový, napevno registrovaný v controlleru. */
interface FeedSource
{
    /** @return list<array<string,mixed>> karty dle kontraktu (§ Kartový kontrakt) */
    public function collectCards(FeedContext $ctx): array;
}
```

`FeedContext` (readonly VO): `$db`, `?ConfigRuntime $config`, `string $language`,
`int $maxCards`. (Analog `McpInvocationContext`.)

### 2. `modules/core/mail/src/Feed/MailSuggestionsSource.php` — **nové**

Emituje kartu **per vytěžený doklad** (D5):

- Dotaz nad `core_mail_extracted_documents` `status IN (10,20,30)`
  JOIN `core_mail_incoming_messages` (subject, sender_name, received_at)
  — vzoruj se `MailListPendingTool` SQL (korelované subquery, žádné N+1).
- Mapování stavu → `kind` a akcí:
  - **10** → `kind=ready`, `stateStyle=done`; akce: `apply` (primary),
    `review`, `reject`.
  - **20/30** → `kind=review`, `stateStyle=confirmed`/`edit`; akce:
    `review` (primary), `reject`.
- **Chybové karty**: zprávy `docState=70` → `kind=urgent`, `stateStyle=error`;
  akce `reanalyze` (primary, `{messageNdx}`) + `open_viewer`
  (`core.mail.incoming`, recordId=messageNdx).
- **Titulek**: `doc_type` → label z cfgItem `core.mail.extractedDocTypes`.
  **Podtitulek**: partner + částka parsovat z `extracted_json` (kanonický
  doklad — headline pole), + `jistota {confidence}` + zdroj e-mail subject.
  Feed stropovaný → N `json_decode` únosné (denormalizace = otevřený bod).
- `timestamp` = `received_at`.

### 3. `modules/core/mail/src/Feed/AlertsSource.php` — **nové**
*(nebo `modules/core/alerts/src/Feed/` — umísti do modulu, kam patří data)*

- Dotaz nad `core_alerts_alerts` `alert_state = 10` (Active; Snoozed NE).
- `severity` → `kind`: error→urgent, warning→review, info→info.
- `stateStyle` dle severity (reuse konvence z alerts vieweru).
- `actions[]` **propíšou beze změny** (už `open_form`/`open_viewer`, už
  lokalizované). `title`=titulek alertu, `subtitle`=jméno checku.
- `timestamp` = `last_seen_at` (nebo `first_seen_at`).
- `id` = `"alert:{id}"`.

### 4. `src/Api/Controller/DashboardController.php` — **přepis `dashboard()`**

```php
public function dashboard(
    ViewerRegistry $registry,
    DataSourceConnection $db,
    ?ConfigRuntime $config = null,
    ?string $language = null,
): Response {
    $lang = $language ?? 'en';
    $ctx  = new FeedContext($db, $config, $lang, self::MAX_CARDS);

    // D10 — napevno registrované zdroje (analog dispatchMcp)
    $sources = [
        new MailSuggestionsSource(),
        new AlertsSource(),
    ];

    $cards = [];
    foreach ($sources as $src) {
        foreach ($src->collectCards($ctx) as $card) {
            $cards[] = $card;
        }
    }
    $cards = $this->sortAndCap($cards, self::MAX_CARDS);   // §4.2 žebříček + strop

    $tasks = $this->buildTasksWidget($registry, $db, $config, $lang); // reuse fáze 1

    return Response::success([
        'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'summary'     => ['aiText' => null, 'counts' => $this->countByKind($cards)],
        'cards'       => $cards,
        'tasks'       => $tasks,
    ]);
}
```

- `MAX_CARDS` konstanta (~30). `sortAndCap`: seřaď dle `KIND_ORDER`
  `['urgent'=>0,'review'=>1,'ready'=>2,'info'=>3]`, sekundárně `timestamp` DESC;
  ořízni na MAX; při ořezu přidej info kartu „a další…" (viz API).
- Zachovej `renderRowToWidgetItem`/`flattenTextField`/`countActiveByDocState`/
  `buildTasksWidget` (tasks blok používá stávající logiku a shape).
- `buildAlertsWidget`/`buildMailWidget` **smaž** (nahrazeno zdroji).

### 5. `ExtractedDocumentApplier::unapply()` + endpoint

- `unapply(int $extractedNdx, ?int $userId): ExtractedApplyOutcome` — logika
  z API kontraktu výše. Reuse `writeStatusTransition` **rozšíř** o povolený
  přechod 40→20 (dnes povoluje jen z pending stavů — přidej cílený guard pro
  unapply, nebo samostatná privátní metoda).
- `AnalysisController::unapplyExtracted()` (tenká slupka: parse ndx/auth →
  applier → Response). Registrace routy `POST /_mail/extracted-documents/{ndx}/unapply`
  vedle apply/reject (sleduj `Router.php`).

## Změny souborů — frontend

### 6. `frontend/src/api/dashboard.js` — nový tvar
`fetchDashboard()` vrací `{ generatedAt, summary, cards, tasks }` (jinak beze změny).

### 7. `frontend/src/api/exchange.js` — doplnit
```js
export async function unapplyExtractedDocument(extractedNdx) {
  return await post(`/_mail/extracted-documents/${extractedNdx}/unapply`, {});
}
```

### 8. `frontend/src/components/dashboard/Dashboard.svelte` — **přepis**
- Nahoře `AiSummaryCard` (counts by kind, aiText=null → statický text).
- `Feed cards={data.cards}` s callbacky akcí + drží preview modal, reject prompt,
  toast s undo (pattern jako fáze-1 form modal — podmíněný refetch po akci).
- Pod feedem tasks widget: `WidgetCard widget={data.tasks}` (reuse).
- Mount `DocumentExchangePreviewModal` (jednou, na úrovni Dashboardu) —
  `onApply={handleApplyFromModal}`, `onReject={openRejectPrompt}`.

### 9. `frontend/src/components/dashboard/Feed.svelte` — **nové**
- Renderuje `#each cards (card.id)` → `FeedCard`, prázdný stav
  („Vše zpracováno ✓"). Emituje akce nahoru přes `onCardAction(card, action)`.

### 10. `frontend/src/components/dashboard/FeedCard.svelte` — **nové**
- kind proužek (reuse `docState_*` / kind→stateStyle), ikona, titulek,
  podtitulek, řada tlačítek z `card.actions` (primary zvýrazněné).
- Tlačítko volá `onAction(action)` — logiku drží Dashboard.

**Chování akcí (Dashboard handlery):**
- `apply_extracted` → `applyExtractedDocument(ndx)` (bez userActions → `safe`):
  - úspěch → toast „Vytvořen koncept #{id} [Otevřít] [Vrátit]",
    optimisticky odstranit kartu, refetch. „Vrátit" → `unapplyExtractedDocument(ndx)`
    → refetch.
  - **fall-through**: když response error == „reference nevyřešeny"
    (kód dle backendu, viz Před implementací) → otevřít
    `DocumentExchangePreviewModal` na `ndx` (uživatel dořeší a potvrdí odtud).
- `review_extracted` → otevřít modal na `ndx`.
- `reject_extracted` → `RejectReasonPrompt` → `rejectExtractedDocument(ndx, reason)`
  → refetch.
- `reanalyze` → `reanalyzeMessage(messageNdx)` (přidej wrapper do `api/mail`
  nebo `exchange.js`; endpoint `/_mail/messages/{ndx}/reanalyze`) → refetch.
- `open_viewer`/`open_form` → `navigationStore.navigateToViewer(...)` /
  otevřít `FormDialog`.

### 11. `frontend/src/components/dashboard/RejectReasonPrompt.svelte` — **nové**
- Vytáhnout reject-reason dialog z `ViewerDetail.svelte` (sdílený `Modal`,
  textarea, povinný neprázdný důvod, `onConfirm(reason)`/`onClose`).
- **Refaktor `ViewerDetail.svelte`** ať používá tuto komponentu (odstranit
  duplicitu). Pokud refaktor přidává riziko, minimum: nová komponenta pro feed,
  ViewerDetail ponech (zdokumentuj v rozhodnutích).

### 12. Toast s undo
- Pokud existuje toast infrastruktura, reuse; jinak minimální lokální toast
  v `Dashboard.svelte` (fixed dole, tlačítka „Otevřít"/„Vrátit", auto-dismiss
  ~8 s). Sleduj, jestli app už toast má (grep `toast`).

## Empty stavy

| Stav | Text |
|---|---|
| `cards.length === 0` | „Vše zpracováno ✓ — dnes nic nečeká." |
| tasks widget prázdný | (beze změny fáze 1) |

## i18n klíče

Do `cs.js`/`en.js` (parita přes `npm run check:i18n`). Přibližně:

```
dashboard.feed.empty                — „Vše zpracováno ✓ — dnes nic nečeká."
dashboard.feed.andMore              — „…a další nezpracovaná pošta"
dashboard.card.action.apply         — „Použít"
dashboard.card.action.review        — „Zkontrolovat"
dashboard.card.action.reject        — „Zamítnout"
dashboard.card.action.reanalyze     — „Znovu analyzovat"
dashboard.card.action.openMail      — „Otevřít e-mail"
dashboard.card.confidence           — „jistota {pct} %"
dashboard.toast.applied             — „Vytvořen koncept #{id}"
dashboard.toast.open                — „Otevřít"
dashboard.toast.undo                — „Vrátit"
dashboard.toast.reverted            — „Vráceno"
dashboard.reject.label / .confirm / .required
dashboard.aiSummary.counts.*        — kind county (plurály)
```

Lokalizované labely typů dokladů čti z cfgItem `core.mail.extractedDocTypes`
(server), ne z FE slovníku.

## Testy

### Backend — `tests/Unit`
- `MailSuggestionsSourceTest`: stavy 10/20/30 → správný kind + akce; zpráva
  docState 70 → urgent + reanalyze; prázdný vstup → [].
- `AlertsSourceTest`: severity→kind mapping; actions passthrough; jen state=10.
- `DashboardControllerTest`: `sortAndCap` řadí dle žebříčku + strop + „andMore"
  karta; `countByKind`; tasks blok zachován; oba zdroje zapojené.
- `ExtractedDocumentApplierTest` (unapply): status 40 + Koncept 10 → trash 90 +
  extracted 20 + zpráva 30; doklad posunutý dál → 409 DOC_ADVANCED; ne-applied
  → 409 INVALID_STATE; idempotence.

### Backend — integrace (pokud běží test DB)
- Smoke: `GET /_ui/dashboard` proti seedu → cards[] správně; apply → unapply
  round-trip vrátí zprávu do 30 a Koncept do koše.

### Frontend — manuální smoke
1. Login → feed jako výchozí pohled; karty seřazené urgent→ready.
2. Karta stav 10: „Použít" → toast s „Vrátit"; karta zmizí; „Vrátit" ji vrátí.
3. Karta stav 10 s nevyřešeným dodavatelem: „Použít" → **otevře se modal**
   (fall-through), dořešení reference → Použít z modalu založí Koncept.
4. Karta stav 20/30: „Zkontrolovat" → modal (PDF + náhled + resolve).
5. „Zamítnout" → prompt na důvod → karta zmizí.
6. Urgent karta (chyba AI): „Znovu analyzovat" → zpráva zpět do fronty.
7. Alert karta: „Otevřít" naviguje do vieweru/formu.
8. Tasks widget pod feedem funguje jako dřív.
9. Prázdný feed → „Vše zpracováno ✓".
10. Refresh; přepnutí cs/en (plurály county).

## Dokumentace

- **`docs/dashboard.md`** — přepsat: nahradit widget MVP kapitolu obsahem z
  `docs/dashboard-feed.md` (feed, kartový kontrakt, zdroje, akce, undo, API).
  Ponechat sekci o tasks widgetu. Na konec „Fáze 2b — AI shrnutí (plánováno)".
- **`docs/dashboard-feed.md`** — po sloučení do `dashboard.md` **smazat**
  (jediný zdroj pravdy je pak `dashboard.md`).
- **`docs/architecture.md`** — `DashboardController` řádek update (feed +
  zdroje); přidat `unapply` k mail endpointům.
- **`docs/mail/api-contract.md`** — nová sekce 9.11 `unapply`.
- **`CLAUDE.md`** — update sekce Dashboard (feed, kind, akce, undo).
- **`docs/README.md`** — **neupravovat** (David).

## Doporučené pořadí

1. **Config prereq**: žádné `.jsonc` změny nutné (stavy existují) — přeskoč.
   Kdyby přibyl kind styling v cfg, `ds-upgrade` první.
2. **Backend zdroje + kontrakt** (kroky 1–4): `FeedSource`, dva zdroje,
   `DashboardController` přepis. Verifikace `curl /_ui/dashboard`.
3. **Backend unapply** (krok 5) + testy. `vendor/bin/phpunit 2>&1`.
4. **FE api + Dashboard/Feed/FeedCard** (kroky 6–10). `npm run build 2>&1`.
5. **RejectReasonPrompt vytažení + fall-through + toast/undo** (kroky 11–12).
6. **i18n** + `npm run check:i18n`.
7. **Manuální smoke** (10 scénářů).
8. **Dokumentace**.

## Konvence

- **API na drátě camelCase** (`generatedAt`, `messageNdx`, `openAllAction`) —
  konzistence s `_ui/*`.
- PHP 8.5 `strict_types`, `readonly`, `final`; zdroje bezstavové.
- Svelte 5 runes; CSS jen přes `var(--shpd-...)`.
- **Před `patch_file` na Svelte přečíst celý soubor** (whitespace). Velké
  přepisy (Dashboard.svelte) raději `write_file`.
- **Účetní/stavová invarianta**: unapply nikdy tvrdě nemaže doklad — jen do
  koše (vratné). Zpráva 40→30 přes reconcile, ne ručním UPDATE mimo Document flow.
- Build verifikace po každém logickém kroku.

## Rozhodnutí ✓

Přebírá D1–D10 z `docs/dashboard-feed.md`. Task-level upřesnění:

- ✓ **Endpoint zůstává `GET /_ui/dashboard`**, jen nový tvar (ne nový `/_ui/feed`).
- ✓ **Řadí a stropuje server** (`sortAndCap`), frontend jen renderuje.
- ✓ **`MAX_CARDS ~30`** + „andMore" info karta při ořezu.
- ✓ **Undo = do koše (`docState=90`), ne hard-delete**; extracted vždy zpět
  na `status=20` (pending_review), ne na původní.
- ✓ **Guard undo**: jen když cílový Koncept je stále `docState=10`; jinak 409.
- ✓ **Jednoklik posílá `safe`** (`applyExtractedDocument(ndx)` bez userActions);
  nevyřešeno → fall-through do modalu (ne chyba).
- ✓ **Alert karty jen navigují** (passthrough `actions[]`); snooze/dismiss
  zůstává ve viewer detailu.
- ✓ **Reject-reason dialog vytažen do sdílené komponenty**; ViewerDetail
  refaktorovat na ni (fallback: ponechat, pokud riziko).
- ✓ **Zdroje napevno v controlleru** (D10); `FeedSource` je jen kontrakt, ne registr.
- ✓ **`docs/dashboard-feed.md` se po sloučení smaže** (ne archivuje) — jediný
  zdroj pravdy je `dashboard.md`.

## Mimo rozsah

Viz Scope/Mimo rozsah výše. Klíčové: AI shrnutí (2b), module-driven zdroje,
tasks jako kind zdroj, multi-doc seskupení, příkazový řádek/chat, snooze mailu,
auto-refresh, „pošta čeká dlouho" alert.

## Otevřené body

- **Fall-through error signál** — potvrdit přesný kód/tvar z apply (`safe`),
  na který FE otevře modal. (Před implementací přečti.)
- **Undo mechanismus** — delete-vs-trash v `DocDocument` (`docState=90` cesta);
  spotřeba čísla dokladu při apply do Konceptu a jak s ním při unapply.
- **Denormalizace headline** (partner/částka do sloupců) — optimalizace, až bude
  potřeba; zatím parse `extracted_json`.
- **Multi-doc karty** — vizuální seskupení „ze stejného e-mailu" (odloženo).
- **Umístění `AlertsSource`** — `core.mail` vs `core.alerts` modul (rozhodne
  implementer dle toho, kam data patří — preferuj `core.alerts`).

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` prochází (nové testy zdrojů + unapply)
- [ ] `cd frontend && npm run build 2>&1` bez chyb
- [ ] `npm run check:i18n` parita cs/en
- [ ] `GET /_ui/dashboard` vrací `{summary, cards[], tasks}`; cards seřazené
- [ ] Login → feed výchozí pohled
- [ ] Stav 10: jednoklik „Použít" → toast + undo funguje (vytvoří/vrátí Koncept)
- [ ] Nevyřešená reference: „Použít" → fall-through do modalu, dořešení funguje
- [ ] Stav 20/30: „Zkontrolovat" → modal (PDF + náhled + resolve + Použít)
- [ ] „Zamítnout" → prompt na důvod → reject
- [ ] Urgent (chyba AI): „Znovu analyzovat" → reanalýza
- [ ] Alert karta naviguje (open_viewer/open_form)
- [ ] Tasks widget pod feedem funguje
- [ ] Prázdný feed → „Vše zpracováno ✓"
- [ ] `POST /_mail/extracted-documents/{ndx}/unapply` — round-trip apply→unapply
- [ ] `docs/dashboard.md` přepsané na feed; `architecture.md`,
  `mail/api-contract.md`, `CLAUDE.md` aktualizované
- [ ] `docs/dashboard-feed.md` sloučen do `dashboard.md` a smazán

## Commit strategie

1. `feat(dashboard): feed sources and card contract backend`
   — `FeedSource`/`FeedContext`, `MailSuggestionsSource`, `AlertsSource`,
   `DashboardController` přepis, testy zdrojů.
2. `feat(mail): unapply endpoint for extracted documents`
   — `ExtractedDocumentApplier::unapply`, `AnalysisController` endpoint, routa,
   testy.
3. `feat(dashboard): feed frontend, card actions, undo`
   — `Feed`/`FeedCard`/`RejectReasonPrompt`, `Dashboard.svelte` přepis,
   `api` update, toast/undo, fall-through, i18n.
4. `docs(dashboard): merge feed spec, update architecture/mail/CLAUDE`
   — `dashboard.md` přepis, `architecture.md`, `mail/api-contract.md`,
   `CLAUDE.md`, smazání `dashboard-feed.md`.
