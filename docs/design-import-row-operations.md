# Design: řádkové operace pro zálohy, majetek a řádky bez položky (vlna C)

> **Status:** k diskusi (D1–D7) · **Moduly:** docs.core, economy.accounting,
> imports.newShipard (old_shipard) · **Datum:** 2026-07-20

## Problém

Migrace klasifikuje řádek jako `item` jen při vazbě na položku
(`item > 0`), jinak `text` — a textový řádek ztrácí operaci, částky
i účetní význam. Zasažené třídy (ověřeno na msi-zlin + lefreal):

| stará operace | význam | msi | lefreal | starý deník |
|---|---|---|---|---|
| 1020101 | Odpočet poskytnuté zálohy (invni, záporné částky) | 449 | 88 | DAL 314901 (tax≠0: 232×) / DAL 314001 (tax=0: 249×) |
| 1020104 | Zdanění poskytnuté zálohy (invni) | 212 | 57 | MD 314901 + DPH z rekapitulace |
| 1010101 | Odpočet přijaté zálohy (invno, záporné částky) | **1 036** | — | MD 324001 (tax=0: 1 033×) / MD 324901 (tax≠0: 1×) |
| 1010104 | Zdanění přijaté zálohy (invno) | 1 | — | zrcadlo 1020104 |
| 1090050/51/52 | Pořízení majetku (vazba `property`, ne item) | 257 | 5 | MD 042002 / 042001 / 501101 |
| 1010102/1010199 | Nákup zásob bez item | 17 | 1 | kategorie nákladů |
| 1010001/02/99, 1090060 | Prodej bez item (služby/zásoby/majetek) | 76 | — | kategorie výnosů |

**Dopad je dvojí a druhý je zákeřnější:** na invni rozbitý deník (alerty),
na invno **tiché podhodnocení/nadhodnocení** — ztracený řádek zmizí
z řádků i součtů zároveň, deník je vyrovnaný a alert nevznikne. Kontrola
old↔new součtů po číselných řadách (msi): invni v novém **−15,7 M**
(ztracené kladné řádky), invno místy **+0,5 až +1 M na řadu** (ztracené
záporné odpočty → nadhodnocené pohledávky).

## Co už nová strana umí (průzkum)

- `docs_core_rows` má `account` + `acc_side`; `rowOperations.jsonc` zná
  `rowAccount: "direct"`, `rowPartner`, `rowPaymentId` (dnes využívá jen
  cmnbkp: `acc.record`, `acc.item`, zápočty).
- `AccountingEngine` umí `accountSrc: "row"`, `sideSrc: "row"`,
  `reverseSign` (otočení znaménka) — záporný řádek na kroku
  s `reverseSign` skončí jako kladný zápis na protistraně. Beze změn
  engine.
- DPH rekapitulace záporné řádky přirozeně netuje (po vlně A) — odpočet
  se záporným základem i daní sníží nárok správně; EUCZ112 (bez daně)
  se mapuje standardní cestou `mapVatCode`.
- Migrace už umí posílat kontační řádky (cmnbkp cesta, task
  11-cmnbkp-import) — applier `account`/`accSide` na řádku přijímá.

## Rozhodnutí k potvrzení

**D1 — Čtyři nové operace pro zálohy** v `rowOperations.jsonc`:
`purchase.advanceDeduction`, `purchase.advanceVat` (invni),
`sale.advanceDeduction`, `sale.advanceVat` (invno). Všechny
`rowAccount: "direct"` (účet 314xxx/324xxx je analytika, ať je explicitní
na řádku) a `rowPaymentId: 1` — `payment_reference` ponese číslo
zálohového dokladu (staré `symbol1`), na které později naváže saldokonto
Fáze 4+ (párování záloh). Částky odpočtů zůstávají na dokladu **záporné**
(věrný obraz faktury), stranu otáčí předpis.

**D2 — Jedna operace pro majetek**: `purchase.asset` (invni),
`rowAccount: "direct"`. Migrace mapuje účet deterministicky:
1090050 → 042002, 1090051 → 042001, 1090052 → 501101. Vazba `property`
se do nového **nepřenáší** — modul majetku v novém neexistuje; evidence
zůstává ve starém a při budoucí migraci majetku se řádky dohledají přes
číslo dokladu. (Alternativa „property do description" zamítnuta —
znečišťuje data.)

**D3 — Řádky bez item přestávají degradovat na text.** Nová klasifikace
v `DocsRunner::loadRows()`: (a) řádek s mapovanou operací → operační
řádek (item nepovinný — kategorie předpisu účet dodá: 504/518/548, 6xx);
(b) item/itemCode → item řádek jako dnes; (c) text řádek **jen** bez
peněz (`priceAll` prázdné/0); (d) řádek s penězi a nemapovanou operací →
**hlasitá chyba importu**, nikdy tichý text. Tím se pokryjí i třídy
1010102/1010199 a prodejní 1010001/02/99/1090060 bez item.

**D4 — Kroky účtovacího předpisu** (`accountingRules.cz.jsonc`):
- invni: `{src rows, accountSrc row, side 1, reverseSign 1,
  operation purchase.advanceDeduction}`, `{src rows, accountSrc row,
  side 0, operation purchase.advanceVat}`, `{src rows, accountSrc row,
  side 0, operation purchase.asset}`;
- invno zrcadlo: `{… side 0, reverseSign 1, sale.advanceDeduction}`,
  `{… side 1, sale.advanceVat}`.
Kategorie nákladů/výnosů u D3(a) beze změn — jen se k nim dostanou
i řádky bez item.

