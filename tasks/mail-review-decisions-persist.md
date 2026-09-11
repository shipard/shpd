# Task: Review modal — průběžné ukládání rozhodnutí z resolve panelu (Issue #76)

**Stav:** hotovo

## Status / cíl

V review modalu („Zkontrolovat" nad zprávou v dashboardu i ve ViewerDetail)
uživatel přiřazuje položky řádkům, dodavatele, bankovní účty atd. přes
badge popovery (`ResolveDecisionPanel`). Tato rozhodnutí žijí **jen ve
stavu `userActions` v `DocumentExchangePreviewModal.svelte`** a na server
odejdou až s „Vystavit koncept / Vystavit a uzavřít" jako `_resolve`
v `POST /_mail/messages/{ndx}/apply`. Každé zavření modalu (Esc, klik na
overlay, Zavřít, Přeskočit v batch módu) rozhodnutí zahodí a `loadPreview()`
je při dalším otevření resetuje na `{}`. Modal nemá Uložit (nedává tam
smysl) ani dirty guard.

Cíl: **každé rozhodnutí se okamžitě uloží na server** (na řádek poslední
úspěšné analýzy zprávy) a při dalším otevření modalu se předvyplní.
Modal se zavírá volně; ConfirmDialog „Neuložené změny" se objeví **jen**
když uložení právě běží nebo selhalo. Tlačítko Uložit se nepřidává,
„Zrušit výběr" v popoveru zůstává beze změny.

GitHub Issue: shipard/shpd#76 (Sebik). Navazuje na
`tasks/exchange-resolve-decision-ui.md` (popovery, `userActions`) a
`tasks/mail-message-centric.md` (message-centrické endpointy).

## Potvrzená designová rozhodnutí (Anna, 2026-09-11)

1. **Varianta (b) z diskuse — persistence, ne jen dirty guard.** Dirty
   guard zůstává pouze jako záchranná síť pro selhání autosave (bod 7).
2. **Guard se vztahuje i na Přeskočit** v batch módu „Projít frontu"
   (za stejných podmínek jako zavření). Zamítnout a oba apply se neguardují.
3. **Kolize Popover ↔ Modal se neřeší** — v issue je jen zmateně popsané
   zavření modalu; nic takového reprodukovat nezkoušet.
4. **Úložiště: nový sloupec `core_mail_message_analyses.user_actions_json`**
   (longtext, nullable, group `result`). Obsah = flat mapa
   `{"supplier": "useExisting:42", "rows[0].item": "skip"}` — přesně tvar,
   který dnes posílá `applyMessage` v `_resolve`. Vazba na **analýzu**, ne
   na zprávu: reanalýza vytvoří nový řádek, staré rozhodnutí přirozeně
   zaniká (cesty `rows[i].item` by po reanalýze nemusely sedět). Název
   drží termín `userActions` / `userAction` z existujícího kódu. Schéma
   se propíše přes `ds-upgrade` (na alfě spouští Anna).
5. **Endpoint `POST /_mail/messages/{ndx}/decisions`**, body
   `{"_resolve": {...}}` — vždy **celá mapa**, ne delta; last-write-wins.
   Guardy shodné s reject: zpráva existuje a je mimo Archiv/Koš, poslední
   úspěšná analýza + `analysis_state = 30`, `resolution IS NULL` → jinak
   409 `INVALID_STATE`. `ReadOnlyPolicy` → 403 (výchozí verdikt pro
   neuvedené akce). Odpověď `{messageNdx, analysisNdx, userActions}`
   s mapou tak, jak byla uložena (po sanitizaci).
6. **Autosave při každém `onUserActionsChange`** včetně „Zrušit výběr"
   (klíč z mapy mizí — `DocumentExchangePreview` už dnes dělá
   `delete next[path]`). Bez debounce — hromadné rozhodnutí přijde jako
   jeden callback. Ukládá se na pozadí, UI neblokuje.
7. **Záchranná síť:** modal drží `pendingSave` a `saveError`. Guard
   (`ConfirmDialog` Zahodit/Zůstat, vzor `FormDialog.guardDirty`) se
   aktivuje **jen** když `pendingSave || saveError`, pro Esc, overlay,
   „×", Zavřít a Přeskočit. Při `saveError` nenápadný text v patičce
   („Rozhodnutí se nepodařilo uložit"). Další změna uložení zopakuje.
8. **Načtení:** `previewMessage` vrací `userActions` (flat mapa nebo `{}`)
   v `data`; modal jí inicializuje stav místo `{}`. `canApply` /
   `allDecided` beze změny.
9. **Po verdiktu se sloupec nemaže** (apply, reject) — je to záznam, co
   uživatel rozhodl (základ pro budoucí učení mapování). Po `unapply`
   (`resolution → NULL`) se modal otevře s původním rozhodnutím — žádoucí.
10. **Mimo rozsah:** MCP nástroj `mail_draft_document` a one-click apply
    z feed karty bez `_resolve` uložená rozhodnutí **nečtou**; souběh dvou
    uživatelů = last-write-wins bez ETagu.

## Před implementací přečti

- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
  (celý, 336 ř.) — `$effect` na `open` ř. 65–73 (**reset `userActions`
  nesmí vyvolat save**), `loadPreview` ř. 75–92, `handleUserActionsChange`
  ř. 94–96, `allDecided` ř. 100–120, `<Modal … {onClose}>` ř. 143,
  patička ř. 197–250 (Zavřít, Přeskočit, Zamítnout, apply).
- `frontend/src/components/exchange/DocumentExchangePreview.svelte`
  ř. 254–306 — jak vzniká `next` (`delete next[path]` při unselect,
  hromadné rozhodnutí = jeden callback ř. 297).
- `frontend/src/components/form/FormDialog.svelte` ř. 85–130
  (`pendingAction`, `guardDirty`, `discardPending`, `stayPending`,
  `handleClose`) a ř. 306–316 (`<ConfirmDialog>` s `form.unsaved*` klíči)
  — **vzor, který se přebírá**.
- `frontend/src/components/ui/Modal.svelte` ř. 114–129 — Esc i overlay
  volají `onClose` prop; „×" ř. 208. Guard tedy stačí na jednom místě:
  modalu se předá `handleClose`, ne `onClose`.
- `frontend/src/api/exchange.js` ř. 40–79 — `previewMessage`,
  `applyMessage` (JSDoc o tvaru `_resolve`).
- `frontend/src/i18n/cs.js` ř. 458–477 — klíče `exchange.preview.*`;
  `en.js` totéž.
- `src/Api/Router.php` ř. 623–660 (`resolveMailMessagesRoute` — regex
  `(reanalyze|apply|unapply|reject|preview)`, mapování na akce controlleru).
- `src/Api/ReadOnlyPolicy.php` ř. 116–127 — blok `analysis`; neuvedená
  akce = 403, stačí doplnit komentář.
- `src/Api/Controller/AnalysisController.php` — `applyMessage` ř. 1446,
  `outcomeToResponse` ř. 1470, `rejectMessage` ř. 1498 (**vzor pro
  `saveDecisions`**: auth → parse body → applier → outcome → Response),
  `previewMessage` ř. 1648–1690 (`$base` ř. 1680 — sem přibude
  `userActions`; **musí být ve všech větvích**, tj. v `$base`).
- `modules/core/mail/src/MessageProposalApplier.php` — konstanty ř. 33–50,
  `latestSuccessfulAnalysis` ř. 80, `apply` ř. 98–150 (guardy),
  `reject` ř. 334–380 (**vzor pro `saveUserActions` — stejná sekvence
  guardů**), `writeResolution` ř. 566 (dibi transakce, UPDATE tvar),
  `expandUserActions` ř. 680–699 (**regex a whitelist cest — sanitizace
  musí používat totéž**).
- `modules/core/mail/tables/core_mail_message_analyses.jsonc` ř. 162–176
  (`canonical_json` — nový sloupec hned za něj, stejný tvar).
- `modules/core/mail/docs/ai-analysis.md` ř. 406–435 (sekce
  Message-centrické akce) a `docs/mail/api-contract.md` §9.8 (ř. 446),
  §9.12 (ř. 505) — doplnit.
- Testy: `tests/Unit/Module/Core/Mail/MessageProposalApplierTest.php`
  (`testApplyRejectsArchivedOrTrashedMessage` ř. 242 a okolí — vzor mock
  DB), `tests/Unit/Api/Controller/AnalysisControllerPreviewMessageTest.php`
  (`setUp` ř. 39, továrna controlleru ř. 84),
  `AnalysisControllerResolveBodyTest.php`, `tests/Unit/Api/RouterTest.php`,
  `tests/Unit/Module/Core/Mail/MailModuleDefinitionTest.php`.

## Rozsah

### 1. Backend

#### 1.1 `modules/core/mail/tables/core_mail_message_analyses.jsonc`

Za `canonical_json` nový sloupec:

```jsonc
{
    // Rozhodnutí uživatele z review modalu (resolve badge popovery) —
    // flat mapa {cesta: userAction}, stejný tvar jako `_resolve` v body
    // POST /apply. Ukládá se průběžně (POST /decisions), vázáno na tento
    // běh analýzy; po verdiktu se nemaže (historie). NULL = nic nerozhodnuto.
    "id": "user_actions_json",
    "name": "Review decisions (JSON)",
    "name:cs": "Rozhodnutí z review (JSON)",
    "name:en": "Review decisions (JSON)",
    "type": "longtext",
    "nullable": true,
    "group": "result"
}
```

Pak `bin/shpd-ds ds-upgrade` na dev DS; na alfě Anna.

#### 1.2 `MessageProposalApplier` — uložení a sanitizace

- `public static function sanitizeUserActions(array $flat): array` —
  ponechá jen dvojice, kde klíč projde **stejným** whitelistem jako
  `expandUserActions` (`supplier|customer|supplierBank|customerBank`,
  `rows[N].(item|unit|vatCode)`) a hodnota je neprázdný string. Ostatní
  zahodí (bez chyby). Vytáhnout regex do privátní konstanty, aby ho
  `expandUserActions` i sanitizace sdílely.
- `public static function decodeUserActions(?string $json): array` —
  `json_decode` → pole, jinak `[]`; výsledek projde `sanitizeUserActions`
  (obrana proti ručně poškozenému sloupci).
- `public function saveUserActions(int $messageNdx, ?int $userId, array $flat): ProposalApplyOutcome`
  — guardy **1:1 jako `reject`** (NOT_FOUND 404; Archiv/Koš 409; bez
  analýzy / `analysis_state != 30` 409; `resolution !== null` 409), pak
  `$db->updateWhere('core_mail_message_analyses', ['user_actions_json' => $json], 'id = %i', $analysisNdx)`
  — hodnota `json_encode($sanitized, JSON_UNESCAPED_UNICODE)`, nebo
  `NULL` když je mapa po sanitizaci prázdná. Bez transakce (jeden
  UPDATE); pád zápisu → 500 `INTERNAL_ERROR` (jako `reject`). Vrací
  `ProposalApplyOutcome::ok($messageNdx, $analysisNdx, null, null)`.
  Metoda sanitizuje vstup sama (obrana, i když ji zavolá kdokoli jiný);
  controller sanitizuje také a **tu samou mapu** vrátí v odpovědi —
  nic se nečte zpět z DB.
- `$userId` se zatím nezapisuje (sloupec pro autora rozhodnutí není) —
  parametr ponechat kvůli symetrii s ostatními akcemi.

#### 1.3 `Router::resolveMailMessagesRoute`

Regex rozšířit o `decisions`, metoda POST, mapování
`'decisions' => 'saveDecisions'`. Komentář nad regexem doplnit.

#### 1.4 `ReadOnlyPolicy`

V bloku `analysis` doplnit `saveDecisions` do komentáře seznamu akcí → 403
(žádný kód; výchozí verdikt).

#### 1.5 `AnalysisController::saveDecisions`

```php
public function saveDecisions(AuthContext $auth, Request $request, int $messageNdx): Response
```

- 401 bez auth (jako ostatní).
- Body: `_resolve` musí být pole (i prázdné) → jinak 422
  `VALIDATION_ERROR` s `[['field' => '_resolve']]`.
- `$flat = MessageProposalApplier::sanitizeUserActions($body['_resolve'])`
  → `buildProposalApplier()->saveUserActions($messageNdx, $auth->userId, $flat)`.
- Neúspěch → `Response::error($outcome->errorCode, $outcome->errorMessage, $outcome->statusCode)`.
- Úspěch → `Response::success(['messageNdx', 'analysisNdx', 'userActions' => $flat])`
  (`(object) []` / `new \stdClass()` pro prázdnou mapu, aby JSON bylo `{}`,
  ne `[]` — frontend dělá `?? {}` a `Object.keys`).

#### 1.6 `AnalysisController::previewMessage`

Do `$base` přidat
`'userActions' => MessageProposalApplier::decodeUserActions($analysis['user_actions_json'] ?? null)`
— opět jako objekt (`{}`) u prázdné mapy. Tím ho dostanou všechny větve
(ai_failed, registry, bez applieru, docs).

#### 1.7 Testy backend

- `MessageProposalApplierTest`: `saveUserActions` happy path (UPDATE
  dostane JSON se sanitizovanou mapou), prázdná mapa → `NULL`, guardy
  Archiv/Koš a `resolution` obsazené → 409, `sanitizeUserActions` zahodí
  neznámou cestu, ne-string hodnotu a prázdný string; `decodeUserActions`
  na `null` / nevalidní JSON → `[]`.
- `AnalysisControllerPreviewMessageTest`: `userActions` v odpovědi
  z hodnoty sloupce; `{}` při `NULL`.
- Nový `tests/Unit/Api/Controller/AnalysisControllerSaveDecisionsTest.php`:
  401, 422 bez `_resolve`, 200 s vrácenou mapou, propagace 409 z applieru.
- `RouterTest`: `POST /_mail/messages/5/decisions` → `analysis.saveDecisions`,
  GET → 405.
- `MailModuleDefinitionTest` — pokud snapshotuje sloupce, doplnit.

**→ Commit 1** (`feat(mail): endpoint /decisions a sloupec user_actions_json — průběžné ukládání rozhodnutí z review (#76 1/2)`).

### 2. Frontend

#### 2.1 `frontend/src/api/exchange.js`

- `export async function saveDecisions(messageNdx, userActions)` → POST
  `/_mail/messages/${messageNdx}/decisions`, body `{_resolve: userActions}`.
  JSDoc: celá mapa, last-write-wins, prázdná mapa = smazat.
- V JSDoc `previewMessage` doplnit `userActions: object` do `data`.

#### 2.2 `DocumentExchangePreviewModal.svelte`

- Hlavičkový komentář: nový odstavec „Persistence rozhodnutí" (autosave,
  guard jen při pending/error, D4–D9 odkaz na tento task).
- `loadPreview`: `userActions = result.data.userActions ?? {}` (místo
  `{}` před voláním nechat reset, ale po úspěchu přepsat).
- Nový stav: `let saveSeq = 0` (ne-reaktivní čítač), `pendingSave`,
  `saveError` (`$state(false)`), `pendingAction = $state(null)`.
- `handleUserActionsChange(next)`: `userActions = next; void persist(next);`
- `async function persist(map)`: `const seq = ++saveSeq; pendingSave = true;`
  `try { r = await saveDecisions(messageNdx, map) } catch { r = null }`;
  **jen pokud `seq === saveSeq`** (poslední odpověď vyhrává, starší se
  ignorují): `pendingSave = false; saveError = !r?.success;`.
- `let unsafeToClose = $derived(pendingSave || saveError)`.
- `guardClose(then)` / `discardPending` / `stayPending` — kopie vzoru
  z `FormDialog` (`discardPending` navíc `saveError = false`).
- `handleClose = () => guardClose(onClose)`, `handleSkip = () => guardClose(onSkip)`.
  Do `<Modal onClose={handleClose}>`, tlačítko Zavřít `onclick={handleClose}`,
  Přeskočit `onclick={handleSkip}`. Zamítnout a apply beze změny.
- `$effect` na `open` (větev `else`): navíc `pendingSave = false;
  saveError = false; pendingAction = null; saveSeq++` (zneplatní
  případnou dobíhající odpověď). **Žádné volání `persist` z efektu.**
  Tentýž reset (`resetDecisionState()`) i na začátku `loadPreview` —
  v batch módu `open` zůstává true a mění se jen `messageNdx`, větev
  `else` tedy neproběhne.
- Patička: před tlačítky `{#if saveError}<span class="shpd-exchange-modal__save-error">{t('exchange.preview.decisions.saveError')}</span>{/if}`
  (barva `--shpd-color-danger`, `font-size: var(--shpd-font-size-sm)`,
  `margin-right: auto`, aby se tlačítka nepohnula).
- `<ConfirmDialog open={pendingAction !== null} … testid="review-unsaved-dialog">`
  s klíči `exchange.preview.decisions.unsavedTitle` / `unsavedMessage` /
  `discard` / `stay`, `variant="danger"`.

#### 2.3 i18n

`cs.js` / `en.js`, blok `exchange.preview.*`:

```
'exchange.preview.decisions.saveError':     'Rozhodnutí se nepodařilo uložit',
'exchange.preview.decisions.unsavedTitle':  'Neuložená rozhodnutí',
'exchange.preview.decisions.unsavedMessage':'Poslední rozhodnutí se ještě neuložilo nebo uložení selhalo. Zavřít modal a rozhodnutí zahodit?',
'exchange.preview.decisions.discard':       'Zahodit',
'exchange.preview.decisions.stay':          'Zůstat',
```

#### 2.4 Hostitelé

`Dashboard.svelte` (ř. 662–666) a `ViewerDetail.svelte` (ř. 492) — **beze
změny**; guard je uvnitř modalu, callbacky se volají až po průchodu guardem.

**→ Commit 2** (`feat(mail): review modal ukládá rozhodnutí průběžně, guard jen při selhání uložení (#76 2/2)`).

### 3. Dokumentace (součást commitu 1, resp. 2)

- `modules/core/mail/docs/ai-analysis.md` — do sekce „Message-centrické
  akce" odrážka **`POST /_mail/messages/{ndx}/decisions`** (tvar body,
  guardy, `user_actions_json`, nemaže se po verdiktu, reanalýza = nový
  řádek bez rozhodnutí); u preview doplnit `userActions` v odpovědi.
