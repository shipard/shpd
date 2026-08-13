# Ruční nahrání došlé pošty z dashboardu

**Stav:** hotovo

Implementováno 2026-08-13 (backend + frontend, D1–D8). Zbývá ruční smoke
v prohlížeči (drag-n-drop, picker, oba módy, chybová cesta).
**Cíl:** Uživatel nahraje soubory (drag-n-drop nebo file picker) přímo
z dashboardu; vznikne jedna nebo více došlých zpráv s přílohami a zprávy
se automaticky zařadí do fronty AI analýzy. Odpadá oklika přes formulář
došlé pošty a postupné nahrávání příloh.

## Návaznost

- `docs/dashboard.md` — feed, hlavička, toast vzor, FormDialog.
- `docs/mail/api-contract.md` §2, §5 — transakční intake vzor
  (`MailController::receiveIncoming`): INSERT zprávy + upload příloh
  v jedné tx, rollback + `cleanupOrphanedFiles`.
- `docs/attachments.md` — `AttachmentService::upload`, limity
  (nginx 128M / PHP `upload_max_filesize` 128M, `post_max_size` 130M,
  `max_file_uploads` default 20).
- `IncomingMessageDocument::beforeSave` — `message_id`, default
  `primary_type` ze schránky, `resolveInitialAnalysisState` (docState 10
  + aktivní profil → `analysis_state=10`). Frontování analýzy tedy
  **neřešíme** — vyplyne ze správného založení zprávy.
- `tasks/mail-isdoc-import.md` — deterministický ISDOC import
  (`runIsdocImport`, post-commit, nikdy neshodí intake).

## Rozhodnutí (schválená)

- **D1** — Tlačítko **„Nahrát"** v hlavičce dashboardu (vedle Obnovit)
  otevře `MailUploadModal`. Drag-n-drop kamkoli na plochu dashboardu
  **neukládá rovnou** — otevře tentýž modal s předvyplněnými soubory.
  Mód se vždy potvrzuje v modalu.
- **D2** — Mód = segmented control v modalu: „Jedna zpráva" /
  „Každý soubor zvlášť". Default **„Každý soubor zvlášť"**; poslední
  volba se pamatuje v `localStorage` (`shpd_mail_upload_mode`).
  Při jediném souboru se přepínač skryje (módy jsou ekvivalentní).
- **D3** — Backend: nový endpoint `POST /_mail/messages/upload`
  (multipart, běžný uživatelský token). Celá dávka v **jedné transakci**
  (all-or-nothing), rollback + unlink orphan souborů dle vzoru
  `receiveIncoming`.
- **D4** — Defaulty zprávy (modal žádná další pole nemá):
  `mailbox` = default schránka; `subject` = název souboru bez přípony
  (single mód s N>1 soubory: `"{první soubor} (+N−1)"`);
  `sender_email`/`sender_name` = přihlášený uživatel (fallback níže);
  `received_at` = now; `source_type = 1`; `docState = 10`;
  `primary_type` = default schránky; `analysis_state` z `beforeSave`.
- **D5** — Po úspěchu toast „Nahráno N zpráv, zařazeno do AI analýzy"
  + refetch feedu. Karta se objeví až po doběhnutí analýzy — pásmo
  „analyzuje se" feed nemá (budoucí rozšíření).
- **D6** — Bez MIME whitelistu; `.eml` je v1 běžná příloha. Strop
  **20 souborů** per dávka (client-side i server-side, 422
  `TOO_MANY_FILES`). Velikost hlídají existující nginx/FPM limity.
- **D7** — Odloženo: párování PDF+`.isdoc` dle basename; parsing `.eml`;
  checksum dedup warning napříč zprávami; mailbox/typ picker v modalu;
  počítadlo „V analýze (N)" na dashboardu.
- **D8** — Ruční upload sdílí **post-commit ISDOC cestu**: po commitu
  dávky se pro každou vzniklou zprávu s ISDOC kandidátem zavolá
  `runIsdocImport` (stejné chování jako e-mailová cesta). Sender rules
  pre-triage se **přeskakuje** (odesílatel = přihlášený uživatel,
  matching nedává smysl).

## Scope

**Backend:** nová akce `MailController::uploadMessages`, route
`POST /_mail/messages/upload`, testy.
**Frontend:** `MailUploadModal.svelte`, dashboard drag-n-drop overlay,
tlačítko Nahrát, API wrapper, i18n cs+en.
**Mimo scope:** vše z D7; změny schématu (žádné nejsou — `ds-upgrade`
není potřeba).

## API kontrakt

