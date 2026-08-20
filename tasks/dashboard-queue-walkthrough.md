# Task: Dashboard — sériový průchod frontou přijatých faktur („Projít frontu")

**Stav:** naplánováno

## Cíl

Issue #32, celek 1. Dashboard dnes vyžaduje zpracování pošty karta-po-kartě:
otevřít review modal, rozhodnout, modal se zavře, najít další kartu, otevřít…
Cíl je plynulý sériový průchod: tlačítko **„Projít frontu (N)"** otevře
`DocumentExchangePreviewModal` na první zprávě fronty a po každém verdiktu
(Vystavit a uzavřít / Vystavit koncept / Zamítnout / Přeskočit) se místo
zavření načte další zpráva. Na konci souhrnný toast a jeden refresh feedu.

**Scope této iterace: pouze přijaté faktury (target `docs`).** Spisovna
(registry) vědomě mimo — viz Mimo rozsah.

Součástí je předkrok pro karty „Nová kategorie" (Issue #35, komentář
v Issue #32): před průchodem zprávami se nabídne založení chybějících
otagovaných položek, aby návrhy „jen štítek" prošly v průchodu už jako
plná trojice.

## Návaznost

- `frontend/src/components/dashboard/Dashboard.svelte` (~670 ř.):
  `previewNdx` (~ř. 64), `handleCardAction` (~ř. 140), `applyFlow`
  (~ř. 195), `finishApply` (~ř. 222, po commitu 1411c5a s param
  `finalized`), `handleApplyFromModal` (~ř. 348), `handleRejectFromModal`,
  `submitRejectFlow`, `materializeTagFlow`, `dropCardByMessage`.
- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
  (~290 ř.): props `{open, messageNdx, onClose, onApply, onReject}`,
  `$effect` reload na změnu `messageNdx`, `handleApplyClick(applyOptions)`,
  footer Zavřít / Zamítnout / Vystavit koncept / Vystavit a uzavřít
  (registry: jediné Zařadit). Po commitu 1411c5a `onApply(ndx, userActions,
  target, applyOptions)`, `{targetDocState: 40}` = Vystavit a uzavřít.
- `frontend/src/components/ui/Modal.svelte`: snippet `headerExtra`
  (~ř. 44–47, render ~ř. 152) — místo pro počítadlo „3 / 8".
- `frontend/src/components/dashboard/RejectReasonPrompt.svelte`:
  Modal-based (width 480px) — otevření nad preview modalem jde přes
  modal stack (nested shrink 30px/strana/úroveň).
- `frontend/src/components/dashboard/FeedFilter.svelte`: chip bar filtru
  — vedle něj (v řádku) přijde tlačítko Projít frontu.
- Kartový kontrakt (server): `MailSuggestionsSource::buildSuggestionCard`
  (`modules/core/mail/src/Feed/MailSuggestionsSource.php` ~ř. 174):
  `id: 'mail_suggestion:{ndx}'`, `kind: 'ready'|'review'`,
  `category: 'invoices'|'registry'`, `context: {messageNdx, target}`,
  `timestamp` (ATOM z `received_at`, může být `null`).
  `ContentTagSuggestionsSource` (`modules/core/exchange/src/Dashboard/…`
  ~ř. 175): `id: 'content_tag:{tag}'`, `kind: 'info'`,
  `category: 'invoices'`, `context: {tag, waiting}`, akce
  `materialize_content_tag` (u goods/materiálu dvě s volbou účtu),
  **bez messageNdx**.
- `frontend/src/api/contentTags.js`: `materializeContentTag(tag, account)`.
- `docs/dashboard.md` — kartový kontrakt, pásma, `sortAndCap`.

## Potvrzená designová rozhodnutí (Anna, 2026-08-20)

- **D1 — Složení a pořadí fronty:** snapshot při startu průchodu; jen karty
  `mail_suggestion` s `category === 'invoices'` a `context.target === 'docs'`
  (pásma ready + review). Vyloučeno: registry, urgent karty (ai_failed /
  není faktura — nemají co previewovat), info karty. Řazení **chronologicky
  od nejstarší** dle `timestamp` ASC (karty s `timestamp === null` na konec,
  mezi sebou v pořadí feedu). Karty přibylé během průchodu se do běžícího
  snapshotu nepřidávají. Pozn.: pořadí je snadno změnitelné (jeden sort),
  ale s „Vystavit a uzavřít" (číslo hned při apply) drží chronologie čísla
  faktur v pořadí doručení — nechat tak.
