# Task: Review modal — „Vystavit a uzavřít" (apply rovnou do V pořádku)

**Stav:** naplánováno

## Cíl

Review modal přijaté faktury (`DocumentExchangePreviewModal`) má dnes jediné
apply tlačítko **„Použít"** — vystaví doklad jako **Koncept** (docState 10)
a otevře ho ve FormDialogu k potvrzení a uzavření. AI analýza je už natolik
spolehlivá, že u velké části dokladů je ruční dokontrolování zbytečná práce.

Cíl:

1. **Nové primární tlačítko „Vystavit a uzavřít"** — doklad se vystaví
   rovnou ve stavu **40 V pořádku** (číslo přiděleno, zaúčtováno), review
   modal se zavře a uživatel zůstává na dashboardu. Žádný FormDialog.
   Úspěch potvrdí toast s odkazem „Otevřít".
2. **Stávající tlačítko přejmenovat** na **„Vystavit koncept"** a degradovat
   na secondary — chování beze změny (Koncept + FormDialog).

Backend je z většiny připraven: `applyOptions.targetDocState` existuje
(import mode, `RegistryPersonImporter`), HTTP endpoint ho propouští,
validace stavů 20/40/80 v `DocDocument::validate` proběhne a zaúčtování
zajistí přechodová detekce `0→40` (`trackStateChange` + 
`DocsHeadsEventHandler`). Jediná skutečná mezera je **přidělení čísla
dokladu** — `processStateTransition` ho dnes dělá jen na 10→20.

## Návaznost

- `MessageProposalApplier::apply` (`modules/core/mail/src/MessageProposalApplier.php`
  ~ř. 98, `targetDocState` ~ř. 214–222): `applyOptionsOverride['targetDocState']`
  → `canonical['applyOptions']['targetDocState']`, default 10.
- `DocumentApplier::transform` (`modules/core/exchange/src/Document/DocumentApplier.php`
  ~ř. 973): `docState = targetDocState`. Save jde přes
  `TransactionlessTableGateway::saveDocument` → plný Document flow
  (validate, beforeSave, event dispatch).
- `AnalysisController::applyMessage` (~ř. 1325): `applyOptions` z body se
  předává do service beze změny — **backend endpoint žádnou úpravu nepotřebuje**.
- `DocDocument::trackStateChange` (~ř. 250): insert mimo Koncept ⇒
  `stateTransition = {old: 0, new: 40}` („ať se importované doklady ve 40
  zaúčtují").
- `DocsHeadsEventHandler::onStateChanged`
  (`modules/economy/accounting/src/DocsHeadsEventHandler.php`): přechod do
  40 → `AccountingEngine::accountDocument`.
- `DocumentValidator::checkPartnerDocNumber`
  (`modules/core/exchange/src/Document/DocumentValidator.php` ~ř. 414):
  pro `targetDocState >= 20` warning `partner_doc_number_missing` —
  neblokuje, jen se objeví v issues.
- Review modal má **dva hostitele**: `Dashboard.svelte` a
  `ViewerDetail.svelte` (Došlá pošta) — oba předávají `onApply`.

## Potvrzená designová rozhodnutí (Anna, 2026-08-20)

- **D1** Popisky: stávající apply = **„Vystavit koncept"**, nové =
  **„Vystavit a uzavřít"**. (U registry targetu zůstává jediné „Zařadit".)
- **D2** Primární akce (variant `success`, úplně vpravo ve footeru) je
  **„Vystavit a uzavřít"**; „Vystavit koncept" → variant `secondary`.
  „Zamítnout" (`danger`) a „Zavřít" beze změny.
- **D3** Gating obou tlačítek je **stejné `canApply`** (všechny nerozhodnuté
  reference vyřešeny, ne ai_failed). Požadavky stavu 40 (partner, registrace
  DPH, řádky, kurz…) hlídá backendová validace — při chybě 422/500 se ukáže
  alert s hláškou a uživatel může použít cestu přes koncept.
- **D4** Úspěch na dashboardu: zavřít modal, karta pryč, **toast**
  „Doklad #{id} vystaven a uzavřen [Otevřít]" (vzor
  `dashboard.toast.appliedRegistry`; Otevřít → FormDialog nad
  `docs_core_heads`, existující `openCreatedDoc` mechanismus toastu).
