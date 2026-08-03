# Ops: systémový cron runner (`shpd-server cron`)

**Stav:** hotovo

## Kontext

Na alfě se zjistilo, že alerts runner nikdy neběžel — a při bližším pohledu
z cronu neběží **nic**: žádný crontab pro `shipard`, žádný soubor
v `/etc/cron.d`. Periodické úlohy dnes systém nemá; dokumentace předpokládá
ručně opisované cron řádky per DS (`docs/operations/production.md` pro
`mail-outbox-run`, `docs/alerts.md` §12 pro `alerts-run` — tam výslovně
„operations TODO"). Ruční krok, který nikdo neudělal = tichý výpadek celé
funkce. To je přesně failure mode, který provisioning (`shpd-server
upgrade`, vzor nginx/FPM versioned includes) vznikl řešit.

Cíl: **jeden systémový mechanismus** — dispatcher příkaz + generovaný
`/etc/cron.d/shipard` + doctor kontrola, že to žije.

## Návaznost

- Vzor idempotentního generování systémových souborů:
  `shpd-server upgrade` (nginx/FPM versioned include files).
- Iterace přes DS: `DsUpgradeAllCommand`.
- Spotřebitelé (existující per-DS příkazy): `mail-outbox-run` (každou
  minutu), `alerts-run` (à 5 min, self-throttling přes `next_run_at`),
  `alerts-prune` (týdně), `mail-idempotency-prune` (bezpečný opakovaně —
  kadenci ověř v `docs/cli.md`).
- Odblokuje: expirace Spisovny (fáze 4 alert check), outbox health,
  budoucí auto-trigger saldokonto matcheru po ingestu (tasks backlog).

## Před implementací přečti

- `src/Command/Server/UpgradeCommand.php` — jak se generují versioned
  include soubory (marker verze, idempotence, oprávnění — upgrade běží
  s právy zápisu do systémových cest; cron.d vyžaduje totéž, ověř).
- `src/Command/Server/DsUpgradeAllCommand.php` — objevování a iterace
  aktivních DS, chování při chybě jednoho DS.
- `src/Command/DataSource/MailOutboxRunCommand.php` +
  `AlertsRunCommand.php` — kontrakt per-DS příkazů (cwd = adresář DS dle
  `production.md`, exit kódy: FAILURE jen infra chyba).
- `src/Command/Server/DoctorCommand.php` — vzor checků (binárky, fronty).
- `docs/operations/production.md` §Cron + `docs/alerts.md` §12 —
  nahrazované ruční postupy.
- `docs/logging.md` — centrální `shipard.log` (dispatcher loguje tam).

## Scope

**V rozsahu:**

- `shpd-server cron --slot=<minute|five-minutes|daily|weekly>` —
  dispatcher
- generování `/etc/cron.d/shipard` v `shpd-server upgrade` (idempotentní,
  versioned marker)
- heartbeat per slot + kontrola v `shpd-server doctor` (cron soubor
  existuje a odpovídá verzi; heartbeat není zatuchlý)
- aktualizace dokumentace (production.md, alerts.md §12, cli.md)
- rollout na alfě (mutace — po odsouhlasení)

**Mimo rozsah:**

- deklarativní registr jobů v `module.jsonc` (`cronJobs`) — až bude jobů
  víc než hrstka; teď hardcoded mapa (vzor FeedSources)
- systemd timers — zváženo a zamítnuto: cron.d je jednodušší, konzistentní
  s dosavadní dokumentací a bez per-DS unit generátoru
- per-DS override kadence, per-job enable/disable v UI
- `tasks/README.md` neaktualizuj

## Návrh

### Dispatcher `shpd-server cron`

- `--slot` povinný; mapa slot → per-DS příkazy je **konstanta v příkazu**:
  - `minute` → `mail-outbox-run`
  - `five-minutes` → `alerts-run`
  - `daily` → `mail-idempotency-prune` (kadenci potvrď dle cli.md)
  - `weekly` → `alerts-prune`
- Běh: pro každý aktivní DS (mechanismus z `DsUpgradeAllCommand`) spustí
  příkazy slotu **shell-outem** `shpd-ds <cmd>` s cwd v adresáři DS —
  izolace pádů, žádné sdílení stavu mezi DS; timeout per job (konstanta,
  např. 10 min); chyba jednoho DS/jobu nezastaví ostatní
  (continue-on-error, vzor `AllRunner`).
- **Lock per slot** (flock na `/opt/shipard/run/cron-<slot>.lock`):
  překrývající se běh se tiše ukončí (log info) — minute slot nesmí
  pile-upovat, když jeden běh trvá déle.
- **Heartbeat**: po doběhnutí zapíše `/opt/shipard/run/cron-<slot>.heartbeat`
  (timestamp + verze + souhrn: DS count, failed count).
- Logování: centrální `shipard.log` (start/konec slotu agregovaně, chyby
  per DS/job); stdout minimální (cron redirect je jen poslední záchrana).
- Exit: SUCCESS i při selhaných jobech (reportuje doctor/alerty),
  FAILURE jen infra (nelze číst DS seznam apod.).

### Generovaný `/etc/cron.d/shipard`

`shpd-server upgrade` zapisuje idempotentně (versioned marker v hlavičce,
vzor nginx includes; přepis jen při změně verze šablony):

```cron
# shipard cron — generováno `shpd-server upgrade`, verze N. NEEDITOVAT.
*    * * * * shipard /usr/bin/php /opt/shipard/shpd/bin/shpd-server cron --slot=minute        >> /var/log/shipard/cron.log 2>&1
*/5  * * * * shipard /usr/bin/php /opt/shipard/shpd/bin/shpd-server cron --slot=five-minutes  >> /var/log/shipard/cron.log 2>&1
17 3 * * *   shipard /usr/bin/php /opt/shipard/shpd/bin/shpd-server cron --slot=daily         >> /var/log/shipard/cron.log 2>&1
43 4 * * 0   shipard /usr/bin/php /opt/shipard/shpd/bin/shpd-server cron --slot=weekly        >> /var/log/shipard/cron.log 2>&1
```

- Cesty (php binárka, repo root, log dir) ber ze server konfigurace /
  konstant deploye — nehardcodovat v šabloně víc, než je nutné; existence
  `/var/log/shipard/` a `/opt/shipard/run/` zajistí upgrade
  (mkdir + práva, vzor `fix-permissions`).
- DS list se v cron souboru **nevyskytuje** — ds-create/ds-delete
  nevyžadují regeneraci (to je hlavní důvod dispatcheru místo per-DS
  řádků).

### Doctor

`shpd-server doctor` nové kontroly (sekce Cron):

- `/etc/cron.d/shipard` existuje a marker odpovídá aktuální verzi šablony
  (jinak: „spusť shpd-server upgrade");
- heartbeat `minute` mladší než 10 min, `five-minutes` než 20 min (jinak
  error — cron démon neběží nebo soubor nefunguje);
- `daily`/`weekly` jen informativně (stáří < 2× perioda → OK).

Tím se uzavírá smyčka: chybějící cron už nikdy nebude tichý.

## Doporučené pořadí

1. Dispatcher + testy (mapa slotů, lock, continue-on-error, heartbeat,
   exit kódy; shell-out mockovatelný).
2. Generátor cron.d v `UpgradeCommand` + run/log adresáře + testy
   (idempotence, verze marker).
3. Doctor kontroly + testy.
4. Dokumentace: `production.md` §Cron přepsat (ruční entry pryč, odkaz na
   mechanismus), `alerts.md` §12 aktualizovat (TODO splněno), `cli.md`
   doplnit `shpd-server cron`.
5. **Rollout alfa** (po odsouhlasení, jednotlivě): deploy → `shpd-server
   upgrade` → ověřit `/etc/cron.d/shipard` → po pár minutách
   `core_alerts_check_states` se plní, heartbeaty živé, doctor zelený.

## Testy

- dispatcher: neznámý slot → chyba; prázdný DS list → SUCCESS + heartbeat;
  selhání jobu na jednom DS → ostatní běží, exit SUCCESS, log obsahuje
  chybu; lock drží (druhá instance končí hned); heartbeat obsah.
- generátor: soubor vzniká s markerem; opakovaný upgrade beze změny =
  žádný přepis; nová verze šablony = přepis.
- doctor: chybějící soubor / starý marker / zatuchlý heartbeat →
  odpovídající severity a hláška.

## Commit strategie

(1) dispatcher, (2) generátor + adresáře, (3) doctor, (4) dokumentace.

## Hotovo když

- [ ] `shpd-server cron --slot=…` běhá per DS s lockem, heartbeatem
      a continue-on-error; loguje do centrálního logu
- [ ] `shpd-server upgrade` idempotentně generuje `/etc/cron.d/shipard`
      + potřebné adresáře; ds-create/delete nevyžadují zásah do cronu
- [ ] `shpd-server doctor` hlásí chybějící/zastaralý cron soubor
      a zatuchlé heartbeaty
- [ ] dokumentace bez ručních cron kroků (production.md, alerts.md §12,
      cli.md)
- [ ] na alfě: cron soubor nasazen, `check_states` se plní, outbox worker
      běhá, doctor zelený
- [ ] testy zelené

## Otevřené body

1. Kadence `mail-idempotency-prune` (daily vs weekly) — dle cli.md.
2. Rotace `/var/log/shipard/cron.log` (logrotate include do provisioning?)
   — drobnost, může být součást kroku 2, nebo samostatně.
3. Deklarativní `cronJobs` v module.jsonc — až přibudou joby (saldokonto
   auto-matcher je první kandidát).
