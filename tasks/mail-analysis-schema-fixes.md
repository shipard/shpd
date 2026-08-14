# Modul `mail` — Opravy AI analýzy: schéma, prompt, frontování podle docState

**Stav:** hotovo — schéma/prompt/frontování realizováno v ed59dfa (kind structured, nullable vat, pravidla promptu, docState gate + datová oprava v ds-upgrade); `schema_error` a ISDOC follow-up nahrazeny message-centric designem (daemon: error_type schema_error → /failed → analysis_state 70; server: forenzní wrapper nevalidního canonicalu v /result; ISDOC structured v IsdocImportService). Ověřeno 14. 8. 2026: testy zelené, alfa 0 nafrontovaných archivovaných zpráv.

**Cíl:** Odstranit dvě třídy problémů AI analýzy zjištěné diagnostikou
reálného provozu na alfě (14. 7. 2026):

**(A) `schema_error` selhání extrakce** — všech 6 selhaných analýz na
alfě (prompt v2.2.0) padá na validaci výstupu modelu proti
`output_schema`, ve třech opakujících se vzorech:

1. `attachments[].kind`: model u strojově čitelné přílohy (ISDOC XML)
   vrací `'structured'`/`'isdoc'`, enum zná jen
   `original/scan/supplement/preview/null` a prompt povolené hodnoty
   nikde nevyjmenovává (4 výskyty; zpráva DS B 6481 selhala 3×
   identicky).
2. `supplier.registration`: `Party` má `additionalProperties: false`
   a pro rejstříkový údaj pole `courtRegistration` — to ale chybí
   v ukázce v promptu, takže model klíč vymýšlí (3 výskyty).
3. `vat: null`: top-level `vat` je jediný významný ne-nullable objekt
   ve schématu; model místo vynechání vrátil `null` (1 výskyt).

**(B) Nekonzistentní fronta z importů** —
`IncomingMessageDocument::resolveInitialAnalysisState` ignoruje
`docState`: kouká jen na `ai_analysis_enabled` + existenci aktivního
AI profilu. Zrcadlení archivní pošty do DS, kde už profil existoval,
tak nafrontovalo tisíce zpráv (firma 11 127, DS B 268), které
`/queue` nikdy nevydá (filtruje Archiv/Koš) — trvale zavádějící stav
„Ve frontě" a latentní riziko hromadné analýzy při odarchivování.

**Návaznost:**

- Navazuje na `tasks/mail-phase3a.md` (AI analýza)
  a `tasks/mail-states-and-classification.md` (`analysis_state`).
- Souvisí s `tasks/mail-isdoc-import.md`: hotový ISDOC import stáhne
  ISDOC zprávy z AI úplně, ale schema opravy platí pro všechny faktury
  „jen v PDF". Drobný follow-up v `IsdocReader` (bod 3 rozhodnutí).
- Externí analyzer daemon (`ai_analyzer`) **nevyžaduje změny** —
  `prompt_template` i `output_schema` dostává v claim response
  z profilu (api-contract §claim); oprava profilu + reload se
  propaguje sama.
- Dokumentaci aktualizovat: `docs/exchange-format.md`,
  `modules/core/mail/docs/ai-prompts.md`,
  `modules/core/mail/docs/ai-analysis.md`.

---

## Klíčová rozhodnutí (potvrzena Annou 14. 7. 2026)

1. Do enum `attachments[].kind` přibude hodnota **`structured`**
   (strojově čitelná příloha — ISDOC, UBL, XML export). Model si ji
   spontánně vymýšlel — sémanticky ve výčtu chybí. Follow-up:
   `IsdocReader` u ISDOC přílohy nově emituje `kind: 'structured'`
   místo `'original'`.
2. Top-level `vat` bude nullable (`"type": ["object", "null"]`) —
   konzistence se zbytkem schématu. Prompt navíc posílí pravidlo
   „objekty nikdy nenahrazuj null, vynech je".
