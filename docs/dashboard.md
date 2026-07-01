# Dashboard

Home obrazovka aplikace — výchozí pohled po přihlášení. Od fáze 2 je to
**prioritizovaný feed akčních karet**: uživatel vidí, co má řešit, a provede
akci **přímo z feedu** (apply / review / reject / reanalyze) bez procházení
viewerů. Úkoly zůstávají jako sekundární widget pod feedem.

Fáze 2 pokrývá jeden tok — **došlá pošta → doklad** — plus deterministické
alerty jako druhý zdroj. AI shrnutí je zatím statické (počty dle kind); generované
shrnutí je plánované jako fáze 2b (§11).

## 1. Přehled

```
┌──────────────────────────────────────────────────────────────┐
│  Dashboard                                       [Obnovit ↻]  │
├──────────────────────────────────────────────────────────────┤
│  🤖 Dnešní shrnutí                                            │
│  Aktuálně máte: 1 naléhavou věc, 4 ke kontrole, 3 připravené. │
├──────────────────────────────────────────────────────────────┤
│  🔴 Chyba analýzy e-mailu                                     │
│     e-mail „Nečitelná faktura"     [Znovu analyzovat][Otevřít]│
│  🟢 Přijatá faktura — ČEZ a.s.                                │
│     4 200 Kč · jistota 94 %   [Použít][Zkontrolovat][Zamítnout]│
│  🟡 Přijatá faktura — Dodavatel                               │
│     jistota 62 %                     [Zkontrolovat][Zamítnout] │
├──────────────────────────────────────────────────────────────┤
│  ✓ Aktivní úkoly (5)                          [Otevřít všechny]│
│    Item 1 · Item 2 · …                                        │
└──────────────────────────────────────────────────────────────┘
```

## 2. Princip

Fáze 1 (widget MVP) říkala *„přehled, ne přístupový bod"*. Fáze 2 ten princip
**vědomě obrací** pro ohraničenou, bezpečnou sadu akcí:

- Feed je **místo, kde se akce odehraje**, ne jen odkaz do vieweru.
- Bezpečné je to proto, že `apply` zakládá jen **Koncept** (`docState=10`),
  nic nefinalizuje, vše je auditovatelné (`source.aiExtraction`, `applied_by`)
  a `apply` je **vratné** (undo, §6.4).
- Deterministika zůstává deterministická: **pravidla (alerts) pro stav, AI
  (analyzer) pro jazyk a nejednoznačnost.** Oba zdroje padají do téhož feedu.
- **Řadí a stropuje server** (`sortAndCap`), frontend jen renderuje.

## 3. Architektura

```
            GET /_ui/dashboard
                   │
                   ▼
        ┌──────────────────────┐
        │  DashboardController │   napevno registrované FeedSource zdroje (D10)
        │  ::dashboard()       │
        └──────────┬───────────┘
        ┌──────────┴───────────┐
        ▼                      ▼
 MailSuggestionsSource     AlertsSource        buildTasksWidget()
 collectCards(ctx)         collectCards(ctx)   (re-use fáze 1)
        │                      │                     │
        └──────────┬───────────┘                     │
                   ▼ sortAndCap (žebříček + čas)      │
              cards[]  ────────────────────────────► tasks
                   ▼
              Feed.svelte ── FeedCard × N
                              │
                              ├─ apply_extracted  → apply(ndx) → toast+undo
                              │     └─ nevyřešeno → review modal (fall-through)
                              ├─ review_extracted → DocumentExchangePreviewModal
                              ├─ reject_extracted → RejectReasonPrompt → reject
                              ├─ reanalyze        → reanalyze(msgNdx)
                              └─ open_viewer/open_form → navigace
```

- **Zdroj karet** = lehké rozhraní `FeedSource::collectCards(FeedContext): array`
  (`src/Core/Feed/`). Dva konzumenti registrované napevno v controlleru (D10);
  žádný plugin-registr. `MailSuggestionsSource` (`modules/core/mail/src/Feed/`),
  `AlertsSource` (`modules/core/alerts/src/Feed/`).