- **D2 — Batch mód potlačuje per-item UX:** žádný FormDialog po konceptu,
  žádný per-item toast (koncept, finalized), žádné `load()` po každé
  položce. Místo toho počítadla; na konci průchodu jeden souhrnný toast
  „Uzavřeno X · Konceptů Y · Zamítnuto Z · Přeskočeno W" (nulové části
  vynechat) + jeden `load()`. Souhrnný toast bez akce Otevřít.
- **D3 — Vstupní bod:** tlačítko „Projít frontu (N)" v řádku s FeedFilterem;
  N = počet queueable karet (D1). Viditelné jen na záložkách **Vše**
  a **Faktury** a jen když N > 0. Na Spisovna/Ostatní skryté (registry je
  mimo scope — tlačítko by tam bylo zavádějící).
- **D4 — Přeskočit:** tlačítko v patičce modalu, **jen v batch módu** —
  posun na další zprávu bez verdiktu, karta zůstává ve feedu.
- **D5 — Počítadlo:** „{i} / {n}" přes `headerExtra` snippet Modalu,
  nad snapshotem — přeskočení posouvá pozici, celkový počet nemění.
- **D6 — Chyby apply:** beze změny — `canApply` gate-uje nerozhodnuté
  reference, backendová validace (422/500) → alert, **zůstat na téže
  zprávě**, nepokračovat. Uživatel může dořešit resolve, zvolit koncept,
  přeskočit, nebo zamítnout.
- **D7 — Zamítnout v batch módu:** `RejectReasonPrompt` se otevře **nad**
  preview modalem (previewNdx se nenuluje); po potvrzení → další zpráva,
  po zrušení promptu → zůstat na aktuální.
- **D8 — Předkrok „Nová kategorie" (Issue #35):** pokud ve feedu existují
  `content_tag:*` karty, průchod začne lehkým dialogem se seznamem štítků
  a jejich materialize akcemi (vč. volby materiál/zboží) + tlačítkem
  „Pokračovat" (label „Pokračovat bez založení", dokud něco zbývá).
  Po materializaci řádek z dialogu zmizí; snapshot fronty zpráv se nemění
  — povýšení návrhů se projeví přirozeně (preview se při otevření počítá
  čerstvě). Počty v labelu tlačítka Projít frontu se po materializaci
  neaktualizují (ignorovat, průchod jde stejně po jedné).
- **D9 — Oba apply posouvají dál:** „Vystavit a uzavřít" i „Vystavit
  koncept" v batch módu → další zpráva; souhrn je počítá zvlášť
  (uzavřeno / konceptů). Rozložení a gating tlačítek beze změny
  (exchange-apply-finalize D1–D3).

## Rozsah

### V rozsahu

1. `frontend/src/components/dashboard/Dashboard.svelte` — stav fronty,
   start/advance/finish, batch větve ve `finishApply` a
   `handleRejectFromModal`/`submitRejectFlow`, tlačítko Projít frontu,
   souhrnný toast.
2. `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
   — props `queue` (`null | {index, total}`) a `onSkip`; počítadlo přes
   `headerExtra`; tlačítko Přeskočit v patičce (jen `queue !== null`).
3. Nová komponenta
   `frontend/src/components/dashboard/QueueCategoriesPrompt.svelte`
   — předkrok D8 (Modal-based, vzor RejectReasonPrompt).
4. i18n `frontend/src/i18n/cs.js` + `en.js` — nové klíče (viz krok 6).
5. `docs/dashboard.md` — sekce Sériový průchod frontou.
6. `help/` — doplnit zmínku do `help/posta/kontrola-vytezeni.md`
   (nebo kam tematicky patří dle README helpu).

### Mimo rozsah

- **Registry (Spisovna) v průchodu** — vyžaduje rozhodnout UX „Zařadit"
  v batch (žádné pásmo důvěry u registry karet dnes neřeší priority).
  Až samostatně; queue filtr je na `context.target`, rozšíření je pak malé.
- Celky 2–4 z Issue #32 (sekce podle pásem, Použít vše, seskupování
  per partner).
- Backend — beze změn, vše nad existujícími endpointy
  (`/_mail/messages/{ndx}/preview|apply|reject`, `/_exchange/content-tags/
  materialize`).
- Živá aktualizace fronty během průchodu (snapshot je vědomé rozhodnutí).
- Klávesové zkratky v modalu (Enter = apply apod.) — případně TODO.md.
- Průchod z ViewerDetail (Došlá pošta) — modal tam zůstává single-message
  (`queue = null`).

## Datový tok

```
[Projít frontu (8)]
  ▼ Dashboard.startQueue()
