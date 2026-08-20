# Import účetní historie (booking history) — formát a zpracování

**Stav:** design potvrzen v chatu (D29–D35), k implementaci

**Cíl:** Nový Shipard umí přijmout soubor s agregovanou účetní historií
z libovolného zdrojového systému (starý Shipard, cizí ERP, tabulky) ve
formátu `shpd.economy.booking-history.v1` a nad ním: (a) vyrobit **report**
o kvalitě zdroje a o taxonomii obsahových štítků (pokrytí, konzistence),
(b) založit **seed pravidla** `IČO → štítek` (origin `seed`),
(c) **reverzně otagovat** existující položky. Tím se z jednorázového
validačního batche (D8) stává trvalá onboarding schopnost produktu.

Protistrana: export ze starého Shipardu —
`old_shipard: modules/imports/newShipard/tasks/27-booking-history-export.md`.
Tento dokument je **kanonický vlastník specifikace formátu**.

---

## Návaznost

- Uzavírá třetí větev obsahové klasifikace: D8 (validace taxonomie) +
  seed pro D21/D12b (kolektivní inteligence přes IČO). Issue #35.
- Staví na: cfgItem `core.exchange.contentTags`, `ContentTagResolver` +
  `AccountingItemsOffer` (fallback/reverz účet↔štítek z otagované nabídky),
  `core_exchange_tag_rules` (origin `seed` rezervován od tasku 1),
  `AnthropicLlmClient` + `AiBackendResolver` + settings override backendu
  (D18), `TableGateway::saveDocument` pro zápisy položek, vzor `shpd-ds`
  commandů (`src/Command/DataSource/`, registrace
  `src/Cli/DsApplicationFactory.php`).
- Kolektivní analýza napříč ~120 DS (sdílený seed balík, korekce
  taxonomie) je **samostatný pozdější task** — offline skript nad mnoha
  exporty; vyhodnocovací logiku sdílí s reportem odsud, proto ji
  implementovat jako znovupoužitelné služby, ne kód zadrátovaný
  do commandu.

## Rozhodnutí

| D | Rozhodnutí |
|---|---|
| D29 | Formát `shpd.economy.booking-history.v1`: JSONL, první řádek hlavička, dále agregované záznamy. Zdrojová strana exportuje **jen fakta** (texty, účty, IČO, četnosti) — žádné štítky, žádnou znalost taxonomie. Přepočet novou verzí taxonomie nevyžaduje nový export. |
| D30 | Agregační klíč záznamu: `{companyId, account, itemCode, rowTextNorm}`. Exportují se **obě textová pole** — text řádku dokladu (obsah, vstup LLM) i název položky (účetní kategorizace, součást reverzního signálu). Normalizace klíče jen lehká (trim, collapse whitespace, lowercase); do souboru jde nejčetnější originální varianta textu. |
| D31 | Zpracování v1 = CLI `shpd-ds booking-history` s režimy `--report`, `--apply-seed`, `--tag-items` (kombinovatelné), `--dry-run`. UI (upload + report obrazovka) je fáze 2, mimo scope. |
| D32 | Seed per-DS: dominantní reverzní štítek per IČO s prahy `share >= 0.8` (podíl řádků) a `docCount >= 3`. Zápis origin `seed`; existující `user`/`learned` pravidla se **nikdy** nepřepisují (skip + log), existující `seed` se aktualizuje. |
| D33 | Degenerované texty (prázdný, == název položky, == číslo účtu) se detekují při zpracování, nikoli při exportu. Jejich podíl = metrika kvality zdroje v reportu (celkem + per účet). LLM klasifikace běží jen nad obsahonosnými texty; výsledky se cachují do sidecar souboru, opakované běhy reportu jsou zadarmo. |
| D34 | `--tag-items`: reverzní otagování živých položek DS dle mapování účet→štítek z nabídky aktivní varianty; jen jednoznačné účty, jen položky s prázdnými `content_tags`, zápis přes `TableGateway`. (Sdílí logiku se settings obrazovkou z D27 — extrahovat do společné služby, pokud už není.) |
| D35 | Report: markdown soubor (`--report-out`, default vedle vstupu) + souhrn na stdout. Sekce: kvalita zdroje, pokrytí taxonomie, konzistence (LLM × reverz), mrtvé štítky, náhled seedu. |

## Specifikace formátu `shpd.economy.booking-history.v1` (kanonická)

JSONL, UTF-8, jeden JSON objekt na řádek. Implementace materializuje tuto
specifikaci do `docs/booking-history-format.md` (a odtud ji budou citovat
obě strany i budoucí cizí exportéry).

**Řádek 1 — hlavička:**

```jsonc
{
  "format": "shpd.economy.booking-history",
  "version": 1,
  "sourceSystem": { "name": "shipard-e10", "version": "..." },   // volný identifikátor zdroje
  "sourceRef": "<identifikace zdrojového DS/firmy — volný string>",
  "chartVariant": "default" | "npo" | "unknown",
  "currency": "CZK",              // měna částek (domácí měna zdroje)
  "period": { "from": "2019-01-01", "to": "2026-06-30" },   // účetní datum, může být null
  "docTypes": ["invni"],
  "exportedAt": "2026-08-20T10:00:00+02:00",
  "recordCount": 12345
}
```

**Řádky 2+ — agregované záznamy:**

