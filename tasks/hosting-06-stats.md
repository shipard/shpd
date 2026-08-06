# Hosting — Task 5: Přehled napříč DS (Fáze 5)

**Stav:** hotovo — 2026-08-06; poznámky k implementaci na konci

> PRD pro jednu Claude Code session. Design: `docs/hosting.md`,
> rozhodnutí **D7**, §5.2 krok 3. Poslední fáze — po dokončení je
> design D1–D12 kompletně implementovaný.

## Kontext

Portálová karta DS zatím ukazuje jen název a vstupní tlačítko. D7:
DS servery pushují malý agregát („kolik čeho čeká") do hostingu,
portál ho zobrazí. Záměrně **jen čísla** — žádné názvy partnerů,
částky, předměty mailů (§7).

**Vědomé volby (potvrzeno v chatu):**

1. **Dvě metriky, aditivně rozšiřitelné**: `alerts_count` (aktivní
   alerty) a `mail_count` (pošta k řešení = extrahované dokumenty
   `status IN (10,20,30)` + zprávy `analysis_state = 70` mimo
   Archiv/Koš — stejná sémantika jako karty feedu, ale laciné COUNTy
   bez per-user kontextu). `NULL` = modul na DS není aktivní.
2. **Sběr přes nový read-only `shpd-ds hosting-stats --json`** —
   subprocess per DS (vzor ostatních kroků agenta); počítá přímo nad
   tabulkami, feed pipeline se nespouští (je per-user a drahá).
3. **Kadenci řídí hosting**: response reconcile dostane
   `stats_wanted: bool` (true když nejstarší snapshot serveru je
   starší než ~10 min). Agent je stateless — stats krok běží jen na
   vyžádání (`--stats` option pro ruční vynucení).
4. **Snapshot upsert** — jeden řádek per DS (unique `data_source`),
   žádná historie (tabulka neroste; historie = případná v2).
5. **Portál**: badge se součtem („N k řešení") + rozpad v tooltipu;
   snapshot starší 60 min nebo chybějící → badge se nezobrazí
   (žádná stará čísla vydávaná za aktuální).

## Cíl

1. `shpd-ds hosting-stats --json` (read-only COUNTy).
2. Krok 3 agenta (`stats_wanted` → sběr → `POST
   /_hosting/server/stats`) — kotva v runneru už čeká.
3. Tabulka `hosting_core_ds_stats` + endpoint + upsert.
4. `/_hosting/portal/my-datasources` + `PortalScreen` badge.
5. Uzavření design dokumentu (status D1–D12 implementováno).

## Před implementací přečti

- `docs/hosting.md` §5.2 (krok 3), §7, §4 (ds_stats)
- `modules/core/alerts/src/Feed/AlertsSource.php` — konstanta
  aktivního `alert_state` (převzít, nehardcodovat číslo znovu)
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` — přesné
  stavy (status 10/20/30; analysis_state 70 + vyloučené docStates)
  a názvy konstant — COUNTy musí sedět se sémantikou karet
- `src/Core/Server/HostingSyncRunner.php` — reconcile call, kotva
  stats kroku, subprocess seam
- `src/Api/Controller/HostingServerController.php` — reconcile
  response, vzor validace body, párování ds_id ↔ evidence
- `src/Api/Controller/HostingPortalController.php` +
  `frontend/src/components/portal/PortalScreen.svelte` — rozšíření
  response a karty
- `src/Command/DataSource/` — vzor read-only commandu (např.
  doctor/status commandy, pokud existují — jinak set-key bez zápisu)

## Změny po souborech

### `src/Command/DataSource/HostingStatsCommand.php` (nový)

`shpd-ds hosting-stats --json` → jediný JSON objekt:
`{"alerts": N|null, "mail": N|null}`. Tabulka modulu chybí
(SHOW TABLES / try-catch dle okolních vzorů) → `null`. Jen SELECTy,
žádný zápis. Konstanty stavů importovat ze Source tříd (nebo
z jednoho sdíleného místa — dle toho, kde dnes žijí; žádné druhé
kopie číselných hodnot).

### `hosting_core_ds_stats.jsonc` (nová tabulka)

`adminOnly`, bez docStates (snapshot): `data_source` (int FK →
hosting_core_data_sources, **unique**), `alerts_count` (int,
nullable), `mail_count` (int, nullable), `collected_at` (datetime).
`tableId` přes `next-table-id`; rebuild cfg + `ds-upgrade` gn5c.
Bez vieweru — čísla jsou vidět na portálu; admin je má v DB
(případný viewer = v2).

### `HostingServerController`

- **reconcile response**: `{ok: true, stats_wanted: bool}` —
  true, když server nemá žádný snapshot nebo nejstarší
  `collected_at` DS tohoto serveru < now − 10 min.
- **Nová akce `stats`** (POST, auth klíčem serveru): body
  `{stats: [{ds_id, alerts: N|null, mail: N|null}]}` (limit velikosti
  po vzoru reconcile). Per položka: ds_id patří tomuto serveru →
  upsert (INSERT … ON DUPLICATE KEY dle konvence okolního kódu /
  existující upsert helper) s `collected_at = now`; neznámé/cizí
  ds_id → přeskočit + warning do logu (vzor reconcile). Response
  `{ok: true, accepted: N}`.
- Router + dispatch + exempt dle vzoru reconcile/queue/confirm.

### Agent (`HostingSyncRunner`)

Krok 3 (kotva): `stats_wanted` z reconcile response (nebo `--stats`
option commandu) → pro každý lokální DS z inventury spustit
`cd {dsDir} && shpd-ds hosting-stats --json`, parsovat; selhání
jednoho DS → přeskočit + log (nesmí shodit běh). Nasbírané →
`POST …/stats`. Prázdný výsledek → nepostovat.

### Portál

- `HostingPortalController::myDatasources`: LEFT JOIN ds_stats,
  do položek `stats: {alerts, mail, collected_at} | null`.
- `PortalScreen.svelte`: badge „{total} k řešení" (total =
  alerts + mail, null se počítá jako 0; total 0 → zelená fajfka nebo
  nic — zvol dle stávajícího vizuálu karet), tooltip/title s rozpadem
  („{alerts} upozornění · {mail} pošta"). `collected_at` starší 60 min
  nebo `stats = null` → badge vůbec nerenderovat. i18n cs+en
  (pozor na plurály — použij existující i18n plural mechanismus,
  pokud je; jinak neutrální formulace „k řešení: N"),
  `npm run check:i18n`
  (`PATH=/home/sebik/.nvm/versions/node/v24.14.0/bin:$PATH`).

### Dokumentace

`docs/hosting.md`: §5.2 krok 3 skutečný tvar (stats_wanted,
endpoint), §8 Fáze 5 hotová, **status v hlavičce → „Design D1–D12
kompletně implementován (Fáze 0–5 hotové)"**. `docs/cli.md`:
`hosting-stats`, `hosting-sync --stats`.

## Testy

- `HostingStatsCommandTest`: COUNTy sedí s fixture stavy (vč. hranic
  — status 40 se nepočítá, archivovaná zpráva s analysis_state 70 se
  nepočítá); chybějící tabulka modulu → null; výstup je čistý JSON.
- `HostingServerControllerTest` (rozšíření): stats_wanted logika
  (žádný snapshot / čerstvý / starý); stats akce — upsert (druhý push
  přepíše), cizí ds_id skip + accepted počet, auth matice zděděná.
- Runner: krok 3 jen při stats_wanted / --stats; selhání jednoho DS
  neshodí ostatní; prázdný sběr → žádný POST (přes seams).
- Portal: stats v response (LEFT JOIN — DS bez snapshotu má null).
- PHPUnit `--filter 'HostingStats|HostingServer|HostingPortal'`.

## E2E na dev (součást tasku)

1. `shpd-ds hosting-stats --json` na vlm9 (má poštu i alerty) a na
   DS bez core.mail (mail: null).
2. `hosting-sync --stats` proti gn5c → řádky v ds_stats; druhý běh
   bez `--stats` do 10 min → stats krok se přeskočí (reconcile
   `stats_wanted: false`).
3. Portál: karta vlm9 s badge a rozpadem; DS bez snapshotu bez badge;
   ručně zestaršit `collected_at` v DB → badge zmizí.

## Commit strategie

1. `hosting: hosting-stats command + ds_stats table + stats endpoint (D7)`
2. `hosting: stats step in hosting-sync, cadence via stats_wanted (D7)`
3. `hosting: portal cards show pending counts (D7)`

## Hotovo když

- [x] Portálová karta ukazuje čerstvý součet „k řešení" s rozpadem;
      stará/chybějící data se nevydávají za aktuální
- [x] Čísla sedí se sémantikou feedu (stejné stavy, sdílené
      konstanty) a neobsahují nic než počty
- [x] Kadence: stats se sbírají jen když je hosting chce
      (stats_wanted), tabulka neroste (upsert)
- [x] Selhání sběru na jednom DS neovlivní ostatní ani zbytek běhu
      agenta
- [x] Testy zelené, i18n check zelený, `docs/hosting.md` uzavřen
      (D1–D12 implementováno)

## Poznámky k implementaci (odchylky od zadání)

- **Sdílené konstanty**: stavy zpráv (`analysis_state`, docState
  Archiv/Koš) neměly public kanonické místo — povýšeny na public
  v `IncomingMessageDocument` a `MailSuggestionsSource` přepnut na ně;
  `AlertsSource` obdobně na `AlertReconciler::STATE_ACTIVE`. Extracted
  stavy už public byly (`ExtractedDocumentDocument::STATUS_*`).
- **mail_count drží i predikát `doc_type != 'other'`** z feed dotazu
  (v zadání explicitně nebyl, ale „stejná sémantika jako karty feedu"
  ho vyžaduje).
- **Upsert stylem fetch-then-update/insert** (ne raw ON DUPLICATE KEY)
  — konzistentní s okolním kódem controlleru a simulovatelné
  v `InMemoryHostingServerDb` (fixture neumí `execute()`).
- **`stats_wanted` porovnává timestampy, ne stringy** — dibi vrací
  datetime jako string s `T` oddělovačem, lexikografické porovnání
  s `Y-m-d H:i:s` tiše selhávalo (odhaleno E2E na gn5c, opraveno).
  Bez tabulky ds_stats (hosting před ds-upgrade) je `stats_wanted`
  false a akce stats vrací 404 — tabulka se záměrně nepřidala do
  sdíleného `gate()`, aby nerozbila reconcile na neupgradnutém
  hostingu.
- **Badge pro total 0** = zelená fajfka s tooltipem (zvoleno místo
  „nic" — odlišuje „zkontrolováno, nic nečeká" od chybějících dat).
- **E2E na dev (2026-08-06, gn5c)**: `hosting-stats` ověřen na vlm9
  (0/0), gn5c (`mail: null`, install.hosting nemá core.mail) a btpg
  (1/16 — sedí 1:1 s přímými SQL COUNTy); agent spuštěn přímo přes
  `HostingSyncRunner` (dev stroj nemá `hosting` sekci v server.json,
  stejně jako u hosting-05) — plný cyklus: bez snapshotu push (6 DS
  sebráno, hosting přijal 1 — jen vlm9 je v evidenci, zbytek skip
  + warning), čerstvý snapshot → krok přeskočen, `--stats` vynutí,
  zestaršený snapshot (2 h) → `stats_wanted: true` a obnova upsertem
  (pořád 1 řádek). Portálové API vrací `stats` vč. `collected_at`
  ISO 8601; **zbývá ruční proklik badge v prohlížeči** (vizuál,
  zmizení po zestaršení — logika je client-side).