3. Frontování podle `docState`: nová zpráva dostane `analysis_state=10`
   jen když vzniká v docState **10 (Nová) nebo 20 (K řešení)**;
   Hotovo/Archiv/Koš (40/80/90) → 0. Explicitní `analysis_state`
   v import requestu má dál přednost (stávající chování).
4. Cleanup nafrontovaných archivních zpráv = **idempotentní datová
   oprava v `ds-upgrade`** (ne ruční SQL): opraví všechny DS včetně
   alfy při příštím upgradu.
5. Retry politika `schema_error` v analyzer daemonu se **teď neřeší**
   (patří do `ai_analyzer` repa, odloženo).
6. Nová verze promptu **v2.3.0**; po nasazení `ai-profile-reload
   --force` na dotčených DS (spouští Anna).

## Mimo scope

- Změny `ai_analyzer` daemonu (retry politika, promotion
  `message_classification` — trvá stávající server-side čtení
  z `analysis_json`).
- Backfill / hromadná reanalýza dříve selhaných zpráv — po nasazení
  ručně přes existující „Znova analyzovat" (viz Ověření na alfě).
- Generování enum typů z `primaryTypes.jsonc` (future work
  z mail-states-and-classification).

---

## Implementační kroky

### 1. Kanonické schéma (exchange)

`modules/core/exchange/schemas/shpd.docs.document.v1.json` **a**
`.jsonc` (obě kopie synchronně!):

- `$defs` přílohy: `"kind": { "enum": ["original", "scan",
  "supplement", "preview", "structured", null] }`.
- Top-level `vat`: `"type": ["object", "null"]`.

Ověřit, že `DocumentApplier` (a `previewExtracted`) zvládá `vat: null`
stejně jako chybějící `vat` — pravděpodobně už dnes přes `?? []`,
pokud ne, doplnit normalizaci `null → absent` na vstupu applieru.

### 2. Inline `output_schema` v profilu

`modules/core/mail/profiles/default_czech_invoices.jsonc` — tytéž dvě
změny v inline kopii schématu (řádky s `"kind"` enum a top-level
`"vat"`). Pozn.: inline kopie je zdroj pravdy pro analyzer daemon
(claim response), kanonická pro server-side validaci v `/result` —
rozjeté kopie = analyzer pustí výstup, který server zamítne.

### 3. Prompt v2.3.0

Tamtéž, `prompt_template` + pole `prompt_version`:

- Do PRAVIDEL doplnit:
  - `attachments[].kind` smí být POUZE: `original` (hlavní dokument),
    `scan` (sken/foto), `supplement` (doprovodný materiál), `preview`
    (náhled), `structured` (strojově čitelný formát — ISDOC, XML,
    UBL). Žádnou jinou hodnotu nevymýšlej.
  - Objekty (`vat`, `payment`, `customer`, …), které nelze určit,
    VYNECHEJ nebo vrať `null` — nikdy nevracej prázdné objekty
    s vymyšleným obsahem.
  - Do struktury nikdy nepřidávej klíče, které nejsou v ukázce
    (schéma má `additionalProperties: false`).
- Do ukázky `supplier` doplnit
  `"courtRegistration": "C 12345 vedená u Městského soudu v Praze"`
  (přesně tenhle údaj si model vymýšlel jako `registration`).
- Bump verze: pole `prompt_version` + **dva výskyty** `v2.2.0`
  v textu promptu (`source.promptVersion je "v2.3.0"` a hodnota
  v ukázkovém JSON).
- `modules/core/mail/docs/ai-prompts.md` — changelog v2.3.0.

### 4. Follow-up `IsdocReader`

`modules/core/exchange/src/Isdoc/IsdocReader.php`: canonical
`attachments[0].kind` = `'structured'` (dosud `'original'`).
Aktualizovat fixtures/testy + mapovací tabulku
v `tasks/mail-isdoc-import.md` (poznámkou) a případnou zmínku
v dokumentaci ISDOC importu.

### 5. Frontování podle docState