- `docs/mail/api-contract.md` — §9.12 doplnit `userActions` do response;
  nová §9.13 `POST /_mail/messages/{ndx}/decisions` (auth, body, guardy,
  odpověď, chyby 404/409/422). Přečíslování neřešit, 9.13 na konec před §10.
- `tasks/exchange-resolve-decision-ui.md` — jednořádková poznámka
  u `userActions`, že persistence řeší tento task (jen pokud tam je
  přirozené místo; jinak vynechat).

### Mimo rozsah

- Použití uložených rozhodnutí v `mail_draft_document` (MCP) a v one-click
  apply z feed karty bez `_resolve` — safe mode se nemění. Kandidát na
  navazující task, až bude jasné, co má „bezpečně" znamenat u rozhodnutí
  `create` / `skip`.
- Autor a čas rozhodnutí (`user_actions_by/at`) — až bude důvod (audit).
- Konfliktní editace dvěma uživateli (ETag / verze).
- Učení mapování z rozhodnutí (dodavatelské kódy → položka) — jiná oblast
  (`SupplierCodeCaptureHandler`), i když sloupec je pro to vstup.
- Registry větev (Spisovna) — nemá resolve panel, `userActions` bude
  vždy `{}`; UI se nemění.
- Dataset export/seed (`MailExporter` / `MailSeeder`) sloupec
  `user_actions_json` **nepřenáší** — rozhodnutí z review je pracovní stav
  uživatele, ne součást testovací sady.