- **D5** `ViewerDetail` (Došlá pošta) sdílí modal, tlačítko tam bude taky:
  úspěch → zavřít modal + `onRefresh()`, **bez** otevření FormDialogu
  (na rozdíl od konceptové cesty). Toast infrastruktura ve vieweru není —
  bez toastu.
- **D6** Unapply se nerozšiřuje: doklad vzniklý rovnou ve 40 nelze
  odaplikovat (guard vyžaduje netknutý Koncept). Cesta zpět je ruční
  (Opravit / Storno). Vědomá finalizace.
- **D7** Jednoklikové apply z karty ready pásma (`applyFlow`) zůstává
  konceptové — varianta „rovnou V pořádku" z karty je mimo scope
  (poznámka v Mimo rozsah).

## Rozsah

### V rozsahu

1. `modules/docs/core/src/DocDocument.php` — `processStateTransition`
   (~ř. 982): přidělit číslo i pro přechody `{0,10} → {20,40}` (mimo
   import mode); `assignDocumentNumber` (~ř. 1095): vnitřní transakce
   podmíněná — viz Pasti P1.
2. `frontend/src/api/exchange.js` — `applyMessage` (~ř. 66): třetí
   parametr `applyOptions = null` → body `{_resolve?, applyOptions?}`.
3. `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
   — footer: přejmenování, nové tlačítko, `onApply(messageNdx,
   userActions, target, applyOptions)`.
4. `frontend/src/components/dashboard/Dashboard.svelte` —
   `handleApplyFromModal` (~ř. 340) přijme `applyOptions` a předá dál;
   `finishApply` (~ř. 220) nová větev pro finalizované docs → toast
   místo FormDialogu.
5. `frontend/src/components/viewer/ViewerDetail.svelte` —
   `handleApplyFromModal` (~ř. 138): finalizovaná cesta bez
   `onAction('openCreatedDoc', …)`, jen close + refresh.
6. i18n `frontend/src/i18n/cs.js` (+ `en.js`):
   `exchange.preview.actions.apply` → „Vystavit koncept" / "Issue as draft",
   nový `exchange.preview.actions.applyFinal` → „Vystavit a uzavřít" /
   "Issue and close", nový `dashboard.toast.appliedFinal` →
   „Doklad #{id} vystaven a uzavřen" / "Document #{id} issued and closed".
7. PHPUnit: test přechodu 0→40 v DocDocument (číslo přiděleno, ne
   placeholder) + test, že import mode (`_importNumber`) zůstal beze změny.

### Mimo rozsah

- Změny `AnalysisController` / `MessageProposalApplier` /
  `DocumentApplier` — `targetDocState` už funguje end-to-end.
- Rozšíření `unapply` na docState 40 (D6).
- „Rovnou V pořádku" z jednoklikové karty ready pásma (D7) — případně
  později do `tasks/TODO.md`.
- Whitelisting `applyOptions` v HTTP endpointu (klient už dnes může poslat
  cokoli; případné zpřísnění je samostatné téma).
- MCP tool `MailDraftDocumentTool` — zůstává natvrdo `targetDocState: 10`.
- Busy/in-flight stav tlačítek modalu (dnes není ani u stávajícího apply).

## Datový tok

```
[Vystavit a uzavřít] → onApply(ndx, userActions, target, {targetDocState: 40})
  ▼ Dashboard.handleApplyFromModal / ViewerDetail.handleApplyFromModal
applyMessage(ndx, userActions, {targetDocState: 40})
  → POST /_mail/messages/{ndx}/apply  {_resolve?, applyOptions: {targetDocState: 40}}
  ▼ AnalysisController.applyMessage → MessageProposalApplier.apply
canonical.applyOptions.targetDocState = 40
  ▼ DocumentApplier.apply → transform → docState: 40
  ▼ TransactionlessTableGateway.saveDocument → DocDocument
validate (stav 40: partner, DPH registrace, řádky, kurz, pohyby řádků)
beforeSave → trackStateChange {old: 0, new: 40}
           → processStateTransition → assignDocumentNumber  ← NOVÉ
event dispatch → DocsHeadsEventHandler → AccountingEngine (zaúčtování)
  ▼ úspěch
