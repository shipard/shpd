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

## Default prompt (v2.0.0)

Od `v2.0.0` AI vrací data přímo v kanonickém **`shpd.docs.document.v1`**
formátu (viz [`docs/exchange-format.md`](../../../../docs/exchange-format.md))
v poli `documents[].fields`. Předchozí ad-hoc shape (`supplier.ico`,
`invoice_number`, `vat_breakdown[]`, `line_items[]` …) byl nahrazen
canonical strukturou, aby `core.exchange` Applier mohl výstup uložit bez
další transformační vrstvy.

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
    "documents": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["doc_type", "source_attachment_ndxs", "confidence", "fields"],
        "properties": {
          "doc_type": { "enum": ["invoiceReceived", "invoiceIssued", "creditNote", "other"] },
          "source_attachment_ndxs": { "type": "array", "items": { "type": "integer", "minimum": 0 } },
          "confidence": { "type": "number" },
          "fields": { /* inline shpd.docs.document.v1 schema */ }
        }
      }
    }
  }
}
```

**`fields` je inline kopie** `modules/core/exchange/schemas/shpd.docs.document.v1.json`.
Analyzer (`/claim` response) dostává `output_schema` napřímo — neumí
`$ref` resolve napříč souborům, takže canonical schéma musí být doslovně
embedded. Drift mezi profilem a canonical souborem hlídá test
[`tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php`](../../../../tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php) —
selže s odkazem na regeneraci, pokud někdo updatuje jedno a zapomene
druhé.

Plné schéma viz [`profiles/default_czech_invoices.jsonc`](../profiles/default_czech_invoices.jsonc).

## Customization guidelines

### Přidání nového typu dokumentu

1. Přidej klíč do `core.mail.extractedDocTypes`
   ([config/extractedDocTypes.jsonc](../config/extractedDocTypes.jsonc)).
2. V profilu rozšiř `supported_doc_types` (JSON pole klíčů).
3. V `prompt_template` doplň pravidla pro nový typ.
4. V `output_schema.documents[].doc_type` enum přidej nový klíč. Pole
   `fields` zůstává jednotné napříč typy — canonical formát je polymorfní
   podle `fields.docType`, nepotřebuje per-typ branch v output_schema.
5. Bumpni `prompt_version` (`v2.0.0` → `v2.1.0`).

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
4. Z DS adresáře spusť reload do DB:
   ```
   bin/shpd-ds ai-profile-reload [--dry-run]
   ```
   - `--dry-run` ukáže, co se změní, bez zápisu.
   - Bez `--force` příkaz odmítne stejnou nebo nižší verzi šablony než v DB
     (ochrana proti náhodnému downgrade nebo přepisu admin tweaků se
     zapomenutým bumpem).
   - `--force` přepíše i při stejné/nižší verzi.
   - Reload **nepřepisuje** `name`, `is_default`, `is_active`, `backend` —
     admin si je může lokálně upravit.
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

## Reference

- [ai-analysis.md](ai-analysis.md) — architektura
- [profiles/default_czech_invoices.jsonc](../profiles/default_czech_invoices.jsonc)