## Pasti

- **Dispatch tabulka v `public/index.php`.** Router jen mapuje URL na
  název akce; volání controlleru dělá ruční `match` v `dispatchAnalysis()`
  (`public/index.php`), kde neznámá akce vrací 500 „Unknown analysis
  action". Nová akce = Router + `dispatchAnalysis()` + ReadOnlyPolicy.
  RouterTest tuhle díru neodhalí — při první implementaci se na ni přišlo
  až v E2E („Rozhodnutí se nepodařilo uložit" hned při prvním rozhodnutí).
- **Pořadí nasazení:** nejdřív jsonc + `ds-upgrade`, pak kód. Do té doby
  `SELECT *` sloupec nevrací → v PHP vždy `$analysis['user_actions_json']
  ?? null`, aby preview na neupgradovaném DS nespadlo (`Dibi\Row` na
  neexistující klíč hází). Na alfě upgrade dělá Anna po `git pull`.
- **Reset stavu v `$effect` nesmí ukládat.** Jediné místo, které volá
  `persist`, je `handleUserActionsChange`. Jinak by zavření modalu
  poslalo `{}` a smazalo rozhodnutí na serveru.
- **Závod odpovědí.** Dvě rychlá kliknutí → dva POSTy; pomalejší starší
  odpověď nesmí přepsat `saveError`/`pendingSave`. Proto `saveSeq`
  (čítač, ne `$state` — nemá se na něj nic vykreslovat).
- **`saveError` a apply.** Když selhalo uložení, klient stále drží
  správnou mapu a apply ji pošle v `_resolve` — apply funguje nezávisle
  na persistenci. Guard tedy apply neblokuje (rozhodnutí 2).
- **Prázdná mapa v JSON = `{}`, ne `[]`.** PHP `json_encode([])` dá `[]`;
  v odpovědích controlleru použít `new \stdClass()` pro prázdný případ.
  Do sloupce se prázdná mapa neukládá vůbec (`NULL`).
- **Sanitizace sdílí regex s `expandUserActions`.** Kdo rozšíří whitelist
  cest jen na jednom místě, rozbije buď apply, nebo persistenci. Test
  v `MessageProposalApplierTest` porovná, že `expandUserActions(sanitize(x))
  === expandUserActions(x)` pro validní vstup.
- **`ConfirmDialog` je další Modal na stacku** — Esc v něm zavře dialog
  (= Zůstat), nikdy review modal; stejné chování jako ve `FormDialog`,
  neladit.
- **`onClose` prop vs. `handleClose`.** `Modal` volá `onClose` pro Esc,
  overlay i „×"; předat **`handleClose`**, jinak guard obejde dvě ze tří
  cest.
- **Batch mód:** po Přeskočit rodič změní `messageNdx` a `$effect`
  načte další zprávu → reset stavů (viz výše) je nutný i tady, jinak
  `saveError` z předchozí zprávy přeteče do další.
- **`ReadOnlyPolicyTest`** může enumerovat akce `analysis` — pokud ano,
  doplnit `saveDecisions` → 403.
- **Diakritika:** PHP komentáře, jsonc, i18n, docs i tento soubor —
  editovat přes Python `io.open(..., encoding='utf-8')` s
  `assert s.count(old) == 1`, ne `patch_file`.
- **Citlivá data:** testy a docs jen s fiktivními ID/názvy; před commitem
  `python3 scripts/check-sensitive.py`.

## Hotovo když

1. `vendor/bin/phpunit --filter 'MessageProposalApplier|AnalysisController|Router|ReadOnlyPolicy|MailModuleDefinition'`
   zelené.
2. `cd frontend && timeout 90 npm run build` bez chyb.
3. E2E na dev DS, zpráva s přijatou fakturou s nerozpoznanou položkou:
   otevřít Zkontrolovat → přiřadit položku → v Network `POST …/decisions`
   200 s mapou → kliknout mimo modal (overlay) — **zavře se bez dialogu**
   → znovu otevřít → badge řádku je matchedDecided, „Vystavit koncept"
   povolené (pokud nic jiného nechybí). „Zrušit výběr" → zavřít → otevřít
   → řádek opět nerozhodnutý; v DB `user_actions_json IS NULL`.
4. Simulace selhání (DevTools offline nebo blokace URL): změna
   rozhodnutí → v patičce „Rozhodnutí se nepodařilo uložit"; Esc →
   ConfirmDialog; Zůstat ponechá modal; po obnovení sítě další změna
   uloží a text zmizí; Zahodit zavře.
5. Batch mód „Projít frontu": Přeskočit při selhaném uložení → dialog;
   při uloženém → posun bez dialogu.
6. Apply s uloženými rozhodnutími proběhne jako dřív; po apply
   `user_actions_json` na analýze zůstává vyplněný.
7. Alfa: po `git pull` a `ds-upgrade` (Anna) preview vrací `userActions`
   i pro staré analýzy (`{}`).
