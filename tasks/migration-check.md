# Task: Automatizovaný reimport a porovnání zdrojů (`migration-check`)

**Stav:** návrh — čeká na schválení rozhodnutí M1–M7, pak implementace
**Issue:** založit nové umbrella issue „Migrační smyčka: reimport + porovnání" (odkaz
z #59); rozhodnutí sem
**Návaznost:** stará strana `old_shipard` `modules/imports/newShipard/tasks/33-migration-check-exports.md`
(exportéry statistik a DPH, `source-check`, `known-failures`, lock). Staví na
`export-general-ledger` (stará) + `report-run` / `report-diff` (nová) — ty se nemění,
jen zapojují. `fix-source/` (task 32) je vstupní gate.

## Cíl

Jedním příkazem, bez ruční asistence, pro každý zdroj z konfigurace: ověřit, že zdroj
je připravený → `ds-reset` cíle → plný import → porovnat starý a nový Shipard ve
čtyřech vrstvách → vydat semaforový souhrn s historií. Dvě úrovně běhu: **fast**
(malý zdroj, po každém nasazení, ~20 min) a **full** (všechny zdroje, noční).
Smyslem není „import doběhl", ale **„rozdíl proti starému Shipardu je nula, nebo
vysvětlený položkou ve `fix-source`"**.

Dnešní stav, který to nahrazuje: ruční spuštění importu, ruční grep `.err` logu,
ad hoc SQL na obou stranách (reimport 689089 2026-09-07 stál půl dne analýzy).

Před implementací **přečti**:

- `old_shipard` `modules/imports/newShipard/README.md` — sekce Rychlý start §4, Export
  hlavní knihy, Idempotence a re-import, Lokální stav, Opravy zdrojových dat
- `src/Command/DataSource/{DsResetCommand,ReportRunCommand,ReportDiffCommand,DsUpgradeCommand}.php`
- `docs/reports.md` §7.4 (kontrakt `ReportResult`, co `report-diff` porovnává)
- `modules/economy/vat/docs/README.md` (periody `economy_vat_report_periods` — jak se
  identifikuje instance: `report_kind`, rok, pořadí/kód), `config/reports.jsonc`
- `tasks/dataset-phase1.md` — jak jsou řešené CLI nástroje nad DS (vzor pro `ds-stats`)
- `tools/` (pokud existuje) a `bin/shpd-ds` bootstrap; video runner (`tools/video/`?)
  jako vzor samostatného nástroje s vlastní konfigurací a výstupním adresářem
- `/opt/shipard/data-sources/<id>/config/` — co `ds-reset` zachová (API klíče →
  import config na staré straně přežije reset)

## Rozhodnutí (k potvrzení)

- **M1 Umístění.** Orchestrátor je na **nové** straně: `tools/migration-check/`
  (PHP CLI `check.php`, konfigurace `pairs.local.jsonc` mimo git + `pairs.example.jsonc`).
  Důvod: porovnávací nářadí (`report-run`, `report-diff`, DS cesty, `claude_ro`) je tady;
  starou stranu volá přes `ssh <old-host> "cd /var/lib/shipard/data-sources/<id> && shpd-ds-import …"`.
  Žádný nový síťový kanál, žádné API pro reset.
- **M2 Páry.** Konfigurace = seznam párů `{oldDsId, oldHost, newDsId, tier: fast|full,
  vatPeriods: [...], fiscalYears: [...]}`. Výchozí sada: **fast** = `73208441284308` →
  `e8w1-iu9x-82vy-9ye7`; **full** = `68908901448295` → `btpg-peg5-b0tr-chln`
  + zdroje alfy (`qrce-5`, `2xvt-y` ← `33271805401633`, `l6ot-0`) —
  jejich staré ID a cílové DS doplní David. Kritéria výběru dalších zdrojů: pokrytí
  funkcí (689089: pokladna, prodejky, terminály, zálohy) a ochota uživatele zdroje
  opravovat data (`fix-source` bez majitele zplesniví).
- **M3 Gate před resetem.** Běh na zdroji se **neprovede**, pokud (a) `source-check`
  na staré straně hlásí nálezy třídy blokátor, nebo (b) `fix-source/<dsid>/README.md`
  obsahuje položky ve stavu `schváleno` neaplikované. `--force` gate přeskočí a souhrn
  to označí. Cíl se **resetuje vždy** (žádný inkrementální režim v této smyčce —
  idempotence runneru je pro ruční práci, smyčka měří čistý import).