**D5 — Účty odpočtů podle zdanění**: migrace plní `account` pravidlem
`tax ≠ 0 → x14901/x24901, tax = 0 → x14001/x24001` (ověřeno proti starému
deníku: 481/481 na invni, 1 034/1 034 na invno). Pokud se v jiném DS
objeví jiná analytika, pravidlo se ověří proti tamnímu
`e10doc_debs_journal` stejným dotazem (do PRD jako kontrolní krok).

**D6 — Oprava dat = plný re-import obou dev DS** po nasazení (ds-reset +
`all`). Vyřeší zároveň 252 konceptových dokladů lefreal (kolize kódů
účtů) a definitivně srovná slité výpisy. Akceptační kritérium: old↔new
kontrola součtů po číselných řadách (COUNT + SUM per `LEFT(doc_number,3)`)
sedí na korunu u invni i invno (modulo koncepty/storna), účetní alerty
klesnou na známé akceptované případy.

**D7 — Rozpad na PRD**: (1) nov_shipard — operace + předpis + testy
(unit: engine kroky s reverseSign a direct účtem; integrace: invni
s odpočtem end-to-end, invno zrcadlo); (2) old_shipard task 21 —
DocsRunner mapování a klasifikace řádků (D3, D5) + dry-run ověření na
vzorových dokladech 49264, 56036 (invni) a vzorku 1010101 (invno);
(3) re-import + moje read-only akceptace dle D6.

## Vzorové doklady (důkazní materiál)

- **49264 / 22010540** (invni): majetek 1090052 „Matrace" (+43 538,
  property 276, MD 501101) + 2× odpočet 1020101 (−20 000,01 / −23 538,
  DAL 314901); netto ≈ 0, DPH netto 0 — starý deník bez 321/343.
- **56036 / 22210024** (invni): zdanění zálohy 1020104 (+7 290 → MD
  314901 6 024,79 + MD 343110 1 265,21) + odpočet tax0 (−7 290 →
  DAL 314001).
- invno odpočty: 1 033× tax0 → MD 324001.

## Dodatek 2026-07-20: per-DS analytiky (D9, D10 — D8 zrušeno)

Kontrolní dotaz D5 na lefreal odhalil, že analytiky nejsou univerzální:
zálohy účtuje na 314900/314100/324100 (msi: 314901/314001/324901/324001)
a majetek dokonce **per řádek** (042100 i 042500 pro tutéž operaci, dle
druhu majetku). Strany a struktura ověřeny se shodou na obou DS —
výchozí předpis (D4) platí beze změny až na náhradu `accountSrc: "row"`
za `cat` u zálohových kroků (viz D10). Původní závěr „maska to
nevyřeší“ platil jen pro plné účty — prefixové masky kategorii
dohledávají per DS v jeho rozvrhu a struktura analytik (x14/x149)
je napříč DS shodná, viz D10:

**D10 — Zálohové účty kategoriemi předpisu (ruší D8).**
Po diskusi: ruční per-DS config je při stovkách budoucích DS neudržitelný
a nová strana má ekvivalent starého `acc-default.json` —
`resolveCategoryAccount` + `maskResolver` (`LIKE prefix%`, první aktivní
analytika rozvrhu DS). Ověřeno proti rozvrhům obou DS: maska `314` →
314001/314100, `3149` → 314901/314900, `324` → 324001/324100 — vše
přesně dle starého deníku; `3249` → 324901 na msi, na lefreal analytika
neexistuje (účtují 324100 = výsledek masky `324`).

Zálohové operace tedy **nemají** `rowAccount` (jako saldokontní zápočty;
`rowPaymentId` zůstává), kroky předpisu dostanou `cat` místo
`accountSrc: "row"` a předpis dostane položky kategorií:

```jsonc
{"cat": "advances.given",    "accountMask": "314",  "query": {"vat_amount": 0}},
{"cat": "advances.given",    "accountMask": "3149"},
{"cat": "advances.received", "accountMask": "324",  "query": {"vat_amount": 0}},
{"cat": "advances.received", "accountMask": ["3249", "324"]}
```

Rozlišení zdaněná/brutto jde pořadím záznamů a `query` (loose
porovnání, `vat_amount 0.00/NULL` → brutto). Jediná změna engine:
`accountMask` smí být pole — řetěz masek zkoušených po řadě
(`resolveCategoryAccount`), první dohledaná vyhrává. Migrace účty záloh
vůbec neodesílá; dotaz D5 zůstává jako akceptační kontrola před
importem každého nového DS (porovnání masek proti tamnímu deníku),
už ne jako zdroj konfigurace.

**D9 — Majetkový účet per řádek ze starého deníku** (beze změny —
kategorie per-řádkovou analytiku dle druhu majetku vyjádřit neumí): DocsRunner dohledá MD účet řádku joinem na
`e10doc_debs_journal` přes `document` + `property` + částku (deníkové
řádky majetku nesou `property` — silný klíč). Právě jedna shoda = účet;
jinak chyba dokladu (hlasitě, dle D3d). Rozsah je malý (msi 257 + lefreal
5 řádků), per-řádkový lookup je levný.

Pro úplnějsí budoucnost: per-DS mapování kategorií účtů na nové straně
(nastavení DS pro UI předvykávání zálohových účtů) patří k accbal
Fázi 4+, ne do migrace.

## Mimo scope

- Saldokontní párování záloh (accbal Fáze 4+) — D1 mu připravuje
  `payment_reference`; návrh párování je samostatná kapitola.
- Modul majetku (property evidence) a jeho migrace.
- Zálohové faktury `invpo` (v novém docType neexistuje; nemigrují se —
  stav quo, netýká se účetnictví finálních faktur).
