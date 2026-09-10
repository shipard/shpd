# Task: Strukturovaná pole se schématem (`json` + `schema`) — #74

**Stav:** PRD
**Issue:** #74 (rozhodnutí S1–S7), motivace #55 D19 (hlavička podání, profil podatele)
**Návaznost:** `src/Core` (Database, Form, Document, Viewer), `docs.core` jen jako
konzument typu; první reálný konzument `economy.vat` — **profil podatele** na registraci
k DPH (`economy_codebooks_vat_registrations.filing_profile`). Hlavička podání
(`economy_vat_filings.header`) dostane schéma per typ tvrzení až ve **F3**, tady jen
resolver hook, který to umožní. Prerekvizita M1 Fáze 3 (XML).

## Cíl

Obecný mechanismus pro strukturovaná data se schématem, které se mění podle typu
záznamu, země a času a která se nevyhledávají ani nejoinují: definice ve stejném
formátu jako sloupce tabulky, uložení v `json` sloupci s verzí schématu v datech,
editace běžným formulářem bez změn klienta, validace na serveru, generické zobrazení
v detailu vieweru. Nahrazuje `subColumns`/properties starého Shipardu (`e10doc/taxes`
`part1.json`, `reportsParts::subColumnsInfo`) — princip převzat, verze schématu
se **neodvozuje** z data záznamu.

Před implementací **přečti**:

- issue #74 celé; #55 komentář „Fáze 2" D19
- `docs/table-definitions.md` §6 (typ `json`, ruční serializace v `beforeSave`),
  `src/Core/Database/ColumnDefinition.php` (`fromArray`, `ALLOWED_TYPES`),
  `SchemaValidator.php`, `SchemaLoader.php`
- `docs/edit-forms.md` §§ 3–8 (`FormDefinition`, elementy, sekce/gridy, `triggers`,
  warningy, kontrakt `field`), `src/Core/Form/{FormElement,FormSection,TableForm,
  AutoFormBuilder,EnumOptionsHelper}.php`
- `src/Api/Controller/FormController.php`: `meta` (řádek s `decodeJsonColumns`),
  `save` → `initDocState` → `TableGateway::saveDocument`, `recalculate`
- `src/Core/Document/{Document,DefaultDocument,TableGateway,ValidationError,
  ValidationResult}.php` — kam patří validace a serializace, aby platila pro **každý**
  zápisový kanál (formulář, API, applier), ne jen pro formulář
- `src/Core/Config/ConfigCompiler.php` + `I18n/ConfigLocalizer` (cfgItem klíč
  `<modul>.<soubor>`, lokalizace `name:cs`/`name:en`)