`modules/core/mail/src/IncomingMessageDocument.php`
(`resolveInitialAnalysisState`): před dotazem na profil vrátit
`ANALYSIS_NONE`, pokud `docState` v datech není 10 ani 20 (chybějící
`docState` = default Nová → frontovat). Konstanty docState už
v Document třídě jsou / doplnit.

Testy (`IncomingMessageDocumentTest`): docState 10/20 + profil → 10;
docState 40/80/90 + profil → 0; explicitní `analysis_state`
v datech má přednost (regrese import endpointu, spec
mail-states-and-classification: „Import s default docState=40
dostává 0" — nově platí pro 40 z pravidla, ne ze speciální větve;
ověřit, že speciální větev pro import jde sloučit).

### 6. Datová oprava v ds-upgrade

`modules/core/mail/src/AIAnalyzerProvisioner.php` — do `provision()`
přidat idempotentní krok:

```sql
UPDATE core_mail_incoming_messages
   SET analysis_state = 0
 WHERE analysis_state = 10 AND docState IN (80, 90)
```

Po prvním běhu no-op (WHERE nic nenajde). Zalogovat počet opravených
řádků do outputu upgradu. Pozn.: docState 40 (Hotovo) do WHERE
nepatří — zpráva mohla do Hotova dojít legálně workflow cestou
s dokončenou analýzou; stav 10+40 reálná data nemají.

### 7. Dokumentace

- `docs/exchange-format.md` — enum `kind` v §5 (přidat `structured`
  s vysvětlením), nullable `vat`.
- `modules/core/mail/docs/ai-analysis.md` — sekce „Stavy zprávy":
  pravidlo frontování rozšířit o podmínku docState 10/20.
- `modules/core/mail/docs/ai-prompts.md` — v2.3.0 (viz krok 3).

## Doporučené pořadí commitů

1. `fix(exchange): schema — kind structured, nullable vat` (kroky 1, 4)
2. `fix(mail): prompt v2.3.0 + output_schema profilu` (kroky 2, 3)
3. `fix(mail): analysis_state jen pro aktivni docState + data fix v ds-upgrade` (kroky 5, 6)
4. `docs(mail): opravy AI analyzy` (krok 7, pokud nejde po částech s 1–3)

## Akceptace

1. Canonical s `attachments[].kind='structured'` projde
   `SchemaValidator` (server i inline kopie profilu jsou identické —
   test diffem obou schémat, ideálně unit test porovnávající inline
   `output_schema` s kanonickým souborem).
2. Canonical s `vat: null` projde validací; apply/preview na takovém
   dokumentu nespadne.
3. Prompt v2.3.0: vyjmenované hodnoty `kind`, `courtRegistration`
   v ukázce, pravidlo o null objektech, verze bumpnutá na všech
   3 místech (pole + 2× text).
4. `IsdocReader` emituje `structured`; testy exchange zelené.
5. Nová zpráva v docState 10/20 s aktivním profilem → `analysis_state
   10`; v docState 40/80/90 → 0; explicitní hodnota v requestu vyhrává.
6. `ds-upgrade` na DS s nafrontovanými archivními zprávami je opraví
   (log počtu) a druhý běh je no-op.
7. `php -l`, PHPUnit (exchange + mail), frontend build zelené.

## Nasazení a ověření na alfě (po merge do stable)

1. `ds-upgrade` na všech 4 DS (datová oprava: očekávaný dopad
   firma ~11 127, DS B ~268 řádků; DS A a DS C 0).
2. `ai-profile-reload --force` na všech 4 DS (v2.3.0).
3. Reanalyze dříve selhaných zpráv přes UI („Znova analyzovat"):
   DS A 76960 (ISDOC kind), DS B 6481 (kind 3× retry),
   6490/6491/6493 (registration), 6497 (vat null) — všechny musí
   skončit v `analysis_state=30` bez `schema_error`.
4. Kontrola: `SELECT analysis_state, COUNT(*)` per DS — žádné
   `10` u docState 80/90.
