# Booking history — follow-upy z pilotních běhů (hardening reverzu, práh pokrytí, otagování z užití)

**Stav:** hotovo

**Cíl:** Tři korekce nástroje `shpd-ds booking-history` vzešlé z pilotních
běhů nad reálným exportem (3 559 záznamů; cílové DS
`4l3j-…` cizí firma a `btpg-…` táž firma jako zdroj):

1. přesná shoda analytiky na neznámé osnově produkuje falešné reverzní
   štítky → hardening,
2. seed pravidla mohou stát na malém výseku historie dodavatele → práh
   pokrytí,
3. u DS migrovaného z téže firmy se kódy položek souboru kryjí 1:1
   s katalogem → nový režim otagování položek z užití (LLM dominance
   per položka), řádově silnější než reverz přes nabídku.

## Návaznost

- Rozšiřuje [booking-history-import.md](booking-history-import.md)
  (D29–D35), issue #37. Formát souboru se **nemění** (žádný nový export).
- Kód: `modules/core/exchange/src/BookingHistory/` — `AccountTagMap` +
  `AccountTagMatch` (reverz, druhy `exact`/`synthetic`/`ambiguous`/…),
  `BookingHistoryAnalyzer`/`BookingHistoryAnalysis`,
  `BookingHistorySeedBuilder` + `SeedCandidate`, `BookingHistoryReport`,
  `BookingHistoryClassifier` (sidecar cache), command
  `src/Command/DataSource/BookingHistoryCommand.php`.
- Empirie z pilotů (pro kontext implementace):
  - MSI používá vlastní analytiky; jeho `518201/518202` = finanční
    leasing, zatímco nabídka je taguje `it.phone`/`it.internet` → 34
    falešných reverzních řádků přesnou shodou.
  - Seed kandidáti `people.catering`/`admin.insurance` s pokrytím
    20–43 % (pravidlo z malého výseku).
  - `btpg` DS: 275 migrovaných položek, 53 kódů ze souboru sedí 1:1,
    0 otagováno; reverz přes nabídku tu skoro nic nezasáhne
    (catch-all analytiky).

## Rozhodnutí

| D | Rozhodnutí |
|---|---|
| D36 | **Hardening reverzu při `chartVariant: unknown`:** přesná shoda analytiky (`KIND_EXACT`) se přijme jen po sanity checku názvu položky — normalizovaná podobnost `itemName` záznamu vs. název položky nabídky nesoucí štítek (práh ~0.5, metrika `similar_text`/Levenshtein poměr, diakritika a case insensitive). Neshoda → degradace na syntetickou logiku (jako by přesná shoda nebyla); záznam bez `itemName` → degradace rovněž (poctivě). U `chartVariant: default|npo` beze změny (deklarovaná osnova = analytiky věříme). Report: nový řádek v sekci pokrytí — počet degradovaných přesných shod. |
| D37 | **Třetí práh seedu — pokrytí:** `coverage = oštítkované řádky IČO / všechny řádky IČO >= 0.5` (nový parametr `--seed-min-coverage`, default 0.5; stávající prahy podíl 0.8 a docCount 3 zůstávají, též povýšit na parametry `--seed-min-share`, `--seed-min-docs`). Kandidáti pod prahem pokrytí se v reportu ukazují dál, se stavem „pod prahem pokrytí" (transparence zůstává). |
| D38 | **Otagování z užití (`--tag-items` režim `usage`):** když se kódy položek souboru potkávají s katalogem DS, agregují se LLM štítky klasifikovaných obsahonosných textů per `itemCode`; položka dostane návrh štítku při dominanci `>= 0.7` podílu klasifikovaných řádků a `>= 5` řádcích (parametry `--usage-min-share`, `--usage-min-rows`). Zapisují se jen položky s prázdnými `content_tags`, přes `TableGateway`. Volba režimu: `--tag-items=offer|usage|auto`, default `auto` = usage, pokud míra shody kódů (matchnuté kódy / kódy v souboru) >= 0.8, jinak offer; zvolený režim a míra shody se vypíší. Dry-run vypisuje plán per položka (kód, název, štítek, podpora). |

Poznámky k D38: agregace per kód počítá jen obsahonosné texty (degenerace
dle D33 se vynechá — text == název položky by kruhově potvrzoval sám
sebe); položky, jejichž dominantní výsledek je LLM `null` nebo pod
prahem, se do plánu nedostanou (catch-all a leasing správně bez štítku);
multi-tag se v v1 nenavrhuje — jen jeden dominantní štítek.

## Scope

**In:**
1. `AccountTagMap`/`AccountTagMatch`: sanity check názvů (D36) — mapě
   dodat názvy položek nabídky per štítek (má je `AccountingItemsOffer`),
   nový druh výsledku nebo flag `degradedExact` pro report.
2. `BookingHistorySeedBuilder`/`SeedCandidate`: coverage práh (D37),
   nové parametry commandu, stav kandidáta v reportu.
3. Nová služba `BookingHistoryItemUsageTagger` (D38): match kódů,
   agregace, prahy, plán; wiring do commandu (`--tag-items` rozšíření),
   dry-run výpis, zápis přes gateway.
