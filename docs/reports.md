# Shipard — Reporty (doména `report`)

**Designový dokument.** Reporty = datové výstupy: komplexní čtení z dat,
agregace a prezentace (typicky tabulka, výhledově grafy a infografika).
Zakládá celou doménu reportů; první tři reporty jsou **hlavní kniha,
výsledovka a rozvaha** (interní podoba) — kontrolní protějšek DPH výstupů
z milníku M1 a validace importu ze starého Shipardu (M3).

> **Stav:** Návrh. Rozhodnutí D1–D16 potvrzena (GitHub issue #42
> + doplnění D15–D16 v komentáři). Implementace nezačala; PRD v `tasks/`
> vznikne fázovaně.

---

## 1. Terminologie a vymezení domény

Nový Shipard rozlišuje tři domény výstupů (D1, D2):

| Doména | CZ | Co to je | Klíčová vlastnost |
|---|---|---|---|
| `print` | tisk | tisková podoba dokladu (faktura, dodací list) | 1 záznam → PDF ven z firmy |
| `report` | report | datový výstup (hlavní kniha, výsledovka, …) | data → strukturovaný výsledek → obrazovka/API |
| `filing` | podání / výkaz | úřední výstup (přiznání DPH, výkazy dle vyhlášky) | data → formát úřadu, má lifecycle (sestavit → podat → zamknout) |

Slovo **„sestava" se nepoužívá** — bylo zdrojem chaosu starého Shipardu.

