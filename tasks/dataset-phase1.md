# Datové sady — fáze 1: `dataset-dump` + `dataset-seed`

**Stav:** připraveno k implementaci

## Kontext / Cíl

Mechanismus přenosných datových sad dle issue **shipard/shpd#40** — rozhodnutí
D1–D5 v komentáři issue (2026-08). Pilotní konzument: demonstrační sada pro
web (`shpd-web/docs/demo-vyroba.md`, D8; obsah dle scénářů S1/S2/S5
v `shpd-web/docs/demo-scenare.md`).

Fáze 1 = dva CLI příkazy:

- **`shpd-ds dataset-dump <dir>`** — vyexportuje obsah DS do složky sady
  (výměnné formáty + PDF přílohy + manifest).
- **`shpd-ds dataset-seed <dir|zip>`** — zresetuje DS (`ds-reset`) a naplní
  ho obsahem sady standardní import cestou (applieři).

Mimo rozsah fáze 1: scénářové akce, expected výsledky + report, AI eval
(live mode — seedne se jen zpráva a analýza se pustí naostro), `dateMode:
relative`, filtry dumpu, banka a saldokonto. Architektura na ně myslí
(manifest má `dateMode` a strukturu pro rozšíření), implementují se později.

## Potvrzená rozhodnutí (viz #40)

- **D1** — sada = stávající výměnné formáty; import přes appliery,
  export = dump do týchž formátů.
- **D2** — došlá pošta nese snapshot analýz; fáze 1 implementuje jen
  *snapshot mode* (analýza se importuje jako data, žádné volání AI).
- **D3** — sada = přenosná složka (zazipovatelná), nezávislá na úložišti;
  nástroje v `shpd`.
