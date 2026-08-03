# Spisovna — import endpoint `POST /_registry/import`

**Stav:** hotovo

## Kontext

Migrace starého modulu `wkf.docs` (Dokumenty) do Spisovny — design
`docs/registry-mvp.md` §10, migrační rozhodnutí M1–M4 potvrzena (viz task
`old_shipard: modules/imports/newShipard/tasks/18-registry-import.md`,
který je spotřebitelem tohoto endpointu).

Dokumenty Spisovny nelze pro import zakládat přes generický CRUD ani přes
běžný Document flow beze změn: import potřebuje **zachovat historické
`created`** (audit hook by ho přepsal), **explicitní cílový `docState`**
(40/70/80/10 dle staré evidence, ne vznik v Konceptu) a **idempotenci**
(dedupe podle legacy identity při opakovaném běhu). Stejný důvod, proč
vznikl `POST /_mail/import` (`tasks/mail-phase4-import-endpoint.md`) —
tento endpoint je jeho analogie pro Spisovnu.

## Návaznost

- **Vzor:** `MailController::importMessage` (`POST /_mail/import`) — auth
  konvence (api key `_legacy_importer`), tvar chyb, styl validace.
- **Staví na:** Fáze 1 Spisovny (tabulky, `RegistryDocumentDocument`),
  `TableGateway::saveDocument` (centrální odvození `docStateMain`).
- **Spotřebitel:** `RegistryRunner` v migračním pipeline (old_shipard task
  18). Přílohy endpointem **netečou** — nahrává je obecný attachments
  klient (07a) na tableId 428 po založení dokumentu.
- Payload je `shpd.registry.document.v1` rozšířený o import blok
  (design §10.2) — s tím rozdílem, že import posílá přímo `docKind`
  (klíč `base.registry.docKinds`), ne analyzer `docType`.

## Před implementací přečti

- `docs/registry-mvp.md` §10 (mapování + mechanika).
- `src/Api/Controller/MailController.php::importMessage` — auth, validace,
  response konvence.
- `src/Api/Controller/RegistryController.php` — sem přibude metoda.
- `modules/base/registry/src/RegistryDocumentDocument.php` — beforeSave
  (audit + promote sync); **ověř, jak zapsat historické `created`** —
  pokud ho audit hook přepisuje, zvol cestu (import režim / explicitní
  update po insertu); výsledkem musí být zachované staré datum.
- `src/Api/Router.php` — větev `/_registry/` existuje (Fáze 1).

## Scope

**V rozsahu:** endpoint, validace, dedupe, testy, zmínka v README modulu.
**Mimo rozsah:** runner (old_shipard task 18), přílohy (07a klient),
jakékoli UI.

## Specifikace

`POST /api/v1/_registry/import` — auth jako `/_mail/import`.

Payload (JSON):

```jsonc
{
    "schema": "shpd.registry.document.v1",
    "docKind": "contract",              // klíč base.registry.docKinds, povinný
    "title": "…",                        // povinný, neprázdný
    "binder": "Smlouvy",                 // volitelný — název živého šanonu
    "notice": "…",                       // volitelný (starý `text`)
    "validFrom": "2019-01-01",           // volitelné (starý model je skoro nemá)
    "validTo": null,
    "docState": 40,                      // 10 | 40 | 70 | 80, default 40
    "created": "2013-09-10T14:21:30+02:00",  // povinný, ISO 8601
    "legacy": {                          // povinný blok
        "ndx": 123,                      // povinný — starý wkf_docs_documents.ndx
        "id": "SML-001",                 // volitelný — starý documentId
        "kind": "Smlouva",               // původní název druhu
        "author": "Jana Nováková",       // jméno starého autora (osoby)
        "folder": "Mzdy / 1999"          // plná stará cesta složky
    }
}
```

Chování:

1. **Validace:** `docKind` existuje v cfg; `title` neprázdný;
   `docState ∈ {10, 40, 70, 80}`; `created` parsovatelné;
   `legacy.ndx` int > 0. Chyba → 422 s kódem pole.
2. **Dedupe (idempotence):** existuje-li dokument (mimo Koš)
   s `metadata->>'$.legacyNdx'` = `legacy.ndx` a `source_kind='import'`
   → **200 `{id, existed: true}`**, žádný zápis. (Objemy ≤ tisíce řádků,
   JSON scan bez indexu je v pořádku; kdyby ne, generated column později.)
3. **Šanon:** `binder` se resolvuje case-insensitive na živé šanony;
   nenalezený → `binder=NULL` + `warning: "BINDER_NOT_FOUND"` v response
   (endpoint šanony **nezakládá** — to je práce runneru před dokumenty).
4. **Zápis:** řádek přes `TableGateway::saveDocument` (`docStateMain`
   odvodí gateway): title, doc_kind, binder, notice, valid_from/to,
   `source_kind='import'`, `source_message=NULL`,
   `metadata = {legacyNdx, legacyId, legacyKind, legacyAuthor,
   legacyFolder}`, `created` = historické (viz ověření výše),
   `created_by=NULL`, cílový `docState` z payloadu (vznik v 10 + přechod
   v téže transakci, pokud automat vynucuje — vzor Fáze 2).
5. **Response:** `201 {id}` / `200 {id, existed}` (+ `warning?`).

## Testy

- happy path (40 i 70), zachované `created`, docStateMain odvozený;
- dedupe: druhé volání se stejným `legacy.ndx` → `existed`, beze změn;
- neznámý `docKind` / prázdný title / špatný `docState` → 422;
- `BINDER_NOT_FOUND` warning + NULL binder;
- metadata obsahují kompletní legacy blok.

PHPUnit úzkým `--filter` (RegistryImport).

## Hotovo když

- [ ] endpoint funguje dle specifikace vč. dedupe a zachovaného `created`
- [ ] auth shodná s `/_mail/import`
- [ ] testy zelené; README modulu zmiňuje endpoint
- [ ] `docStateMain` konzistentní se stavem (odvozeno centrálně)