- **M4 Čtyři vrstvy porovnání** (každá samostatný semafor):
  - **L1 Statistiky** — počty a součty: doklady per `docType` × stav × rok (počet,
    `Σ total`), osoby, položky, bankovní transakce, řádky deníku (+ `is_error`),
    pokladny × řady. Obě strany emitují stejný JSON (`export-doc-stats` stará /
    `ds-stats` nová), tool diffuje. Chyby importu (`failed`) se porovnají s
    `fix-source/<dsid>/known-failures.txt` — nová chyba = červená, známá = žlutá.
  - **L2 Hlavní kniha** — per fiskální rok z konfigurace: `export-general-ledger`
    (stará) → `report-run economy.accounting.generalLedger` (nová) → `report-diff`.
    Pokrývá výsledovku, rozvahu **i pokladní zůstatky** (211 analytiky) a tranzitní
    účty (261100 = 0). Nemění se.
  - **L3 DPH** — per instance tvrzení z konfigurace: `export-vat-return --period`
    (stará, nový exportér — ReportResult JSON řádků DP3 z starých daňových řádků)
    → `report-run economy.vat.returnLive --period=<id>` → `report-diff`. KH/SH až
    v druhém kroku (A4/B2 detail je citlivější na snapshoty partnerů; začít DP3).
  - **L4 Saldo** — otevřené položky per účet × partner ke konci roku: stará
    `e10doc_balance_*` vs. nová accbal. **Fáze 2 tohoto tasku** — nejdřív L1–L3.
- **M5 Výstup.** `tools/migration-check/reports/<YYYYMMDD-HHMM>-<tier>/` s
  `summary.md` (tabulka zdroj × vrstva, semafor, čas fází, odkaz na diff soubory),
  `<dsid>/` s JSON exporty obou stran a výstupy `report-diff`; `reports/latest-<tier>`
  symlink. Historie se nemaže (běhy jsou levné na disk, drahé na čas). Notifikace
  (mail/Slack) mimo scope — David čte `latest`.
- **M6 Souběh.** Jeden běh v čase per zdroj: lock na staré straně
  (`import-newShipard.lock` v DS adresáři — task 33), tool ho respektuje a při
  cizím locku zdroj přeskočí se žlutým „locked". Ruční import a smyčka se tak nikdy
  nepotkají na jednom DS.
- **M7 Reálná data.** Tool i Claude pracují nad `claude_ro` na obou stranách;
  výstupy jsou agregáty (počty, součty per účet/období), diffy L1–L3 neobsahují jména
  ani jednotlivé doklady. Detail chyb importu (`ndx`, číslo dokladu, důvod) je v
  `known-failures`/`fix-source` — bez jmen. Platí pravidla `alpha.md`.

## Scope — nová strana

### 1. `bin/shpd-ds ds-stats --json`

Nový command (vzor `ReportRunCommand`): pro DS vrátí JSON

```jsonc
{
  "schema": "shpd.migration.stats.v1",
  "generatedAt": "...",
  "docs": [ {"docType":"invno","docState":40,"year":2025,"count":631,"total":6441061.68}, ... ],
  "persons": {"count": N, "byState": {...}},
  "items":   {"count": N},
  "bankTransactions": {"count": N, "byYear": {...}},
  "journal": {"rows": N, "errorRows": N, "byYear": {...}},
  "cashDesks": [ {"code":"1","docState":40,"hasAccount":true,"series":["cash","cashreg"]} ],
  "vatPeriods": [ {"kind":"DPHDP3","name":"2026-03","docs":N} ]
}
```

Rok = `issue_date` (nová) / `dateAccounting` (stará — task 33 zrcadlí; sjednotit
na **účetní datum** na obou stranách: nová `accounting_date`). Částky zaokrouhlené
na 2 místa. Řazení deterministické (klíče), aby šel soubor diffovat textově.

### 2. `tools/migration-check/`

- `check.php <tier|dsid> [--force] [--skip-reset] [--layers=L1,L2,L3] [--dry-run]`
  - načte `pairs.local.jsonc`, vybere zdroje (tier nebo konkrétní),
  - pro každý zdroj sekvenčně: **gate** (§M3: `ssh old "shpd-ds-import source-check --json"`,
    parse `fix-source/<dsid>/README.md` přes ssh — konvence stavů z tasku 32) →
    **reset** (`bin/shpd-ds ds-reset --yes` nad DS cestou; `ds-reset` už volá
    `ds-upgrade`) → **import** (`ssh old "shpd-ds-import all --yes --reset --continue-on-error"`,
    stdout do `<dsid>/import.log`, exit code) → **exporty** (stará: `export-doc-stats`,
    `export-general-ledger` per rok, `export-vat-return` per perioda — soubory
    přenést `scp`; nová: `ds-stats`, `report-run` per rok/perioda) → **diffy**
    (`stats-diff` vlastní, `report-diff` pro L2/L3, `known-failures` porovnání) →
    **summary**.
  - `--skip-reset` pro ladění porovnání nad už naimportovaným cílem.
  - Selhání fáze = červená pro zdroj, pokračuje dalším zdrojem; exit code 1 pokud
    kterýkoli zdroj červený.
- `StatsDiff.php` — porovnání dvou `shpd.migration.stats.v1`: per klíč rozdíl počtů
  a součtů; tolerance 0 na počty, `0.01` na součty; výstup markdown tabulka jen
  s rozdíly + celkové „shoda/rozdíl" počítadlo.