```
POST /api/v1/_mail/messages/upload
Authorization: Bearer shpd_st_… (běžný uživatelský token; admin api_key OK)
Content-Type: multipart/form-data

Fields:
  mode:           "single" | "perFile"   (povinné)
  attachments[]:  1..20 souborů          (povinné, ≥1; název pole kvůli
                                          reuse collectAttachmentFiles())
```

Úspěch — 201:

```json
{
  "success": true,
  "data": {
    "mode": "perFile",
    "messages": [
      { "ndx": 123, "message_id": "MSG-20260813-0004", "subject": "Faktura-CEZ" }
    ]
  }
}
```

Chyby: `401 UNAUTHORIZED` (chybí token / analyzer klíč `_ai_analyzer`
sem nesmí — endpoint je pro UI), `422 VALIDATION_ERROR` (chybí soubory,
neplatný `mode`, žádná default schránka), `422 TOO_MANY_FILES`,
`500 INTERNAL_ERROR` (rollback + unlink proběhl).

## Změny po souborech

### `src/Api/Router.php`

- `resolveMailMessagesRoute()`: subpath `/_mail/messages/upload` (POST)
  → `Route('mail', 'uploadMessages')` — **před** parsováním `{ndx}`
  (literál `upload` není numerický ndx; guard explicitně, ať nevznikne
  404/parse chyba).

### `src/Api/Controller/MailController.php`

Nová akce `uploadMessages(AuthContext $auth, Request $request): Response`:

1. **Auth**: `$auth->isAuthenticated`, jinak 401. Session token i api_key
   OK (vzor `apply`/`reject` v MessagesController — sjednotit dle
   skutečného vzoru user-auth mail akcí). Uživatel `_ai_analyzer` /
   `_mail_router` → 403 (endpoint patří UI).
2. **Vstup**: `mode` ∈ {`single`, `perFile`}, jinak 422. Soubory přes
   `collectAttachmentFiles()` — už existuje, čte `$_FILES['attachments']`;
   pole formuláře pojmenovat `attachments[]` pro reuse beze změny.
   0 souborů → 422; > 20 → 422 `TOO_MANY_FILES`.
3. **Sender resolve**: `SELECT email, full_name FROM core_system_users
   WHERE id = $auth->userId`. Když e-mail chybí nebo neprojde
   `FILTER_VALIDATE_EMAIL` → fallback `email_address` default schránky;
   `sender_name` = full_name uživatele (fallback login/NULL).
4. **Mailbox**: `resolveMailbox('')` — default schránka (existující
   helper, 422 když chybí).
5. **Plán zpráv**: `single` → 1 zpráva se všemi soubory, subjekt
   `basename` prvního souboru bez přípony, při N>1 `" (+{N−1})"`;
   `perFile` → N zpráv, subjekt = basename souboru bez přípony.
   Prázdný basename (např. soubor `.pdf`) → `(bez předmětu)`.
6. **Transakce** (jedna pro celou dávku): pro každou plánovanou zprávu
   `insertIncomingMessage($fields, $mailboxId, $auth->userId, null)` —
   `$fields` s `source_type=1`, `received_at=now`, body/external pole
   NULL, `is_bulk=0`; `matchedRule=null` (D8 — žádná pre-triage).
   Pak `AttachmentService::upload` pro každý soubor zprávy; neúspěch →
   `RuntimeException` → rollback + `cleanupOrphanedFiles` (sbírat
   `$uploadedFiles` napříč celou dávkou). `raw_source_attachment` se
   **neplní** — žádný `.eml` originál neexistuje (viz tabulková doc:
   sloupec je nullable, panel Originál se prostě nezobrazí).
7. **Post-commit**: pro každou zprávu `runIsdocImport($messageId,
   $contentAttachmentsOfThatMessage)` (D8) — helper existuje, jen mu
   předat správné podmnožiny uploadů per zpráva.
8. **Response 201** dle kontraktu výše.

Poznámky: `insertIncomingMessage` dnes čte `$fields[...]` klíče, které
manuální cesta nemá — doplnit NULL hodnoty do `$fields`, signaturu
neměnit. `docState` neuvádět (DB default 10 → `beforeSave` zafrontuje
analýzu, pokud existuje aktivní profil).

### `frontend/src/api/mail.js`

- `uploadMailMessages(files, mode)` — multipart POST na
  `/_mail/messages/upload` (`FormData`: `mode`, N× `attachments[]`);
  bearer token vzor `attachments.js::uploadAttachment`. Vrací parsed JSON.

### `frontend/src/components/dashboard/MailUploadModal.svelte` (nový)

- `Modal` (ui/Modal.svelte), props `{ open, initialFiles, onClose,
  onUploaded }`.
