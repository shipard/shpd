# Shipard — Nastavení nového zdroje dat (`ds-setup`)

**Designový dokument.** Mechanismus, který dovede čerstvě založený zdroj dat do
stavu „lze v něm účtovat“ — bez toho, aby uživatel na chybějící nastavení přišel
až ve chvíli, kdy nejde potvrdit první faktura. Skládá se ze tří částí:
**instalačních parametrů** zadaných při zakládání, odvozeného **checklistu**
chybějícího nastavení a **průvodce**, který je jen UI nad tím checklistem.

Podoblast [`hosting.md`](hosting.md) — Hosting přispívá výhradně přenosem dvou
instalačních parametrů z admin formuláře do `ds-create` (D7). Zbytek mechanismu
žije v samotném zdroji dat a funguje shodně pro DS založené z konzole i z dev
dashboardu.

> **Stav:** **Fáze 0 (diskuse) hotová, rozhodnutí D1–D9 schválená
> (2026-08-11). Fáze 1 (vrstva A) hotová — Task 01 (`getCountry()`,
> `ds-create --language --country`, dev dashboard, `[WARN]` v `ds-upgrade`)
> + Task 02 (sloupce na hostingu, formulář, queue payload, agent).
> Z Fáze 2 hotový Task 03 — klíče `economy.accountChart`
> a `economy.fiscalYearStartMonth` (`LayerCParameters`), CLI `ds-setting`,
> odložený provisioning osnovy a fiskálních roků, `[TODO]` výpis
> v `ds-upgrade`, konec `getAccountChart()`. Zbývá `economy.homeCurrency`
> (Task 04) a `economy.vatAgenda` (vat-payer-01).**
> Fázování viz §8, otevřené body §10.

---

## 0. Schválená rozhodnutí

