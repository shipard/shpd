# AI canonical — source.extractedAt razítkuje server, ne model

**Stav:** hotovo
**Repo:** nov_shipard

## Cíl

V AI extrakcích je `source.extractedAt` hodnota, kterou vymyslel model —
typicky opsaná z ukázky v promptu. Ověřený případ: PLECA analýza
(DS `4l3j`, analysis id 14, vytvořeno 16. 8. 2026) nese
`extractedAt: "2025-01-09T10:30:00Z"` — datum z ukázkového JSONu šablony.
Přes `DocumentApplier::mapExtractedAt` se propisuje do
`source_extracted_at` vystaveného dokladu jako falešná provenance.

Čas extrakce je serverový fakt, ne extrahovaný údaj — model ho nemá co
vyplňovat. Server ho přepíše vlastním časem bez ohledu na to, co přišlo.

## Návaznost

- `src/Api/Controller/AnalysisController.php` —
  `validateAndStoreCanonical()` (~ř. 975): jediné místo ukládání AI
  canonicalu (docs i registry větev přes
  `validateAndStoreRegistryCanonical`).
- `modules/core/exchange/src/Isdoc/IsdocReader.php:107` — vzor:
  `'extractedAt' => date(DATE_ATOM)` v okamžiku čtení. ISDOC cesta se
  NEMĚNÍ (její čas je autentický).
- `modules/core/exchange/src/Document/DocumentApplier.php` —
  `mapExtractedAt()` beze změny (tolerantní k null/nevalidnímu vstupu).
- Prompt šablona (`czech_general.jsonc`) se NEMĚNÍ — ať model pole
  posílá nebo ne, server ho přepíše; odstraňování z ukázky/schema není
  potřeba a šetří to reload profilů.

## Scope

### 1. `AnalysisController::validateAndStoreCanonical()` — D12

Na začátku metody (před schema validací a před větvením na registry),
pokud je `$extractedJson['source']` pole:

```php
$extractedJson['source']['extractedAt'] = date(DATE_ATOM);
```

- Nepodmíněný přepis (i validně vypadající hodnota od modelu je
  nedůvěryhodná).
- Formát `DATE_ATOM` — konzistence s `IsdocReader` a se schematem
  (date-time).
- Chybí-li `source` úplně nebo není pole, nerazítkovat — takový
  canonical stejně spadne do `_validationError` wrapperu a forenzní
  obsah se nemá dotvářet.
- Razítko proběhne PŘED enrichmentem — uložený canonical ho nese vždy,
  i když enrichment selže.
- Implementační ověření: registry větev — pokud
  `shpd.registry.document.v1` pole `source.extractedAt` nezná/nepovoluje,
  razítkovat jen v docs větvi (rozhodne schema, ne dohad).

### 2. Testy

- Unit/integrace `AnalysisController` (úroveň stávajících testů
  /result): canonical s `extractedAt: "2025-01-09T10:30:00Z"` → uložený
  `canonical_json` nese serverový čas (≠ vstup, parsovatelné DATE_ATOM,
  aktuální ±minuty).
- Canonical bez `source` → beze změny chování (wrapper, žádná mutace
  `_rawOutput`).
- ISDOC cesta bez regrese (stávající testy).
- PHPUnit úzký `--filter`, `timeout_sec: 120`.

## Hotovo když

- [x] AI canonical v DB nese serverový `source.extractedAt` bez ohledu
      na výstup modelu (docs větev; registry schéma `source` nezná —
      razítkuje se jen docs, dle rozhodnutí „rozhodne schema")
- [x] ISDOC cesta beze změny
- [x] Testy zelené
- [ ] Ověření na dev DS: reanalyze libovolné zprávy → `extractedAt`
      odpovídá času analýzy (ruční krok — spouští live LLM analýzu)

## Commit strategie

1. `fix(mail): server-side stamp source.extractedAt v AI canonicalu` (vč. testů)

## Potvrzená rozhodnutí

- **D12** — `source.extractedAt` v AI canonicalu razítkuje server
  v `validateAndStoreCanonical()`; hodnota od modelu se nepodmíněně
  přepisuje; ISDOC cesta beze změny. (potvrzeno 17. 8. 2026)
