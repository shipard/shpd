# Design: vlna D — nálezy akceptace plného re-importu (D6)

> **Status:** k diskusi (D11–D15; D11 rozhodnuto) · **Datum:** 2026-07-21
> **Návaznost:** `design-import-row-operations.md` (vlna C),
> akceptační protokol D6 (DS A 112 chyb, DS B 145 chyb)

## Výchozí stav po D6

Vlny A + C ověřeny: deník DS A **0 nevyrovnaných** (z 1 830), DS B 4
haléřové; invno DS B 988/988 se sumami na korunu; banka finálně
zelená (7 024/1, 1 943/3); vzorové doklady vlny C účtují identicky se
starým vč. `payment_reference`. Zbylé chyby tvoří pět tříd.

## D11 — `partner_bank`: povinnost se stěhuje k platbě (ROZHODNUTO)

**Dopad:** 38 (DS A) + 113 (DS B) nenaimportovaných invni.
**Místo:** `modules/docs/invoicesIn/src/ReceivedInvoiceDocument.php` —
`addError('partner_bank', …)` při stavech 20/40/80 a `paymentMethod = 1`.
**Řešení (potvrzeno):** import i běžné uložení **neblokovat** — validace
se z uložení degraduje na warning (`partner_bank_recommended`, stejný
text), hard požadavek se přesune do budoucího platebního flow (tvorba
platebního příkazu z dokladu; modul zatím neexistuje — zapsat do jeho
zadání, až vznikne). Historické doklady jsou zaplacené, blokace nedává
smysl ani při ručním pořízení.

## D12 — Kurzové rozdíly saldokonta: čtyři operace s kategoriemi (NÁVRH)

**Dopad:** 83 + 194 cmnbkp zaparkovaných na stavu 20 (op 1090011/12,
~295 řádků) — deník i saldo za tyto doklady chybí.
**Data:** starý řádek nese částku, partnera a `symbol1` = číslo párované
faktury; starý deník generuje **dva zápisy**: P&L strana (563/663)
× saldo strana (311/321). Analytiky per DS (DS B 563100/311100) —
přesný případ pro maskové kategorie.

**Varianta A (doporučená):** čtyři první-class operace
`acc.fxLossReceivable` (MD 563 / DAL 311), `acc.fxGainReceivable`
(MD 311 / DAL 663), `acc.fxLossPayable` (MD 563 / DAL 321),
`acc.fxGainPayable` (MD 321 / DAL 663) — všechny s `rowPartner: 1`,
`rowPaymentId: 1` (saldo párování přes payment_reference = starý
symbol1), účty výhradně kategoriemi (`fx.loss` → maska 563, `fx.gain` →
663, receivables/payables už existují). Předpis: dva kroky per operace
(každá strana svou kategorií). Migrace určí konkrétní operaci ze
znaménka/strany starého deníku per řádek. Není to interim — je to model,
který accbal FX fáze rovnou použije (párování už bude mít data).

**Varianta B (zamítnout?):** interim `acc.record` s účty joinem na starý
deník (à la D9) — bez kategorií, bez saldo významu, později se předělá.

Operace 1090070–72 (28 řádků, odpisy/inventarizace?) — rozebrat v PRD,
pravděpodobně `acc.record` + join (jsou marginální a bez saldo vazby).

## D13 — Parse čísla: fallback přes řady docTypu (MECHANICKÉ)

**Dopad:** 41 DS A dokladů (0 DS B). Všech 41 = tentýž mechanismus:
doklad je ve zdroji přilinkovaný ke špatné řadě (`dbCounter` → docKeyId
nesedí s 5. znakem čísla; např. 601300001 pod řadou „Otevření" kódu 9,
601910001 tamtéž). Číslo je u historických dat ground truth.
**Řešení:** když parse proti formuli přilinkované řady selže, zkusit
formule ostatních řad téhož docType; právě jedna shoda → použít ji
(+ warn s oběma řadami), nula či víc → chyba jako dnes. Poznámka:
řada kódu 0 („Počáteční stavy") už ve zdroji DS A existuje (INSERT
2026-07-21) — fallback ji dohledá.

## D14 — Duplicitní `(řada, rok, pořadí)` (INVESTIGACE)

**Dopad:** 15 DS A + 2 DS B chyb, vždy pár dokladů na stejném klíči
(`'2-10-7'`, `'4-12-307'`, …). Hypotéza 1: část je downstream D13 —
špatně přilinkovaný doklad se parsuje do cizí řady a koliduje s jejím
právoplatným číslem → po D13 zmizí. Hypotéza 2: skutečné duplicitní
číslo ve zdroji → rozhodnout (oprava zdroje vs. import-mode sufix).
Postup: po implementaci D13 zopakovat dry-run, enumerovat rezidua
s dvojicemi starých ndx a rozhodnout adresně. Kandidát na kořen 244
`unq_series_seq` chyb z alfy — ověřit tam stejným postupem.

## D15 — Drobné (ENUMERACE + ADRESNÉ OPRAVY)

1. **`account_not_found`** — 15 DS A + 2 DS B: přímý účet řádku
   (majetek z joinu na starý deník) není v novém rozvrhu. Prověřit
   `AccountsRunner`: importuje celý rozvrh vč. archivních účtů?
   (Podezření na stejný vzor jako linkable states.)
2. **Nevyrovnaný při apply** — 2 DS A + 1 DS B: doklady s reálným
   nesouladem deklarovaných součtů; enumerovat, patrně vady zdroje →
   oprava zdroje či akceptace.
3. **4 haléřové rozdíly deníku** (DS B cmnbkp 601410041, 601690001,
   601790001, 602690003): starý deník vyrovnaný, náš ±0,01–0,03 —
   per-řádkové zaokrouhlení vs. stará agregace; diff po řádcích na
   jednom dokladu a rozhodnout (agregační krok / rounding zápis).
4. **1 datum validace** (DS A) — enumerovat.

## Akceptace vlny D

Po implementaci: cílený re-run selhaných dokladů (nejsou v LocalIdMap)
+ re-run cmnbkp stavu 20 (forget doc? rozmyslet v PRD — parkované DOK
v mapě jsou). Kritéria: počty per docType×stav = zdroj (modulo mapování
stavů), invni/invno sumy po řadách na korunu na obou DS, deník 0
nevyrovnaných (mimo akceptované haléře, dokud D15.3 nerozhodne),
stav 20 jen u dokladů, kde je to obhajitelné.

## Mimo scope

- Platební příkazy / payment flow (D11 tam jen deleguje požadavek).
- Accbal FX fáze — D12 varianta A jí připravuje data, párování samotné
  je její.
- Oprava alfy — po vlně D bude k dispozici kompletní sada fixů
  (banka ×5, DPH ×3, řádky ×2, D11–D15); rozhodnutí samostatně.