- Obsah: dropzone (vzor `AttachmentPanel.svelte` — `dragOver` state,
  `ondragover/ondragleave/ondrop`) + tlačítko „Vybrat soubory" (skrytý
  `<input type="file" multiple>`), seznam vybraných souborů (název,
  velikost přes `formatFileSize` z `api/attachments.js`, křížek pro
  odebrání), segmented control módu (skrytý při ≤1 souboru; init
  z `localStorage`, zápis při změně), footer: „Nahrát" (disabled bez
  souborů / během odesílání) + „Zrušit".
- Client-side strop 20 souborů — nadlimitní se nepřidají + inline hláška.
- Submit: `uploadMailMessages` → success → `onUploaded(count)`; error →
  inline chybová hláška (`translateError`), modal zůstává otevřený.
- Duplicitní přidání téhož souboru (name+size) do výběru se ignoruje.

### `frontend/src/components/dashboard/Dashboard.svelte`

- Hlavička: tlačítko „Nahrát" (ikona upload — ověřit v `icons.js`,
  případně přidat) vedle Obnovit → `uploadModal = { open, files: [] }`.
- Drag-n-drop na plochu: `ondragover/ondrop` na `.shpd-dashboard`
  (jen když modal není otevřený); během dragu poloprůhledný overlay
  „Přetáhněte soubory pro nahrání do došlé pošty"; drop → otevřít modal
  s `initialFiles` z `event.dataTransfer.files`. Filtrovat
  ne-soubory (drag textu apod. overlay nespouští — kontrola
  `dataTransfer.types` obsahuje `Files`).
- `onUploaded(count)`: zavřít modal, `showToast({ kind: 'uploaded',
  message: t('dashboard.toast.uploaded', { count }) })`, `load()`.
  Správné skloňování počtu (1 zpráva / 2–4 zprávy / 5+ zpráv) — vzor
  existujících pluralizací v i18n.

### `frontend/src/i18n/cs.js`, `en.js`

Nové klíče (názvy sjednotit s konvencí souboru):
`dashboard.upload.button`, `dashboard.upload.title`,
`dashboard.upload.dropHint`, `dashboard.upload.selectFiles`,
`dashboard.upload.modeSingle`, `dashboard.upload.modePerFile`,
`dashboard.upload.submit`, `dashboard.upload.tooMany`,
`dashboard.upload.dropOverlay`, `dashboard.toast.uploaded` (plurály).
`npm run check:i18n` z `frontend/` musí projít.

## Testy

PHPUnit (narrow `--filter`, integrační vyžadují
`SHIPARD_INTEGRATION_DS_PATH`):

- `perFile`: 3 soubory → 3 zprávy, každá 1 příloha; `source_type=1`,
  `docState=10`, `analysis_state=10` (s aktivním profilem),
  `message_id` sekvence, subjekty z názvů souborů.
- `single`: 3 soubory → 1 zpráva se 3 přílohami, subjekt
  `"{první} (+2)"`.
- `analysis_state=0`, když v DS není aktivní AI profil.
- Sender fallback: uživatel bez e-mailu → adresa default schránky.
- 0 souborů → 422; 21 souborů → 422 `TOO_MANY_FILES`; neplatný `mode`
  → 422; bez tokenu → 401.
- Rollback: simulované selhání uploadu (např. neexistující tmp soubor
  u 2. souboru) → žádná zpráva v DB, žádné orphan soubory na disku.
- ISDOC: upload `.isdoc` kandidáta → `runIsdocImport` se zavolá
  (postačí ověření přes existující vzor testů ISDOC importu).

Frontend: `npm run check:i18n` zelené; ruční smoke (drag-n-drop,
picker, oba módy, chybová cesta).

## Commit strategie

1. Backend: route + `uploadMessages` + testy.
2. Frontend: API wrapper + `MailUploadModal` + integrace do Dashboardu
   + i18n.

## Hotovo když

- [x] `POST /_mail/messages/upload` funguje pro oba módy, transakčně,
      s user tokenem; `_mail_router`/`_ai_analyzer` klíč dostane 403.
- [x] Zprávy vznikají s defaulty dle D4 a s aktivním profilem se samy
      frontují (`analysis_state=10`).
- [x] ISDOC kandidát z ručního uploadu projde deterministickým importem
      (D8).
- [x] Tlačítko Nahrát + drag-n-drop na dashboard otevírají modal;
      drop nikdy nevytváří zprávy bez potvrzení (D1).
- [x] Přepínač módu: default „Každý soubor zvlášť", persistence
      v localStorage, skrytý při 1 souboru (D2).
- [x] Toast s pluralizací + refetch feedu po úspěchu (D5).
- [x] Stropy: 20 souborů client- i server-side (D6).
- [x] PHPUnit testy zelené (narrow filter), `npm run check:i18n` zelené.