snapshot = queueable karty (D1) → sort timestamp ASC
content_tag karty existují? ──ano──▶ QueueCategoriesPrompt
  │                                    │ materializeContentTag(tag, account)…
  │◀──────── „Pokračovat" ─────────────┘
  ▼
queue = {list: [ndx…], index: 0, counts: {closed: 0, draft: 0, rejected: 0, skipped: 0}}
previewNdx = list[0]   → modal se otevře, $effect stáhne preview
  ▼ verdikt
Vystavit a uzavřít → apply {targetDocState: 40} → counts.closed++ → advance()
Vystavit koncept   → apply                       → counts.draft++  → advance()
Zamítnout          → RejectReasonPrompt nad modalem → counts.rejected++ → advance()
Přeskočit          → counts.skipped++ → advance()
Zavřít (×/Zavřít)  → finishQueue(aborted) — souhrn jen za zpracované
  ▼ advance(): index++; previewNdx = list[index]  (modal zůstává open, jen reload)
  ▼ index === list.length → finishQueue(): previewNdx = null, queue = null,
    souhrnný toast, load()
```

## Co je potřeba udělat

### 1. `Dashboard.svelte` — stav a životní cyklus fronty

```js
// Sériový průchod frontou (Issue #32/1). null = běžný single-message režim.
// list je snapshot messageNdx — nezávislý na data.cards (ty se během
// průchodu optimisticky mažou přes dropCardByMessage).
let queue = $state(null);
// Předkrok D8 — seznam content_tag karet pro QueueCategoriesPrompt;
// null = zavřeno. Snapshot zpráv se bere už při kliknutí (před předkrokem).
let queuePrecheck = $state(null);
```

- `queueableCards` jako `$derived`: `data.cards` filtr
  `c.id.startsWith('mail_suggestion:') && c.category === 'invoices'
  && c.context?.target === 'docs'`. (Prefix id je nejpřesnější
  rozlišení zdroje; `kind` nechat bez filtru — ready/review projdou,
  urgent/info karty prefix nemají resp. neprojdou target filtrem.)
  Pozor: `content_tag` karty mají category invoices, ale prefix je
  odfiltruje.
- `startQueue()`: snapshot `[...queueableCards]` → sort
  `(a, b) => (a.timestamp ?? '\uffff').localeCompare(b.timestamp ?? '\uffff')`
  (ATOM řetězce se řadí lexikograficky; null na konec) → `list =
  map(c => c.context.messageNdx)`. Když ve `data.cards` existují
  `content_tag:*` karty → `queuePrecheck = [ty karty]`, jinak rovnou
  `openQueueAt(0)`.
- `openQueueAt(i)`: `queue = {list, index: i, counts: …}`;
  `previewNdx = list[i]`.
- `advanceQueue()`: `index + 1 === list.length` → `finishQueue()`;
  jinak `queue.index++; previewNdx = queue.list[queue.index]`
  (previewNdx **nenulovat** mezi položkami — modal zůstává otevřený,
  `$effect` reload zajistí čerstvé preview i reset `userActions`).
- `finishQueue()`: `previewNdx = null`; souhrnný toast z `queue.counts`
  (nulové části vynechat; při všech nulách — okamžité zavření — žádný
  toast); `queue = null`; `load()`.
- Zavření modalu (`onClose`) v batch módu = `finishQueue()` (souhrn jen
  za dosud zpracované).

### 2. Batch větve existujících flow

- `finishApply(messageNdx, docId, target, finalized)`: na začátek větev
  `if (queue) { dropCardByMessage(messageNdx); finalized
  ? queue.counts.closed++ : queue.counts.draft++; advanceQueue();
  return; }` — žádný FormDialog, žádný toast, žádné `load()`.
  (Registry větev se v batch nemůže trefit — fronta je jen docs.)
- `handleRejectFromModal(messageNdx)`: v batch módu **nenulovat**
  `previewNdx`, jen `rejectNdx = messageNdx` — prompt se otevře nad
  modalem (modal stack).
- `submitRejectFlow(reason)`: po úspěchu v batch módu
  `queue.counts.rejected++; advanceQueue();` místo `load()`.
  Zrušení promptu (onClose) beze změny — zůstává aktuální zpráva.
- `handleSkip()`: `queue.counts.skipped++; advanceQueue();`.

### 3. `DocumentExchangePreviewModal.svelte` — queue props

```js
let {
  …,
  queue = null,        // {index, total} | null — batch mód
  onSkip = () => {},
} = $props();
```

- Modal: `headerExtra` snippet s `{queue.index + 1} / {queue.total}`
  (jen `queue !== null`), styl nenápadný badge (vzor headerExtra badge
  jinde, např. doc-state badge ve FormDialogu).
- Footer: před Zamítnout přidat `{#if queue}` tlačítko Přeskočit
  (`variant="secondary"`), `disabled={loading}`, `onclick={onSkip}`.
- Nic dalšího — apply/reject delegace beze změny, `$effect` na
  `messageNdx` už reload a reset `userActions` řeší.

### 4. `QueueCategoriesPrompt.svelte` — předkrok D8

Modal-based (vzor RejectReasonPrompt, width ~560px), props
`{open, cards, onContinue, onClose}`:

- Řádek per karta: title („Nová kategorie: Kancelářské potřeby"),
  subtitle (počet čekajících), tlačítka z `card.actions`
  (`materialize_content_tag` — jedno „Založit položku (501100)" nebo
  dvě „Jako materiál/zboží"; labely bere z akcí, server je posílá).
- Materialize volá `materializeContentTag(target.tag, target.account)`
  přímo (lokální busy stav per řádek); úspěch → řádek zmizí ze
  seznamu + `dropCardById` u rodiče (callback nebo řešit v Dashboardu);
  chyba → inline hláška u řádku (ne alert — jsme v dialogu).
  **Bez toastu a bez `load()`** — feed se přepočítá až na konci průchodu.
- Patička: „Pokračovat" (primary; když seznam prázdný → label „Pokračovat",
  jinak „Pokračovat bez založení") → `onContinue()` →
  Dashboard: `queuePrecheck = null; openQueueAt(0)`.
  Zavřít (×) → `queuePrecheck = null`, fronta se nespouští.

### 5. Tlačítko „Projít frontu (N)"

V `Dashboard.svelte` vedle FeedFilteru (obalit oba do flex řádku
`shpd-dashboard__feed-toolbar`, filter vlevo, tlačítko vpravo):

```svelte
{#if (feedFilter === 'all' || feedFilter === 'invoices') && queueableCards.length > 0}
  <Button variant="primary" size="sm"
    label={t('dashboard.queue.button', { n: queueableCards.length })}
    onclick={startQueue} />
{/if}
```

### 6. i18n (cs + en)

- `dashboard.queue.button`: „Projít frontu ({n})" / "Walk the queue ({n})"
- `exchange.preview.actions.skip`: „Přeskočit" / "Skip"
- `exchange.preview.queuePosition`: „{i} / {n}" (sdílené)
- `dashboard.queue.precheckTitle`: „Nové kategorie před průchodem" /
  "New categories before the walkthrough"
- `dashboard.queue.precheckContinue`: „Pokračovat" / "Continue"
- `dashboard.queue.precheckContinueWithout`: „Pokračovat bez založení" /
  "Continue without creating"
- `dashboard.toast.queueSummary`: skládat z částí (uzavřeno/konceptů/
  zamítnuto/přeskočeno) — čtyři klíče s `{n}` + join „ · " v kódu;
  plurály přes messageformat kde česky potřeba.

### 7. Dokumentace

- `docs/dashboard.md`: nová podsekce „Sériový průchod frontou" — složení
  fronty (D1), snapshot sémantika, batch potlačení per-item UX (D2),
  předkrok Nová kategorie (D8), odkaz na Issue #32.
- `help/posta/kontrola-vytezeni.md`: krátký odstavec o Projít frontu
  (uživatelský pohled: tlačítko, Přeskočit, souhrn na konci).

## E2E ověření (dev DS)

1. Feed s ≥3 fakturovými návrhy (mix ready/review) + ≥1 registry kartou
   + ≥1 content_tag kartou: tlačítko ukazuje počet **jen** fakturových
   docs karet; na záložce Spisovna tlačítko není.
2. Start → předkrok: založit jednu kategorii (řádek zmizí, žádný toast),
   Pokračovat → modal na **nejstarší** zprávě, počítadlo „1 / N".
3. Vystavit a uzavřít → žádný FormDialog ani toast, modal ukáže další
   zprávu, „2 / N". Návrh, kterému předkrok založil položku, ukazuje
   plnou trojici (povýšení bez reanalýzy).
4. Vystavit koncept → dtto (žádný FormDialog!), počítá se zvlášť.
5. Zamítnout → prompt **nad** modalem (modal vidět pod ním, shrink);
   potvrzení → další zpráva; zrušení → táž zpráva.
6. Přeskočit → další zpráva bez verdiktu; po `load()` na konci karta
   přeskočené zprávy ve feedu zůstala.
7. Poslední zpráva → modal se zavře, souhrnný toast s korektními počty
   (nulové části chybí), feed refresh.
8. Zavřít (×) uprostřed → souhrn jen za zpracované, feed refresh.
9. Zpráva s nerozhodnutou referencí: apply disabled, dořešit v resolve
   panelu → apply projde → další.
10. Single-message režim („Zkontrolovat" z karty): žádné počítadlo, žádné
    Přeskočit, po apply koncept → FormDialog jako dřív (regrese!).
11. `cd frontend && timeout 90 npm run build` bez chyb.

## Pasti

- **P1 — previewNdx nenulovat mezi položkami:** `finishApply` dnes začíná
  `previewNdx = null` — v batch větvi se k tomu nesmí dojít, jinak modal
  zavře/otevře (flicker) a `$effect` v modalu shodí data. Batch větev
  musí být **před** stávajícím tělem a končit `return`.
- **P2 — snapshot nezávislý na data.cards:** `dropCardByMessage` během
  průchodu muta `data.cards`; queue.list musí být zkopírované pole ndx,
  ne derived z karet.
- **P3 — reject prompt nad modalem:** dnes `handleRejectFromModal` nuluje
  `previewNdx` — v batch módu ne (D7). Ověřit modal stack: prompt (480px)
  nad full-width modalem, Escape zavírá jen prompt.
- **P4 — `load()` v batch:** stávající flow volají `load()` po každé akci
  (finishApply, submitRejectFlow). V batch větvích **nevolat** — jeden
  `load()` až ve `finishQueue()`. Jinak zbytečné dotazy a překreslování
  pod modalem.
- **P5 — timestamp null:** `toAtom` na serveru může vrátit null; sort
  s fallbackem null-last (viz krok 1), jinak `localeCompare` na null padá.
- **P6 — stejná zpráva dvakrát:** nemůže — `mail_suggestion:{ndx}` je
  per zpráva unikátní (poslední úspěšná analýza). Neřešit dedup.
- **P7 — chyba apply v batch:** alert + zůstat (D6) — v batch větvi
  `finishApply` se nevolá (volá se jen při `result.success`), takže
  chování vzniká samo; nepřidávat auto-skip po chybě.
- **P8 — materialize v předkroku bez load():** karta content_tag zmizí
  z feedu až po závěrečném `load()` (query-driven). Optimisticky ji
  odklidit `dropCardById` hned při úspěchu v předkroku, ať po zrušení
  předkroku nevisí zastaralá karta s už založenou položkou.
- **P9 — citlivá data:** do task filu ani commitů žádná reálná jména
  dodavatelů, čísla dokladů, částky (scripts/check-sensitive.py před
  commitem).
