# Task: Documentation for third-party modules — Phase 5 (final) of third-party modules support

**Stav:** hotovo

## Context

Phases 1–4 implemented the third-party modules feature:
- `ModulePathResolver` discovers modules across multiple roots
- `server.json` accepts an `extraModulesPath` list
- Custom `ModuleClassLoader` autoloads PHP classes for any module regardless of root
- `next-table-id --range=N:M` allocates in reserved ranges

**Phase 5 documents the feature for developers and server operators.** No
code changes — just updates to two existing doc files. After this phase,
a developer or admin can read the docs and know how to add a third-party
module without asking us.

## Files to modify

### 1. `docs/modules.md`

#### Structural change — insert new section 10

Insert a new section **`## 10. Third-party moduly`** **between** the current
sections 9 (Aktivace modulů ve zdroji dat) and 10 (CLI příkaz `shpd-ds ds-upgrade`).

Renumber the subsequent sections:
- Old `## 10. CLI příkaz shpd-ds ds-upgrade` → `## 11. CLI příkaz shpd-ds ds-upgrade`
- Old `## 11. Modul core.system` → `## 12. Modul core.system`
- Old `## 12. Implementační plán pro Claude Code` → `## 13. Implementační plán pro Claude Code`
- Old `## 13. Otevřené otázky` → `## 14. Otevřené otázky`

Update any internal cross-references inside `docs/modules.md` if they
mention these section numbers (a quick `grep -n "sekce " docs/modules.md`
will list them — fix as needed).

#### Content of the new section 10

```markdown
## 10. Third-party moduly

Hlavní repozitář Shipardu obsahuje moduly v adresáři `modules/`. Pro některé situace ale potřebujeme moduly držet mimo hlavní repozitář:

- **Interní zákaznické moduly** — specifická logika pro konkrétního zákazníka, která nemá smysl pro veřejnost
- **Placené moduly** — moduly, které jsou zpoplatněné a nemohou být v public repozitáři
- **Experimenty a prototypy** — moduly, které se zatím nezveřejnily

Shipard takové moduly podporuje přes mechanismus **dalších rootů** (extra module roots). Modul z externího rootu se chová identicky jako modul z hlavního `modules/` — stejné `module.jsonc`, stejné tabulky, stejné PHP třídy, stejné závislosti.

### 10.1 Konfigurace — `extraModulesPath` v `server.json`

V serverové konfiguraci se přidá volitelné pole `extraModulesPath` se seznamem absolutních cest k dalším rootům:

```json
{
    "host": "127.0.0.1",
    "port": 3306,
    "admin_user": "root",
    "admin_password": "...",
    "mode": "production",
    "extraModulesPath": [
        "/opt/shipard/extra/acme-customer/modules",
        "/opt/shipard/extra/billing-pro/modules"
    ]
}
```

Pravidla:
- Pole je **volitelné** — bez něj se chová systém jako dřív (jen hlavní `modules/`)
- Cesty jsou **absolutní** a musí existovat (jinak start aplikace selže s chybou)
- **Pořadí v poli definuje pořadí načítání** — hlavní `modules/` je vždy první, extras pak v deklarovaném pořadí
- Změna `extraModulesPath` vyžaduje **restart aplikace / nový request** — není to live reload

### 10.2 Adresářová struktura externího rootu

Externí root má stejnou strukturu jako hlavní `modules/`:

```
/opt/shipard/extra/acme-customer/modules/
└── cust_acme/                       ← skupina
    └── reporting/                   ← modul
        ├── module.jsonc
        ├── tables/
        │   └── cust_acme_reporting_reports.jsonc
        ├── src/
        │   └── ReportDocument.php   ← Shipard\Module\CustAcme\Reporting\ReportDocument
        └── forms/
            └── cust_acme_reporting_reports.jsonc
```

Skupiny v externím rootu mohou být úplně nové (`cust_acme`) nebo i existující (`economy`, pokud chce externí modul přidávat do oficiální skupiny). Jediná podmínka — ID modulu `{skupina}.{modul}` musí být globálně unikátní napříč všemi rooty.

### 10.3 Konvence pro ID modulu

Aby se předešlo kolizím mezi externími moduly, doporučujeme tyto prefixy ve skupině:

| Prefix | Účel | Příklad |
|--------|------|---------|
| `cust_*` | Zákaznické (in-house) moduly | `cust_acme.reporting` |
| `ext_*` | Placené moduly od vendorů | `ext_billingpro.invoices` |

Konvence **není vynucena v kódu** — systém pouze detekuje kolize ID. Pokud dva externí rooty deklarují stejné ID modulu, `ds-upgrade` (nebo HTTP request) selže s konkrétní chybovou hláškou jmenující obě cesty:

```
Module 'cust_acme.reporting' found in multiple roots:
  '/opt/shipard/extra/acme-v1/modules/cust_acme/reporting'
  '/opt/shipard/extra/acme-v2/modules/cust_acme/reporting'
