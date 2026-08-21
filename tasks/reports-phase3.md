# Reporty Fáze 3 — ReportViewer (Svelte UI + navigace + deep-link)

**Stav:** hotovo

> Implementováno 2026-08-21. Backend testy + build + API smoke na dev DS
> zelené; z checklistu „Hotovo když" zbývá ruční proklik UI v prohlížeči
> (picker, deep-link copy-paste, rozbitá rozvaha). Odchylka od zadání:
> `periods.fiscalYears[].name` je string, ne number (shoda s parametrem
> `fiscalYear`) — viz `docs/reports.md` §12.

> PRD pro jednu Claude Code session. Design: `docs/reports.md` (D7–D10).
> Staví na Fázích 1+2 (jádro, tři buildery, REST `GET /_reports`).
> Backend se mění jen minimálně (katalog + navigace); těžiště je frontend.

## Kontext

Reporty zatím žijí jen v API. Fáze 3 je přinese uživatelům: podsekce
**Reporty v sekci Účtárna** (sidebar, po jedné položce na report — D10),
jedna generická stránka `ReportViewer`, picker období ve stylu starého
Shipardu (roky × měsíc/čtvrtletí/pololetí/rok), parametry v URL
(deep-link). Frontend **nemá URL routing** — deep-link se řeší minimálně
a samonosně (query string + one-shot hint ve stylu `pendingViewGroup`),
žádný router se nezavádí.

Zobrazení „v tisících" je čistě prezentační přepínač ve vieweru (dělení
1000 při renderu) — data z API nesou vždy plnou přesnost (D6, rozhodnuto
ve Fázi 2).

## Cíl

1. Backend: katalog `GET /_reports` rozšířit o fiskální období (data pro
   picker) a `ReportColumn` o zobrazovací hint; deklarace reportů
   o `navSection`/`navOrder`; NavigationController emituje skupinu Reporty.
2. Frontend: `api/reports.js`, `ReportViewer.svelte`, `PeriodPicker.svelte`,
   zapojení do ContentArea + navigation store (`panelParams`), URL sync.
3. i18n (cs/en) + `npm run check:i18n`.
4. Testy backendu + manuální ověřovací checklist UI.

## Návaznost

- **Prerekvizity:** Fáze 1+2 hotové (včetně D17 — seed kinds + warning).
- **Odemyká:** každodenní použití reportů (kontrola účtování, příprava na
  DPH výstupy M1); Fáze 4 (MCP + diff) je nezávislá.
- Tisk reportu (tlačítko Tisk) — **mimo rozsah**, čeká na PDF službu #34.

## Před implementací přečti

- `docs/reports.md` §4, §5, §7.1 (deklarace, období, UI)
- Backend: `src/Api/Controller/ReportsController.php`,
  `src/Core/Reports/ReportDefinition.php` + `ReportDefinitionLoader`,
  `DbFiscalPeriodProvider` (zdroj období), `ReportColumn`
- Navigace: `src/Api/Controller/NavigationController.php` (doc komentář
  třídy + zpracování `panels[]` ř. ~349, `cleanItem`, sekce `accounting`
  v navSections), `frontend/src/stores/navigation.svelte.js` (tvary items,
  vzor one-shot hintů `pendingViewGroup` — deep-link kopíruje tenhle vzor),
  `frontend/src/components/layout/Sidebar.svelte` (skupiny s `children`
  fungují), `ContentArea.svelte` (mapa `panelComponents`)
- Frontend vzory: `frontend/src/api/accounting.js` (tvar API klienta),
  `frontend/src/components/browser/` — `JournalViewer` (tabulka, footer,
  styly), i18n `frontend/src/i18n/cs.js`/`en.js` + `npm run check:i18n`
  (spouštět z `frontend/`, Node vyžaduje PATH
  `/home/sebik/.nvm/versions/node/v24.14.0/bin`)
- Screenshoty starého Shipardu v issue kontextu: picker období = mřížka
  roky × 1–12 | 1Q–4Q | 1|2–2|2 | rok (viz `tasks/` diskuze, D8)

## Scope

**Uvnitř:** katalog + periods; `ReportColumn.display`; `navSection` v
deklaraci; NavigationController skupina Reporty + `panelParams` plumbing;
panel `reports` v module.jsonc; frontend (api klient, viewer, picker,
ContentArea, navigation store, URL sync); i18n; testy backendu.