- `KnownFailures.php` — `failed` seznam z import summary (`ndx` + důvod, task 33
  formát) vs. `known-failures.txt`; nové položky vypsat ve tvaru pro vložení do
  `fix-source` README.
- `pairs.example.jsonc` s komentáři; `README.md` (jak spustit, jak číst summary,
  co dělat s červenou — rozhodovací strom: nová chyba importu → `fix-source` nebo
  runner; rozdíl L2 → který účet, který rok → `report-diff` soubor; rozdíl L3 → řádek
  DP3 → dohledat doklady per vat kód přes `claude_ro`).
- Cron (mimo git, poznámka v README): `full` denně 02:00 na dev-sebik-shpd,
  `fast` ručně po nasazení (později hook na deploy).

### 3. `report-run` — identifikace periody DPH

`--period=<id>` je interní id; tool nemá jak ho znát dopředu. Přidat `--period-key=`
(např. `DPHDP3:2026:3` — `report_kind:rok:pořadí`, nebo cokoli, čím je instance
v `economy_vat_report_periods` jednoznačná — ověř sloupce) a totéž akceptovat na
staré straně v `export-vat-return`. Konfigurace párů pak nese klíče, ne id.

### 4. Dokumentace

- `docs/migration-check.md` (nový): účel, vrstvy, semafory, rozhodovací strom,
  vztah k `fix-source`, jak přidat zdroj.
- `docs/reports.md` §7.4: odkaz na L2/L3 použití; `docs/README.md` index.
- Issue: komentář s prvním úspěšným `fast` během (summary bez jmen).

### 5. Testy

- Unit: `StatsDiff` (shoda, rozdíl počtu, rozdíl součtu v toleranci/nad),
  `KnownFailures` (známá/nová/zmizelá), parser stavů `fix-source` README.
- `ds-stats` integrační nad `4l3j` — schéma a determinismus (dva běhy = identický JSON).
- Orchestrátor se testuje `--dry-run` (vypíše plán příkazů bez spuštění) — snapshot
  test výstupu nad `pairs.example.jsonc`.

## Scope — stará strana (task 33, souhrn; detail v `old_shipard`)

- `export-doc-stats --output=` — `shpd.migration.stats.v1` ze staré DB (stejné klíče,
  stavy mapované starou→novou mapou runneru, rok = `dateAccounting`).
- `export-vat-return --period-key=` — řádky DP3 ze starých daňových řádků v
  ReportResult JSON (analog `export-general-ledger`). **Ověřit strukturu starých
  tabulek DPH** (`e10doc_taxes_*`/`e10doc_core_taxes` — při psaní tasku nebylo možné
  nahlédnout, server byl vytížený importem); pokud starý systém ukládá hotové
  výsledky tvrzení, použít je přednostně (jsou to podaná čísla).
- `source-check --json` — zobecnění `cash-check`: všechny známé třídy anomálií
  (`cashBox=0`, osoby mimo mapu, `ambiguous-header`, neznámé pohyby, stav 1200,
  duplicitní čísla, prázdné doklady …) s klasifikací blokátor/informativní.
- `all --yes` neinteraktivní, `import-newShipard.lock` po dobu běhu, na konci
  `log/import-summary-<ts>.json` (`failed[]` = `{ndx, docType, docNumber, reason}`,
  počítadla fází) — vstup pro `KnownFailures`.
- `fix-source/<dsid>/known-failures.txt` — jeden `ndx` + krátký důvod na řádek;
  udržuje David/Claude při zápisu položky do `fix-source`.

## Fázování

1. **Fáze A (tento task):** `ds-stats`, orchestrátor s L1 + L2, gate, summary; fast
   běh na e8w1-i zelený nebo s vysvětlenými rozdíly. Stará strana:
   `export-doc-stats`, `source-check`, lock, `import-summary`.
2. **Fáze B:** L3 DPH (`export-vat-return`, `--period-key`), full běh 689089.
3. **Fáze C:** L4 saldo; KH/SH v L3; notifikace.

## Mimo scope

- Inkrementální reimport (smyčka měří čistý import).
- Automatické aplikování `fix-source` SQL (ruční, gate jen kontroluje stav).
- Import DS alfy z produkčního starého Shipardu (jiný host, jiná pravidla) — až po
  ověření smyčky na dev.
- `accounting-repost` (po M1) — s ním by fast tier klesl z minut reimportu na sekundy
  při změně předpisu; poznámka do issue.

## Hotovo když

- [ ] `php tools/migration-check/check.php fast` proběhne bez asistence: gate → reset
      → import → exporty → diffy → `reports/latest-fast/summary.md`.
- [ ] e8w1-i: L1 zelená nebo jen položky z `known-failures`; L2 pro všechny
      roky z konfigurace zelená, nebo každý rozdíl přiřazený k položce `fix-source`.
- [ ] Souběh s ručním importem je vyloučený (lock) a viditelný v summary.
- [ ] `--dry-run` vypíše plán, žádný příkaz nespustí (snapshot test).
- [ ] Dokumentace `docs/migration-check.md`; issue založené, odkaz z #59.