```

### 10.4 Rezervované rozsahy `tableId`

`tableId` musí být globálně unikátní napříč všemi moduly. Pro řízenou alokaci ve smíšeném prostředí (core + externí moduly) doporučujeme následující rozdělení:

| Rozsah | Použití |
|--------|---------|
| `1 – 9 999` | Core (oficiální Shipard moduly) |
| `10 000 – 19 999` | Custom (in-house zákaznické moduly) |
| `20 000 – 29 999` | Vendor (placené moduly od třetích stran) |
| `30 000 – 65 535` | Rezerva |

Rozsahy jsou **konvence, ne enforcement** — `ds-upgrade` neodmítne `tableId` z důvodu "špatného rozsahu". Slouží jako koordinační nástroj při paralelním vývoji a usnadňují alokaci přes `next-table-id --range`.

Pro alokaci v rámci rozsahu:

```bash
bin/shpd-server next-table-id --range=10000:10099
# 10000
```

Příkaz vrátí **první volný** `tableId` v daném rozsahu, ne `max + 1` — to umožňuje znovu použít ID po smazaných modulech a hospodárně využít rozsah.

Bez `--range` vrací příkaz historicky `max(použité) + 1` napříč všemi rooty.

### 10.5 PHP třídy v externím modulu

PHP třídy externích modulů se autoloadují **automaticky**. Konvence namespace → adresář:

- Namespace: `Shipard\Module\{Group}\{Module}\{ClassName}`
- Cesta: `{root}/{group}/{module}/src/{ClassName}.php`

Konverze:
- Group v namespace = PascalCase → adresář v lowercase (`CustAcme` → `cust_acme` — pozor, **lcfirst**, ne `strtolower`)
- Module v namespace = PascalCase → adresář v camelCase (`Reporting` → `reporting`, `MyModule` → `myModule`)

Pravidlo: první písmeno na malé, zbytek beze změny.

Příklady:

| Namespace | Adresář |
|-----------|---------|
| `Shipard\Module\Base\Persons\PersonDocument` | `base/persons/src/PersonDocument.php` |
| `Shipard\Module\Docs\InvoicesOut\Foo` | `docs/invoicesOut/src/Foo.php` |
| `Shipard\Module\CustAcme\Reporting\ReportDocument` | `custAcme/reporting/src/ReportDocument.php` |

> ⚠️ **Pozor na skupiny s podtržítkem v ID.** ID modulu `cust_acme.reporting` znamená skupina `cust_acme` a modul `reporting`. V namespace se ale skupina musí psát jako jeden PascalCase token: `CustAcme`. Tj. pokud máš podtržítkové ID, namespace odpovídá `Shipard\Module\CustAcme\Reporting\…` a adresář `cust_acme/reporting/`. Autoloader se řídí podle adresářového ID, takže funkčně to sedí — ale **konvence pojmenování PHP tříd v takových modulech vyžaduje pozornost**. Doporučujeme držet jednoduchá skupinová jména bez podtržítek tam, kde to jde.

`composer.json` **nemusí být upravován** — registrace tříd je dynamická přes `ModuleClassLoader`.

### 10.6 Frontend

Externí moduly jsou **backend-only**. Veškeré UI se renderuje přes server-driven UI mechanismus (viewers, forms, settings) definovaný v `module.jsonc` a JSONC souborech. Vlastní Svelte komponenty z externího modulu zatím podporované nejsou — frontend je SPA bundle build-ovaný z hlavního repozitáře.

Praktický důsledek: externí modul může definovat tabulky, viewer pro CRUD, edit formy přes JSONC, validační logiku v PHP Document třídách a vlastní endpointy přes Controller třídy. Ale nemůže přidat vlastní Svelte view.

### 10.7 Instalace externího modulu — postup

1. Naklonovat / zkopírovat externí modul na server, např. do `/opt/shipard/extra/acme-customer/modules/`
2. Přidat cestu do `server.json` pole `extraModulesPath`
3. Restartovat aplikaci (nebo počkat na další request — PHP nemá perzistentní stav)
4. Aktivovat modul v konkrétním DS přidáním jeho ID do `modules` v `config/main.json`
5. Spustit `shpd-ds ds-upgrade` v adresáři DS

Update modulu = `git pull` (nebo nahrání nové verze) v externím adresáři + `ds-upgrade` na všech DS, kde je modul aktivní.

### 10.8 Detekce kolizí a chybové stavy

| Situace | Chování |
|---------|---------|
| Cesta v `extraModulesPath` neexistuje | `RuntimeException` při startu — adminkovi se ukáže konkrétní hláška |
| Stejné ID modulu ve dvou rootech | `RuntimeException` při startu s oběma cestami |
| Stejné `tableId` ve dvou modulech | `SchemaValidator` selže během `ds-upgrade` se seznamem konfliktních souborů |
| Modul aktivovaný v DS, ale neexistující v žádném rootu | `ModuleResolver` zaloguje warning a modul přeskočí |
| PHP třída z modulu, který není v žádném rootu | Standardní PHP `Class not found` při použití |

### 10.9 Co Shipard zatím nedělá

Pro úplnost — tyto věci jsou mimo scope aktuální implementace a budou řešeny později:

- **Verzování modulů** — neexistuje `require: "ext_billingpro.invoices: ^2.0"`. Externí moduly jsou prostě adresáře s aktuální verzí
- **Distribuce přes composer** — externí moduly nejsou composer balíky, instalují se ručně (případně jako git submoduly do separátního privátního repozitáře)
- **Sandbox / izolace PHP kódu** — PHP třídy z externích modulů běží ve stejném procesu se stejnými oprávněními
- **Frontend komponenty** — viz 10.6
- **Per-modul oprávnění** — kdo smí co v jakém modulu se zatím neřeší
```

