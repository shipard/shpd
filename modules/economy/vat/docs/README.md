# Modul `economy.vat` — daňové výstupy

Živé výstupy DPH počítané **on-demand** z potvrzených dokladů (Fáze 0+1
milníku M1, `tasks/taxes-phase01.md`, D1–D6), **instance daňových tvrzení**,
do kterých se doklady zařazují při uložení (revize po fázi 1,
`tasks/vat-report-periods.md`, D7–D13) a **podání** — persistovaný snapshot
toho, co se za období podalo (Fáze 2, `tasks/vat-filings.md`, D14–D22).
Vše v issue #55.

## Co modul dělá

Tři reporty v doméně `report` (docs/reports.md), registrované
v `config/reports.jsonc` s `periodSource: "vatPeriod"` a `vatReportType`
(`return` / `cs` / `rs`) — parametr běhu je `period` = id instance tvrzení
odpovídajícího typu:

| Report | Výstup |
|---|---|
| `economy.vat.returnLive` | Přiznání k DPH (DPHDP3) — řádky formuláře + dopočty 46/62–65, operativní stav (ř. 64/65) a křížová kontrola proti deníku v messages |
| `economy.vat.controlStatementLive` | Kontrolní hlášení (DPHKH1) — sekce A1/A2/A4/A5/B1/B2/B3, detailní řádky s ev. číslem / DIČ / DPPD, agregáty A5/B3 |
| `economy.vat.recapitulativeStatementLive` | Souhrnné hlášení (DPHSHV) — agregace per (kód plnění, DIČ odběratele) |

Nic se nepersistuje (D1) — persistence přijde až s Podáním (Fáze 2,
doména `filing`).

## Mapovací konfigurace

