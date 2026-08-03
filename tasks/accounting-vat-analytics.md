# Účtování dokladů — DPH analytiky per vatCode

**Stav:** hotovo

## Stav: hotovo ✓ (2026-06-11)

Všech 5 balíčků implementováno, commity na stable:

- `3149ced` — W4: PDP výstup cz-150/151/152/350 dostal `noPayTax` +
  `sumTax: 0` (dle `zeroTax` EUCZ150 ve starém Shipardu)
- `994a863` — W1: reverse páry na opačnou stranu + recap změna (níže)
- `f46d58e` — W2: 55 mapovacích řádků, `vat_code_country`, omezený fallback
- `5520dc0` — W3: +9 účtů v default rozvrhu, NPO zrcadlí celou sadu (57),
  alfanumerické účty povoleny v `AccountDocument`, test úplnosti
- `3275be9` — W5: `docs/accounting.md` (rozhodnutí 13–17) + CLAUDE.md

Odchylky od zadání zjištěné implementací:

- **W1 vyžadoval i zásah do `buildVatRecapitulation`** (odsouhlaseno jako
  „varianta A"): primární řádek samovyměření měl `tax = 0` (`noPayTax`),
  takže pouhý flip strany páru by deník nevyrovnal — kontrolní příklad
  `343217 MD / 343207 DAL` neměl pro stranu MD zdroj. Nově primární řádek
  kódu s `reverseVatCode` nese spočtenou daň (nárok na odpočet, využije
  i budoucí DPH přiznání), `total` zůstává jen základ — head totals beze
  změny. Důsledek: dříve uložené reverse-charge doklady mají v DB recap
  s nulou — potřebovaly by re-save, ne jen reaccount (v dev DS žádný nebyl).
- **Chybový řádek bez masky** vracel `??????`; pro slíbené `343???` doplněn
  display-only hint ze syntetiky poslední masky dané kategorie.
- **Alfanumerické účty** blokovala validace `AccountDocument` (jen číslice)
  — povolena analytika `3 číslice + 1–9 číslic/písmen`, `beforeSave`
  normalizuje na velká písmena.

Ověřeno na dev DS: `ds-upgrade` doplnil 9 účtů (`is_system = 1`),
existující doklady po přeúčtování dostaly per-kód analytiku
(`cz-120` → `343120`). Plné sady i filtry `Accounting|Vat` zelené.

## Kontext

Účtování (Fáze 1–3) zatím sype veškerou DPH na jedinou masku `343`.
Číselník DPH kódů přitom existuje (`world.vat.cz`, ~50 kódů) a seed
rozvrh už obsahuje analytiky v konvenci „343 + číselná část kódu"
(`cz-110` → `343110`). Tento task: každý DPH kód účtovat na vlastní
analytiku — deklarativně, bez substring magie starého Shipardu.

Součástí je oprava chyby: engine dnes účtuje reverse-charge páry
rekapitulace (`is_reverse_pair = 1`) na stejnou stranu jako primární
řádek, takže EU pořízení / tuzemské PDP na vstupu skončí nevyrovnaným
deníkem.

Pracovní balíčky:

- **W1** — reverse páry na opačnou stranu (oprava enginu)
- **W2** — per-kód mapování v předpisu + `vat_code_country` pojistka
- **W3** — doplnění analytik do seed rozvrhů (default + NPO)
- **W4** — ověření `cz-150` (PDP výstup) a případná oprava configu
- **W5** — dokumentace (konvence, OSS směr, oprava accounting.md)

## Návaznost

- `docs/accounting.md` — návrh účtování; sekci o DPH tento task **mění**
  (slib „analytika jako atribut vatCode v world.vat" se ruší ve prospěch
  mapování v předpisu — viz Rozhodnutí).
- Hotové Fáze 1–3 účtování (`tasks/accounting-phase{1,2,3}.md`).
- OSS (prodej neplátcům v EU se zahraničními sazbami) je **budoucí
  samostatný úkol** — tady jen pokládáme kompatibilní základ (konvence
  čísel, pojistka fallbacku, test alfanumerických účtů).

## Před implementací přečti

- `modules/economy/accounting/src/AccountingEngine.php` —
  `buildVatLines`, `resolveCategoryAccount`, `matchesQuery`,
  `passesSignAndReverse`
- `modules/world/vat/config/vat-cz.jsonc` — kódy, `vatPercents`,
  `reverseVatCode` vazby, flagy `noPayTax`/`sumTax`/`hidden`
- `modules/docs/core/src/DocDocument.php` — `buildVatRecapitulation`
  (vznik reverse párů, flagy `sum_*`, `is_reverse_pair`)
- `modules/economy/accounting/config/accountingRules.cz.jsonc` — sekce
  `accounts` (pořadí matchování: první shoda vyhrává!)
- `modules/economy/accounting/config/accountChartDefault.jsonc` +
  `accountChartNpo.jsonc` — stávající 343 analytiky
- `modules/economy/accounting/src/AccountDocument.php` —
  `deriveStructure` (délková logika, alfanumerika projde)
- starý Shipard (projekt `old_shipard`):
  `modules/install/country-modules/debs/eu/config/vat-cz.json` —
  referenční flagy pro W4 (porovnání `cz-150` ↔ `EUCZ150`)

## Scope

### V scope

- oprava strany reverse párů v enginu
- mapovací řádky `vat_code → 343{NNN}` v `accountingRules.cz.jsonc`
- odvozené pole `vat_code_country` pro query + fallback omezený na `cz`
- doplnění chybějících 343 analytik do obou seed rozvrhů
- ověření/oprava `cz-150` (a sourozenců) v `vat-cz.jsonc`
- test alfanumerického účtu (OSS-ready)
- aktualizace `docs/accounting.md`

### Mimo scope

- OSS jako celek: `vat-de.jsonc` a další státy, zahraniční kódy v UI
  dokladu, OSS přiznání, per-datasource vrstva mapování, enablement
  („zapnutí OSS pro stát X") — budoucí úkol, tady jen zdokumentovat směr
- DPH přiznání / kontrolní hlášení
- jakékoliv zásahy do saldokonta, skladu

---

## Co implementovat

### W1 — Reverse páry na opačnou stranu

`AccountingEngine::buildVatLines`: řádek rekapitulace
s `is_reverse_pair = 1` se účtuje na **opačnou** stranu, než určuje
krok předpisu (`side` 0 ↔ 1). Vše ostatní beze změny — částka
z `tax_dom`/`tax`, účet přes kategorii `vat` (query se matchuje proti
řádku rekapitulace, takže pár dostane analytiku **svého** kódu),
default text `DPH {code} {pct}%`.

Pozor na interakci s `passesSignAndReverse` — flip strany je nezávislý
na `reverseSign` kroku (to je mechanismus pro zaokrouhlení); nemíchat.

Kontrolní příklady (testy):

1. **EU pořízení služeb** (invni, cz-217, základ 1 000, 21 %):
   `518xxx MD 1000`, `321xxx DAL 1000`, `343217 MD 210`,
   `343207 DAL 210` — vyrovnáno, state 1.
2. **Tuzemské PDP4 na vstupu** (invni, cz-115): základ na náklad,
   `343115 MD` + `343203 DAL` — vyrovnáno.
3. Head totals: u obou případů `total_vat` 0 (sumTax 0) a
   `total_amount` = jen základ — protistrana 321 sedí bez úprav.

### W2 — Mapování per kód + pojistka fallbacku

**W2.1 Odvozené pole** — engine při matchování query nad řádkem
rekapitulace (`src: vat`) doplní do kopie záznamu pole
`vat_code_country` = lowercase část `vat_code` před první pomlčkou
(`cz-110` → `cz`). Nepersistuje se, existuje jen pro `matchesQuery`.
Implementuj na jednom místě (obohacení záznamu v `buildVatLines` před
voláními), ať to query vidí i v `resolveCategoryAccount`.

**W2.2 Mapovací řádky** — do `accounts` sekce
`accountingRules.cz.jsonc`, **před** fallback, blok s komentářem:

```jsonc
// DPH analytiky per kód — konvence 343{NNN}, NNN = číselná část kódu.
{"cat": "vat", "accountMask": "343110", "query": {"vat_code": "cz-110"}},
{"cat": "vat", "accountMask": "343111", "query": {"vat_code": "cz-111"}},
// ... atd.
```

Jeden řádek pro **každý kód, který může vyprodukovat nenulovou daň**:

- všechny kódy s nenulovou hodnotou ve `vatPercents` (vstup i výstup,
  vč. historických 3xx — kvůli zpětně datovaným a migrovaným dokladům)
- všechny kódy, na které ukazuje `reverseVatCode` (páry: 203, 204, 370,
  205–208, 360–363, 405–408, 460–463)
- kódy s trvalou nulou (112, 122, 123, 201, 202, 401) řádek nedostanou
  — nulová daň žádný řádek deníku negeneruje

Seznam vygeneruj programově z `vat-cz.jsonc` (jednorázový skript /
ruční křížová kontrola), ať nic nechybí; v testu ověř úplnost
(viz Hotovo když bod 4).

**W2.3 Fallback omezit na tuzemsko** — stávající
`{"cat": "vat", "accountMask": "343"}` dostane
`"query": {"vat_code_country": "cz"}`. Důsledek: neznámý tuzemský kód
spadne na syntetiku 343 (jako dnes), ale zahraniční kód bez mapování
skončí hlasitě — `account_not_found`, řádek `343???`, `is_error = 1`,
alert. Tichému smíchání cizí DPH s tuzemskou je zabráněno.

Pozn. k zemi fallbacku: hodnota `cz` je správně, dokud existuje jen
`rules.cz`; až přibudou další country předpisy, každý má svůj fallback
se svou zemí — žádná generalizace teď.

### W3 — Seed rozvrhy

Diff: pro každý kód z W2.2 musí v `accountChartDefault.jsonc` existovat
účet `343{NNN}`. Chybějící doplnit (čekej minimálně reverse kódy
203/204/370, EU 215–218 + 205–208, dovoz 415–418 + 405–408 a historická
3xx řada) — názvy ve stylu stávajících
(`"Daň z přidané hodnoty (vstup - …)"`,
`account_kind` shodný s ostatními 343). Stejný diff a doplnění pro
`accountChartNpo.jsonc` — pokud NPO rozvrh 343 analytiky nemá vůbec,
zrcadli default sadu.

Provisioner upsertuje podle `number`, takže existující datasourcy
dostanou nové účty při `ds-upgrade` — nic dalšího není potřeba. Ověř
jen, že doplněné účty dostanou `is_system` shodně s ostatními seed
záznamy.

### W4 — cz-150 / PDP výstup

Podezření: `cz-150` (a `cz-151`, `cz-152`, `cz-350` — PDP výstup) nemá
v `vat-cz.jsonc` `noPayTax`/`sumTax: 0`, takže vydaná PDP faktura by
mohla daň chybně načíst do `total_vat`/`total_amount`. U PDP výstupu
daň odvádí zákazník — faktura je jen základ + text „Daň odvede
zákazník".

1. Napiš test: invno s řádkem `cz-150`, základ 1 000 → `total_vat = 0`,
   `total_amount = 1000`; zaúčtování: `602xxx DAL 1000`,
   `311xxx MD 1000`, **žádný** řádek 343; vyrovnáno.
2. Pokud test odhalí chybu, porovnej flagy s `EUCZ150` ve starém
   Shipardu (cesta výše, projekt `old_shipard`) a oprav `vat-cz.jsonc`
   (pravděpodobně doplnit `noPayTax: 1`, `sumTax: 0`). Oprava configu
   je v scope tohoto tasku; pokud se ukáže, že je potřeba zásah do
   `buildVatRecapitulation`, zastav se a popiš problém — to už by byl
   samostatný task.

### W5 — Dokumentace

`docs/accounting.md`:

- sekce o DPH: nahradit „zatím jedna maska 343" a „analytika jako
  atribut vatCode" novým stavem — mapování v `accounts` sekci předpisu
  (query na `vat_code`), reverse páry na opačnou stranu,
  `vat_code_country` pojistka
- nová podsekce **Konvence DPH analytik a OSS**: tuzemsko `343{NNN}`,
  zahraničí `343{CC}{NNN}` (`de-120` → `343DE120`); zahraniční účty se
  **neprovisionují** do každého DS — vzniknou on-demand s budoucím OSS
  úkolem (enablement per stát = doprovisionování účtů + per-datasource
  vrstva mapování; pořadí resolution pak: per-DS mapování → předpis →
  fallback); alfanumerická čísla účtů projdou celým stackem
  (`deriveStructure` je délková)
- log rozhodnutí doplnit (viz níže)

---

## Hotovo když

1. Testy W1 (EU pořízení, PDP4 vstup) zelené — deník vyrovnaný, obě
   strany DPH na správných analytikách.
2. Tuzemská faktura: DPH na `343120`/`343121` (výstup) resp.
   `343110`/`343111` (vstup) podle kódu — ne na syntetice 343.
3. Zahraniční kód bez mapování (test s fiktivním `de-120` vloženým
   přímo do recap dat): `343???`, `is_error = 1`, `account_not_found`,
   state 2 — žádné tiché zaúčtování na 343.
4. Test úplnosti: pro každý kód z `vat-cz.jsonc` s možnou nenulovou
   daní existuje mapovací řádek v rules **a** účet v obou seed
   rozvrzích (programová kontrola, ne ruční seznam).
5. Alfanumerický účet: ručně založený `343DE123` projde
   `deriveStructure` (level 4, g3 `343`), maska `343DE123` ho dohledá,
   řádek deníku ho unese (varchar 12).
6. W4 test PDP výstupu zelený (po případné opravě configu).
7. `ds-upgrade` na dev DS doplní nové účty; existující doklady jdou
   přeúčtovat (`/_accounting/reaccount`) a dostanou per-kód analytiky.
8. Úzké filtry (`--filter 'Accounting|Vat'`) zelené; existující testy
   neporušené.

## Doporučené pořadí

1. W4 test (odhalí stav configu) → případná oprava → commit
2. W1 reverse páry + testy → commit
3. W2 mapování + pojistka + testy → commit
4. W3 rozvrhy + test úplnosti → ds-upgrade → commit
5. W5 dokumentace → commit

## Rozhodnutí ✓

- Mapování vat_code → analytika žije v `accounts` sekci účtovacího
  předpisu (query na `vat_code`), **ne** jako atribut kódu ve
  `world.vat` (legislativní vrstva zůstává bez účetních konvencí) a
  **ne** v DB číselníku (ten přijde až jako per-DS override s OSS).
- Konvence: `343{NNN}` tuzemsko, `343{CC}{NNN}` zahraničí; `NNN` =
  číselná část vat kódu.
- Reverse pár (`is_reverse_pair = 1`) → opačná strana než krok; analytika
  podle vlastního kódu páru.
- Fallback `vat` omezen na `vat_code_country = cz` — zahraniční kódy bez
  mapování selžou hlasitě (chybový řádek + alert), nikdy tiše na 343.
- Zahraniční analytiky se neprovisionují plošně; on-demand s OSS.
- Historické kódy (3xx řada 2015–2023) mapování i účty dostanou — kvůli
  zpětně datovaným a migrovaným dokladům.

## Otevřené body

- Pokud W4 ukáže problém hlubší než config flag (zásah do
  `buildVatRecapitulation`), zastavit a eskalovat — nepatří do tohoto
  tasku.
- Krácené kódy (118/119/341/342, koeficient odpočtu) účtujeme zatím
  plnou daň na vlastní analytiku; krácení odpočtu (548 dopočet) je
  budoucí téma DPH přiznání — poznamenat v accounting.md mimo scope.