```jsonc
{
  "companyId": "26378191",        // IČO dodavatele, normalizované; null = neznámé
  "account": "518202",            // číslo účtu z položky (string); null = položka bez účtu
  "itemCode": "518202",           // kód položky ve zdroji; null
  "itemName": "Internetové služby",   // název položky; null
  "rowText": "Měsíční paušál za internet 100/10",  // nejčetnější originální varianta
  "docCount": 74,                 // počet dokladů
  "rowCount": 96,                 // počet řádků
  "totalAmount": 366300.00,       // suma základů v domácí měně; null pokud zdroj neumí
  "firstDate": "2019-02-11",      // účetní datum prvního/posledního výskytu
  "lastDate": "2026-06-05"
}
```

Pravidla: záznamy s `companyId: null` jsou legální (nepoužijí se pro seed,
jen pro validaci/report); `account: null` dtto (jen metrika kvality).
Neznámá pole se ignorují (dopředná kompatibilita). Verze se zvyšuje jen
při nekompatibilní změně.

## Scope

**In:**
1. `docs/booking-history-format.md` — materializace specifikace výše.
2. Parser + validátor souboru (`BookingHistoryFile`): hlavička, streamované
   čtení záznamů, srozumitelné chyby s číslem řádku.
3. Služby vyhodnocení (znovupoužitelné, testovatelné bez CLI):
   - `BookingHistoryQuality` — degenerace textů (D33), podíly, top účty;
   - reverz účet→štítek: reuse `AccountingItemsOffer` mapování dle
     `chartVariant` hlavičky (`unknown` → default + poznámka v reportu;
     `npo` → NPO nabídka); prefix tolerance: přesná shoda čísla, jinak
     shoda syntetiky (prvních 3 číslic) pokud je v nabídce jednoznačná;
   - `BookingHistoryClassifier` — LLM klasifikace distinct obsahonosných
     `rowText` (batch ~50 textů / volání, prompt s enum z cfgItem, vzor
     `ContentTagClassifier` — zvážit extrakci sdíleného prompt builderu);
     sidecar cache `<input>.tags.jsonl` `{rowTextNorm, tag|null,
     promptVersion}`; přepínač `--backend` (ndx/název backendu, default
     jako D18);
   - `BookingHistorySeedBuilder` — agregace per IČO z reverzních štítků,
     prahy D32, výstup kandidátů se supportem;
   - `BookingHistoryReport` — markdown dle D35.
4. Command `src/Command/DataSource/BookingHistoryCommand.php`
   (`shpd-ds booking-history --input=<file> [--report] [--report-out=…]
   [--apply-seed] [--tag-items] [--dry-run] [--backend=…]`):
   - `--report`: kvalita + pokrytí (podíl a top clustery textů s LLM
     null, objemné účty bez reverzního štítku) + konzistence (matice
     shody LLM×reverz per štítek, štítky s neshodou > prahu, rozptyl
     účtů per štítek) + mrtvé štítky + náhled seed kandidátů;
   - `--apply-seed`: zápis `core_exchange_tag_rules` dle D32 (dry-run
     vypíše plán); origin `seed`, `confirmed=1`;
   - `--tag-items`: D34 (dry-run vypíše plán);
   - bez režimu → jen validace souboru + souhrn hlavičky.
5. Testy, dokumentace (odkaz z ai-analysis.md sekce obsahové eskalace).

**Out:**
- UI (upload, report obrazovka) — fáze 2.
- Kolektivní skript napříč DS a sdílený seed balík — samostatný task.
- Import endpoint přes HTTP API (CLI stačí pro v1).
- Export ze starého Shipardu (task na staré straně).
- Jakékoli změny produkční eskalační pipeline.

## Testy

1. **Parser/validátor**: validní soubor; chybějící/rozbitá hlavička;
   neznámá pole ignorována; chyba s číslem řádku.
2. **Kvalita**: degenerace (prázdný / == itemName / == account),
   podíly per účet.
3. **Reverz**: přesná shoda; syntetika jednoznačná/kolizní; npo vs
   default vs unknown varianta.
4. **Classifier**: batch parsování, neznámý štítek zahozen, cache hit
   (druhý běh bez LLM volání), degenerované texty se neklasifikují.
5. **SeedBuilder**: prahy share/docCount; companyId null se přeskočí;
   remíza dominance → bez kandidáta.
6. **Apply-seed**: insert; existující user/learned skip; existující seed
   update; dry-run nemění DB.
7. **Tag-items**: jen prázdné `content_tags`, jen jednoznačné účty,
   zápis přes gateway; dry-run.
8. **Command**: end-to-end nad fixture souborem (malý JSONL v tests
   fixtures) s mock LLM.

## Commit strategie

1. Formát (docs) + parser/validátor + testy.
2. Kvalita + reverz + seed builder + testy.
3. Classifier s cache + report + testy.
4. Command + apply režimy + dokumentace.

## Hotovo když

- [ ] `docs/booking-history-format.md` existuje a odpovídá specifikaci.
- [ ] `shpd-ds booking-history --input=x.jsonl` zvaliduje soubor a vypíše
      souhrn; rozbitý soubor → srozumitelná chyba s řádkem.
- [ ] `--report` vyrobí markdown se všemi sekcemi D35; druhý běh nad
      týmž vstupem nevolá LLM (cache).
- [ ] `--apply-seed --dry-run` vypíše plán; ostrý běh založí seed
      pravidla dle prahů a nikdy nepřepíše user/learned.
- [ ] `--tag-items` otaguje jen jednoznačné netagované položky.
- [ ] Soubor s `companyId`/`account` null projde bez pádu a promítne se
      do metrik kvality.
- [ ] Testy zelené s úzkými filtry.