**Print a report jsou oddělené subsystémy.** Žádná sdílená hierarchie tříd,
žádné dědění mezi nimi (poučení ze starého Shipardu, kde provázané třídy
vytvářely zmatek). Sdílet mohou nanejvýš infrastrukturu úplně dole
(PDF rendering služba #34 — pro reporty až budoucí rozšíření, §8).

`filing` je v tomto dokumentu jen vymezen; „státní" výkaz zisku a ztráty
či rozvaha dle vyhlášky 500/2002 **nejsou pohledy reportu**, ale budoucí
samostatná doména s vlastními položkami v navigaci.

---

## 2. Princip: jeden výpočet, N prezentací

Klíčové architektonické rozhodnutí (D4): report engine produkuje
**výhradně strukturovaný `ReportResult`** (JSON). Všechno ostatní jsou
renderery nad ním:

```
ReportBuilder ──► ReportResult (JSON)
                     │
                     ├── Svelte ReportViewer (UI)
                     ├── REST endpoint (vrátí JSON tak jak je)
                     ├── MCP tool (AI asistent)
                     ├── diff dvou výsledků (kontrola importu, M3)
                     └── (budoucí) tisk: HTML šablona → PDF služba #34
```

Důsledky:

- **Mezisoučty počítá engine, nikdy UI.** API klient a UI nesmí mít šanci
  ukázat různá čísla.
- Prezentační volby (znaménka, zaokrouhlení na tisíce v zobrazení) jsou věc
  rendereru; data nesou vždy plnou přesnost a strany účtu (§3.2).
- Stejný vzor jako one-LLM-call princip v AI analýze: spočítej jednou,
  předej strukturu, prezentace se odvozuje.

---

## 3. `ReportResult`

### 3.1 Tvar (D5)

Samonosný JSON: metadata + definice sloupců + **plochý** seznam řádků.
Žádný strom — plochý seznam s `level` se triviálně renderuje, diffuje
i čte AI asistentem.

```json
{
  "reportId": "economy.reports.profitLoss",
  "params": {"period": {"fiscalYear": 2026, "monthFrom": 5, "monthTo": 5}, "detail": "analytic"},
  "generatedAt": "2026-08-21T12:52:46+02:00",
  "dataSource": "dtje-3qu7-3iof-5imh",
  "status": "ok",
  "messages": [],
  "columns": [
    {"id": "month",   "type": "money", "label": "Měsíc"},
    {"id": "year",    "type": "money", "label": "Rok"}
  ],
  "rows": [
    {"kind": "detail",   "level": 3, "account": "501001", "label": "Spotřeba materiálu",
     "values": {"month": {"md": 59281.34, "d": 0, "balance": 59281.34},
                "year":  {"md": 419454.36, "d": 0, "balance": 419454.36}}},
    {"kind": "subtotal", "level": 2, "account": "501", "label": "Spotřeba materiálu", "values": {"…": "…"}},
    {"kind": "subtotal", "level": 1, "account": "50",  "label": "Spotřebované nákupy", "values": {"…": "…"}},
    {"kind": "computed", "level": 1, "account": null,  "label": "Výsledek hospodaření běžného období", "values": {"…": "…"}},
    {"kind": "total",    "level": 0, "account": null,  "label": "Celkem", "values": {"…": "…"}}
  ]
}
```

- `kind`: `detail | subtotal | total | computed`.
- `level`: hloubka řádku (analytika → syntetika → skupina → třída → celkem);
  renderer z něj odvozuje odsazení a zvýraznění.
- Přesný seznam polí řádku doladí PRD (např. `accountId` vedle
  `account_number` pro drill-down do deníku).

### 3.2 Hodnoty per strany účtu (D6)

Každá buňka nese `md`, `d` a `balance` (zůstatek dle strany účtu).
**Znaménková prezentace je věc rendereru** — starý Shipard zobrazoval
náklady mínusem (aby se výsledek „sečetl sám"), což polovina uživatelů
nechápala; data nového systému žádnou takovou konvenci nezakódovávají.

### 3.3 Stav a zprávy (D15)

Ve starém Shipardu byly chyby prezentační záležitost: červené řádky
+ `addMessage(...)` štosující chyby a warningy „pod čarou" reportu.
To stačí pro oči, ne pro stroje. `ReportResult` proto nese **tvrdý aparát**:

- `status`: `ok | warnings | errors` — odvozený z nejvyšší severity zpráv,
- `messages`: seznam `{severity: error|warning|info, code, text, rowRef?}` —
  `code` je strojově čitelný (např. `journal.accountNotFound`,
  `period.notClosed`), `text` lidský, `rowRef` volitelná vazba na řádek.

Závazná pravidla pro konzumenty:

- **Renderer**: červené řádky a zprávy pod čarou — prezentace nad daty,
  jako dřív.
- **MCP / AI asistent**: výsledek se `status: errors` se nesmí mlčky
  použít jako spolehlivá čísla — asistent chybu ohlásí (a případné
  odpovědi z takových dat explicitně označí). Tool `report_run` tuto
  sémantiku deklaruje v popisu.
- **Diff** (§7.4): porovnání výsledku s `errors` odmítne nebo výsledek
  zřetelně označí — diff „skoro správných" čísel je horší než žádný.

### 3.4 Rozvaha a dopočtené řádky (D13)

Rozvaha potřebuje výsledek hospodaření běžného období jako řádek v pasivech,
který v deníku neexistuje (dopočet z tříd 5/6). Je to regulérní součást
datového modelu — `kind: computed` — ne hack v rendereru. Kontrolní
invarianty rozvahy: **aktiva = pasiva** a **zisk z rozvahy = zisk
z výsledovky**; jejich porušení okamžitě signalizuje nevyrovnané deníky.

---

## 4. Deklarativní definice reportu (D7)

Report deklaruje: `id`, název, **schéma parametrů** a podporované
granularity období. Z jediné deklarace se odvodí:

- toolbar parametrů v UI,
- validace parametrů na REST API,
- `inputSchema` MCP toolu.

Výpočet = PHP třída `ReportBuilder` per report; dostane validované parametry,
vrátí `ReportResult`.

**Forma deklarace: JSONC cfgItem** (D16) — konzistentní s modulovým
systémem (i18n přes `name:cs`, kompilace konfigurace, `ds-upgrade` po
změně). Deklarace odkazuje na `ReportBuilder` třídu. Zůstává jediným
místem, ze kterého se odvozuje toolbar, validace i MCP schéma.

### 4.1 Parametry v1 (D9)

Všechny parametry žijí v horní liště; v1 **nemá** pravý pruh „pohledů":

- **období** (§5) — povinné u všech tří reportů,
- **úroveň detailu**: analyticky / synteticky (mění sadu řádků výsledku),
- **formát**: přesně / v tisících (u výsledovky a rozvahy).

„Operativní vs. státní" přepínač starého Shipardu se nepřenáší — státní
podoba je doména `filing` (§1).

---

## 5. Období (D8)

Zdroj pravdy: fiskální období (`economy_codebooks_fiscal_years` /
`_fiscal_months`); každý řádek deníku nese `fiscal_year` + `fiscal_month`
(viz `accounting.md` §6).

- **Picker**: roky × měsíc / čtvrtletí / pololetí / rok — převzatý vzor ze
  starého Shipardu. Report deklaruje, které granularity podporuje; picker
  nenabízí nesmysly.
- **Interně** se výběr vždy přeloží na **interval fiskálních měsíců od–do**.
  Engine zná jen tento jeden tvar.
- Počáteční stavy jdou z účetních dokladů (otevírací doklad, účet 701) —
  jsou v deníku jako každé jiné účtování, engine je **nedopočítává** (D3).

---

## 6. Zdroj dat a výpočet

- Jediný zdroj: `economy_accounting_journal` (D3). Reporty nesahají na
  doklady ani transakce — stejný princip jako saldokonto (`accbal.md` §1.2):
  každý budoucí zdroj účtování nakrmí reporty bez změny jejich kódu.
- **v1 počítá vždy živě** (D12) — agregační dotaz nad deníkem s indexem
  (`fiscal_year`, `fiscal_month`) je levný a odpadá invalidace.
- Řádky s `is_error = 1` (nedohledaný účet): report je nesmí tiše
  zahodit — vstoupí do výsledku jako samostatný řádek a vygenerují
  zprávu se severity `error` (§3.3, D15) — failing loudly.

---

## 7. Prezentace

### 7.1 UI (D10)

- Navigace: podsekce **Reporty** v sekci Účtárna.
- Jedna generická stránka `ReportViewer` parametrizovaná `reportId`.
- **Parametry v URL** — deep-link „výsledovka 2026/5 v tisících" jde poslat
  kolegovi.
- Drill-down z řádku do deníku (filtr účet + období) — přirozený krok,
  rozsah určí PRD.

### 7.2 REST API

Endpoint (návrh, doladí PRD): `GET /_reports/{reportId}?…parametry…`
→ `ReportResult` beze změn. Validace parametrů z deklarace (§4).

### 7.3 MCP (D11)

Dva **generické** tooly — počet toolů neroste s počtem reportů:

- `report_list` — katalog: id, název, popis, schéma parametrů,
- `report_run(reportId, params)` — vrátí `ReportResult`.

AI asistent tak dostane konzistentní tvar napříč reporty; „porovnej
výsledovku Q1 a Q2 a najdi největší rozdíly" funguje pro každý report
zadarmo. Sémantika chyb: viz §3.3 (D15) — `status: errors` znamená,
že čísla nejsou spolehlivá a asistent to musí ohlásit.

### 7.4 Kontrolní diff (D14)

Strojové porovnání dvou `ReportResult` (případně CSV export per účet
a období) — vedlejší produkt domény, primární využití: kontrola importu
ze starého Shipardu (stejný report za stejné období musí sedět). Využije M3.
Pozn.: starý Shipard strukturovaná data reportů dnes negeneruje; půjde
snadno dodělat, znaménkovou konvenci srovná diff vrstva.

---

## 8. Budoucí rozšíření (mimo rozsah v1)

- **Materializace výsledků pro uzamčená období** — vypočítat a persistovat
  při uzamčení období (M1); invalidace odpadá, protože zamčené období se
  nemění. Definováno jako rozšíření **vázané na zámek období, ne jako cache
  s invalidací** — otevřená období se nikdy nematerializují (D12).
- **Doména `filing`** — přiznání DPH, kontrolní hlášení, výkazy dle
  vyhlášky 500/2002 (plný/zkrácený rozsah, sloupec minulého období).
- **Tisk reportů** — `ReportResult` → HTML šablona → PDF služba (#34).
- **Grafy a infografika** — další renderery nad `ReportResult`.
- Další reporty: pokladní kniha, cash flow, přehledy výnosů/nákladů, …

---

## 9. Vztah k milníkům

- **M1**: hlavní kniha je kontrolní protějšek přiznání k DPH — čísla na
  sebe musí sedět. Rozvaha agregovaně odhalí nevyrovnané deníky (známý
  backlog ~1 200+ dokladů s imbalancí nad 100 Kč).
- **M3**: kontrolní diff (§7.4) dává rychlou validaci importu.

Reference: GitHub issue #42, `accounting.md` (deník), `accbal.md` §1.2
(vzor „jen deník"), `tasks/pdf-rendering-service.md` (#34).
