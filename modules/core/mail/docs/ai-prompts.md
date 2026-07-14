# AI prompty — default profil + guidelines pro customizaci

## Default profil `czech_invoices`

Šablona: [profiles/default_czech_invoices.jsonc](../profiles/default_czech_invoices.jsonc).
Při prvním `ds-upgrade` z ní `AIAnalyzerProvisioner` vytvoří záznam v
`core_mail_ai_profiles`. Pozdější editace probíhá přímo v DB / UI; soubor
v repu není zdroj pravdy pro běžící DS.

### Pole profilu

| Pole | Popis |
|---|---|
| `profile_id` | Lidský identifikátor (`czech_invoices`) |
| `name` | UI název |
| `language` | ISO 639-1 (`cs`) — řídí jazyk uživatelských textů v promptu |
| `prompt_version` | SemVer (`v1.0.0`) — manuálně bumpuj při netriviální změně promptu |
| `prompt_template` | Vlastní text promptu pro analyzer |
| `output_schema` | JSON Schema, proti kterému analyzer validuje výstup providera |
| `supported_doc_types` | JSON pole klíčů z `core.mail.extractedDocTypes` |
| `confidence_thresholds` | `{"ready": 0.9, "review": 0.6}` — řídí mapping confidence → status extrahovaného dokumentu |

Audit běhu: každý `core_mail_message_analyses` row si propíše `profile_ndx`,
`backend_ndx` a `prompt_version`, takže historie je auditovatelná i po pozdějších
změnách profilu.

## Default prompt (v3.0.0)

Od `v2.0.0` AI vrací data přímo v kanonickém **`shpd.docs.document.v1`**
formátu (viz [`docs/exchange-format.md`](../../../../docs/exchange-format.md))
v poli `documents[].extracted_json`. Předchozí ad-hoc shape (`supplier.ico`,
`invoice_number`, `vat_breakdown[]`, `line_items[]` …) byl nahrazen
canonical strukturou, aby `core.exchange` Applier mohl výstup uložit bez
další transformační vrstvy. Od `v3.0.0` vrací registry typy (smlouvy,
pojistky, nabídky, revize, úřední písemnosti) ve formátu
**`shpd.registry.document.v1`** — cíl je Spisovna (`base.registry`),
apply jde přes `RegistryApplier`.

Klíčové pokyny v promptu:

- Pole, která AI nedokáže určit, vynechej (neuhaduj).
- Datumy ISO 8601 `YYYY-MM-DD`, měny ISO 4217 uppercase (`CZK`), země
  ISO 3166-1 alpha-2 lowercase (`cz`).
- `selfParty` vždy `"customer"` (jsme příjemce přijaté faktury).
- `source.kind` vždy `"aiExtraction"`, `source.promptVersion` vždy
  shodná s `prompt_version` profilu (`v2.0.0`).
- VAT kódy v řádcích jsou klíče z `world.vat.{country}.vatCodes`
  cfgItem (`cz-110`, `cz-111`, …) — ne sazby v procentech.
- Když žádná příloha není dokladem, vrať `documents: []`.

Plný prompt v [`profiles/default_czech_invoices.jsonc`](../profiles/default_czech_invoices.jsonc)
sekce `prompt_template`.

## Output schema

JSON Schema **draft-2020-12** (od `v2.0.0`; dřív draft-07). Wrapper:

```json
{
  "type": "object",
  "required": ["overall_confidence", "documents"],
  "additionalProperties": false,
  "properties": {
    "overall_confidence": { "type": "number", "minimum": 0, "maximum": 1 },
    "message_classification": { /* primary_type + confidence, enum enabled typů */ },
    "documents": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["doc_type", "source_attachment_ndxs", "confidence", "extracted_json"],
        "properties": {
          "doc_type": { "enum": ["invoiceReceived", "creditNote", "contract", "insurance", "quotation", "certificate", "official"] },
          "source_attachment_ndxs": { "type": "array", "items": { "type": "integer", "minimum": 0 } },
          "confidence": { "type": "number" },
          "extracted_json": {
            "oneOf": [
              { /* inline shpd.docs.document.v1 schema */ },
              { /* inline shpd.registry.document.v1 schema */ }
            ]
          }
        }
      }
    }
  }
}
```