#### Update existing section 4 (Identifikace modulu)

In the **"Adresářová struktura"** subsection of section 4, append a short note after the existing tree example:

```markdown
> Adresářová struktura se vztahuje na **jakýkoliv root**, ne jen na hlavní `modules/`. Stejné pravidlo platí pro externí rooty z `extraModulesPath` (viz sekce 10). Cesta k modulu se vždy odvozuje z jeho ID jako `{root}/{group}/{module}/`.
```

### 2. `docs/table-definitions.md`

In section 4 ("Metadata tabulky"), in the **"`tableId` — unikátní numerické ID"** subsection, append a paragraph after the existing rules list:

```markdown
**Rezervované rozsahy.** Pro projekty s externími moduly (viz `docs/modules.md` sekce 10) doporučujeme rozdělení `tableId` do rozsahů:

| Rozsah | Použití |
|--------|---------|
| `1 – 9 999` | Core (oficiální moduly) |
| `10 000 – 19 999` | Custom (in-house zákaznické moduly) |
| `20 000 – 29 999` | Vendor (placené moduly od třetích stran) |
| `30 000 – 65 535` | Rezerva |

Rozsahy nejsou vynuceny — `ds-upgrade` přijme jakýkoliv unikátní `tableId`. Konvence ale usnadňuje paralelní alokaci. Pro alokaci v konkrétním rozsahu použij `bin/shpd-server next-table-id --range=10000:10099` (vrátí první volné ID v rozsahu).
```

## Order of operations

1. **Read the current section numbering** of `docs/modules.md` to confirm
   the table of contents matches what's described above.
2. **Insert the new section 10** with the content provided.
3. **Renumber sections 10–13** to 11–14.
4. **Update internal cross-references** if any existing text mentions
   section numbers that shifted (`grep -n "sekce 1[0-3]" docs/modules.md`
   should find them, if any).
5. **Add the short note** to the existing section 4.
6. **Edit `docs/table-definitions.md`** — append the reserved-ranges
   paragraph in the `tableId` subsection.
7. **Verify rendering** — open both files in a markdown viewer (or use
   `mdcat` / GitHub preview) and check tables and headings render cleanly.

## Acceptance criteria

- `docs/modules.md` has a new section **10. Third-party moduly** with
  subsections 10.1–10.9.
- Old sections 10–13 in `docs/modules.md` are now numbered 11–14.
- Section 4 has a one-paragraph note pointing to section 10.
- `docs/table-definitions.md` has a "Rezervované rozsahy" paragraph in
  the `tableId` subsection.
- No code files are modified.
- `vendor/bin/phpunit` is still green (sanity check — no tests should
  have broken from doc edits).
- Markdown tables render correctly (no missing column separators).

## What this phase does NOT do

- Does **not** rewrite the implementation plan (section 13 in the new
  numbering, originally 12). That section is outdated, but its cleanup
  is a separate concern, not part of the third-party-modules work.
- Does **not** update the "Otevřené otázky" section.
- Does **not** add English translations of the new content. Project
  convention is Czech for docs.
- Does **not** touch other doc files (`docs/architecture.md`,
  `docs/document-system.md`, etc.). They don't currently discuss module
  roots in a way that needs updating; if you find an outdated mention
  during the work, note it but don't fix it here.

## Gotchas worth watching for

- **Czech typography.** Use Czech quotation marks where the rest of the
  document uses them (`„text"`). Numbers with thousands separator use
  the non-breaking space (`10 000`), matching the existing style in
  the tables.
- **Markdown table alignment.** Some viewers are picky about missing
  trailing pipes. Keep the table style consistent with the existing
  tables in `docs/modules.md`.
- **Cross-reference accuracy.** After renumbering, double-check that
  section 10 is referred to as "sekce 10" everywhere it's mentioned —
  not "sekce 11" by mistake.
- **lcfirst note in 10.5 is important.** The PascalCase → camelCase
  conversion for module names is the one thing developers will trip on.
  The example table covers all the gotcha cases (single word, multi-word,
  underscore-in-group). Don't trim the warning callout — it's there for
  a reason.
- **Don't number-bump from 10.0**. The first subsection is `### 10.1`,
  not `### 10` or `### 10.0`. Match the existing convention in the
  document (which uses flat headers and a numbered list for major
  sections; this is the first place we introduce sub-numbering, so just
  pick `10.1`, `10.2`, ... and don't agonize).
```