- **Řazení + strop** dělá `DashboardController::sortAndCap()`: seřaď dle
  `KIND_ORDER` (urgent/review/ready/info), uvnitř pásma `timestamp` DESC, ořízni
  na `MAX_CARDS` (~30); při ořezu přidej info kartu „a další…".
- **Tasks** zůstávají jako sekundární widget pod feedem — re-use fáze 1
  (`buildTasksWidget` + `fetchWidgetItems` + `renderRowToWidgetItem`).

## 4. Kartový kontrakt

```json
{
  "id": "mail_extracted:456",
  "source": "mail",
  "kind": "ready",
  "icon": "check",
  "stateStyle": "done",
  "title": "Přijatá faktura — ČEZ a.s.",
  "subtitle": "4 200,00 CZK · jistota 94 % · e-mail „Faktura 2026000123\"",
  "timestamp": "2026-06-28T10:00:00+00:00",
  "context": { "messageNdx": 123, "extractedNdx": 456, "confidence": 0.94 },
  "actions": [
    { "id": "apply",  "kind": "apply_extracted",  "target": { "extractedNdx": 456 }, "primary": true },
    { "id": "review", "kind": "review_extracted", "target": { "extractedNdx": 456 } },
    { "id": "reject", "kind": "reject_extracted", "target": { "extractedNdx": 456 } }
  ]
}
```

- `id` — stabilní `"{source}:{entityId}"` (dedup / animace mizení po akci).
- `stateStyle` — reuse globálních `docState_*` CSS tříd (proužek karty).
- `timestamp` — sekundární řazení uvnitř pásma (ATOM).
- `context` — volitelná zdrojově-specifická data.
- `actions[].label` — u mail akcí **chybí**, frontend ho lokalizuje podle
  `action.id` (i18n `dashboard.card.action.*`); alert akce nesou vlastní
  pre-lokalizovaný `label` (passthrough).

### 4.1 `kind` a řazení

Prioritní žebříček (sestupně), uvnitř pásma `timestamp` DESC.

| `kind` | Pásmo | Zdroj → mapování |
|---|---|---|
| `urgent` | 🔴 | alert `error`; zpráva `docState=70` (chyba AI) |
| `review` | 🟡 | extracted 20/30; alert `warning` |
| `ready`  | 🟢 | extracted 10 (jednoklik) |
| `info`   | ℹ️ | alert `info`; „a další…" karta |

### 4.2 Slovník `kind` akcí (chování odvozuje frontend)

| Action `kind` | Chování | Cíl |
|---|---|---|
| `apply_extracted` | inline apply (safe) + fall-through do modalu | `{extractedNdx}` |
| `review_extracted` | otevři `DocumentExchangePreviewModal` | `{extractedNdx}` |
| `reject_extracted` | `RejectReasonPrompt` → reject | `{extractedNdx}` |
| `reanalyze` | inline `reanalyze(messageNdx)`, refetch | `{messageNdx}` |
| `open_viewer` | navigace | `{viewerId, recordId?}` |
| `open_form` | otevři form | `{table, recordId?/id?}` |

## 5. Zdroje karet

### 5.1 MailSuggestionsSource

Karta **per vytěžený doklad** (`core_mail_extracted_documents.status ∈ {10,20,30}`
JOIN `core_mail_incoming_messages`):

- **10** → `kind=ready`, `stateStyle=done`; akce `apply` (primary), `review`, `reject`.
- **20/30** → `kind=review`, `stateStyle=confirmed`/`edit`; akce `review` (primary),
  `reject`. (Jednoklik se u nízké jistoty záměrně nenabízí.)

**Chybové karty** — zprávy `docState=70` (AI selhala) → `kind=urgent`,
`stateStyle=error`; akce `reanalyze` (primary, `{messageNdx}`) + `open_viewer`
(`core.mail.incoming`).

Titulek: `doc_type` → label z cfgItem `core.mail.extractedDocTypes`. Podtitulek:
částka + partner z `extracted_json` (kanonický doklad — protistrana dle
`selfParty`), + jistota + zdrojový e-mail. Feed je stropovaný, takže N
`json_decode` je únosné; denormalizace headline do sloupců je pozdější optimalizace.

### 5.2 AlertsSource