- `src/Core/Viewer/TableViewer.php::renderDetail` (typ `properties`) a
  `modules/economy/codebooks/src/CashDesksViewer.php` jako vzor detailu
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc` +
  `VatRegistrationsForm/Viewer` (první konzument)
- old_shipard: `modules/e10doc/taxes/config/cz-tax-ci/2021/part1.json` (`fields.columns`,
  `groups`, `enumCfg`) a `tables/reportsParts.php::subColumnsInfo` — referenční tvar,
  ne architektura

## Rozhodnutí (S1–S7 z #74 + implementační I1–I6)

- **S1** Úložiště = existující `json` sloupec + atribut definice `"schema": "<cfgItem>"`.
  Hodnoty se neagregují, nejoinují, nejsou cílem FK. Kdo potřebuje hledat, dá relační sloupec.
- **S2** Schéma = formát sloupců tabulky bez SQL sémantiky; skupiny = sekce formuláře /
  bloky detailu.
- **S3** Verze v datech: `"_schema": "<cfgItem>/<version>"`. Nový záznam dostane schéma
  z resolveru; uložený se čte podle vlastní `_schema`.
- **S4** Formulář přes virtuální sloupce `<sloupec>.<pole>` — flatten při `meta`/`recalculate`,
  unflatten při ukládání; klient beze změn.
- **S5** Generický detail (`properties`).
- **S6** Fáze 1 = ploché pole; tabulky řádků uvnitř pole až s konkrétní potřebou (DPPO).
- **S7** Umístění `src/Core/StructuredFields/`.
- **I1 — Formát schématu (cfgItem soubor, `modules/<m>/<n>/config/<Name>.jsonc`):**
  ```jsonc
  {
      "version": "2026",                   // část _schema; změna = nová verze souboru
      "groups": [ { "id": "subject", "name:cs": "Daňový subjekt", "name:en": "Taxpayer" } ],
      "fields": [
          { "id": "typ_ds", "type": "enumString", "length": 1, "cfgItem": "economy.vat.filingSubjectTypes",
            "group": "subject", "name:cs": "Typ subjektu", "required": true },
          { "id": "c_ufo", "type": "int", "group": "office", "name:cs": "Finanční úřad", "cfgItem": "world.cz.taxOffices" },
          { "id": "email", "type": "varchar", "length": 255, "inputType": "email", "group": "contact", "name:cs": "E-mail" }
      ]
  }
  ```
  Povolené `type`: `varchar` (`length`), `text`, `int`, `numeric` (`precision`, `scale`),
  `date`, `boolean`, `enumInt`/`enumString` (`cfgItem`). Dále `required`, `default`,
  `hint`, `inputType` (podmnožina `FormElement::ALLOWED_INPUT_TYPES`), `readOnly`.
  Jiný klíč = chyba kompilace (fail loudly). Validátor schémat běží v `ConfigCompiler`
  pro každý cfgItem, na který ukazuje nějaký `schema` atribut.
- **I2 — Resolver:** `ColumnDefinition.schema` je **statický** klíč (fáze 1: profil
  podatele). Pro dynamický výběr podle záznamu (hlavička podání per typ, F3) má
  `Document` hook `structuredSchemaFor(string $column, array $data): ?string`
  (default = statický atribut). Čtení uloženého záznamu jde vždy podle `_schema`
  v datech; pokud chybí (staré/importované záznamy), použije se resolver a
  `_schema` se doplní při dalším uložení.
- **I3 — Jedna autorita zápisu:** unflatten → validace → `_schema` → `json_encode`
  provádí `TableGateway::saveDocument` **před** `Document::beforeSave` (obecný krok pro
  všechny sloupce se `schema`, i pro `DefaultDocument`), stejně jako derivace stavů —
  žádný zápisový kanál nesmí obejít. `beforeSave` konzumenta pak dostane
  `$data['<sloupec>']` jako **pole** (dekódované), gateway ho po hooku znovu serializuje.
  Prázdné pole (jen `_schema`, vše null) → sloupec NULL, ne `{}`.
- **I4 — Chyby:** field-level `ValidationError(column: "<sloupec>.<pole>")` — kontrakt
  `field` = shoda s `column` elementu, takže klient chybu přiřadí sám. Typové chyby
  (int/numeric/date parse, délka, enum mimo cfgItem, `required`) blokují; nic
  „chytrého" (žádná koerce mimo trim a číselný parse).
- **I5 — Formulář:** `StructuredFieldFormBuilder::elements(schema, data, prefix)` vrací
  `FormElement[]` (`separator` per skupina, `input`/`select` per pole, `options` z
  `EnumOptionsHelper`), které konzument vloží do libovolné sekce/tabu. `TableForm`
  dostane pohodlnou metodu `structuredFieldElements(string $column, array $data)`.
  `AutoFormBuilder` (tabulky bez vlastního formuláře) přidá sloupec se schématem jako
  vlastní sekci automaticky.
- **I6 — Detail:** `StructuredFieldRenderer::properties(schema, value, lang)` →
  `{title, items:[{label, value}]}[]` pro typ `properties`; enum přes cfgItem label,
  date/numeric lokalizovaně, prázdné položky se vynechávají; neznámé klíče v datech
  (pole zrušené novější verzí) se zobrazí pod syrovým klíčem, nezahazují se.

## Scope

### 1. `src/Core/StructuredFields/`

- `StructuredSchema` (value object: version, groups, fields; `fromCfgItem(ConfigRuntime, key)`;
  `key()` = `<cfgItem>/<version>`), `StructuredSchemaValidator` (I1, pro `ConfigCompiler`),
  `StructuredFieldValues` (flatten/unflatten s prefixem `<sloupec>.`, dekódování string/pole,
  `isEmpty`), `StructuredFieldValidator` (I4 → `ValidationError[]`),
  `StructuredFieldFormBuilder` (I5), `StructuredFieldRenderer` (I6),
  `StructuredFieldResolver` (I2: statický atribut + Document hook).
- Čisté třídy, bez DB; ConfigRuntime jen pro cfgItem lookup.

### 2. Definice tabulek

- `ColumnDefinition`: `public readonly ?string $schema` (jen pro `type: json`, jinak
  `InvalidArgumentException`). `SchemaValidator`/`ds-upgrade`: cfgItem ze `schema`
  musí existovat v kompilovaném configu (chyba upgradu, ne warning).
- `ConfigCompiler`: pro každý referencovaný `schema` cfgItem spustí
  `StructuredSchemaValidator`; lokalizace `name:*` skupin a polí přes `ConfigLocalizer`
  jako u ostatních cfgItems.

### 3. Zápis a čtení

- `TableGateway::saveDocument`: krok „structured fields" (I3) před `Document::beforeSave`;
  po hooku serializace zpět. `Document::structuredSchemaFor()` hook s defaultem.
- `FormController::meta` a `recalculate`: po `decodeJsonColumns` flatten sloupců se
  `schema` (`StructuredFieldValues::flatten`) — klient vidí `header.c_ufo`; `save`
  posílá plochá data dál, unflatten dělá gateway (I3), ne controller.
- `DefaultDocument` nic navíc — hook v gateway pokrývá i tabulky bez Document třídy.

### 4. Formulář a detail

- `TableForm::structuredFieldElements()`, `AutoFormBuilder` auto-sekce.
- `TableViewer`: pomocná `structuredFieldProperties(column, row)` pro `renderDetail`.

### 5. První konzument — profil podatele (economy.vat, #55 D19)

- `economy_codebooks_vat_registrations.filing_profile` `json` nullable,
  `"schema": "economy.vat.filingProfileCz"`.
- `modules/economy/vat/config/filingProfileCz.jsonc` (I1): skupiny subjekt / finanční
  úřad / adresa / kontakt / sestavil / zástupce; pole dle atributů `VetaP` v DPHDP3/
  DPHKH1/DPHSHV, které starý Shipard skutečně plnil (`typ_ds`, `c_ufo`, `c_pracufo`,
  `c_okec`, `naz_obce`, `ulice`, `c_pop`, `c_orient`, `psc`, `stat`, `email`, `c_telef`,
  `id_dats`, `sest_jmeno`, `sest_prijmeni`, `sest_telef`, `zast_kod`, `zast_typ`,
  `zast_nazev`, `zast_jmeno`, `zast_prijmeni`, `zast_ic`, `zast_dat_nar`, `zast_ev_cislo`)
  — **přesný seznam a enumy (`typ_ds` P/F, `zast_typ`) potvrdit proti XSD ve F3**;
  tady stačí, aby schéma šlo naplnit tím, co je v starých `properties`.
  Číselníky FÚ (`c_ufo`) a pracovišť (`c_pracufo`) jako cfgItems `world.cz.taxOffices`
  / `world.cz.taxOfficeBranches` — přenést ze starého `e10doc/taxes/config` (statická data,
  mimo DS).
- `VatRegistrationsForm`: tab „Podací údaje" = `structuredFieldElements('filing_profile')`;
  `VatRegistrationsViewer::renderDetail`: záložka z `StructuredFieldRenderer`.
- Import ze starého: **mimo scope** (profil zadá uživatel; hodnoty ze starých `properties`
  jako doporučení v helpu).

### 6. Testy

- `StructuredSchemaValidatorTest`: platné schéma; neznámý klíč, neznámý typ, `varchar`
  bez `length`, `enum*` bez `cfgItem`, duplicitní `id`, pole s neexistující skupinou →
  výjimka s cestou.
- `StructuredFieldValuesTest`: flatten/unflatten round-trip, prázdné → null, `_schema`
  se zachová, neznámé klíče se zachovají.
- `StructuredFieldValidatorTest`: required, délka, int/numeric/date parse, enum mimo
  cfgItem → `ValidationError` s `column = "<sloupec>.<pole>"`; validní projde.
- `StructuredFieldFormBuilderTest`: elementy per skupina, `select` s `options`,
  `inputType`, `readOnly`.
- `StructuredFieldRendererTest`: properties, enum label, prázdné vynechány, cizí klíč
  zobrazen.
- `TableGatewayStructuredFieldsTest` (integrační nad testovací tabulkou): uložení přes
  gateway serializuje s `_schema`; `beforeSave` vidí pole; API zápis bez formuláře
  validuje stejně; záznam se starší `_schema` se načte podle své verze.
- Konzument: `VatRegistrationsForm` obsahuje tab s elementy `filing_profile.*`;
  uložení přes formulář a přes API dá stejný JSON.
- E2E (Playwright, dev DS 4l3j): otevřít registraci k DPH → tab Podací údaje → vyplnit,
  uložit, chyba `required` se zobrazí u pole; detail ve vieweru ukáže skupiny.
  **Ověřit S4**: klient s klíčem obsahujícím tečku v datech i `field` chyb — pokud selže,
  změnit oddělovač na `__` (rozhodnout v prvním commitu, ne později).

### 7. Dokumentace

- Nový `docs/structured-fields.md`: kdy použít (a kdy ne — hranice S1), formát schématu,
  verze, resolver hook, formulář/detail, chyby, příklad profilu podatele.
- `docs/table-definitions.md` §6: atribut `schema`, odkaz; poznámka o ruční serializaci
  se u sloupců se schématem ruší (dělá gateway).
- `docs/edit-forms.md`: virtuální sloupce a kontrakt `field`.
- `modules/economy/vat/docs/README.md`: profil podatele (D19), co doplní F3.
- `tasks/README.md`, `docs/README.md` neaktualizuj (David).

## Mimo scope

- Tabulky řádků uvnitř pole (`recordSetTable`, S6 fáze 2).
- Hlavička podání se schématem per typ (F3) — tady jen hook I2.
- Import profilu ze starých `properties`.
- Vyhledávání/filtrování podle hodnot ve strukturovaném poli (nikdy — S1).
- Migrace existujících `json` sloupců (snapshoty, alerty) na schéma — nemají ho mít.

## Commity

1. `StructuredSchema` + validátor + `ColumnDefinition.schema` + `ConfigCompiler`/`SchemaValidator`
   + testy; **rozhodnutí o oddělovači** (`.` vs `__`) zapsané v docblocku.
2. `StructuredFieldValues` + `StructuredFieldValidator` + krok v `TableGateway` + hook
   `Document::structuredSchemaFor` + `FormController` flatten + testy.
3. `StructuredFieldFormBuilder` + `TableForm::structuredFieldElements` + `AutoFormBuilder`
   + `StructuredFieldRenderer` + `TableViewer` helper + testy.
4. Konzument: `filing_profile` + `filingProfileCz.jsonc` + číselníky FÚ + formulář/detail
   registrace + E2E.
5. Dokumentace.

## Hotovo když

- [ ] Testy zelené (schéma, values, validátor, builder, renderer, gateway).
- [ ] `ds-upgrade` na dev DS 4l3j přidá `filing_profile`; nekompilovatelné schéma
      (neznámý klíč) upgrade zastaví s cestou k chybě.
- [ ] Registrace k DPH: tab Podací údaje se edituje, `required` chyba u pole, uložení
      zapíše JSON s `_schema: "economy.vat.filingProfileCz/2026"`, detail ve vieweru
      zobrazí skupiny; totéž přes API bez formuláře.
- [ ] Záznam s ručně upravenou starší `_schema` se načte a zobrazí podle své verze.
- [ ] `docs/structured-fields.md` existuje a F3 (`tasks/vat-filing-xml.md`) na ně může
      odkázat pro `economy_vat_filings.header`.