**`extracted_json` je od `v3.0.0` oneOf dvou inline kopií** — struktura se
volí podle **targetu** typu dokumentu (cfgItem `core.mail.extractedDocTypes`):

- target `docs` (faktury, dobropisy) →
  `modules/core/exchange/schemas/shpd.docs.document.v1.json`,
- target `registry` (smlouvy, pojistky, nabídky, revize, úřední
  písemnosti — Spisovna) →
  `modules/base/registry/schemas/shpd.registry.document.v1.json`.

Analyzer (`/claim` response) dostává `output_schema` napřímo — neumí
`$ref` resolve napříč souborům, takže obě canonical schémata musí být
doslovně embedded. Drift mezi profilem a canonical soubory hlídá test
[`tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php`](../../../../tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php) —
selže s odkazem na regeneraci, pokud někdo updatuje jedno a zapomene
druhé. Shodu `kindFields` registry schématu s `base.registry.docKinds`
hlídá `tests/Unit/Module/Base/Registry/RegistrySchemaDriftTest.php` —
názvy polí se **nikdy nesmí lišit** (analyzer plní kindFields přesně dle
schématu; přejmenované pole = tiché prázdno v metadatech).

Plné schéma viz [`profiles/default_czech_invoices.jsonc`](../profiles/default_czech_invoices.jsonc).

## Customization guidelines

### Přidání nového typu dokumentu

1. Přidej klíč do `core.mail.extractedDocTypes`
   ([config/extractedDocTypes.jsonc](../config/extractedDocTypes.jsonc))
   včetně `target` (`docs` / `registry`; registry typy navíc `docKind`
   z `base.registry.docKinds`) a párový klíč do `primaryTypes.jsonc`.
2. V profilu rozšiř `supported_doc_types` (JSON pole klíčů).
3. V `prompt_template` doplň pravidla pro nový typ — u registry typů
   **vyjmenuj přesné názvy `kindFields`** dle `docKinds.fields`
   (nesoulad = tiché prázdno, hlídá `RegistrySchemaDriftTest` +
   `ProfileSchemaDriftTest::testPromptEnumeratesKindFieldsExactly`).
4. V `output_schema.documents[].doc_type` enum přidej nový klíč a zvol
   větev `extracted_json.oneOf` podle **targetu** typu: docs typy jedou
   přes `shpd.docs.document.v1` (polymorfní dle `docType`, bez per-typ
   branche), registry typy přes `shpd.registry.document.v1` (nový druh =
   nová if/then větev `kindFields` v registry schématu + kopie embedu).
5. Bumpni `prompt_version` (`v3.0.0` → `v3.1.0`).

### Vlastní profil pro jiný jazyk / účel

1. Vytvoř nový řádek v `core_mail_ai_profiles` se `profile_id` (např.
   `english_invoices`), `language=en`, vlastním promptem a schématem.
2. Pokud má být default DS, ostatní `is_default` ručně shoď — invariant
   "max 1 default profile per DS" vynucuje aplikační validace, nikoli DB.

### Tweak thresholdů

`confidence_thresholds` řídí, do jakého stavu (`ready_to_apply` / `pending_review`
/ `low_confidence`) se po extrakci dostane dokument. Přísnější DS by mohlo mít
`{"ready": 0.95, "review": 0.75}`. Změna je živá — projeví se až u nových
analýz.

### Iterativní ladění promptu

Workflow pro ladění promptu z JSONC šablony v repu (zdroj pravdy pro
default profil):

1. Uprav `modules/core/mail/profiles/default_czech_invoices.jsonc` —
   `prompt_template`, případně `output_schema`, `confidence_thresholds`,
   `supported_doc_types`, `language`.