Aktivní alerty (`core_alerts_alerts.alert_state=10`; Snoozed NE). `severity` →
`kind`: error→urgent, warning→review, info→info. `actions[]` alertu se **propíšou
beze změny** (už `open_form`/`open_viewer`, už lokalizované). `title`=titulek
alertu, `subtitle`=zpráva (fallback `check_id`), `timestamp`=`last_seen_at`,
`id`=`"alert:{id}"`. Alert karty jen navigují; snooze/dismiss zůstává ve viewer
detailu.

## 6. Akce a jejich sémantika

### 6.1 `apply_extracted` — optimistický jednoklik

1. Karta ve stavu 10 ukáže primární **„Použít"**.
2. Klik → `applyExtractedDocument(ndx)` (bez `userActions` → `safe` mód,
   `targetDocState=10`).
3. **Úspěch** → toast *„Vytvořen koncept #123 [Otevřít] [Vrátit]"*, karta zmizí
   (optimisticky), refetch.
4. **Reference nevyřešeny** — backend vrátí `unresolved_required` (HTTP 422) →
   frontend **automaticky otevře `DocumentExchangePreviewModal`** místo chyby.
   Uživatel dořeší reference a potvrdí odtud (`handleApplyFromModal` posílá
   nasbírané `userActions` → strict mód).

### 6.2 `review_extracted` — review modal

Otevře `DocumentExchangePreviewModal` na `extractedNdx` (PDF vlevo, kanonický
náhled vpravo, resolve panel, `Použít` gated `canApply`). `onReject(ndx)` →
`RejectReasonPrompt`.

### 6.3 `reject_extracted` — prompt na důvod

Sdílená komponenta `RejectReasonPrompt` (povinný neprázdný důvod) →
`rejectExtractedDocument(ndx, reason)`. Používá ji feed i modal i ViewerDetail.

### 6.4 Undo — `unapply`

`POST /_mail/extracted-documents/{ndx}/unapply` (viz §7). Toast „Vrátit" po apply.
Logika (`ExtractedDocumentApplier::unapply`):

1. Extracted musí být `status=40` (applied) s `target_row_ndx > 0`, jinak
   `409 INVALID_STATE`.
2. Cílový doklad musí být **stále nedotčený Koncept** (`docState=10`), jinak
   `409 DOC_ADVANCED` (uživatel řeší ručně).
3. Cílový doklad → **Koš** (`docState=90`, ne hard-delete — vratné) přes Document
   flow. Koncept nespotřeboval číslo dokladu (přiděluje se až 10→20), takže není
   co vracet.
4. Extracted → `status=20` (pending_review — vždy, ať se před re-apply znovu
   zkontroluje), vynulování `target_row_ndx`/`applied_*`.
5. Zpráva `docState 40→30` přes reverzní reconcile
   (`ExtractedDocumentDocument::reconcileMessageAfterUnapply`, opak apply).

## 7. API kontrakt

### `GET /_ui/dashboard`

**Auth**: Bearer token.

```json
{
  "success": true,
  "data": {
    "generatedAt": "2026-06-28T08:42:11+00:00",
    "summary": { "aiText": null, "counts": { "urgent": 1, "review": 4, "ready": 3 } },
    "cards": [ /* seřazené dle žebříčku, strop MAX_CARDS ~30 */ ],
    "tasks": { /* tasks widget block z fáze 1 (viz §9) */ }
  }
}
```

- `summary.aiText` je `null` (naplní fáze 2b). `counts` = počet karet dle kind,
  jen actionable pásma (urgent/review/ready).
- Přetečení stropu → karty se ořežou a přidá se závěrečná info karta
  „…a další nezpracovaná pošta" s `open_viewer` na `core.mail.incoming`.

### `POST /_mail/extracted-documents/{ndx}/unapply`

**Auth**: běžný uživatelský token. Transakčně vratí apply — viz §6.4.

**Odpovědi**: `200 { ndx, status, messageNdx, trashedDocId }`,
`409 INVALID_STATE` / `409 DOC_ADVANCED`, `404 NOT_FOUND`, `500 INTERNAL_ERROR`.

## 8. Frontend komponenty