**Mimo:** tisk (#34); export CSV a diff (Fáze 4); MCP (Fáze 4); grafy;
drill-down do deníku (viz Otevřené body); střediska; jakýkoli router.

## Co implementovat

### A. Backend — katalog s obdobími a display hint

1. **`GET /_reports`** rozšiř o klíč `periods` (jednou pro celou odpověď,
   ne per report): `{"fiscalYears": [{"name": 2026, "months": 12}]}` —
   z `DbFiscalPeriodProvider` (jen běžné měsíce, `period_type` 1; roky
   řazené dle name). Picker si čtvrtletí/pololetí odvodí sám.
2. **`ReportColumn`**: nový volitelný atribut `display: 'balance' | 'sides'`
   (default `'balance'`), v `toArray()` vždy přítomen. Buildery:
   hlavní kniha — `turnover` → `sides`, `opening`/`closing` → `balance`;
   výsledovka a rozvaha — všude `balance`. Viewer podle toho renderuje
   sloupec buď jako jednu hodnotu (Zůstatek), nebo trojici MD / D / Zůstatek.
3. Testy: `ReportsApiTest` — `periods` ve tvaru výše; `ReportCoreTest` —
   display v toArray včetně defaultu.

### B. Deklarace — `navSection` / `navOrder`

Do report deklarace (JSONC) volitelné `navSection` + `navOrder`;
`ReportDefinition` je nese. Všem třem reportům nastav
`"navSection": "accounting"` a navOrder: hlavní kniha 60, výsledovka 61,
rozvaha 62 (za existující účetní položky). Jedna deklarace → navigace
i UI (duch D7).

### C. NavigationController — skupina Reporty

Za zpracování `panels[]` doplň blok: načti `ReportRegistry` (loader už
existuje; lazily, jen pokud DS má aktivní modul s reporty — při výjimce
loguj a pokračuj, vzor navigationProviders). Reporty s `navSection`
seskup per sekce do **skupiny** `{id: 'reports', label: 'Reporty'/'Reports',
children: [...]}`; child = `{id: 'report:' + reportId, label: <lokalizovaný
název>, type: 'panel', panelId: 'reports', panelParams: {reportId},
icon: 'chart'}` (ikonu vyber z existující sady, ať nevzniká nová).
`cleanItem` musí `panelParams` propustit. Řazení skupiny v sekci dle
nejnižšího navOrder childů.

### D. module.jsonc — panel `reports`

Do `modules/economy/accounting/module.jsonc` přidej `panels[]` položku
`{id: "reports", name: "Reports", "name:cs": "Reporty"}` — **bez**
`navSection` (do navigace vstupují per-report children z C, panel sám ne).

### E. Frontend

1. **`api/reports.js`** — `fetchReportCatalog()` (`GET /_reports`),
   `runReport(reportId, params)` (`GET /_reports/{id}?...`). Vzor
   `accounting.js`.
2. **Navigation store** — `panelParams` propustit do leaf tvaru (vzor
   `panelId`); nová one-shot dvojice `pendingReportParams` (vzor
   `pendingViewGroup`) pro deep-link.
3. **`ContentArea.svelte`** — do mapy `panelComponents` přidej
   `reports: ReportsPage`; panel komponentám předej `item={activeItem}`
   (ostatní panely prop ignorují — ověř, že to nic nerozbije).
4. **`ReportsPage.svelte`** (`frontend/src/components/reports/`) — obal:
   z `item.panelParams.reportId` + katalogu vybere definici, drží stav
   parametrů, volá `runReport`, renderuje toolbar + `ReportView`.
   Stav parametrů per reportId přežívá přepnutí reportů v rámci session
   (jednoduchá mapa v modulu), default = poslední celý měsíc existujícího
   fiskálního roku, detail analytic.
5. **`PeriodPicker.svelte`** — dropdown s mřížkou dle screenshotu starého
   Shipardu: řádky = fiskální roky (z katalogu), sloupce = 1–12 |
   1Q–4Q | 1|2, 2|2 | rok; nabízej jen granularity z deklarace reportu.
   Výběr → `{fiscalYear, monthFrom, monthTo}`. Zobrazený label:
   „2026 / 8", „2026 / 2Q", „2026 / 1|2", „2026".
6. **`ReportView.svelte`** — čistý renderer `ReportResult`:
   - hlavička sloupců dle `columns` (+ `display: sides` → podsloupce
     MD / D / Zůstatek),
   - řádky: odsazení dle `level`, subtotal tučně + podbarvení, total
     zvýrazněný, computed kurzívou/odlišeně — vzor vzhledu JournalViewer,
   - přepínač Přesně / V tisících (jen render: /1000, zaokrouhlení,
     header „(v tis.)"),
   - řádky s `rowRef` v messages podbarvit červeně; **messages pod čarou**
     dole (severity ikonka + text), status badge nahoře jen při
     warnings/errors,
   - prázdný výsledek → hlášky „Žádná data za zvolené období".
7. **URL sync (deep-link, D10)** — v ReportsPage při každé změně
   parametrů `history.replaceState` s query
   `?report=<id>&fy=<rok>&mf=<od>&mt=<do>&detail=<d>` (bez reloadu,
   bez zásahu do zbytku shellu). Při startu aplikace (kde se čte
   navigace) když query obsahuje `report=`: nastav
   `pendingReportParams` a aktivuj příslušný nav leaf (`report:<id>`);
   neznámé id → ignorovat (normální start). „V tisících" do URL nepatří
   (čistě vizuální volba).

### F. i18n

Všechny texty vieweru přes slovníky cs/en (klíče `reports.*`);
`npm run check:i18n` zelený.

## Testy

Backend (`--filter 'ReportCoreTest|ReportsApiTest|NavigationReportsTest'`):
1. `ReportCoreTest` — `ReportColumn.display` (default + explicitní).
2. `ReportsApiTest` — `periods` ve výstupu katalogu.
3. **`NavigationReportsTest`** (nový, vzor existujících navigation testů,
   pokud jsou; jinak integrace nad dev DS) — sekce accounting obsahuje
   skupinu `reports` se 3 children, child nese `type: 'panel'`,
   `panelId: 'reports'`, `panelParams.reportId`; labels lokalizované.

Frontend nemá test infrastrukturu — **manuální checklist** (Hotovo když).

## Commit strategie

1. `api: reporty — periods v katalogu + ReportColumn.display` (A)
2. `economy.accounting: navSection reportů + panel reports; navigace — skupina Reporty (panelParams)` (B+C+D)
3. `frontend: ReportViewer — stránka, picker období, render ReportResult` (E1–E6, F)
4. `frontend: deep-link reportů přes query string` (E7)
5. `docs: reports.md — stav Fáze 3`

## Hotovo když

- [ ] backend testy zelené; `npm run check:i18n` zelený; `ds-upgrade` projde
- [ ] sidebar: Účtárna → skupina Reporty → 3 položky; ne-admin je vidí
      (reporty čtou deník — žádné adminOnly)
- [ ] dev DS: hlavní kniha 2026/srpen — turnover jako MD/D/Zůstatek,
      opening/closing jako Zůstatek; čísla sedí na Fázi 1 ověření
- [ ] výsledovka: computed řádek odlišený, period/ytd sloupce
- [ ] rozvaha: AKTIVA/PASIVA CELKEM zvýrazněné, při uměle rozbitém dokladu
      messages pod čarou + červený řádek (ověř a vrať)
- [ ] picker: měsíc/čtvrtletí/pololetí/rok fungují, label dle vzoru
- [ ] „V tisících" přepíná jen zobrazení (API volání se neopakuje)
- [ ] deep-link: URL zkopírovaná z otevřeného reportu otevře po vložení
      do nového okna stejný report se stejnými parametry; URL se mění
      při změně parametrů bez reloadu
- [ ] přepnutí na jiný report a zpět zachová parametry (session)
- [ ] `docs/reports.md`: stav Fáze 3 + zaznamenat display hint a tvar
      deep-link URL

## Otevřené body (nerozhodují o Fázi 3)

- Drill-down z řádku do deníku (klik na účet → JournalViewer s filtrem
  účet+období) — přirozená Fáze 3.1, chce jednotný způsob předání filtru
  do vieweru; teď neblokuje.
- Trvalé uložení naposledy použitých parametrů (localStorage/user setting)
  — zatím jen session mapa.
- Tlačítko Tisk — po #34.