| # | Rozhodnutí |
|---|---|
| D1 | Vrstva **A** (`main.json`, jednorázové, nezměnitelné) = **jen jazyk a země**. Přepínače `ds-create --language --country`, nový getter `country` v `DataSourceConfig`. |
| D2 | Všechny ostatní parametry (účtová osnova, domácí měna, první měsíc fiskálního roku, plátcovství DPH) žijí v `core_system_settings` přes `SettingsStore`. **Absence klíče = nerozhodnuto.** |
| D3 | Stav nastavení se **nikde nepamatuje** — dopočítává se. Checklist má dva druhy položek: chybějící business řádek (`COUNT = 0`) a nerozhodnutý parametr (chybí klíč). Žádný příznak „průvodce dokončen“. |
| D4 | Průvodce je **UI nad checklistem**, ne samostatná evidence. Nový setup check = nový krok průvodce. Průvodce je přeskočitelný a neblokující. |
| D5 | Plátcovství DPH ([Issue #17](https://github.com/shipard/shpd/issues/17)) = **varianta 1**: absence Registrace DPH pro dané datum znamená neplátce. Příznak se jmenuje `economy.vatAgenda` („vede agendu DPH“) — záměrně ve tvaru předvolby, ne faktu, aby ho nikdo později nepoužil jako zdroj pravdy o plátcovství. |
| D10 | **Příznak řídí budoucnost a navigaci; renderování existujících dat řídí doklad sám** (`vat_mode`). Tím je přechod plátce ⇄ neplátce vyřešený bez zvláštní práce a bývalý plátce nepřestane vidět svá stará data o DPH. |
| D11 | Navigace agendy DPH (Registrace, Období) se skrývá jen tehdy, když je příznak neplátce **a zároveň nikdy neexistovala žádná registrace** (`COUNT(*) = 0` včetně ukončených). Samotný příznak jako podmínka nestačí. |
| D6 | Provisioning fiskálních roků se **odkládá** za rozhodnutí o prvním měsíci. Čerstvý DS chvíli nemá fiskální roky — nevadí, doklad se stejně nepotvrdí bez vlastní Osoby. |
| D7 | Hosting přispívá **výhradně vrstvou A** — dva sloupce na `hosting_core_data_sources`, pole ve formuláři, položky v queue payloadu, předání v `HostingSyncRunner`. Žádný ARES v provisioning agentovi, žádné business řádky. |
| D8 | Setup alerty se ve feedu dashboardu agregují **podle tagu** (ne podle `check_id`) do jedné karty; její primární akce otevírá panel průvodce — nový druh akce `open_panel`. |
| D9 | **Bez backfillu existujících DS.** Zdroje dat se přeimportují ze starého Shipardu a import si parametry vrstvy C zapisuje sám (§7.2). |

### Vědomě mimo scope

- **Profily instalace** (firma plátce / firma neplátce / OSVČ / nezisková
  organizace) jako pojmenované sady předvoleb — odloženo. Rozhodnutí padne až po
  zkušenosti s jednotlivými parametry; datový model to nesmí zablokovat, ale
  nestaví se.
- **Vzorce číselných řad** — `NumberSeriesProvisioner` generuje výchozí sadu;
  ladění prefixů a restartů je samostatné téma.
- **Pokladny** (`economy_codebooks_cash_desks`), **sklady**, **nákladová
  centra** — přidají se jako podmíněné checky, až bude hotová příslušná agenda.
- **Kdo smí nastavení měnit** — dnes kdokoli s přístupem do Nastavení, stejně
  jako u `app.theme`. Jemnější RBAC mimo scope.
- **Více vlastních Osob v jednom DS** (více firem pod jednou databází) —
  `PersonDocument::validate` dnes připouští právě jednu a to zůstává.
- **Migrace jako cesta k nastavení** — import ze starého Shipardu parametry
  zapisuje sám a checklist na importovaném DS má být rovnou prázdný (§7.2).

---

## 1. Motivace

Po `ds-create` + `ds-upgrade` je zdroj dat schématem hotový a referenční data
naseedovaná, ale **nedá se v něm potvrdit jediný doklad**. Chybějící nastavení
se dnes projeví až jako validační chyba v okamžiku, kdy uživatel poprvé něco
vystavuje — tedy nejdřív, kdy o něm nechce slyšet.

Tvrdé blokace v kódu:

| Blokace | Kde | Kdy se projeví |
|---|---|---|
| `no_own_company` | `DocDocument::validate` přes `OwnCompanyResolver` | potvrzení **jakéhokoli** dokladu (stavy 20/40/80) |
| `vat_registration` je povinná | `DocDocument::validate`, pokud `vat_mode !== 0` | potvrzení dokladu s DPH |
| `bank_account` je povinný | `IssuedInvoiceDocument::validate` | potvrzení **vydané** faktury |

K tomu měkké mezery, které nic nehlásí: vlastní Osoba bez adresy sídla
(snapshot dodavatele na dokladu vyjde neúplný), chybějící Položky, nevyplněný
název a logo aplikace (tisknou se na doklady).

Existující dílek: `MissingOwnPersonCheck` (`base.persons.missing_own_person`,
`severity: warning`, `interval: 1h`, `tags: ["setup"]`) s akcí `open_form`
a `preset: {is_own: true, person_type: 2}`. Tenhle check je **prototyp celé
mašinerie** — zbytek dokumentu ho jen zobecňuje.

---

## 2. Tři vrstvy nastavení

Bolest vzniká z toho, že se do jednoho pytle míchají tři věci s různým
životním cyklem. Rozdělení podle vrstvy je hlavní myšlenka tohoto dokumentu:

| Vrstva | Co to je | Kde žije | Kdy se rozhoduje | Změnitelné? |
|---|---|---|---|---|
| **A** | Instalační parametry — jazyk a země | `config/main.json` (read-only instalační config) | při zakládání DS | ne |
| **B** | Referenční data — jednotky, druhy položek, osnova, saldo skupiny, období DPH, číselné řady, mail router, AI analyzer | tabulky, generuje `ds-upgrade` | automaticky, bez vstupu uživatele | idempotentně dorovnává |
| **C** | Firemní identita a parametry účetnictví | `core_system_settings` (parametry) + business tabulky (řádky) | při prvním otevření DS, průvodcem | ano |

**Vrstva B je už hotová** — `DsUpgradeCommand` spouští provisionery jednotek,
druhů položek, účtové osnovy, saldo skupin, fiskálních roků, období DPH a
číselných řad; clearing infrastruktura a AI analyzer se zajišťují bezpodmínečně
(i pod `skipProvisioning`). Vrstva B je dobrý precedent hlavně tím, že je
**idempotentní a spouští se opakovaně**, ne jednorázově při zakládání.

**Proč jazyk a země patří do A.** Steerují to, co ostatní vrstvy vůbec smí
nabídnout: který registr se dotazuje (ARES / RPO / Handelsregister — viz
`base.persons.sourceKinds`), které varianty osnovy existují, jaké jsou sazby
DPH (`world.vat`), jaký je formát adres a bankovních spojení. Firma navíc
nemění jurisdikci; jazyk lze per uživatel přebít (`account.language`), takže
hodnota v `main.json` je jen fallback pro požadavky bez `Accept-Language`.

**Proč zbytek patří do C.** Účtová osnova, domácí měna, fiskální rok
i plátcovství DPH jsou obchodní rozhodnutí, na která zakladatel DS (typicky
provozovatel hostingu nebo účetní kancelář) často nezná odpověď. Zároveň to
jsou parametry, které steerují provisionery — proto nesmí skončit v business
tabulce, ale v key-value nastavení, odkud je čte HTTP i CLI.

---

## 3. Inventura nastavení

Kompletní seznam toho, co musí být nastaveno, než je DS použitelný. Sloupec
*Detekce* je zároveň specifikací setup checku.

| Nastavení | Vrstva | Úložiště | Detekce | Zdroj hodnoty |
|---|---|---|---|---|
| Jazyk | A | `main.json` → `defaultLanguage` | — (povinný parametr `ds-create`) | zakladatel |
| Země | A | `main.json` → `country` (nové) | — (povinný parametr `ds-create`) | zakladatel |
| Vlastní Osoba | C | `base_persons_persons.is_own = 1` | `COUNT(*) = 0` nad aktivními | registr (ARES/RPO) |
| Sídlo vlastní Osoby | C | `base_persons_addresses`, `address_type = 1` | vlastní Osoba bez adresy typu sídlo | registr |
| Plátcovství DPH | C | settings `economy.vatAgenda` | chybí klíč | uživatel |
| Registrace DPH | C | `economy_codebooks_vat_registrations` | `vatAgenda = true` ∧ `COUNT(*) = 0` | registr plátců + doptání na frekvenci |
| Vlastní bankovní účet | C | `economy_codebooks_bank_accounts` | `COUNT(*) = 0` nad aktivními | překlop z bank. spojení vlastní Osoby |
| Účtová osnova | C | settings `economy.accountChart` | chybí klíč | uživatel |
| První měsíc fisk. roku | C | settings `economy.fiscalYearStartMonth` | chybí klíč | uživatel |
| Domácí měna | C | settings `economy.homeCurrency` | chybí klíč | uživatel |
| Základní Položky | C | `economy_items` | **není check** — nabídka (§10) | generátor, opt-in |
| Název a logo aplikace | C | settings `app.name`, `app.companyLogo` | **není check** — nabídka (§10) | uživatel |

Osm položek se skutečnými checky (jazyk a země se nekontrolují — bez nich DS
nevznikne; nabídky checky vědomě nemají).

---

## 4. Architektura

```
   ┌──────────────────────────────────────────────────────────────┐
   │  ZAKLÁDÁNÍ DS                                                │
   │                                                              │
   │  hosting admin form ──┐                                      │
   │  dev dashboard    ────┼──► ds-create --language --country     │
   │  ruční CLI        ────┘         │                            │
   │                                 ▼                            │
   │                          config/main.json   (vrstva A)       │
   └──────────────────────────────────┬───────────────────────────┘
                                      │
                                      ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  ds-upgrade  (vrstva B, idempotentní, opakovaně)             │
   │  jednotky · druhy položek · saldo skupiny · období DPH ·     │
   │  číselné řady · mail router · AI analyzer                    │
   │  osnova a fiskální roky JEN když je rozhodnuto (D2/D6)       │
   └──────────────────────────────────┬───────────────────────────┘
                                      │
                                      ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  ZDROJ DAT — vrstva C                                        │
   │                                                              │
   │   setup checky (tags: ["setup"])                             │
   │      │  dopočítávají stav z dat a settings                   │
   │      ├──► panel „Průvodce nastavením“   (settingsItems)      │
   │      ├──► jedna agregovaná karta feedu  (open_panel)         │
   │      └──► viewer alertů (snooze/dismiss jako dnes)           │
   │                                                              │
   │   průvodce zapisuje ──► core_system_settings  (parametry)    │
   │                    └──► business tabulky      (řádky)        │
   │                    └──► spouští provisionery osnovy a roků   │
   └──────────────────────────────────────────────────────────────┘
```

Klíčové: **checky jsou jediný zdroj pravdy o stavu nastavení.** Panel, karta
i viewer alertů jsou tři pohledy na tutéž kolekci. Přidání nastavení = přidání
checku; všechna tři místa se aktualizují sama.

---

## 5. Kontrakty

### 5.1 Vrstva A — `main.json` a `ds-create`

`DataSourceConfig` dostane getter `getCountry(): string` (ISO 3166-1 alpha-2
lower-case) a `hasCountry(): bool`. `getDefaultLanguage()` zůstává, jak je.

**Přechodný fallback.** Žádný existující DS `country` v `main.json` nemá, a reimport ze starého Shipardu (D9) je teprve před námi — `getCountry()` proto
vrací přechodně `'cz'`, když klíč chybí, a `ds-upgrade` v tom případě
vypíše `[WARN]`. `hasCountry()` je tu proto, aby ten warning šel vystavit bez
hládání v surovém poli. Po reimportu se fallback odstraní a chybějící hodnota
se stane chybou konfigurace — do té doby je `'cz'` vědomá lokalíní nepřesnost,
která je tichá jen u DS založených před tímto taskem.

V `ds-create` naopak fallback **není** — oba přepínače jsou povinné (§ níže).
Nový DS tedy nikdy nevznikne s odhadnutou zemí; fallback slouží výhradně
starým DS.

`ds-create` dostane dva přepínače:

```
--language   ISO 639-1 (cs|en)          povinný; main.json → defaultLanguage
--country    ISO 3166-1 alpha-2 (cz|sk) povinný; main.json → country
```

Validace jen tvarem (`^[a-z]{2}$` + whitelist jazyků) — cfgItem
`world.base.countries` v okamžiku `ds-create` ještě není zkompilovaný,
sémantická validace tedy patří výš (formulář hostingu, dev dashboard).

Tři volající `ds-create` je předají všichni: `HostingSyncRunner` (z queue
payloadu), `DevDashboardController` (z formuláře dev dashboardu) a ruční CLI.

**Getterům `getAccountChart()` a `getDefaultCurrency()` končí platnost** — po
Fázi 2 je nikdo nečte a klíče v `main.json` jsou mrtvé (D9: žádný backfill,
žádný fallback na ně).

### 5.2 Vrstva C — settings klíče a jejich čtenáři

Klíče namespacované podle modulu (pravidlo z [`app-settings.md`](app-settings.md)
§6), scope `ds`, uložené přes `SettingsStore`. Absence klíče = nerozhodnuto (D2);
`SettingsStore::set($key, null)` klíč maže, takže „vrátit do nerozhodnutého
stavu“ je součást existujícího API.

| Klíč | Typ | Obsah | Čtenáři |
|---|---|---|---|
| `economy.accountChart` | `default` \| `npo` \| `none` | varianta účtové osnovy | `AccountChartProvisioner` |
| `economy.homeCurrency` | string, ISO 4217 lower | domácí měna dokladů | `LedgerGenerator`, `DocsHeadsFormBase`, `ReceivedInvoiceForm` |
| `economy.fiscalYearStartMonth` | int 1–12 | první měsíc fiskálního roku | `FiscalYearsProvisioner` |
| `economy.vatAgenda` | bool | plátcovství DPH | `VatPeriodsProvisioner`, formuláře dokladů (výchozí `vat_mode`), viditelnost DPH v UI |

`SettingsStore` bere v konstruktoru jen `DataSourceConnection` a je podle
`app-settings.md` výslovně volatelný z HTTP i CLI — tentýž klíč tedy čte
průvodce ve frontendu i provisioner v `ds-upgrade`. Žádná nová infrastruktura.

**CLI přístup ke klíčům.** `SettingsStore` dnes není použitý z žádného
commandu. Do doby, než existuje průvodce (Fáze 4), by tedy nebylo jak
parametr rozhodnout — a každý DS založený v tom okně by zůstal bez
osnovy. Proto `ds-setting get|set|list` (Fáze 2) a výpis nerozhodnutých
parametrů na konci `ds-upgrade` včetně příkazů k jejich nastavení —
`ds-upgrade` tak slouží jako provizorní checklist a zároveň si na něm
ověříme obsah budoucích setup checků.

**Call sites k přepojení:** `LedgerGenerator` čte dnes
`$this->dsConfig?->getDefaultCurrency()`; `DocsHeadsFormBase` a
`ReceivedInvoiceForm` mají zadrátovaný fallback `'czk'`. Po Fázi 2 čtou
`economy.homeCurrency`.

**Editovatelnost mimo průvodce.** Parametry mají být vidět a měnitelné
i v Nastavení, tedy na settings stránce. To vyžaduje **field typy `select` a
`checkbox`**, které dnes neexistují — `app-settings.md` je výslovně odkládá na
„první stránku, která je potřebuje“. Tou stránkou je tato oblast. Parser
`ModuleDefinition::fromArray()` typy whitelistuje, takže jde o rozšíření
whitelistu + render ve `SettingsPage.svelte`, ne o architektonickou změnu.

### 5.3 Setup checky

Konvence `id` = `<group>.<module>.<slug>` (viz [`alerts.md`](alerts.md) §1).
Všechny nesou `tags: ["setup"]` — ten tag je **kontraktem** pro agregaci
ve feedu (D8) i pro sběr kroků průvodce (D4). Singleton checky, tedy
`findingKey = ""`.

| Check | Modul | Detekce | Primární akce |
|---|---|---|---|
| `base.persons.missing_own_person` | `base.persons` | *existuje dnes* | `open_form` (preset `is_own`, `person_type`) |
| `base.persons.missing_own_headquarters` | `base.persons` | vlastní Osoba bez adresy `address_type = 1` | `open_form` |
| `economy.codebooks.undecided_vat_agenda` | `economy.codebooks` | chybí `economy.vatAgenda` | `open_panel` |
| `economy.codebooks.missing_vat_registration` | `economy.codebooks` | `vatAgenda = true` ∧ `COUNT(*) = 0` | `open_form` |
| `economy.codebooks.missing_own_bank_account` | `economy.codebooks` | `COUNT(*) = 0` aktivních | `open_form` |
| `economy.codebooks.undecided_fiscal_year_start` | `economy.codebooks` | chybí `economy.fiscalYearStartMonth` | `open_panel` |
| `economy.codebooks.undecided_home_currency` | `economy.codebooks` | chybí `economy.homeCurrency` | `open_panel` |
| `economy.accounting.undecided_account_chart` | `economy.accounting` | chybí `economy.accountChart` | `open_panel` |

**Podmíněnost se řeší sama.** Check vrátí prázdné pole, když položka není
relevantní: `missing_vat_registration` mlčí u neplátce, checky nad osnovou
mlčí při rozhodnutí `none`. Žádný nový mechanismus — check si sám přečte
settings.

**Severity `warning`**, ne `error`. Nejde o poruchu, jde o nedokončené
nastavení; `error` je vyhrazený pro věci, které se rozbily.

### 5.4 Průvodce — nav položka typu `panel`

Průvodce je klientská komponenta, ne generická settings stránka — má vlastní
krokovou logiku a volá provisionery. To je přesně případ pro nav položku typu
**`panel`** (precedent `accountSecurity`): server dodá `panels: [{id, name,
icon}]` + položku v `settingsItems` sekce `app`, frontend mapuje `panelId` →
komponenta přes `panelComponents` v `ContentArea.svelte`.

Kroky, v pořadí danom závislostmi:

1. **Rekapitulace A** — jazyk a země, read-only (nelze měnit; ukazuje se, aby
   uživatel věděl, z jakého registru se bude tahat).
2. **Vlastní Osoba z registru.** Reuse existující cesty: `personsRegistry.js`
   → `PersonsRegistryClient` (ARES + RPO + registr plátců DPH) → kanonický
   `shpd.persons.person.v1` → `_exchange/persons/person/preview` → `apply`.
   Průvodce do kanonického payloadu doplní `status.isOwn = true` —
   `PersonApplier` ten příznak už dnes zapisuje, takže není potřeba žádný
   dodatečný update. Jedním applyem vznikne Osoba, sídlo, bankovní spojení
   a DIČ.
3. **Plátcovství DPH** → `economy.vatAgenda`. Při `true` navazuje předvyplněná
   Registrace DPH: `valid_from` z registru plátců, `country`/`region`
   z vrstvy A, `vat_id` z kanonického payloadu. **Frekvence přiznání
   a kontrolního hlášení (`tax_period_kind`, `report_period_kind`) v registru
   nejsou — na ty se musí zeptat.**
4. **Vlastní bankovní účet** — překlop z `base_persons_bank_accounts` vlastní
   Osoby do `economy_codebooks_bank_accounts`. Mapování je téměř 1:1
   (`accountNumber`, `iban`, `bic`, `currency`); průvodce dogeneruje povinný
   `code` a `name` a nastaví `is_default`. **Tento můstek je snadné
   přehlédnout — jsou to dvě různé tabulky a na vydanou fakturu jde ta
   číselníková.**
5. **Účtová osnova** → `economy.accountChart` + synchronní běh
   `AccountChartProvisioner` (bere `$dsConnection` + seed soubor, tedy je
   volatelný z HTTP).
6. **Fiskální rok** → `economy.fiscalYearStartMonth` + běh
   `FiscalYearsProvisioner` (bere `$dsConnection` + `ConfigRuntime`, také
   z HTTP dostupný). Do tohoto okamžiku DS fiskální roky nemá (D6).
7. **Domácí měna** → `economy.homeCurrency`.
8. **Nabídky** — základní Položky, název a logo aplikace (§10).

Průvodce je **přeskočitelný v každém kroku**; nedokončené kroky zůstanou
v checklistu. Opětovné otevření průvodce začíná u první nesplněné položky.

### 5.5 Agregovaná karta feedu

Dnešní agregace v `AlertsSource` je `GROUP BY check_id` s prahem
`GROUP_THRESHOLD = 3` (tj. 4+). Osm singleton setup alertů by se tedy
**nesbalilo** — každý check má jeden alert, tedy pod prahem.

Proto **nová osa agregace podle tagu** (D8), jako rozšíření `AlertsSource`,
ne jako nový feed zdroj:

- Alerty, jejichž check nese `tags: ["setup"]`, se sbalí do **jedné karty**
  bez ohledu na počet a plně nahradí individuální karty daných checků.
- `id = "alert-group:setup"`, titulek *„Dokončit nastavení“*, podtitulek
  s pravdivým počtem, `kind` podle nejvyšší severity ve skupině (agregace
  nesnižuje viditelnost — stejné pravidlo jako u agregace per check),
  `timestamp = MAX(last_seen_at)`, `context = {tag: 'setup', count, severity,
  group: true}`.
- Sběr zůstává dvoufázový; tagová skupina se vyhodnocuje **před** skupinami
  per check, aby setup alerty nespadly do obou.
- Lookup do `AlertCheckRegistry` (kvůli tagům a lokalizovaným názvům) tam už
  pro titulky skupinových karet je.

**Primární akce = `open_panel`** — nový druh akce vedle `open_form`
a `open_viewer`. Payload `{panelId}`. Ve frontendu je to malé: mapa
`panelComponents` i protékání `panelId` přes `Sidebar.handleItemClick`
a `navigationStore.navigate()` už existují.

### 5.6 Hosting

Rozsah je vědomě minimální (D7):

- **Tabulka** `hosting_core_data_sources` — dva sloupce ve skupině `identity`:
  `language` (enumString, cfgItem jazyků) a `country` (enumString, cfgItem
  `world.base.countries`).
- **Formulář** `DataSourcesForm` — dvě pole.
- **Queue payload** — `HostingServerController::buildQueueItem()` přidá
  `language` a `country`. Varianta `peek` je informativní, může zůstat.
- **Agent** — `HostingSyncRunner` je předá do `ds-create` jako `--language`
  a `--country`.

Hosting se vrstvy C nedotýká. Žádné volání registru z agenta, žádné business
řádky, žádná změna v `confirm`.

---

## 6. Plátcovství DPH (Issue #17)

Rozhodnutí D5 je menší změna, než se zdálo: **`vat_mode = 0` („Bez DPH“) už
existuje** (`docs.core.vatModes`) a `DocDocument::validate` při něm Registraci
DPH nevyžaduje. Chybí tedy jen:

1. Globální příznak `economy.vatAgenda` (§5.2).
2. Výchozí `vat_mode` na novém dokladu odvozený z příznaku. Sloupec má
   `"default": 1` na úrovni definice tabulky — odvozený default patří do
   formuláře, ne do schématu.
3. Viditelnost agendy DPH v **navigaci** podle D11 a skrytí sekce „DPH“
   v hlavičce dokladu, když se s DPH nepracuje.

   Skrývání **jednotlivých polí** už implementované je: `DocsHeadsFormBase`
   počítá `$hasVat = $vatMode !== 0` a věší `hidden: !$hasVat` na
   `vat_calc_source`, `vat_place`, `vat_registration`, `vat_duzp`, `vat_dppd`;
   `DocRowsForm` dělá totéž. Vede to z `vat_mode` dokladu, tedy přesně
   podle D10 — nesáhat na to.

   **Mimo rozsah, protože není co skrývat:** viewery dokladů DPH sloupce
   nemají, tiskové šablony faktur v repu neexistují a DPH výstupy
   (přiznání, kontrolní hlášení) jsou neimplementovaný milník M1.

   **Účtování žádnou změnu nepotřebuje:** `AccountingEngine::buildVatLines()`
   staví řádky z `docs_core_vat_recap` a `buildRowLines()` bere
   `vat_base_dom`, takže doklad s `vat_mode = 0` zaúčtuje plnou částku
   a na 343xxx nesáhne.
4. Provisioning období DPH **v okamžiku vzniku registrace**. `VatPeriodsProvisioner` iteruje registrace, takže na DS bez registrace už dnes nic nevyrobí — „u neplátce negenerovat“ tedy není co implementovat. Chybí opačný směr: dokud se období generují jen při `ds-upgrade`, má uživatel po založení registrace registraci a nulová období. Patří do `vat-payer-01`, průvodce pak volá hotovou věc.

**Proč varianta 1 a ne `neplátce` jako `taxpayer_kind`:** registrace je
časově omezený fakt (`valid_from`/`valid_to`), takže **absence v intervalu je
přirozené kódování „tehdy jsem nebyl plátce“**. Varianta 2 by nutila každého
neplátce držet fiktivní registraci a k ní existující období DPH, a přechod
plátce → neplátce → plátce by se kódoval překrývajícími se záznamy o něčem,
co neexistovalo. Globální příznak pak řídí jen defaulty a viditelnost, nikoli
pravdu.

Issue #17 je v milníku **M0 — Věcná správnost výpočtů**, tedy může mít vyšší
prioritu než zbytek této oblasti. Body 1–4 jsou implementovatelné samostatně,
jen s tím, že bod 1 předpokládá Fázi 2 (§8).

---

## 7. Provozní poznámky

### 7.1 `ds-reset`

`core_system_settings` je v `keepOnReset` modulu `core.system` — **parametry
vrstvy C reset přežijí**, business řádky ne. To je žádoucí chování: po resetu
zůstává „osnova = npo“ a provisioner ji jen znovu naseeduje, zatímco vlastní
Osoba a Registrace DPH se objeví v checklistu.

Právě tohle je důvod, proč stav průvodce **nesmí** být příznak v settings
(D3): takový příznak by po resetu tvrdil „hotovo“, i když data zmizela.
Dopočítávaný stav se nemůže rozejít s realitou.

### 7.2 Import ze starého Shipardu

Bez backfillu (D9) jsou settings klíče jediným zdrojem pravdy, takže **import
musí parametry zapsat sám** — jinak přeimportovaný zdroj přijde s kompletními
daty a současně s plným checklistem, protože „nerozhodnuto“ se pozná právě po
absenci klíče.

Import zapisuje: `economy.accountChart`, `economy.homeCurrency`,
`economy.fiscalYearStartMonth`, `economy.vatAgenda`. Import už dnes běží pod
`skipProvisioning: true`, takže se ta odpovědnost k němu logicky pojí.

Vrstvu A (`language`, `country`) zapisuje `ds-create`, tedy krok před importem.

---

## 8. Fázování

| Fáze | Obsah | Hotovo když |
|---|---|---|
| **1 — Vrstva A** | `country` v `DataSourceConfig`, přepínače `ds-create --language --country`, hosting (dva sloupce, formulář, queue payload, agent) | Nový DS vznikne z hostingu i z konzole s vyplněným jazykem a zemí v `main.json` |
| **2 — Parametry do settings** | Čtyři klíče, provisionery a formuláře je čtou, odložený provisioning osnovy a fiskálních roků (D6), konec `getAccountChart()`/`getDefaultCurrency()` | `ds-upgrade` na čerstvém DS osnovu ani roky nenaseeduje; naseeduje je, jakmile klíč existuje |
| **3 — Setup checky** | Sedm nových checků + zobecnění existujícího, podmíněnost, tagová agregace ve feedu (D8), akce `open_panel` | Čerstvý DS ukazuje jednu kartu „Dokončit nastavení“; viewer alertů ukazuje osm položek |
| **4 — Průvodce** | Panel `dsSetup`, kroky 1–7 (§5.4), reuse registrové cesty, můstek do číselníku bankovních účtů, `select`/`checkbox` field typy | Uživatel projde od čerstvého DS k potvrditelné vydané faktuře bez opuštění průvodce |
| **5 — Nabídky** | Generátor základních Položek, název a logo v průvodci | Uživatel dostane volitelný startovní obsah, aniž by na něj cokoli tlačilo |

Fáze 2 a 3 jsou navzájem nezávislé (checky nad chybějícími řádky nepotřebují
settings) a dají se prohodit nebo dělat paralelně; Fáze 4 potřebuje obě.

**Plátcovství DPH jde přednostně.** Issue #17 je v milníku **M0 — Věcná
správnost výpočtů**, takže body 1–4 z §6 se implementují hned poté, co Fáze 2
dodá mechanismus settings klíčů — tedy před zbytkem Fáze 2 i před Fází 3.
Pořadí tasků: vrstva A → hosting → klíče osnovy a fiskálního roku →
plátcovství DPH → domácí měna → setup checky → tagová agregace → field typy
→ průvodce → nabídky.

---

## 9. Dotčené moduly

| Modul / projekt | Dopad |
|---|---|
| `core.system` | `SettingsStore` beze změny (jen nový konzument); field typy `select`/`checkbox` v `ModuleDefinition` + `SettingsPage.svelte`; panel `dsSetup` v `settingsItems` |
| `core.alerts` | Bez změny jádra — nové checky jsou data. Nový druh akce `open_panel` je kontrakt mezi `AlertFinding` a frontendem |
| `base.persons` | Zobecnění `MissingOwnPersonCheck`, nový check na sídlo |
| `economy.codebooks` | Pět checků, čtení `economy.*` klíčů ve `FiscalYearsProvisioner` a `VatPeriodsProvisioner` |
| `economy.accounting` | Check nad osnovou, čtení `economy.accountChart` v `AccountChartProvisioner` |
| `economy.accbal` | `LedgerGenerator` — `home_currency` ze settings místo `main.json` |
| `docs.core` / `docs.invoicesOut` / `docs.invoicesIn` | Odvozený default `vat_mode`, skrytí DPH u neplátce, `home_currency` ze settings |
| `hosting.core` | Dva sloupce, formulář, queue payload (§5.6) |
| jádro (`src/`) | `DataSourceConfig::getCountry()`, `ds-create` přepínače, `HostingSyncRunner`, `DevDashboardController`, `AlertsSource` (tagová agregace) |
| frontend | Panel průvodce, `open_panel` v akcích karet, render `select`/`checkbox` |
| import ze starého Shipardu | Zápis čtyř settings klíčů (§7.2) |

---

## 10. Otevřené body

- **Nabídky vs. checklist.** „Chceš vygenerovat základní Položky?“ není
  problém, ale nabídka — nesplněná nabídka nemá nikdy nic rozsvítit. Návrh:
  nabídky **nejsou alerty**, nejdou do checklistu ani do feedu a žijí výhradně
  jako kroky průvodce (a tedy jen do prvního dokončení průvodce). Alternativou
  je nový druh alertu (`kind: offer`, jednorázový, mizí po `dismiss` — alerty
  to mechanicky umí), ale je to víc mašinerie za málo. **K potvrzení před
  Fází 5.**
- **Jak se průvodce otevírá poprvé.** Karta ve feedu (D8) ho zpřístupňuje, ale
  otevře se sama, nebo si ho uživatel musí kliknout? Návrh: neotevírat
  automaticky — D4 říká neblokující, a automatické otevření je půl kroku
  k blokujícímu wizardu.
- **Rozsah základních Položek.** Co je „základní položka“ pro účetní firmu bez
  skladu? Kandidáti: práce/služba, doprava, zaokrouhlení. Seed patří do
  `economy.items` po vzoru `itemKindsSeed.jsonc`, ale obsah je věcné
  rozhodnutí.
- **Pořadí kroků 5–7 průvodce.** Osnova, fiskální rok a měna jsou navzájem
  nezávislé; pořadí je zatím zvolené odhadem.

---

[← docs/README.md](README.md) · [hosting.md](hosting.md) · [alerts.md](alerts.md) · [app-settings.md](app-settings.md)
