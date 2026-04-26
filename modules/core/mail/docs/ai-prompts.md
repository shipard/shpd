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

## Default prompt

```
Jsi asistent pro zpracování došlé pošty českých firem. Pro každou přílohu
zprávy rozhodni, zda jde o přijatou fakturu, dobropis nebo jiný dokument.
U faktury extrahuj hlavičku (dodavatel, IČO, DIČ, č. faktury, var. symbol,
data, celková částka, měna, způsob platby, č. účtu) a položky (popis, množství,
MJ, cena, sazba DPH). U dobropisu navíc odkaz na opravovanou fakturu. Pole,
která nelze určit, vynech (neuhaduj). Vrať JSON podle output_schema. confidence
0–1 vyjadřuje tvou jistotu kvality extrakce.
```

Prompt je úmyslně konzervativní — preferuje "vynech pole" před "uhádni".

## Output schema

JSON Schema draft-07. Hlavní struktura:

```json
{
  "type": "object",
  "required": ["overall_confidence", "documents"],
  "properties": {
    "overall_confidence": { "type": "number", "minimum": 0, "maximum": 1 },
    "documents": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["doc_type", "source_attachment_ndxs", "confidence", "fields"],
        "properties": {
          "doc_type": { "enum": ["invoiceReceived", "creditNote", "other"] },
          "source_attachment_ndxs": { "type": "array", "items": { "type": "integer" } },
          "confidence": { "type": "number" },
          "fields": { ... }
        }
      }
    }
  }
}
```

Plné schéma viz [profiles/default_czech_invoices.jsonc](../profiles/default_czech_invoices.jsonc).

## Customization guidelines

### Přidání nového typu dokumentu

1. Přidej klíč do `core.mail.extractedDocTypes`
   ([config/extractedDocTypes.jsonc](../config/extractedDocTypes.jsonc)).
2. V profilu rozšiř `supported_doc_types` (JSON pole klíčů).
3. V `prompt_template` doplň pravidla pro nový typ.
4. V `output_schema` rozšiř enum `doc_type` a přidej fields-section pro nový typ.
5. Bumpni `prompt_version` (`v1.0.0` → `v1.1.0`).

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

1. Nech analyzer projet existující sadu zpráv s aktuálním promptem (read-only,
   neaplikuj výsledky).
2. Manuálně review confidence + extracted fields proti groundtruth.
3. Uprav `prompt_template`, bumpni `prompt_version`.
4. V UI klikni "Znova analyzovat" na vybraných zprávách — vznikne nový run,
   staré extracted_documents se označí `superseded`, applied/rejected zůstávají.
5. Porovnej kvalitu před / po (`message_analyses.prompt_version` umožňuje
   filtrovat).

### Jinak vybraný backend per profil

Pole `backend` v profilu je FK na `core_mail_ai_backends`. Můžeš mít víc
backendů (`default` Anthropic Claude Sonnet pro běžné případy, druhý backend
s Claude Opus pro náročné dokumenty) a přiřadit je různým profilům.

## Reference

- [ai-analysis.md](ai-analysis.md) — architektura
- [profiles/default_czech_invoices.jsonc](../profiles/default_czech_invoices.jsonc)