- **D4** — manifest má `dateMode`; fáze 1 podporuje jen `fixed`
  (jiná hodnota → chyba „not implemented").
- **D5** — první sada = demo webu; vzniká ručním naplněním demo DS na alfě
  → `dataset-dump`.

Dodatečné volby (potvrzeno v chatu): názvy `dataset-dump`/`dataset-seed`;
seed defaultně resetuje (s potvrzením), `--no-reset` doplní do existujícího
DS (use case „metodika"); dump bere **celý DS** bez filtrů; rozsah entit =
osoby, položky, doklady, dokumenty spisovny, došlá pošta + analýzy + přílohy.

## Před implementací přečti

- `docs/exchange-format.md` §3–4 (architektura, apply lifecycle),
  `docs/exchange-format-persons.md`, `docs/exchange-format-items.md`
- Applieři: `modules/core/exchange/src/Document/DocumentApplier.php`,
  `.../Person/PersonApplier.php`, `.../Item/ItemApplier.php`,
  `modules/base/registry/src/RegistryApplier.php`
- Schémata: `modules/core/exchange/schemas/*.jsonc`,
  `modules/base/registry/schemas/shpd.registry.document.v1.json`
- `src/Command/DataSource/DsResetCommand.php` (delegace na ds-upgrade,
  `enableReset`, potvrzení) a `DsUpgradeCommand.php`
- `src/Command/DataSource/SeedPersonsCommand.php` (vzor seed příkazu,
  registrace v CLI)
- `modules/core/mail/tables/core_mail_incoming_messages.md`,
  `core_mail_message_analyses.md` (message-centric model: `canonical_json`,
  `analysis_json`, metadata běhu)
- `modules/core/attachments/src/FileStorage.php` + `AttachmentService.php`
  (uložení souborů do `att/`, vazba `core_attachments_files`)
- `tasks/docs-import-number-mode.md` (zachování čísel dokladů při importu)

## Struktura sady

```
sada/
  manifest.jsonc
  persons/*.jsonc        # shpd.persons.person.v1
  items/*.jsonc          # shpd.items.item.v1
  docs/*.jsonc           # shpd.docs.document.v1
  registry/*.jsonc       # shpd.registry.document.v1
  mail/
    messages/*.jsonc     # shpd.mail.incomingMessage.v1 (nový, viz níže)
    attachments/<soubor>  # binárka přílohy, jméno = obsah pole v message souboru
```

Soubory pojmenované deterministicky (např. `docs/0001-<docNumber>.jsonc`),
aby dump → seed → dump dával identický výsledek (diffovatelnost sady v gitu).

### `manifest.jsonc`

```jsonc
{
  "format": "shpd.dataset.v1",
  "name": "web-demo",           // identifikátor sady
  "title": "Demo webu — den účetní",
  "description": "…",
  "dateMode": "fixed",          // fáze 1: jen "fixed"
  "created": "2026-08-26T10:00:00Z",
  "counts": { "persons": 12, "items": 8, "docs": 15, "registry": 4, "mail": 6 }
}
```

`counts` je informativní (kontrola úplnosti při seedu — nesouhlas = warning).

### `shpd.mail.incomingMessage.v1` (nový formát)

Sadová reprezentace došlé zprávy. Nemá plnohodnotný resolve aparát jako
ostatní formáty — je to primárně dump/restore obal; schema soubor patří do
`modules/core/mail/schemas/`. Obsah:

- hlavičky zprávy (from, to, subject, received_at, message_id…),
  tělo (text/html), `docState`, `primary_type`
- `attachments[]` — pro každou přílohu: původní jméno, mime, odkaz na
  soubor v `mail/attachments/`, příznak `raw_source_attachment`
- `analyses[]` — snapshot běhů: `model_name`, `model_version`,
  `prompt_version`, `analyzed_at`, `status`, `confidence`,
  `analysis_json` (vnořený objekt), `canonical_json` (vnořený objekt —
  je to přímo `shpd.docs.document.v1` / `shpd.registry.document.v1`),
  `proposed_type`, `content_tag`, `resolution` + spol.

Interní ID se nikam nepropisují; vazba analýza→zpráva je dána vnořením,
vazba doklad→zdrojová zpráva (lineage `source_message`) se při seedu
obnoví přes `message_id` (viz krok 5).

## Kroky implementace (commit per krok)

### 1. Manifest + čtečka/zapisovačka sady

`modules/core/exchange/src/Dataset/` — `DatasetManifest`, `DatasetReader`
(složka i zip), `DatasetWriter`. Validace manifestu (format, dateMode).
Unit testy (vzor: existující exchange testy).

### 2. Exportery

Nová třída per entita vedle applierů: `PersonExporter`, `ItemExporter`,
`DocumentExporter` (modules/core/exchange), `RegistryExporter`
(modules/base/registry). DB záznam → canonical dle schématu; reference
externě (partner přes country+companyId, ne interní ID) — zrcadlo resolve
logiky. Exportuje se **celý DS** (všechny nearchivované záznamy;
`docState != 90`). Round-trip unit testy: canonical → apply → export →
shodný canonical (na polích, která formát nese).

### 3. Mail formát + export pošty

Schema `shpd.mail.incomingMessage.v1` v `modules/core/mail/schemas/`.
Export zprávy vč. analýz a příloh (binárky z `att/` přes `FileStorage`).

### 4. `dataset-dump`

`src/Command/DataSource/DatasetDumpCommand.php`. Argument: cílová složka
(`--zip` pro rovnou zabalení). Orchestruje exportery + mail export +
manifest. Pořadí a pojmenování souborů deterministické.

### 5. `dataset-seed`

`src/Command/DataSource/DatasetSeedCommand.php`. Argument: složka nebo zip.

Průběh: validace sady → potvrzení → `ds-reset` (delegace jako u ds-upgrade;
respektuje `enableReset` guard; `--no-reset` krok přeskočí) → import
v pořadí **persons → items → docs/registry → mail**. Doklady a dokumenty
spisovny přes appliery (čísla dokladů se zachovávají — režim explicitního
čísla). Zprávy + analýzy se zapíší přímo (INSERT přes TableGateway,
`canonical_json`/`analysis_json` beze změn), přílohy přes
`AttachmentService`/`FileStorage` do `att/`. Lineage: pokud canonical
dokladu nese odkaz na zdrojovou zprávu, po importu pošty se doplní
`source_message` podle `message_id`.

Na konci souhrn (počty vs. `counts` z manifestu) a nenulový exit code
při jakékoli chybě importu.

### 6. Round-trip test + dokumentace

Integrační test: seed sady → dump → porovnání se zdrojovou sadou
(normalizovaný diff). Dokumentace: nový `docs/datasets.md` (formát sady,
manifest, mail formát, oba příkazy, odkaz na #40), doplnit `docs/cli.md`
(sekce Dataset u `shpd-ds`), zmínka v `docs/exchange-format.md` §1
(dataset jako další konzument). Průběžně s kroky, ne až na konci.

## Hotovo když

- [ ] `dataset-dump` na ručně naplněném demo DS (alfa) vyprodukuje sadu —
      složku čitelnou v gitu (JSONC + PDF)
- [ ] `dataset-seed` na čistém DS sadu naimportuje; obsah DS je shodný
      s původním (osoby, položky, doklady vč. čísel a zaúčtování,
      spisovna, pošta s analýzami a funkčními náhledy příloh)
- [ ] opakovaný seed téže sady = identický výsledek (determinismus)
- [ ] `dataset-seed --no-reset` doplní obsah do existujícího DS
- [ ] dump → seed → dump dává byte-shodnou sadu (modulo `created`
      v manifestu)
- [ ] round-trip testy zelené, `composer test` prochází
      (PHPUnit s úzkým `--filter`)
- [ ] dokumentace: `docs/datasets.md`, `docs/cli.md`, zmínka
      v `docs/exchange-format.md`
