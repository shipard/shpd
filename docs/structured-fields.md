# Strukturovaná pole se schématem

Mechanismus pro **strukturovaná data se schématem, které se mění podle typu
záznamu, země a času** — podle issue [shipard/shpd#74](https://github.com/shipard/shpd/issues/74)
(rozhodnutí S1–S7 a implementační I1–I6). Nahrazuje `subColumns`/properties
starého Shipardu: princip je převzatý, verze schématu se ale **neodvozuje**
z data záznamu, nese ji hodnota.

Kód žije v [`src/Core/StructuredFields/`](../src/Core/StructuredFields/),
první konzument je **profil podatele** na registraci k DPH (sekce 9).

## 1. Kdy použít a kdy ne

Použij, když platí **všechno** z toho:

- sada polí se mění podle typu záznamu, země nebo formuláře úřadu
  (hlavička podání DPH per typ tvrzení, profil podatele, výkazy dle
  vyhlášky, parametry podání do registrů);
- hodnoty jsou **opis, ne zdroj pravdy pro výpočty** — nikdo je neagreguje,
  nejoinuje a nejsou cílem FK;
- editují se ručně ve formuláři a zobrazují v detailu.

Nepoužívej, když:

- podle hodnoty se **hledá, filtruje nebo počítá** → dej jí relační sloupec
  (`JSON_EXTRACT` pro jednorázový dotaz je k dispozici, ale schéma na to
  není stavěné a nikdy nebude — rozhodnutí S1);
- hodnota má být cílem cizího klíče;
- jde o **citlivý údaj** (klíč, token, heslo) → typ `encrypted_text`
  a [operations/secrets.md](operations/secrets.md); strukturované pole se
  ukládá v plaintextu a `inputType: "password"` schéma odmítne;
- jde o data, která existují jako **tabulka řádků** → child tabulka.
  Tabulky uvnitř strukturovaného pole (staré `recordSetTable`) jsou fáze 2
  rozhodnutí S6, dnes je pole v hodnotě chyba validace.

Existující `json` sloupce (snapshoty dokladů, payloady alertů) schéma
**nemají mít** — nejsou to uživatelsky editovaná data.

## 2. Deklarace sloupce

Úložiště je obyčejný sloupec typu `json` s atributem `schema` (viz
[table-definitions.md](table-definitions.md) § 6):

```jsonc
{
    "id": "filing_profile",
    "name": "Filing profile",
    "name:cs": "Podací údaje",
    "type": "json",
    "nullable": true,
    "schema": "economy.vat.filingProfileCz"
}
```

`schema` je **cfgItem klíč** (bez verze) a smí být jen na typu `json`;
jinde je to chyba definice tabulky. `ds-upgrade` navíc vynutí, že cfgItem
existuje a projde validátorem schématu — sloupec, jehož schéma nejde
načíst, by se ukládal bez validace.

**Sloupec deklaruj v modulu, který vlastní schéma.** Když patří do cizí
tabulky, použij extension (vzor:
[`modules/economy/vat/extensions/economy_codebooks_vat_registrations.jsonc`](../modules/economy/vat/extensions/economy_codebooks_vat_registrations.jsonc)) —
jinak by `ds-upgrade` na zdroji dat bez tvého modulu spadl na chybějící
cfgItem.

## 3. Formát schématu

Schéma je cfgItem soubor `modules/<skupina>/<modul>/config/<Název>.jsonc`
registrovaný v `module.jsonc` → `config[]`:

```jsonc
{
    "version": "2026",
    "groups": [
        { "id": "office", "name": "Tax office", "name:cs": "Finanční úřad" }
    ],
    "fields": [
        { "id": "c_ufo", "type": "enumString", "length": 5,
          "cfgItem": "world.cz.taxOffices", "group": "office",
          "name": "Tax office for", "name:cs": "Finanční úřad pro",
          "required": true },
        { "id": "email", "type": "varchar", "length": 255,
          "inputType": "email", "group": "contact", "name": "E-mail" }
    ]
}
```

Formát je **formát sloupců tabulky bez SQL sémantiky** (S2): `length`,
`precision` a `scale` neurčují uložení (to je vždycky JSON), ale validaci
hodnoty.

### Kořen

| Klíč | Povinné | Popis |
|------|---------|-------|
| `version` | Ano | Část `_schema` v datech (`<cfgItem>/<version>`). Písmena, číslice, `. _ -`; **lomítko ne** |
| `groups` | Ne | Skupiny polí = sekce formuláře / bloky detailu, v pořadí deklarace |
| `fields` | Ano | Pole, v pořadí deklarace |

### Pole

| Klíč | Typ | Popis |
|------|-----|-------|
| `id` | string | `[a-z][a-z0-9_]*` — **bez tečky** (je to oddělovač virtuálních sloupců), unikátní |
| `type` | string | `varchar`, `text`, `int`, `numeric`, `date`, `boolean`, `enumInt`, `enumString` |
| `name` | string | Label; vícejazyčně `name:cs` / `name:en`, holé `name` je povinný fallback |
| `group` | string | Id deklarované skupiny; bez něj se pole renderuje před první skupinou |
| `length` | int | Povinné pro `varchar` a `enumString`, jinde zakázané |
| `precision`, `scale` | int | Povinné pro `numeric`, jinde zakázané |
| `cfgItem` | string | Povinné pro `enumInt` / `enumString`, jinde zakázané — číselník hodnot |
| `required` | bool | Povinné **v neprázdné hodnotě** (sekce 6) |
| `default` | scalar | Výchozí hodnota nového záznamu |
| `hint` | string | Nápověda pod polem; vícejazyčně `hint:cs` |
| `inputType` | string | Podmnožina `FormElement::ALLOWED_INPUT_TYPES` kromě `password`; bez něj se odvodí z typu |
| `readOnly` | bool | Pole se zobrazí, needituje |

**Jakýkoli jiný klíč je chyba kompilace** — překlep v `requred` nebo
`enumSting` nesmí tiše vypnout validaci. Kontroluje ho
`StructuredSchemaValidator` v `ConfigCompiler` (tedy při `ds-upgrade`) nad
surovými daty, takže `name:cs` je vícejazyčná varianta a ne neznámý klíč.

`cfgItem` číselníků uvnitř schématu **ds-upgrade nekontroluje** (kontroluje
jen cfgItem samotného schématu) — na to je test, viz sekce 10.

## 4. Verze schématu

Hodnota nese klíč `_schema` = `<cfgItem>/<version>`:

```json
{"_schema":"economy.vat.filingProfileCz/2026","typ_ds":"P","c_ufo":"464"}
```

- **Zápis a editace** vybírá schéma resolverem: hook (sekce 5), jinak
  statický atribut sloupce. Default je tedy **aktuální verze souboru** —
  živé nastavení jako profil podatele se s novou verzí edituje podle ní
  a při dalším uložení se `_schema` přeznačí.
- **Zobrazení** (detail vieweru) jde podle `_schema` uložené hodnoty:
  záznam se čte podle schématu, které nese.
- Klientem poslané `_schema` se **nikdy** nepoužije; hodnotu určuje server.

Starší verze schémat se v systému neuchovávají — kompilovaná konfigurace
nese vždy jen aktuální obsah souboru. Proto:

- **změna sady polí = nová `version`** ve stejném souboru. Pole, které nová
  verze zruší, zůstane v uložených datech a v detailu se zobrazí pod svým
  syrovým klíčem (nezahazuje se);
- kdo potřebuje starou verzi zachovat doslovně, **vydá ji jako nový
  cfgItem** (`filingProfileCz2026`) a nechá staré záznamy ukazovat na něj
  — `_schema` je pojmenuje.

## 5. Resolver a hook

`StructuredFieldResolver` je jediné místo, které zná přednosti. Pro
dynamický výběr schématu podle záznamu má `Document` hook:

```php
public function structuredSchemaFor(string $column, array $data): ?string
{
    // null = použij statický atribut `schema` ze definice sloupce
    return $column === 'header' ? 'economy.vat.filingHeaderDp3' : null;
}
```

Hook je určený pro dva případy:

1. **schéma per typ záznamu** — hlavička podání DPH má jinou sadu polí pro
   přiznání, kontrolní a souhrnné hlášení (Fáze 3 v #55);
2. **připnutí verze u snapshotu** — hodnota, která musí zůstat doslovně
   interpretovatelná, si vrátí klíč podle svého `_schema` místo aktuálního
   souboru.

Formulář má **zrcadlo** `TableForm::structuredSchemaFor()`: podle něj se
kreslí pole, podle dokumentového se validuje a ukládá. Kdo override
potřebuje, musí je držet v souladu (nejlépe delegací na jednu statickou
metodu domény).

## 6. Zápis — jedna autorita

Celý převod dělá `TableGateway::saveDocument()` (rozhodnutí I3), takže platí
pro **každý** kanál, který jde přes gateway: formulář, applier importu,
seeder datové sady, CLI.

Pořadí:

1. **unflatten** virtuálních sloupců `<sloupec>.<pole>` nad základem,
2. **validace** a normalizace hodnot → field-level chyby,
3. `Document::validate()` — dokument vidí hodnotu jako **pole**,
4. `Document::beforeSave()` — pořád pole, hook do ní může dopsat,
5. **serializace** s `_schema` do JSON stringu, teprve pak INSERT/UPDATE.

Pravidla:

- **Slučování, ne náhrada.** Klíč, který v payloadu není, si drží uloženou
  hodnotu. Částečný zápis (`{"filing_profile.email": "…"}`) nesmaže zbytek
  profilu; formulář posílá všechna pole schématu, takže vyprázdnění pole se
  propíše. Poslaný **celý sloupec** (pole nebo JSON string) hodnotu
  nahrazuje.
- **Nedotčený sloupec zůstává na disku.** Payload bez `<sloupec>` i bez
  jeho virtuálních polí se sloupce nedotkne — stejná zásada jako u child
  setů.
- **Prázdná hodnota → NULL**, ne `{}`. Prázdné = všechna pole `null` nebo
  `''`; `false` a `0` jsou hodnoty.
- **Klíče mimo schéma zůstávají** (pole zrušené novější verzí).
- **Kanonická serializace**: `_schema`, pak pole v pořadí schématu (jen
  non-null), pak zachované neznámé klíče. Zápis formulářem a zápis přes
  applier dají nad stejnými hodnotami bajtově stejný JSON.
- **Nedostupné schéma zápis zastaví** (nezkompilovaná konfigurace, neaktivní
  modul) — bez schématu nejde hodnotu zvalidovat.
- U sloupce se schématem **neplatí** poznámka o ruční serializaci v
  `beforeSave` z [table-definitions.md](table-definitions.md) § 6 — hook
  dostane pole a gateway ho serializuje sám.

**Generické CRUD `/api/v1/{table}` strukturovaná pole nezapisuje** (400
`STRUCTURED_COLUMN`). Ten endpoint píše přímo do tabulky a dokumentovou
vrstvu obchází, takže by uložil nevalidovanou hodnotu bez `_schema`. Zápis
patří form endpointu nebo kódu, který jde přes gateway.

## 7. Validace a chyby

Chyby jsou **field-level** s `column = "<sloupec>.<pole>"` — kontrakt
`field` ([edit-forms.md](edit-forms.md) § 8) je stejný jako u běžných
sloupců, takže klient chybu přiřadí k inputu sám.

| Typ | Přijímá | Ukládá | Kód chyby |
|-----|---------|--------|-----------|
| `varchar`, `text` | string, int, float | trimnutý string, délka přes `mb_strlen` | `too_long`, `invalid_value` |
| `int` | int, číselný string | int | `invalid_int` |
| `numeric` | int, float, číselný string | **string** v kanonickém tvaru; víc desetinných míst než `scale` je chyba, nezaokrouhluje se | `invalid_number` |
| `date` | `YYYY-MM-DD`, `DateTimeInterface` | `YYYY-MM-DD` | `invalid_date` |
| `boolean` | bool, 0/1, `true`/`false`/`yes`/`no`/`on`/`off` | bool | `invalid_bool` |
| `enumInt`, `enumString` | jako int / string + členství v cfgItem | int / string | `invalid_enum` |

- Žádná „chytrá" koerce: jen trim, číselný parse a rozpoznání boolean
  reprezentací. `12,50` u `numeric` je chyba, ne oprava na `12.50`.
- Prázdný string se ukládá jako `null`.
- `required` se vynucuje **jen v neprázdné hodnotě**: dokud uživatel do
  pole nic nezadal, sloupec je NULL a povinná pole nic neblokují (jinak by
  nešlo uložit registraci k DPH bez podacích údajů).
- Chybí-li číselník enumu v kompilované konfiguraci, členství se neověří
  (typ a délka ano) — degradace, ne chyba.
- Nedekódovatelný JSON v payloadu je chyba `invalid_value` na sloupci; zápis
  nesmí tiše přepsat uloženou hodnotu prázdnem.

## 8. Formulář a detail

**Formulář** (rozhodnutí S4 + I5) používá virtuální sloupce
`<sloupec>.<pole>` — server data při `meta` / `save` / `recalculate` zploští
a gateway je při ukládání složí zpět. Klient neví, že jde o JSON, a nemá
kvůli tomu žádnou novou komponentu. Oddělovač je **tečka**, stejně jako
u chyb v řádcích (`rows.0.unit_price`); důvody a ověření klienta jsou
v docblocku `StructuredSchema::PATH_SEPARATOR`.

PHP form si elementy vloží do libovolné sekce nebo tabu:

```php
if ($this->hasStructuredColumn('filing_profile')) {
    $tabs[] = $this->tab('filing', 'Podací údaje')
        ->section()->col()
        ->addElements($this->structuredFieldElements('filing_profile', $data))
        ->build();
}
```

`hasStructuredColumn()` je gate pro sloupce z extension — na zdroji dat bez
toho modulu se záložka nekreslí. Skupiny schématu se emitují jako
`separator`, pole jako `input` / `select` (options z cfgItem). Tabulky bez
vlastní form třídy dostanou sekci automaticky z `AutoFormBuilder`.
Deklarativní JSONC formy strukturovaná pole neumí.

**Detail vieweru** (I6) přes helper v `renderDetail()`:

```php
$groups = $this->structuredFieldProperties($record, 'filing_profile', 'economy.vat.filingProfileCz');
if ($groups !== []) {
    $tabs[] = ['id' => 'filing', 'label' => 'Podací údaje',
               'content' => ['type' => 'properties', 'groups' => $groups]];
}
```

Formátování je shodné se sub-tabulkami (`SubtableCellFormatter`): datum
`d.m.Y`, čísla s čárkou, boolean Ano/Ne, enum přes `name` číselníku.
Prázdné položky se vynechávají, klíče mimo schéma se zobrazí pod syrovým
klíčem v bloku „Obecné".

## 9. Příklad — profil podatele (#55 D19)

| | |
|---|---|
| Sloupec | `economy_codebooks_vat_registrations.filing_profile` (extension z `economy.vat`) |
| Schéma | [`economy.vat.filingProfileCz`](../modules/economy/vat/config/filingProfileCz.jsonc), verze `2026` |
| Skupiny | daňový subjekt · finanční úřad · adresa · kontakt · oprávněná osoba · sestavil · podepisující osoba |
| Číselníky | `economy.vat.filingSubjectTypes`, `…filingSignatoryTypes`, `…filingSignatoryCodes`, [`world.cz.taxOffices`](../modules/world/cz/config/taxOffices.jsonc), `world.cz.taxOfficeBranches`, `world.base.countries` |
| UI | Registrace DPH → záložka **Podací údaje**; detail vieweru → **Podací údaje** |

Obsahem jsou údaje **věty P** podání DPHDP3 / DPHKH1 / DPHSHV, které se
nedají odvodit z dokladů ani z registrace. Sada polí a délky jsou přenesené
ze starých properties; proti XSD je ověří Fáze 3 (#55), do té doby profil
jen sbírá data. Import ze starého Shipardu se nedělá — profil zadá uživatel.

Druhým konzumentem je od Fáze 3 (#55) **hlavička podání DPH**
(`economy_vat_filings.header`) — první uživatel hooku ze sekce 5: sadu polí
vybírá podle typu tvrzení `FilingHeaderSchema::forReportType()`, na který
delegují `FilingDocument` i `FilingsForm`. Hodnoty předvyplňuje
`FilingComposer` z profilu podatele; přepočet snapshotu je nepřepisuje.
Pozor na dvě věci, které z toho plynou obecně:

- **sloupec se schématem nesmí být `system`** — `FormController` systémové
  sloupce zahazuje i s jejich virtuálními poli, takže by se hodnota
  z formuláře tiše neuložila;
- schéma, které vybírá jen hook (KH a SH), **nekontroluje `ds-upgrade`** —
  ten vidí jen statický atribut sloupce. Takové schéma potřebuje vlastní
  test (vzor `FilingHeaderSchemaTest`).

## 10. Co to hlídá

| Kontrola | Kde |
|---|---|
| Formát schématu (neznámý klíč, typ, chybějící `length`/`cfgItem`, duplicitní `id`, pole v neexistující skupině) | `ConfigCompiler` při `ds-upgrade`, `StructuredSchemaValidatorTest` |
| Existence cfgItem se schématem | `ds-upgrade` (chyba, ne warning) |
| Existence číselníků **uvnitř** schématu | `FilingProfileSchemaTest` (invariant nad všemi moduly v repozitáři) |
| flatten/unflatten, prázdno → NULL, zachování neznámých klíčů | `StructuredFieldValuesTest` |
| Typy, délky, enumy, `required` | `StructuredFieldValidatorTest` |
| Pořadí kroků vůči Document hookům, slučování nad uloženou hodnotou, `_schema` | `TableGatewayStructuredFieldsTest` |
| Plochý tvar odpovědí formuláře, průchod virtuálních sloupců do gateway | `FormControllerStructuredFieldsTest` |
| Elementy formuláře a bloky detailu | `StructuredFieldFormBuilderTest`, `StructuredFieldRendererTest`, `TableFormStructuredFieldsTest`, `TableViewerStructuredFieldsTest` |
| Odmítnutí zápisu přes generické CRUD | `TableAccessGuardTest` |

## 11. Mimo rozsah

- **Tabulky řádků uvnitř pole** (staré `recordSetTable`) — fáze 2 rozhodnutí
  S6, až s konkrétní potřebou (přílohy DPPO).
- **Vyhledávání a filtrování** podle hodnot ve strukturovaném poli — nikdy
  (S1). Kdo potřebuje hledat, dá hodnotě relační sloupec.
- **Migrace existujících `json` sloupců** na schéma — snapshoty a payloady
  ho mít nemají.