```
frontend/src/components/dashboard/
├── Dashboard.svelte      — fetch, layout (feed + tasks widget), review modal,
│                           reject prompt, toast s undo, fall-through
├── Feed.svelte           — seznam karet (řazení ze serveru), prázdný stav
├── FeedCard.svelte       — jedna karta (kind proužek, ikona, akce)
├── RejectReasonPrompt.svelte — sdílený prompt na důvod (feed i ViewerDetail)
├── AiSummaryCard.svelte  — county dle kind (aiText ready pro 2b)
└── WidgetCard / WidgetRow — re-use pro tasks widget
```

API: `frontend/src/api/dashboard.js` (`fetchDashboard()`), `api/exchange.js`
(`applyExtractedDocument`, `unapplyExtractedDocument`, `rejectExtractedDocument`,
`reanalyzeMessage`, `previewExtractedDocument`).

- **Doc-state proužek**: globální `.docState_*` třídy (`styles/base.css`), pruh
  přes `--shpd-row-bar`. Kind→stateStyle mapuje server.
- **Toast**: app nemá toast infrastrukturu → minimální lokální toast v
  `Dashboard.svelte` (fixed dole, „Otevřít"/„Vrátit", auto-dismiss ~8 s).
- **Ikony**: server posílá sémantický `icon` (check/question/warning/info/…),
  frontend překládá přes `resolveIcon()` (`icons.js`, fallback `iconTable`).

## 9. Tasks widget (pod feedem)

Beze změny proti fázi 1 — `buildTasksWidget` vrací blok
`{id, type, title, icon, count, items[], openAllAction}`; `items[]` z
`renderRow()` přes `renderRowToWidgetItem` (title z `t1`, subtitle z `t2`,
`action` = `{kind:'open_form', table:'tasks_core_tasks', recordId}`). `count` je
samostatný `SELECT COUNT(*)` (může být > `items.length`, strop 7).

**Form modal nad dashboardem.** `Dashboard.svelte` drží
`formModal = {open, table, recordId, wasSaved}` a mountuje `<FormDialog>`.
Refetch po close je podmíněný (`wasSaved` se nastaví jen v `onSaved`):

| Scénář | Refetch? |
|---|---|
| Klik na úkol → close bez editace | Ne |
| Edit → **Uložit** → close | Ano |
| **Hotovo** v FormStateBar (closeForm: 1) | Ano |
| Edit + Esc/× → confirm OK | Ne |

Tentýž `formModal` obsluhuje i alert `open_form` akce a toast „Otevřít"
(otevře vytvořený Koncept nad `docs_core_heads`).

## 10. Empty stavy a refresh

| Stav | Text | i18n |
|---|---|---|
| `cards.length === 0` | „Vše zpracováno ✓ — dnes nic nečeká." | `dashboard.feed.empty` |
| tasks widget prázdný | (beze změny fáze 1) | `dashboard.widget.tasks.empty` |

**Refresh** — fetch při mountu + manuální tlačítko. Žádný polling / SSE.

## 11. Fáze 2b — AI shrnutí (plánováno)

Nahradit statické počty v `AiSummaryCard` **generovaným shrnutím dne** („Dnes
přišlo 8 e-mailů, z toho 5 faktur — 3 připravené, 2 ke kontrole; nejnaléhavější:
…"). Vstup: county + headline karet (žádný nový sběr dat). Model: reuse
`LlmClient` z chatu. **Líné + cache** per uživatel, neblokující (feed se zobrazí
hned, shrnutí dotéká). Interface je připravený — backend naplní `summary.aiText`,
`AiSummaryCard` ho zobrazí místo statických počtů.

## 12. Budoucí rozšíření

- **Module-driven feed zdroje** přes `module.jsonc` (zatím napevno, D10).
- **Tasks jako plnohodnotný zdroj karet** — až přibude assignee.
- **Vizuální seskupení multi-doc karet** (N dokladů z jednoho e-mailu).
- **Denormalizace headline** (partner/částka do sloupců místo parse
  `extracted_json`).
- **Auto-refresh** — polling / SSE, pokud bude potřeba (samostatný task).

---

[← README.md](../README.md) · [Frontend](frontend.md) · [Alerts](alerts.md) · [Exchange formát](exchange-format.md)