`config/vat-reports-cz.jsonc` (cfgItem `economy.vat.reports.cz`) mapuje
**každý** kód `world.vat.cz` na výstupy: `dp3 {row, col?}`, `kh {group,
kodPredPl?}`, `sh {kod}` — i vyloučení je explicitní `null`. Princip
shodný s rozhodnutím #8 účtování: `world.vat` zůstává legislativní vrstva
bez výkaznických konvencí (D3), výkaznictví per country žije tady.
Úplnost vůči číselníku a shodu s `vatReturnRow` hlídá
`VatReportsMappingCompletenessTest`. Sekce `dp3Rows` nese lokalizované
popisky řádků přiznání pro živý report. Sekce `reportTypes` nese zákonný
počátek typu výstupu (`cs.validFrom = 2016-01-01`, #58 — viz §Instance);
neznámý typ nebo špatné datum je chyba konstruktoru `VatOutputsMapping`.

Po změně configu je potřeba `vendor/bin/shpd-ds ds-upgrade` (rekompilace
cfgItem); samotné deklarace reportů se čtou z modulu za requestu.

## Instance daňových tvrzení (D7–D13)

Tabulka `economy_vat_report_periods` (`tables/*.md`): per registrace DPH
a typ (`return` přiznání / `cs` kontrolní hlášení / `rs` souhrnné hlášení)
jeden záznam s rozsahem s denní přesností, uživatelsky editovatelný
(viewer **Daňová tvrzení** v sekci účetnictví, `ReportPeriodsViewer` +
`ReportPeriodsForm`). Nahrazuje kalendářní mřížku období DPH: rozdílné
frekvence KH/SH, změny periodicity i vznik/zánik plátcovství uprostřed
období jsou jen jiné rozsahy v datech; historii dodá import reálných
rozsahů podaných tvrzení (task 30 ve starém Shipardu).

**Pravidla instance** (`ReportPeriodDocument`): bez překryvu v rámci
(registrace, typ) — tvrdá chyba; díra k sousedům = varování. Zrušení
(přechod do 90 přes `stateTransitionsRunDocumentHooks`) i tvrdé smazání
blokuje zámek a přiřazené doklady; guard na podání je připravený bod
rozšíření. Zámek (`locked`) se zatím nevynucuje (Fáze 4).

**Zařazení dokladu** — sloupce `vat_period` / `cs_period` / `rs_period`
na `docs_core_heads` (extension `extensions/docs_core_heads.jsonc`; docs.core
na economy.vat nezávisí). Plní `DocsHeadsVatPeriodHandler` jako
`beforeSave` documentEventHandler (uvnitř save transakce, s recapem už
spočítaným) pravidlem `VatPeriodAssigner`:

- `vat_period` = instance `return` registrace dokladu obsahující **DUZP**;
- `cs_period` / `rs_period` jen má-li recap aspoň jeden kód s mapováním
  `kh` resp. `sh` ≠ null; instance `cs`/`rs` obsahující **clamped efektivní
  datum** = `COALESCE(vat_dppd, vat_duzp)` oříznuté do rozsahu instance
  přiznání dokladu. Jinak NULL (= doklad do hlášení nespadá).
- **Invarianta**: sjednocení dokladů měsíčních `cs` instancí čtvrtletí =
  doklady `return` instance, beze zbytku a bez průniku
  (`VatPeriodAssignerTest::testMonthlyCsInstancesPartitionQuarterlyReturn`).
- **Ruční přesun**: pole změněné v payloadu oproti původnímu řádku handler
  respektuje (ověří existenci, typ a registraci instance); nedotčené pole
  přepočítá. Ruční hodnota tedy drží, dokud doklad neuloží někdo s jinou
  hodnotou v payloadu — formulář posílá aktuální hodnotu, takže běžné
  uložení formulářem ji **přepočítá pravidlem** (limit bez markeru
  „ručně upraveno").
- Import mód žádnou výjimku nemá — věrnost dávají reálné rozsahy
  importovaných instancí (D12).

**On-demand koncepty (D9)**: chybí-li při uložení instance pro datum,
`ReportPeriodsProvisioner` založí koncept (docState 10) dle periodicity
registrace (`tax/cs/rs_period_kind` — D10: už jen defaulty generátoru),
oříznutý do platnosti registrace a o sousední instance. Alert check
`economy.vat.draft_report_periods` (15 min) nabídne koncepty ke kontrole.
Seed běžného období: `VatRegistrationSeedHandler` (`afterSave` na
registraci) a denní cron `shpd-ds vat-periods-ensure` (+ `ds-upgrade`)
zakládají instance pokrývající dnešek a zítřek ve stavu V pořádku.
Dopředu se negeneruje nic.

**Přepočet po změně rozsahu** (`VatPeriodRecalculator`, `afterPersist`
instance, atomicky): dávka = doklady registrace, které na instanci míří
nebo jejichž DUZP / efektivní datum spadá do nového rozsahu. Přepíše se
jen **nekonzistentní** ukazatel (NULL, nebo instance, která datum dokladu
pro svůj typ neobsahuje); konzistentní ukazatel jinam zůstane — tím
přežije ruční přesun do instance, kam datum dokladu spadá. Instance se
při přepočtu nezakládají (find-only), doklad s NULL se dorovná při svém
příštím uložení.

**Zákonný počátek typu výstupu** (`reportTypes.{type}.validFrom` v mapovacím
configu, #58): kontrolní hlášení existuje od 1. 1. 2016 (§ 101c ZDPH),
přiznání a souhrnné hlášení omezení nemají. Je to národní pravidlo
výkaznictví, proto žije ve `vat-reports-cz.jsonc`, ne v názvosloví typů
instancí. Čtyři místa, kde se uplatní:

- **přiřazení** (`VatPeriodAssigner`): clamped efektivní datum před
  `validFrom` ⇒ `cs_period` NULL a lookup se pro ten typ **nevolá** —
  žádný on-demand koncept, žádný alert. Bez mapování žádné omezení;
- **generátor** (`ReportPeriodsProvisioner`, `$validFromByType`): `create()`
  před počátkem vrací null, dolní mez kandidáta = pozdější z platnosti
  registrace a počátku typu. Cesty bez kompilovaného configu (cron na DS
  bez `compiled.*.json`) předají prázdné pole — generují jen dnešek/zítřek;
- **přepočet** (`VatPeriodRecalculator`): ukazatel na instanci typu
  s počátkem, jehož doklad má efektivní datum před ním, je nekonzistentní
  → NULL i když instance datum obsahuje. Přepočet běží jen při změně
  rozsahu nebo stavu instance, guard přiřazených dokladů před ním — na DS
  bez reimportu se anachronická instance zruší až po úpravě jejího
  rozsahu (ta doklady odpojí). Reimport (`ds-reset`) problém řeší úplně;
- **import** (task 30 runner) se nemění — přenáší jen reálné reporty,
  před 2016 žádné KH neexistují.

Konec platnosti (`validTo`) záměrně neexistuje — bez použití.

## Koeficient odpočtu (D13, #59)

Krácený nárok na odpočet (kódy s `dp3.col = "reduced"`, v ČR cz-118/119/
341/342) se v DP3 vykazuje ve sloupci „Krácený odpočet" ř. 40–45 a do nároku
ř. 63 vstupuje přes **ř. 52 = Σ krácený × zálohový koeficient**.

**Model** — tabulka `economy_vat_deduction_coefficients` (`tables/*.md`):
koeficient patří na **registraci DPH × kalendářní rok**, ne na fiskální
období (vypořádací období je podle § 76 ZDPH vždy kalendářní rok) ani na
DS (registrací může být víc). Konstrukce směrnice 2006/112/ES čl. 173–175
(odpočitatelný podíl, předběžný podíl z minulého roku, roční vyrovnání) —
obecná pro EU, národní je jen mapování na řádky (`vat-reports-cz.jsonc`).
Per rok dvě hodnoty: `coefficient_provisional` (zálohový, § 76 odst. 6)
a `coefficient_settled` (vypořádací). Spravuje se v Nastavení → Účetnictví
→ **Koeficienty odpočtu DPH** (`DeductionCoefficientsViewer` / `Form`,
gate `VatAgendaNavGate`); `DeductionCoefficientDocument` hlídá interval
⟨0; 1⟩, přesnost na setiny (`coefficient_precision`) a duplicitu živých
záznamů (DB index není unique — smazaný záznam 90 nesmí blokovat nový).

**Resolver** — `DeductionCoefficientResolver::provisional($regId, $year)`
je jediná autorita: explicitní zálohový roku → vypořádací roku N−1 →
`1.0000` (`source: default` = plný nárok, stav firmy bez osvobozených
plnění). Jen záznamy ve stavu 40. Rok bez záznamu je legitimní stav, ne
chyba (D13d) — `VatReturnLiveBuilder` to řekne ve zprávách
(`vatReturn.deductionCoefficient` + `…Default`), ale jen když v dokladech
instance je nenulový krácený nárok (nešumět). Rok = rok `date_begin`
instance z `VatPeriodRange`.

**Kalkulátor** — `VatReturnCalculator::calculate($docs, $coefficient)`:
computed 52 ve sloupci `taxFull` (základ 0), 63 = 46 + 52 + 53 + 60.
Krácený sloupec ř. 40–45 zůstává vykázaný. Starý Shipard krácený sloupec
nikdy nevyplňoval, import nic nepřenáší; default 1,00 reprodukuje podaná
tvrzení (ověřeno na zdroji 689089, 01–04/2026: shoda ř. 64 až na
zaokrouhlení řádků na Kč).

**Mimo scope:** ř. 53 (roční vypořádání — vstup `coefficient_settled`
je připravený) a ř. 60 (úprava odpočtu).

## Podání (Fáze 2, D14–D22)

Živý report je vždy přepočtený a nemá lifecycle. **Podání** je jeho opak:
persistovaný snapshot obsahu instance tvrzení v okamžiku sestavení, který
se po podání nemění. Po Fázi 2 má firma trvalý záznam „co jsme za období
podali“ a Fáze 3 nad ním jen generuje XML.

**Kotva je instance tvrzení** (D14): `economy_vat_filings.report_period`,
z ní plyne typ, registrace i rozsah období. Proto instanci s nezrušeným
podáním nelze zrušit ani smazat a instanci s **podaným** podáním nelze
změnit rozsah (`ReportPeriodDocument`) — podaný obsah odpovídá rozsahu,
ve kterém se sestavil. Opravný postup je nové podání jiného druhu, ne
editace rozsahu.

### Dvě úrovně snapshotu (D15)

| Tabulka | Co drží |
|---|---|
| `economy_vat_filing_items` | **dokladová úroveň, vždy úplná** — per (podání, doklad, řádek rekapitulace) základ/daň, DIČ protistrany, ev. číslo, DPPD a **materializovaný výsledek mapování** (řádek/sloupec DP3, skupina a sekce KH, kód plnění SH) |
| `economy_vat_filing_return_rows` | řádky DPHDP3: přesná i podaná hodnota |
| `economy_vat_filing_cs_rows` | řádky DPHKH1 (detaily A1/A2/A4/B1/B2 + agregáty A5/B3) |
| `economy_vat_filing_rs_rows` | řádky DPHSHV per (kód plnění, DIČ) |

Dokladová úroveň je to, co dělá podání ověřitelným: umožňuje **rozdíly
mezi podáními po dokladech** a výklad obsahu i po pozdější změně
mapovacího configu. Items jsou úplné i u dodatečného přiznání, které
v řádcích vykazuje jen rozdíly — jinak by nebylo proti čemu diffovat.

### Druhy podání (D16)

Povolené druhy per typ výstupu žijí v mapovacím configu země
(`vat-reports-cz.jsonc` → `reportTypes.{type}.filingKinds`), názvosloví
v `config/filingKinds.jsonc`:

| Typ | Druhy | Po lhůtě |
|---|---|---|
| `return` (DPHDP3) | řádné, opravné, **dodatečné** | dodatečné = rozdíly + ř. 66 (§ 141 DŘ) |
| `cs` (DPHKH1) | řádné, opravné, **následné** | následné = plný obsah znovu, povinné datum zjištění (§ 101f ZDPH) |
| `rs` (DPHSHV) | řádné, **následné** | následné = plný obsah znovu |

Dodatečné a následné se u jednoho typu vylučují — hlídá to test úplnosti,
ne PHP. `FilingDocument` vynucuje pořadí: řádné jen dokud za instanci není
nic podané, ostatní naopak jen po podaném podání, a v instanci smí být
nejvýš **jeden živý koncept**.

### Zaokrouhlení je pravidlo podání, ne reportu (D17)

Živý report zůstává přesný, snapshot nese obě hodnoty vedle sebe.
`FilingRounding` je čistá třída s pravidlem převzatým ze starého
`VatReturnReport` a ověřeným proti podaným XML:

- každý řádek DP3 se zaokrouhlí **samostatně** na celé Kč;
- dopočty **46 / 62 / 63 se počítají ze zaokrouhlených řádků**, ne
  zaokrouhlením přesného součtu — součet zaokrouhlených se od
  zaokrouhleného součtu běžně liší o jednotky Kč a úřad čeká první
  variantu (to je celý smysl pravidla);
- ř. 52 = zaokrouhlený krácený nárok × zálohový koeficient;
- ř. 64/65 = 62 − 63 podle znaménka.

Kontrolní hlášení se podává na haléře (`roundingUnit: 0.01`), proto
u něj sloupce `_filed` vůbec neexistují. Souhrnné hlášení zaokrouhluje
**nahoru** (`ceil`, věrně dle starého `VatRSReport`) — zadání Fáze 2
uvádělo `round()`, rozhodl referenční kód a podaná XML.

**Dodatečné přiznání** vykazuje rozdíl proti poslední známé daňové
povinnosti, tedy proti **kumulativnímu** podanému stavu: řádné a opravné
podání je plná náhrada, dodatečné se přičítá k základu, ze kterého
vzniklo (`FilingComposer::cumulativeFiledRows`). Diffovat proti hodnotám
jednoho předchozího podání by u druhého dodatečného v řadě dalo nesmysl,
protože to samo nese jen deltu. Ř. 64/65 jsou u dodatečného nulové,
změnu povinnosti nese **ř. 66**.

### Sestavení — `FilingComposer`

Jediná autorita, která zapisuje do items a výstupních řádků. Jde
**stejnou cestou jako živý report**: doklady z `VatDocumentSelection`,
výpočet týmiž čistými kalkulátory, sekce KH z `sectionForCode()` téhož
enginu. Žádná druhá výpočetní větev; snapshot přidává jen zaokrouhlení
a materializaci mapování.

Rozdíl je v přísnosti: **kód DPH bez mapování je tvrdá chyba** sestavení
(živý report ho jen hlásí) — z podání nesmí nic tiše vypadnout, takže
výjimka rollbackne celé uložení.

Sestavení je idempotentní (DELETE + INSERT celého podání), takže
„Přepočítat“ nevyrábí duplicity. **Transakci vlastní volající** —
composer běží i z `FilingDocument::afterPersist`, tedy uvnitř save
transakce, a MariaDB nemá vnořené transakce (vzor `VatPeriodRecalculator`).

Volání: `afterPersist` nového konceptu i změny, která mění obsah
snapshotu; akce **Přepočítat** (`POST /_vat/filing-compose`); CLI
`shpd-ds vat-filing-compose --period=<id> [--kind=…] [--date-found=…]`
nebo `--recompute=<id podání>`.

### Lifecycle bez EPO (D18)

cfgItem `economy.vat.docStatesFilings`: **10 Sestaveno** → 40 | 90,
**40 Podáno** (`readOnly`, bez dalších přechodů), **90 Zrušeno**.
Odeslání na Finanční správu dělá člověk; systém drží záznam.

Přechod do Podáno doplní `date_filed` (dnes, když je prázdné) a vyžaduje
sestavený snapshot (`result` není NULL). **Podané podání je immutable** —
změnit smí jen `note`, smazat se nedá; zrušené podání je zmrazené stejně.
Oprava se podává jako nové podání jiného druhu, ne editací. Instance se
při podání **nezamyká** — `locked` a jeho vynucení zůstává Fáze 4.

Uložení může být částečné (API pošle jen to, co mění), takže Document
hodnoty, které payload neposlal, bere z uloženého řádku — jinak by
částečná aktualizace podaného podání vypadala jako koncept.

### UI (D20)

Viewer **Podání DPH** (Účtárna, za Daňovými tvrzeními): řádek nese podaný
výsledek, detail přehled + výstupní řádky (podané vedle přesných) +
zprávy z okamžiku sestavení + **Rozdíly** proti `previous_filing` po
dokladech (podle `doc_head` + `vat_code` + `vat_pct`). Detail instance
v Daňových tvrzeních nese seznam podání a akci **Sestavit podání**.
Hlavičky živých reportů hlásí poslední podání a jeho podanou daňovou
povinnost — vidět rozdíl proti živému výpočtu je celý smysl.

Přílohy (XML, PDF opis) jdou přes `core.attachments` s `table_id` = 443;
Fáze 2 je jen deklaruje (tab Přílohy ve formuláři), plní je Fáze 3.

## Architektura

```
src/
├── VatOutputsMapping.php                  # resolver cfgItem; neznámý kód = výjimka
├── VatDocumentSelection.php               # heads (docState 40) WHERE <xx>_period = instance
│                                          #   + recap + DIČ ze snapshotů
├── VatReturnCalculator.php                # DP3: sumace per (řádek, sloupec) + dopočty (vč. ř. 52)
├── DeductionCoefficientDocument.php       # koeficient odpočtu per registrace × rok: validace
├── DeductionCoefficientResolver.php       # zálohový koeficient roku (explicitní → loňský vypořádací → 1,00)
├── DeductionCoefficientsViewer.php / DeductionCoefficientsForm.php
├── ControlStatementCalculator.php         # KH (CS): rozpad sekcí, limit 10 000, pásma, měkké chyby
├── RecapitulativeStatementCalculator.php  # SH (RS): agregace (kod, DIČ) → počet + hodnota
├── VatJournalCrossCheck.php               # recap tax_dom vs 343 analytiky deníku
├── ReportPeriodDocument.php               # instance: validace, guardy, přepočet po změně rozsahu
├── ReportPeriodsViewer.php / ReportPeriodsForm.php
├── ReportPeriodLookup.php                 # rozhraní „instance pokrývající datum" pro assigner
├── ReportPeriodsProvisioner.php           # find/create (koncept), seed, cron; čistý kandidát
├── VatPeriodAssigner.php                  # pravidlo zařazení (DUZP, clamped DPPD, membership)
├── VatPeriodRecalculator.php              # dávkový přepočet po změně rozsahu
├── DocsHeadsVatPeriodHandler.php          # beforeSave handler na docs_core_heads
├── VatRegistrationSeedHandler.php         # afterSave handler na registraci
├── FilingDocument.php                     # podání: druhy, pořadí, lifecycle, immutabilita
├── FilingRounding.php                     # podané hodnoty (čistá třída): řádky na Kč, dopočty, diff
├── FilingComposer.php                     # snapshot: items + výstupní řádky + result/messages
├── FilingsViewer.php / FilingsForm.php    # viewer Podání DPH (vč. rozdílů) a formulář
├── VatFilingController.php                # POST /_vat/filing-compose (akce Přepočítat)
├── Checks/DraftReportPeriodsCheck.php     # alert: koncepty instancí
└── Reports/
    ├── VatReportSupport.php               # sdílené kusy builderů (kompozice)
    └── Vat*LiveBuilder.php                # tenké ReportBuilder adaptéry
```

Kalkulátory jsou **čisté** (vstup = pole z `VatDocumentSelection`) a testují
se na syntetických datech bez DB. Referenční logika sekcí KH a dopočtů DP3
pochází ze starého Shipardu (`modules/e10doc/taxes`), detaily pravidel jsou
v docblocích kalkulátorů a v zadání.

## Hranice report vs. filing

Živé výstupy jsou **reporty** — vždy přepočtené, bez lifecycle. **Podání**
je doména `filing`: snapshot s lifecyclem, druhy podání a zaokrouhlením
(Fáze 2, hotová). XML (DPHDP3/DPHKH1/DPHSHV), PDF opis a editace hlavičky
přijdou ve Fázi 3 nad strukturovanými poli se schématem (#74); zámek
instance, vynucení proti změnám dokladů a zaúčtování přiznání ve Fázi 4.

## Mimo scope

XML a PDF opis (Fáze 3, po #74), vynucení zámku a zaúčtování přiznání
(Fáze 4), import starých podání (`old_shipard` task 34), rozdíly mezi
dvěma libovolnými podáními (jen proti `previous_filing`), oprava dle § 44
v A4, investiční zlato (A3), ř. 45/47/53/60, OSS a registrace jako
samostatný parametr reportů (přijde s OSS / více DIČ).
