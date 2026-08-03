# Účetní doklady (cmnbkp) — Fáze 1: Schéma řádků + typ dokladu

**Stav:** hotovo

## Kontext

Nový Shipard má zatím jen faktury vydané (`invno`) a přijaté (`invni`).
Otevíráme druhý velký typ dokladu — **účetní doklad** (`cmnbkp` ve starém
Shipardu, „Účetní doklad", `acc:1`, `useTax:0`, bez obchodního i daňového
směru). MVP cílí na **ruční účetní doklad s řádky kontace** (např. ruční
závazek/pohledávka, mzdové účtování, jednoduchý zápočet zadaný ručně).
Pokročilé „activities" starého systému (přiznání DPH, kurzové rozdíly,
počáteční stavy saldokonta, otevření/uzavření období, majetkové operace)
jsou **mimo MVP** — přijdou jako navazující moduly.

Klíčové architektonické zjištění z analýzy:

- **Deník je čistý derivát** (`economy_accounting_journal`) — zapisuje ho
  výhradně `AccountingEngine`. Saldo pohyby z něj derivuje `LedgerGenerator`,
  který saldo skupinu určuje **podle čísla účtu** (prefix match v
  `economy_accbal_balance_accounts`), a partnera + platební symboly +
  splatnost čte **přímo z řádku deníku**.
- Dnes engine razítkuje `partner` + `payment_reference` / `specific_symbol` /
  `constant_symbol` / `due_date` na všechny řádky deníku **z hlavičky**
  dokladu (`AccountingEngine::writeResult`). Pro fakturu (jeden partner, jeden
  VS) to stačí; pro účetní doklad ne (zápočet = dva partneři, mzda = závazek
  s vlastní splatností).
- Proto bude saldo identita **per řádek dokladu**. `docs_core_rows` ji dnes
  nemá (je čistě položkový: item / quantity / price / VAT). Tato fáze ji
  přidává jako sloupce; engine je začne číst až ve Fázi 2.

Tato fáze je **čistě strukturální** — sloupce + config, žádné chování.
Vlajky operací (`rowPartner` / `rowPaymentId`), saldo operace `cmnbkp` a
účtovací předpis přijdou ve **Fázi 2** (společně s enginem, který je
spotřebuje a jde to otestovat).

## Cíl

Schéma a konfigurace připravené pro účetní doklad:
`docs_core_rows` nese per-řádkovou saldo identitu (nullable, zatím nečtená),
`cmnbkp` existuje jako typ dokladu vč. automaticky založené číselné řady, a
jde přes API uložit prázdný koncept `cmnbkp`. Chování faktur se nemění.

## Návaznost

- **Předchází:** docs.core (tabulky, číselné řady, stavy), economy.accounting
  (engine), economy.accbal (saldo) — vše hotové.
- **Páruje se s:** nic (samostatná fáze).
- **Navazuje:** Fáze 2 (engine per-řádkové razítkování + vlajky operací +
  předpis `cmnbkp`), Fáze 3 (modul `docs.accountingDocs` + UI), Fáze 4
  (import ze starého Shipardu).

## Před implementací přečti

- `modules/docs/core/tables/docs_core_rows.jsonc` — tabulka řádků, kam
  přidáváme saldo identitu (sekce za VAT computed sloupci).
- `modules/docs/core/config/docTypes.jsonc` — cfgItem typů dokladů
  (vzor `invno` / `invni`).
- `modules/docs/core/config/rowOperations.jsonc` — operace řádků, stávající
  `acc.entry`.
- `modules/docs/core/src/NumberSeriesProvisioner.php` — `provision()`:
  idempotentní seed řad řízený `docs.core.docTypes`; nový typ řadu vyrobí sám,
  **žádný kód číselných řad v této fázi není potřeba**.
- `modules/economy/accounting/src/AccountingEngine.php` — `writeResult()`
  (~ř. 430+), kde se dnes platební identita razítkuje z hlavičky — jen pro
  kontext, **v této fázi se nemění**.

## Scope

Pouze `docs.core` schéma + config. **Nesahat na:** `AccountingEngine`
(Fáze 2), `LedgerGenerator` / accbal, `DocRowsForm` (Fáze 3), žádný nový modul,
import. Žádné nové vlajky operací ani saldo operace (Fáze 2).

## Co implementovat

### 1. `docs_core_rows.jsonc` — per-řádková saldo identita