2. Bumpni `prompt_version` (semver, např. `v1.1.0` → `v1.2.0`).
3. Commit do gitu, deploy.
4. Z DS adresáře spusť `bin/shpd-ds ds-upgrade` — sync profilu ze šablony
   je součástí provisioning fáze (`[UPDATE] profile 'czech_invoices':
   v1.1.0 → v1.2.0`). Jen upgrade — se stejnou verzí je no-op, s novější
   verzí v DB vypíše `[WARN]` a nic nepřepíše (ochrana proti náhodnému
   downgrade nebo přepisu admin tweaků se zapomenutým bumpem).

   Pro speciální případy zůstává manuální příkaz:
   ```
   bin/shpd-ds ai-profile-reload [--dry-run] [--force] [--template-path=...]
   ```
   - `--dry-run` ukáže, co se změní, bez zápisu.
   - `--force` přepíše i při stejné/nižší verzi (vědomý downgrade).
   - `--template-path` reload z jiné šablony než výchozí.

   Sync ani reload **nepřepisují** `name`, `is_default`, `is_active`,
   `backend` — admin si je může lokálně upravit.
5. V UI klikni "Znova analyzovat" na vybraných zprávách — vznikne nový run,
   staré `extracted_documents` se označí `superseded`, applied/rejected
   zůstávají. Případně re-queue přes SQL.
6. Porovnej kvalitu před / po (`message_analyses.prompt_version` umožňuje
   filtrovat).

Analyzer čte prompt z DB při každém claimu, takže reload neovlivní právě
běžící zpracování — promítne se až do nových claimů po reload.

### Jinak vybraný backend per profil

Pole `backend` v profilu je FK na `core_ai_backends`. Můžeš mít víc
backendů (`default` Anthropic Claude Sonnet pro běžné případy, druhý backend
s Claude Opus pro náročné dokumenty) a přiřadit je různým profilům.

## Changelog promptu

### v3.0.0 (2026-07-14)

Spisovna Fáze 2 — extrakce registry typů
([tasks/registry-phase2.md](../../../../tasks/registry-phase2.md),
design [docs/registry-mvp.md](../../../../docs/registry-mvp.md) §7):

- Nové `doc_type` hodnoty `contract`, `insurance`, `quotation`,
  `certificate`, `official` (target `registry` → dokument Spisovny);
  `primary_type` enum rozšířen o tytéž klíče.
- `documents[].extracted_json` je nově **oneOf**
  [`shpd.docs.document.v1`, `shpd.registry.document.v1`] — registry typy
  vrací `{schema, docType, title, summary, party, kindFields,
  binderSuggestion}`.
- Prompt vyjmenovává přesné názvy `kindFields` per druh (dle
  `base.registry.docKinds`), `summary` 2–3 věty v jazyce profilu,
  `binderSuggestion` volitelný obecný název šanonu.

### v2.3.0 (2026-07-14)

Opravy tří opakujících se vzorů `schema_error` z reálného provozu (spec
[tasks/mail-analysis-schema-fixes.md](../../../../tasks/mail-analysis-schema-fixes.md)):

- `attachments[].kind`: enum ve schématu rozšířen o `structured` (strojově
  čitelná příloha — ISDOC, XML, UBL) a prompt povolené hodnoty nově
  vyjmenovává — model si `structured`/`isdoc` dřív vymýšlel sám.
- Nové pravidlo: objekty (`vat`, `payment`, `customer`, …), které nelze
  určit, vynechat nebo vrátit `null` — nikdy prázdné objekty s vymyšleným
  obsahem. Top-level `vat` je ve schématu nově nullable (konzistence se
  zbytkem schématu).
- Nové pravidlo: nepřidávat klíče mimo ukázku (`additionalProperties:
  false`). Ukázka `supplier` doplněna o `courtRegistration` — model si pro
  rejstříkový údaj dřív vymýšlel klíč `registration`.

### v2.2.0

- Triage celé zprávy: top-level `message_classification`
  (`primary_type` + `confidence`), viz
  [ai-analysis.md](ai-analysis.md).

### v2.0.0

- Výstup přímo v kanonickém `shpd.docs.document.v1` (dřív ad-hoc shape),
  output schema draft-2020-12.

## Reference

- [ai-analysis.md](ai-analysis.md) — architektura
- [profiles/default_czech_invoices.jsonc](../profiles/default_czech_invoices.jsonc)