4. `BookingHistoryReport`: řádek degradovaných shod (D36), stav „pod
   prahem pokrytí" (D37), sekce plánu/výsledku usage otagování, když
   režim běžel (D38).
5. Testy, aktualizace `docs/booking-history-format.md` (jen odstavec
   o režimech tag-items — formát se nemění) a nápovědy commandu.

**Out:**
- Změny exportu na staré straně, změny formátu.
- Multi-tag návrhy per položka; návrhy pro už otagované položky.
- Kolektivní skript (stále samostatný task).
- Změny taxonomie (kandidát `insurance.*` prefix čeká na běhy dalších DS).

## Testy

1. **D36**: exact shoda s podobným názvem → drží `exact`; s odlišným
   názvem (leasing vs. internet fixture) → degradace na syntetiku
   (u 518 → ambiguous); bez itemName → degradace; `chartVariant: default`
   → sanity check se nespouští; počet degradací v analýze.
2. **D37**: kandidát share 1.0/docs 21/coverage 0.27 → vyřazen se stavem
   „pod prahem pokrytí"; coverage 0.6 → prochází; parametry přepínají
   prahy.
3. **D38**: match rate výpočet; auto volba usage/offer; dominance nad/pod
   prahem; degenerované texty mimo agregaci; LLM null dominanta → bez
   návrhu; položka s existujícími tagy se přeskočí; dry-run nemění DB;
   zápis přes gateway.
4. Report: nové řádky/sekce přítomné jen když relevantní.

## Commit strategie

1. D36 hardening + testy + report řádek.
2. D37 prahy + parametry + testy.
3. D38 usage tagger + command + report sekce + testy + docs.

## Hotovo když

- [x] Pilotní export: přesné shody `518201/518202` (leasingové názvy) se
      degradují a `it.phone`/`it.internet` z reverzu zmizí; počet
      degradací je v reportu.
- [x] Seed náhled ukazuje kandidáty s pokrytím < 50 % jako vyřazené;
      `--apply-seed` je nezaloží; prahy jdou přepnout parametry.
- [x] Na `btpg` DS: `--tag-items --dry-run` zvolí režim usage (shoda
      kódů ≥ 0.8), vypíše plán s dominantními štítky; catch-all a
      leasingové položky v plánu nejsou; ostrý běh otaguje jen položky
      s prázdnými `content_tags`.
- [x] Testy zelené s úzkými filtry; nápověda commandu popisuje nové
      parametry.

---

## Výsledky ověření (pilotní export, 3 559 záznamů)

- **D36:** kontrola názvů zamítla 3 přesné shody = 102 řádků (`518201`–`518203`
  s leasingovými názvy). Přesné shody 310 → 208; `it.phone`, `it.internet`
  a `services.postage` z reverzu zmizely. Report to vykazuje včetně rozpadu
  per účet.
- **D37:** 40 kandidátů → 31 přijatých, 9 zamítnutých pokrytím
  (`people.catering` 20–37 %, `admin.insurance` 33–45 %, `services.banking`
  37 % — přesně ti z pilotu). V náhledu zůstávají se stavem „pod prahem
  pokrytí".
- **D38:** auto režim vybral `usage` (shoda kódů 86,8 % = 46 z 53), plán
  20 položek s podporou 9–628 řádků. Zamítnuto správně: leasingové splátky
  a úvěry (dominantní `null`), catch-all položky („Ostatní služby" 1 584
  řádků → 27 %, „Materiál" 2 655 → 27 %) prahem podílu, malé vzorky prahem
  řádků.

## Poznámky k implementaci

- **Podobnost názvů** není jen `similar_text`: ta porovnává znaky v pořadí
  a propadne na přeházených slovech („Připojení k internetu" ×
  „Internetové připojení"). Přidána tokenová shoda s pětiznakovým prefixem
  (pokrývá české skloňování) a podřetězec; bere se maximum.
- **Nabídka bez názvů** (mapa postavená bez nich) kontrolu **projde** —
  degradovat všechno, když není čím ověřovat, by reverz zabilo. Chybějící
  název **v záznamu** je proti tomu selhání, jak zadání chce.
- Degradace přesné shody může skončit **syntetickou shodou téhož štítku**
  (např. `503xxx` = pohonné hmoty). To je záměr: hrubší úroveň je slabší,
  ale legitimní signál — a `degradedExact` je v reportu vidět.
- `usageByItemCode()` žije v `BookingHistoryAnalysis`, ne v taggeru:
  agregace per kód je odvozená statistika (stejná konvence jako
  konzistence a mrtvé štítky), takže ji dostane i kolektivní skript.
  Tagger je pak čistá služba bez DB.
- `--tag-items` je `VALUE_OPTIONAL`, takže rozlišuje tři stavy (chybí /
  bez hodnoty = auto / s hodnotou). Testy nesmí posílat `true` — Symfony
  z něj udělá `"1"`.
- Zápis obou režimů jde přes jediné `ContentTagBackfill::apply()` (merge
  štítků, izolace chyb per položka) — žádná druhá zápisová cesta.
- Pořadí v `execute()` se změnilo: otagování běží **před** zápisem
  reportu, aby v něm mohla být sekce s jeho výsledkem.