Nová sekce sloupců (za blokem „calculated (system)" / před `indexes`),
**všechny nullable**:

```jsonc
// --- saldo identity (per-row override pro saldokontní doklady) ---
// Razítkuje se do deníku per řádek až ve Fázi 2 (engine); operace řádku
// rozhodne přes vlajky rowPartner / rowPaymentId, zda se bere z řádku
// nebo z hlavičky. Zde jen schéma — zatím nečtené, faktur se to netýká.

{ "id": "partner", "name": "Partner", "name:cs": "Partner", "name:en": "Partner",
  "type": "int", "nullable": true, "reference": "base_persons_persons" },
{ "id": "payment_reference", "name": "Payment reference",
  "name:cs": "Variabilní symbol", "name:en": "Payment reference",
  "type": "varchar", "length": 35, "nullable": true },
{ "id": "specific_symbol", "name": "Specific symbol",
  "name:cs": "Specifický symbol", "name:en": "Specific symbol",
  "type": "varchar", "length": 20, "nullable": true },
{ "id": "constant_symbol", "name": "Constant symbol",
  "name:cs": "Konstantní symbol", "name:en": "Constant symbol",
  "type": "varchar", "length": 10, "nullable": true },
{ "id": "due_date", "name": "Due date",
  "name:cs": "Datum splatnosti", "name:en": "Due date",
  "type": "date", "nullable": true }
```

Typy a délky shodné s `docs_core_heads` (payment) a `economy_accounting_journal`
(payment identity) — sjednocená konvence (`payment_reference` varchar 35 kvůli
RF Creditor Reference / EndToEndId). Bez nového indexu (řádky se čtou přes
`doc_head`).

### 2. `docTypes.jsonc` — typ `cmnbkp`

Přidat za `invni`:

```jsonc
"cmnbkp": {
    "name": "Accounting document",
    "name:cs": "Účetní doklad",
    "name:en": "Accounting document",
    "shortcut": "ÚČD",
    "shortcut:cs": "ÚČD",
    "shortcut:en": "AD",
    "doc_id_code": "60",
    "trade_dir": 0,
    "doc_number_pattern_default": "%D%y%C%4",
    "subclass": "Shipard\\Module\\Docs\\AccountingDocs\\AccountingDocument"
}
```

- `doc_id_code: "60"` a vzorec `%D%y%C%4` = parita se starým Shipardem
  (`docIdCode: 60`).
- `trade_dir: 0` — bez obchodního směru (vstup ani výstup).
- `subclass` je zatím jen referenční hint (stejně jako u faktur). Třídu
  `AccountingDocument` zaregistruje modul `docs.accountingDocs` ve Fázi 3; do
  té doby polymorfní dispatch spadne na `defaultClass`
  (`DocsHeadsDocument`), což pro prázdný koncept stačí.

### 3. `rowOperations.jsonc` — povolit `acc.entry` pro `cmnbkp`

Do stávající operace `acc.entry` přidat `cmnbkp` mezi `docTypes`:

```jsonc
"acc.entry": {
    "name": "Accounting entry",
    "name:cs": "Účetní položka",
    "name:en": "Accounting entry",
    "docTypes": {
        "invno": {"order": 900},
        "invni": {"order": 900},
        "cmnbkp": {"order": 100}
    }
}
```

Tím lze založit `cmnbkp` s běžnou účetní položkou (účet z položky typu 2).
Saldo operace `cmnbkp` (s vlajkami `rowPartner` / `rowPaymentId`) se definují
ve Fázi 2 spolu s předpisem.

## Hotovo když

- `ds-upgrade` přidá pět sloupců do `docs_core_rows` bez chyby; existující
  řádky mají hodnoty `NULL`.
- Compiled cfg po rebuildu obsahuje `cmnbkp` v `docs.core.docTypes`.
- `NumberSeriesProvisioner::provision()` (běží v `ds-upgrade`) vytvoří jednu
  číselnou řadu pro `cmnbkp` (`doc_number_pattern = %D%y%C%4`,
  `reset_scope = fiscal_year`, `docState = 40`); opakovaný běh už nic nevytvoří
  (idempotence).
- Přes API jde uložit prázdný koncept dokladu `doc_type = cmnbkp` (stav 10);
  dispatch spadne na `DocsHeadsDocument`.
- Faktury (`invno` / `invni`) se chovají beze změny — nové sloupce jsou
  nullable a žádný stávající kód je nečte (ověřit, že účtování faktury
  produkuje identický deník jako před změnou).

## Doporučené pořadí

1. Sloupce do `docs_core_rows.jsonc`.
2. `cmnbkp` do `docTypes.jsonc`.
3. `cmnbkp` do `acc.entry` v `rowOperations.jsonc`.
4. `ds-upgrade` + rebuild compiled cfg.
5. Sanity: provisioner vytvořil řadu; POST prázdného konceptu `cmnbkp`;
   přeúčtování existující faktury beze změny deníku.

## Rozhodnutí ✓

- **D1** — `cmnbkp` MVP = ruční účetní doklad; activities (DPH, kurz. rozdíly,
  počáteční stavy, otevření/uzavření, majetek) odloženy.
- **D2** — per-řádková saldo identita v `docs_core_rows`
  (`partner`, `payment_reference`, `specific_symbol`, `constant_symbol`,
  `due_date`); žije v `docs.core` (sdílí faktura s odpočtem zálohy).
  - **D2a** — `due_date` i na řádku, razítkuje se per řádek.
  - **D2b** — `paymentBalance` se nezavádí; saldo skupina je account-driven
    (`balance_accounts` prefix match).
  - **D2c** — dvě ortogonální vlajky operace `rowPartner` / `rowPaymentId`
    (definice ve Fázi 2).
- **D5** — operation-driven model (řádek nese `operation`, předpis mapuje na
  účet).
- **Přesun hranic fází** — vlajky operací a saldo operace `cmnbkp` přesunuty
  z Fáze 1 do Fáze 2 (spotřebovává je engine + předpis, koherentnější a
  testovatelné společně). Fáze 1 je čistě strukturální.

## Otevřené body

- **Ergonomie ručního zaúčtování (Fáze 2):** v operation-driven modelu vede
  zaúčtování na konkrétní účet přes položku typu „Účetní položka"
  (`item_type=2`, `accountSrc:'item'`). „Vyber účet přímo na řádku" by byl
  třetí účtovací cesta navíc k D5 — necháno jako možné pozdější vylepšení UX,
  rozhodne se ve Fázi 2.
- **Indexy na `docs_core_rows`:** záměrně bez indexu na `partner` (řádky se
  čtou přes `doc_head`). Přehodnotit, pokud vznikne reporting nad řádky dle
  partnera.
- README (`docs/`, `tasks/`) — spravuje David.