Dashboard: finishApply(…, finalized) → toast „Doklad #… vystaven a uzavřen [Otevřít]"
ViewerDetail: closePreviewModal() + onRefresh()
```

## Co je potřeba udělat

### 1. `DocDocument.php` — přidělení čísla pro přímý skok do 20/40

`processStateTransition` (~ř. 982):

```php
$t = $this->stateTransition;
if ($t === null) {
    return;
}
if (in_array($t['old'], [0, 10], true) && in_array($t['new'], [20, 40], true)) {
    $this->assignDocumentNumber($data);
    return;
}
if ($t['old'] === 20 && $t['new'] === 10) {
    $this->releaseDocumentNumber($data, $originalData);
}
```

Pokrývá dosavadní 10→20 i nové 0→40 (a defenzivně 0→20, 10→40 — `goto`
je dnes neprodukuje). Import mode se sem nedostane (`beforeSave` větví na
`applyImportNumber` dřív). Ověřit greppem, že žádný jiný producent
insertů `docs_core_heads` ve stavu 20/40 bez `_importNumber` neexistuje
(provisionery `NumberSeriesProvisioner`, `AccountChartProvisioner` jedou
nad jinými tabulkami / dokumenty).

### 2. `DocDocument.php` — transakce v `assignDocumentNumber` (Pasti P1)

Dnes: `$this->db->begin()` … `commit()`. V exchange cestě už transakce
běží (`DocumentApplier::apply`, krok 6–11) a vnořený `START TRANSACTION`
by v MariaDB **implicitně commitnul vnější transakci** — přesně důvod
existence `TransactionlessTableGateway` (viz jeho doc-comment).

Řešení: transakci otevírat jen když žádná neběží. Ověřit, co nabízí
`DataSourceConnection` / Dibi (`\Dibi\Connection::inTransaction()` —
zkontrolovat dostupnost ve vendorované verzi); pokud chybí, doplnit
flag/proxy na `DataSourceConnection`. `FOR UPDATE` zámek counteru funguje
uvnitř vnější transakce stejně — jen se drží déle (do commitu celého
apply), což je správně.

Zkontrolovat i dosavadní cestu 10→20 (FormController transition): jestli
tam gateway transakci otevírá, byl by tenhle bug latentně přítomný už
dnes — v tom případě fix platí univerzálně.

### 3. `api/exchange.js` — `applyOptions` parametr

```js
export async function applyMessage(messageNdx, userActions = null, applyOptions = null) {
  const body = {};
  if (userActions !== null) body._resolve = userActions;
  if (applyOptions !== null) body.applyOptions = applyOptions;
  return await post(`/_mail/messages/${messageNdx}/apply`, body);
}
```

Doc-comment doplnit o `applyOptions.targetDocState` (40 = Vystavit a
uzavřít) a pozn., že `autoCreateMode` se dál odvozuje serverem.

### 4. `DocumentExchangePreviewModal.svelte` — footer

- `handleApplyClick(applyOptions = null)` →
  `onApply(messageNdx, userActions, data?.target ?? 'docs', applyOptions)`.
- Pořadí tlačítek: Zavřít (secondary) · Zamítnout (danger) ·
  **Vystavit koncept** (secondary, `actions.apply`) ·
  **Vystavit a uzavřít** (success, `actions.applyFinal`,
  `onclick={() => handleApplyClick({ targetDocState: 40 })}`).
- Registry target: beze změny — jediné „Zařadit" (success), tlačítko
  applyFinal se pro `isRegistry` nerenderuje.
- Oba apply buttony sdílí `disabled={!canApply}` (D3).

### 5. `Dashboard.svelte` — finalizovaná větev

- `handleApplyFromModal(messageNdx, userActions, target, applyOptions)`
  → `applyMessage(messageNdx, userActions, applyOptions)`; do
  `finishApply` předat příznak `finalized = applyOptions?.targetDocState === 40`.
- `finishApply`: pro `finalized && target !== 'registry'` → místo
  `formModal = …` zavolat `showToast({kind: 'applied', message:
  t('dashboard.toast.appliedFinal', {id: docId}), docId, docTable:
  HEADS_TABLE})`. Toast „Otevřít" (`openCreatedDoc`) už s `docTable`
  pracuje — beze změny.

### 6. `ViewerDetail.svelte` — finalizovaná větev

`handleApplyFromModal` přijme `applyOptions`, předá do `applyMessage`;
při `finalized` přeskočit `onAction('openCreatedDoc', …)` — jen
`closePreviewModal()` + `onRefresh()`.

### 7. i18n

`cs.js` (~ř. 400–403, ~ř. 475) + `en.js` — klíče z Rozsahu bod 6.
Pozor na to, že `actions.apply` mění text, ne klíč — žádné další výskyty
klíče netřeba honit.

### 8. Testy + build

- PHPUnit: `vendor/bin/phpunit --filter DocDocument` (nebo příslušný
  test case) — nový test 0→40 přiděluje číslo ze série (žádný
  `!0000000…` placeholder), counter se posune; import mode test beze
  změny chování.
- `php -l` po každé backend editaci.
- `cd frontend && timeout 90 npm run build` (timeout_sec 120).

## E2E ověření (alpha, jen čtení + ruční klikání)

1. Dashboard → karta faktury v review pásmu → modal → **Vystavit a
   uzavřít**: modal se zavře, žádný FormDialog, toast s číslem záznamu,
   karta zmizí.
2. Doklad ve vieweru Přijaté faktury: stav **V pořádku**, `doc_number`
   reálné číslo ze série (SQL přes `claude_ro`: `SELECT doc_number,
   sequence_number, docState FROM docs_core_heads WHERE id = …`),
   tab Účtování má řádky deníku.
3. `docs_core_number_counters` posunutý o 1; následné vystavení dalšího
   dokladu (konceptová cesta 10→20) pokračuje správným pořadovým číslem.
4. **Vystavit koncept**: chování jako dřív (Koncept + FormDialog).
5. Negativní: faktura bez dodavatele (nerozhodnutá reference) — obě
   tlačítka disabled; faktura, které chybí náležitost stavu 40 →
   alert s validační hláškou, doklad nevznikl.
6. ViewerDetail (Došlá pošta) → stejný modal → Vystavit a uzavřít:
   zavře se, detail se refreshne, FormDialog se neotevře.
7. Spisovna target: jediné tlačítko „Zařadit", beze změny.

## Pasti

- **P1 — vnořená transakce (kritické):** `assignDocumentNumber` má vlastní
  `begin()/commit()`; uvnitř transakce `DocumentApplier` by druhý
  `START TRANSACTION` implicitně commitnul vnější (MariaDB nemá nested
  transactions — viz doc-comment `TransactionlessTableGateway`). Bez fixu
  by selhání apply po přidělení čísla nechalo v DB částečně uložený
  doklad. Řešit podmíněnou transakcí (krok 2), ne savepointy.
- **P2 — placeholder číslo:** bez kroku 1 doklad ve 40 skončí s číslem
  `!0000000{id}` z `ensureDocNumberPlaceholder` — vypadá to jako úspěch,
  ale číslo je nesmysl. Test v kroku 8 to musí chytat.
- **P3 — `finishApply` registry větev:** finalized příznak nesmí rozbít
  registry toast — registry apply `targetDocState` neposílá, větvení
  držet za `target !== 'registry'`.
- **P4 — dva hostitelé modalu:** signatura `onApply` se mění na obou
  místech (`Dashboard`, `ViewerDetail`) — zapomenutý hostitel by tiše
  ignoroval `applyOptions` a vystavil koncept.
- **P5 — idempotency:** opakovaný apply téže zprávy vrací existující
  `savedDocId` (`DocumentApplier::checkIdempotent`) — druhé kliknutí na
  „Vystavit a uzavřít" po úspěšném „Vystavit koncept" doklad **nepovýší**
  do 40, jen vrátí koncept. Toast by pak lhal („uzavřen"). Přijatelné
  (kartu po prvním úspěchu stejně odklidíme), ale nezkoušet to „opravit"
  přepisem stavu v idempotentní větvi.
- **P6 — `goto` state machine:** přímý insert ve 40 `goto` nekontroluje
  (precedens: import mode). Nepřidávat 40 do `goto` stavu 10 — UI přechody
  existujících dokladů se nemění.
