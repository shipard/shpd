# Modul `mail` — Oddělení stavů analýzy + AI klasifikace typu pošty

**Stav:** hotovo

analysis_state od docState`, `feat(mail): AI klasifikace primary_type
v result endpointu`, `feat(mail): dashboard karta "Neni faktura" +
trash/archive akce`). Na dev DS zbývá `ds-upgrade` +
`ai-profile-reload --force`.
**Cíl:** (A) Oddělit pipeline status AI analýzy od uživatelského workflow
stavu zprávy — `docState` došlé pošty se srovná se zbytkem aplikace
(Nová / K řešení / Hotovo / Archiv / Koš), stav analýzy se přesune do
nového ortogonálního sloupce `analysis_state`. (B) AI při analýze rovnou
klasifikuje typ zprávy (`primary_type`) a ne-faktury přestanou generovat
šum v dashboardu — místo chybových/review karet dostane uživatel
informační kartu „Není faktura" s jednoklikovým Košem.

**Návaznost:**

- Navazuje na `tasks/mail-phase3a.md` (AI analýza) a `tasks/mail-phase4-import-endpoint.md`.
- Externí analyzer daemon (`ai_analyzer`, Fáze 3b) **nevyžaduje změny** —
  protokol se rozšiřuje jen o volitelné pole v `POST /result` (kontrakt §8:
  volitelná pole jsou zpětně kompatibilní).
- Dokumentaci aktualizovat: `docs/mail/api-contract.md`,
  `modules/core/mail/docs/ai-analysis.md`, `tables/core_mail_incoming_messages.md`.

---

## Klíčová rozhodnutí (potvrzena Annou)

1. Workflow stavy zprávy: **Nová / K řešení / Hotovo / Archiv / Koš**
   („Hotovo", ne „Zpracovaná"). Stavy „V analýze", „Analyzovaná" a
   „Chyba AI" z `docState` mizí — jsou to pipeline statusy.
2. Stav analýzy = nový sloupec `analysis_state` (přežije Koš i Archiv).
3. Ne-faktura po analýze **zůstává v Nové** a dashboard emituje
   **kartu per zpráva** s akcí Koš (žádné auto-zavření, žádný digest).
4. Read-only zámek formuláře se váže na `analysis_state = 20`
   (probíhající claim), ne na `docState`.
5. Migrace existujících dat se **neřeší** (dev DS se resetuje;
   `ds-upgrade` jen přidá sloupce s defaultem).
6. `primary_type` dostává sloupec `primary_type_source`
   (mailbox / user / ai); AI nikdy nepřepisuje hodnotu nastavenou uživatelem.

---

## Fáze A — Oddělení stavů (refactoring, beze změny AI chování)

### A1. Schéma

`core_mail_incoming_messages` — nové sloupce:

- `analysis_state` tinyint NOT NULL default 0, `enumInt`,
  cfgItem `core.mail.analysisStates`. Index `idx_analysis_state`
  (`analysis_state`, `received_at` ASC) — pokrývá frontu analyzeru.
- `primary_type_source` enumString(10) NOT NULL default `mailbox`
  (hodnoty `mailbox` / `user` / `ai`) — připraveno pro Fázi B,
  přidat rovnou, ať je jen jeden ALTER.

Nový config `modules/core/mail/config/analysisStates.jsonc`
(cfgItem `core.mail.analysisStates`):

| Kód | ID | cs | Význam |
|---|---|---|---|
| 0 | `none` | Bez analýzy | AI vypnutá / netýká se |
| 10 | `queued` | Ve frontě | čeká na analyzer |
| 20 | `analyzing` | Analyzuje se | aktivní claim (read-only guard) |
| 30 | `analyzed` | Analyzováno | výsledek uložen |
| 70 | `failed` | Analýza selhala | permanentní chyba, čeká na zásah |

Formát dle vzoru `extractedDocStates.jsonc` (name/name:cs/name:en,
stateStyle, icon, description, order). Styly: none=archive, queued=concept,
analyzing=edit, analyzed=done, failed=error.

### A2. Nový `docStatesIncoming.jsonc`

| Kód | cs | stateStyle | mainState | viewGroup | goto |
|---|---|---|---|---|---|
| 10 | Nová | concept | 1 | active | 20, 40, 80, 90 |
| 20 | K řešení | confirmed | 2 | active | 40, 10, 80, 90 |
| 40 | Hotovo | done | 3 | active, readOnly, closeForm | 80, 90, 10 |
| 80 | Archiv | archive | 4 | archive, readOnly, closeForm | 10, 90 |
| 90 | Smazáno | trash | 5 | trash, readOnly, closeForm | 10 |

Stavy 30 a 70 se z configu **odstraní**. Přechod 10→20 v praxi nastavuje
pipeline (result s dokumenty), ale nechává se i v `goto` pro manuální
použití. Zkontrolovat `MailController::resolveIncomingMainState`
(hardcoded fallback mapa) a všechny konstanty `DOC_STATE_*`
v `AnalysisController` / `IncomingMessageDocument` / feed source.

### A3. Přechody řízené pipeline (AnalysisController)

- **queue:** filtr `analysis_state = 10 AND docState NOT IN (80, 90)`
  + stávající `ai_analysis_enabled` podmínka. Koš/Archiv tedy zprávu
  přirozeně vyřadí z fronty; `docState` se jinak nekontroluje.
- **claim:** `analysis_state 10 → 20`. `docState` se **nemění**.
  Validace `INVALID_STATE` nově proti `analysis_state`.
- **result:** `analysis_state → 30`, vynulovat `needs_reanalysis`.
  `docState`: pokud vznikl aspoň jeden extracted document →
  `10 → 20` (K řešení); pokud `documents` prázdné → `docState`
  beze změny (zpráva zůstává v Nové — viz Fáze B, karta „Není faktura").
  POZOR: dnešní chování „prázdný result → docState 40" se tímto **ruší**.
  Pokud je zpráva už v jiném stavu než 10 (uživatel mezitím ruční zásah),
  docState nechat být — pipeline nikdy nepřepisuje ruční workflow.
- **failed:** `retryable=true` → `analysis_state 20 → 10`;
  `retryable=false` → `analysis_state 20 → 70`. `docState` beze změny.
- **reaper** (`mail-analysis-reap`): expirovaný claim →
  `analysis_state 20 → 10` (jen pokud je stále 20).
- **reanalyze:** validace `analysis_state ∈ {30, 70}` a
  `docState NOT IN (80, 90)`; nastaví `analysis_state = 10`,
  `needs_reanalysis = 1`, `profile_override`; supersede logika
  extracted docs beze změny. `docState` se **nemění**.

### A4. Auto-transition po review extracted docs

`ExtractedDocumentDocument::afterPersist` — auto-transition zprávy
se mění z 30→40 na **20 (K řešení) → 40 (Hotovo)** (když žádný
sourozenec není v 10/20/30). `ExtractedDocumentApplier::unapply` —
reverzní reconcile 40→20.

### A5. Vznik zprávy

`IncomingMessageDocument::beforeSave` / `MailController::receiveIncoming`:
při insertu nastavit `analysis_state = 10` pokud je AI analýza
dostupná/povolená, jinak 0. Import endpoint (Fáze 4) — importované zprávy
s `docState = 40` dostávají `analysis_state = 0` (neanalyzují se),
pokud request explicitně neřekne jinak.

### A6. Read-only guard

- Odebrat `readOnly` z workflow stavu (bývalá 20 „V analýze" už neexistuje).
- `IncomingMessagesForm` (příp. save pipeline v Document): odmítnout
  uložení, když `analysis_state = 20` — chybová hláška
  „Zpráva se právě analyzuje, počkejte na dokončení."

### A7. UI

- `IncomingMessagesViewer`: taby dle nové stavové mapy (řeší systém
  automaticky z cfgItem); do řádku a detailu přidat **badge stavu analýzy**
  (label + stateStyle z `core.mail.analysisStates`; hodnotu 0 nezobrazovat).
- Detail panel: akce „Znova analyzovat" podmíněná nově
  `analysis_state ∈ {30, 70}` (místo docState).
- `viewerDefaults.jsonc` / `viewerDetailLabels.jsonc` — zkontrolovat
  reference na zrušené stavy.
- Frontend beze změn kromě případného generického renderu badge
  (řádky formátuje server) — ověřit.

### A8. Dashboard feed (jen adaptace, nové karty až ve Fázi B)

`MailSuggestionsSource::errorCards`: zdroj chybových karet je nově
`analysis_state = 70 AND docState NOT IN (80, 90)` místo `docState = 70`.

---

## Fáze B — Klasifikace `primary_type` + karta „Není faktura"

### B1. Rozšíření kontraktu `POST /_mail/analysis/{ndx}/result`

Nové **volitelné** pole:

```json
"message_classification": {
  "primary_type": "other",
  "confidence": 0.97
}
```

Serverová logika (v transakci resultu):

- Validace `primary_type` proti klíčům `core.mail.primaryTypes`
  (včetně `enabled: false` typů — AI smí vracet jen enabled, viz prompt,
  server ale toleruje enabled-only; neznámý klíč → pole ignorovat
  a zalogovat warning, **ne** 422 — nesmí rozbít uložení výsledku).
- `UPDATE messages SET primary_type = ?, primary_type_source = 'ai'`
  **jen pokud** `primary_type_source != 'user'`.
- Pole chybí (starý analyzer) → žádná změna. Zpětná kompatibilita drží.

Aktualizovat `docs/mail/api-contract.md` §9.5.

### B2. `primary_type_source`

- `IncomingMessagesForm`: když uživatel změní `primary_type` ve formuláři,
  nastavit `primary_type_source = 'user'` (detekce dirty change
  v Document/Form hooku, ne v UI).
- Default z mailboxu / mail-routeru zůstává `mailbox`.

### B3. Prompt v2.2.0 (profil `czech_invoices`)

Úprava `profiles/default_czech_invoices.jsonc`:

- `prompt_version` → `v2.2.0` (a `source.promptVersion` v ukázce).
- Nový první krok v úkolu: **triage celé zprávy** — rozhodni, zda zpráva
  jako celek je fakturou/dobropisem, nebo něčím jiným (newsletter,
  neúplné podklady, obchodní sdělení…). Výsledek vrať v novém top-level
  poli `message_classification` (`primary_type` ∈ enabled hodnoty
  z `primaryTypes.jsonc`, dnes `invoiceReceived` | `other`; + `confidence`).
- **Explicitní zákaz** emitovat extracted dokumenty s `doc_type: "other"` —
  přílohy, které nejsou faktura/dobropis, do `documents` nepatří.
  Ne-faktura ⇒ `documents: []` + klasifikace `other`.
- `output_schema`: přidat `message_classification` (optional, required
  ponechat jen `overall_confidence` + `documents`); z enum `doc_type`
  v documents **odebrat** `"other"` a `"invoiceIssued"`.
- Enum typů v promptu zatím natvrdo; generování z `primaryTypes.jsonc`
  je future work (poznámka do docs).
- Hlídat `ProfileSchemaDriftTest` (inline kopie canonical schématu
  se nemění, mění se jen obal).
- Reload profilu na DS dělá Anna ručně: `bin/shpd-ds ai-profile-reload --force`.

### B4. Feed — karta „Není faktura" + úklid

`MailSuggestionsSource`:

1. **Pojistka:** `suggestionCards` ignoruje extracted docs
   s `doc_type = 'other'` (WHERE podmínka).
2. **Nový druh karty** (kind `info`, stateStyle `archive`/neutral):
   pro zprávy `analysis_state = 30 AND docState = 10 AND
   primary_type = 'other' AND` žádný extracted doc v 10/20/30.
   Titulek: „Není faktura" + label typu; podtitulek: subject + odesílatel
   + čas. Akce:
   - `trash_message` (primary) — Koš,
   - `archive_message` — Archiv,
   - `open_viewer` — otevřít v došlé poště.
3. **Chybové karty** (`analysis_state = 70`): kind `urgent` zůstává;
   pokud je `primary_type = 'other'` (klasifikace stihla proběhnout dřív,
   např. při reanalyze), degradovat na non-urgent — otevřená maličkost,
   implementovat jednoduše (kind `review`).

Frontend `Dashboard.svelte` (`handleCardAction`): nové action kinds
`trash_message` / `archive_message` → `PUT /_ui/form/core_mail_incoming_messages/save/{ndx}`
s `docState: 90` resp. `80`, optimistické odebrání karty + toast + reload
(vzor `applyFlow`). i18n klíče `dashboard.card.action.trash` /
`.archive` do `cs.js` + `en.js`.

### B5. Mimo rozsah

- UI akce „Změnit typ" z karty dashboardu (budoucnost).
- Dvoufázová pipeline s levným triage modelem (úspora nákladů — future).
- Souhrnná/digest karta pro velké objemy „Ostatní".
- Zapínání dalších `primaryTypes` (creditNote, order, …) — jen config,
  až bude potřeba.
- Změny v analyzer daemonu (`ai_analyzer` repo).
- Migrace historických dat.

---

## Testy a verifikace

- PHPUnit: přechody v `AnalysisController` (queue filtr vč. Koše,
  claim/result/failed nad `analysis_state`, prázdný result nechává
  docState, klasifikace respektuje `primary_type_source='user'`,
  neznámý typ = warning + ignore), reaper, reanalyze validace,
  auto-transition 20→40 + unapply 40→20, feed source (karta „Není faktura",
  ignorace `doc_type=other`, error karty z `analysis_state`).
- `php -l` na dotčené soubory, `vendor/bin/phpunit --filter '...'`
  (baseline šum: Opis validator failures v Exchange/Mail).
- Frontend: `cd frontend && timeout 90 npm run build` (timeout_sec 120).
- `ds-upgrade` spouští Anna ručně.

## Pořadí commitů (návrh)

1. `feat(mail): oddeleni analysis_state od docState` — schéma, configy,
   AnalysisController, Document/Form, reaper, viewer, testy (Fáze A).
2. `feat(mail): AI klasifikace primary_type v result endpointu` —
   kontrakt, primary_type_source, prompt v2.2.0, testy (B1–B3).
3. `feat(mail): dashboard karta "Neni faktura" + trash/archive akce` —
   feed source + frontend + i18n (B4).
4. `docs(mail): aktualizace api-contract a ai-analysis`.
