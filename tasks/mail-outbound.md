# Odchozí pošta — modul core.mail outbound (fronta, transporty, senders)

**Stav:** hotovo

## Kontext

nov_shipard umí poštu jen přijímat (`/_mail/incoming` z mail-routeru).
Odchozí pošta neexistuje — a potřebují ji auth flow (pozvánky, reset hesla,
Fáze 0b), později alerty a odesílání dokladů. Tento task zavádí **obecnou
službu odchozí pošty** s frontou, logem doručení a per-sender transporty.

Poučení ze starého Shipardu (viz `modules/integrations/sendmail/` +
`src/Report/MailMessage.php` v old_shipard): per-sender SMTP (tabulka
sendmails) se osvědčil výborně (Google Workspace zákazníci — nula spamu,
zprávy v Odeslané poště); chyběla fronta („když byl problém, nevědělo se
o něm") a evidence odeslané pošty byla nešikovně navěšená na issues.

**Potvrzená rozhodnutí:**

- **D22 — transport:** přímý SMTP submission z workeru. Default = relay
  (server-level konfigurace), per-DS přepsatelný. Lokální Postfix **není
  vyžadován** (aplikační fronta přebírá store-and-forward roli); zůstává
  podporovaný jako transport `localhost:25`.
- **D23 — závislost:** `symfony/mailer` (+ `symfony/mime` pro MIME,
  přílohy, HTML+text alternative).
- **D24 — schéma:** tři tabulky — `core_mail_senders` (from adresa →
  transport, generalizace starých sendmails), `core_mail_outbox` (zpráva
  jako business objekt), `core_mail_outbox_log` (technické záznamy
  pokusů). Hesla přes `DsSecretCipher`, sloupec sensitive (D18).
- **D25 — fronta:** cron worker per DS, exponenciální backoff, terminal
  `failed`; priority zprávy se synchronním prvním pokusem v requestu;
  viditelnost selhání přes core.alerts check + doctor.
- **D26 — resolution:** *kdo posílá* rozhoduje volající (explicitní from,
  jinak DS default ze settings); *kudy* rozhoduje služba lookupem from
  adresy v senders (hit → custom SMTP, miss → relay).
- **D27 —** outbox nese přílohy (reference na core.attachments) a vazbu
  na Osobu už teď; bounce/VERP handling a plnohodnotné UI „Odeslaná
  pošta" jsou pozdější fáze.

## Návaznost

- **Vyžaduje Fázi 0a** (`tasks/auth-phase0a-hardening.md`) — sensitive
  mechanismus pro `password_enc`, is_admin pro senders UI.
- **Prerekvizita Fáze 0b** (`tasks/auth-phase0b-account-flows.md`) —
  pozvánky a reset stavějí na `enqueueAndSend()`.
- Tabulky patří do existujícího modulu `core.mail`
  (`modules/core/mail/`).

## Před implementací přečti

- `modules/core/mail/module.jsonc` — registrace tabulek, keepOnReset,
  settingsItems, registrace alert checků (vzor dle existujících modulů
  s checky).
- `src/Core/Security/DsSecretCipher.php` — šifrování hesel.
- `src/Core/Alerts/AlertCheck.php` + `AlertCheckRegistry.php` — vzor
  checku; `src/Command/DataSource/AlertsRunCommand.php`.
- `src/Command/Server/DoctorCommand.php` — přidání checků.
- `src/Core/Settings/SettingsStore.php` — DS default from adresa.
- `src/Core/Config/ServerConfig.php` a `DataSourceConfig.php` — relay
  konfigurace (server default, DS override).
- Core.attachments: `FileStorage` — resolve příloh při odeslání.
- old_shipard `modules/integrations/sendmail/tables/sendmails.json` —
  jen pro kontext, nekopírovat (plaintext hesla!).

## Scope

1. Tři tabulky + registrace v modulu.
2. Konfigurace: relay (server default + DS override), DS default from
   (settings), senders (DB).
3. `MailOutboxService`: enqueue, fronta, stavový automat, backoff,
   claim/recovery; `TransportResolver`; `MailComposer`.
4. Worker CLI `mail-outbox-run` + ops CLI (`mail-outbox-retry`,
   `mail-send-test`).
5. Alert check + doctor check.
6. Senders admin UI (CRUD + dedikovaný endpoint na heslo), settings
   položky pro outbox/log.
7. Dokumentace vč. runbooku.

**Non-goals:** bounce handling/VERP; UI „Odeslaná pošta" nad rámec
settings tabulek; šablonovací systém pro business dokumenty (šablony auth
mailů řeší 0b); digest/notifikační pravidla; rate limiting odchozí pošty.

## Schéma

**`core_mail_senders`** (tableId **423**): `id` PK AI, `email_from`
varchar(200) NOT NULL unique, `smtp_host` varchar(200) NOT NULL,
`smtp_port` int NOT NULL default 587, `smtp_security` varchar(10)
('starttls'|'tls'|'none', default 'starttls'), `smtp_username`
varchar(200) NULL, `password_enc` text NULL **sensitive** (DsSecretCipher),
`is_active` boolean default 1, `created` datetime. displayPattern
`{email_from}`.

**`core_mail_outbox`** (tableId **424**): `id` PK AI, `created` datetime,
`created_by` int NULL (user), `source_module` varchar(50) NOT NULL (např.
'core.auth'), `source_ref` varchar(100) NULL, `email_from` varchar(200)
NOT NULL, `email_to` varchar(200) NOT NULL, `recipient_person_id` int
NULL, `subject` varchar(500) NOT NULL, `body_text` text NULL, `body_html`
text NULL, `attachments` text NULL (JSON pole attachment id),
`priority` tinyint default 0 (0 normal, 10 high), `state` varchar(20)
('pending'|'sending'|'sent'|'failed'|'cancelled', idx), `attempt_count`
int default 0, `next_attempt` datetime NULL (idx), `claimed_at` datetime
NULL, `sent_at` datetime NULL, `last_error` varchar(500) NULL.

**`core_mail_outbox_log`** (tableId **425**): `id` PK AI, `outbox_id` int
NOT NULL (idx), `attempt` int NOT NULL, `ts` datetime NOT NULL,
`transport` varchar(200) NOT NULL (host:port / 'sender:{id}'), `result`
varchar(10) ('ok'|'fail'), `smtp_response` varchar(500) NULL,
`duration_ms` int NULL.

**`module.jsonc`**: tabulky zaregistrovat; `keepOnReset` += pouze
`core_mail_senders` (konfigurace; outbox a log jsou data); settingsItems:
senders (other.mail, order 50), outbox (55), outbox_log (60).

## Konfigurace

- **Relay default** — `ServerConfig`, klíč `mail.relay`: `{ host, port,
  security, username, password }` (server.json spravuje provozovatel).
- **DS override** — `main.json` klíč `mail.relay` stejné struktury
  (`DataSourceConfig::getMailRelay()`, fallback na server).
- **DS default from** — `SettingsStore` klíč `mail.defaultFrom`
  (admin-editovatelné v UI dle app-settings vzoru). Enqueue bez from
  a bez defaultu → validační chyba.

## Změny po souborech

### Commit 1 — schéma, konfigurace, závislost

`composer.json` (+`symfony/mailer`), tři `.jsonc` tabulky + `.md` popisy
(konvence modulu core.mail), `module.jsonc`, `ServerConfig` +
`DataSourceConfig` accessory, settings klíč `mail.defaultFrom`.

### Commit 2 — služba a worker

**`src/Core/Mail/OutboundMessage.php`** — readonly DTO (from?, to,
subject, bodyText?, bodyHtml?, attachments[], personId?, sourceModule,
sourceRef?, priority).

**`src/Core/Mail/TransportResolver.php`** — `resolve(string $from):
TransportInterface + label`; lookup aktivního senderu podle from
(case-insensitive), dešifrace hesla, konstrukce symfony SMTP transportu;
miss → relay z konfigurace; žádný relay nakonfigurován → výjimka
(zpráva jde do fail větve s jasnou hláškou).

**`src/Core/Mail/MailComposer.php`** — OutboundMessage/outbox row →
`Symfony\Component\Mime\Email`; text+html alternative; přílohy resolve
přes core.attachments FileStorage (chybějící příloha = fail pokusu,
ne tiché vynechání).

**`src/Core/Mail/MailOutboxService.php`** — API:
- `enqueue(OutboundMessage): int` — doplní from z defaultu, validace,
  INSERT `pending`, `next_attempt = now`.
- `enqueueAndSend(OutboundMessage): int` — enqueue s priority high +
  okamžitý `attemptSend()` v requestu (try/catch — selhání nevyhazuje,
  převezme fronta). Pro 0b (reset hesla nesmí čekat na cron).
- `attemptSend(int $id): bool` — claim (`UPDATE ... SET
  state='sending', claimed_at=NOW() WHERE id=%i AND state='pending'`,
  kontrola affected rows), compose, send, log řádek; úspěch → `sent`;
  chyba → `attempt_count++`, backoff `next_attempt` (60 s, 5 min,
  30 min, 2 h, 6 h), po 6. pokusu → `failed` + `last_error`.
- `processQueue(int $limit = 50): array` — due pending (`next_attempt
  <= now`) ORDER BY priority DESC, created ASC; před tím recovery:
  `sending` starší 10 min → zpět `pending` (pád workeru).

**`src/Command/DataSource/MailOutboxRunCommand.php`** —
`mail-outbox-run [--limit]`, cron per DS à la `alerts-run`; výstup:
processed/sent/failed/requeued.

**`src/Command/DataSource/MailOutboxRetryCommand.php`** —
`mail-outbox-retry --id N` — failed → pending, reset počítadla.

**`src/Command/DataSource/MailSendTestCommand.php`** —
`mail-send-test --to x@y [--from ...]` — smoke test transportu při
zřizování (synchronně, výsledek na stdout, zapíše se do outboxu).

**Alert check** — `src/Core/Mail/MailOutboxAlertCheck.php`: finding
warning při `failed` zprávách za posledních 24 h (agregovaně, počet +
nejstarší), finding při nejstarší `pending` > 30 min (relay nejspíš
leží). Registrace v `module.jsonc` dle existujícího vzoru.

**`DoctorCommand`** — check: relay nakonfigurován (server či DS), počet
`failed` za 24 h, hloubka fronty + stáří nejstaršího pending.

### Commit 3 — UI, endpoint na heslo, docs

**Senders UI** — generický CRUD (guard: `core_mail_*` není systémová
tabulka, ale settingsItem sekce je pro adminy; formulář bez
`password_enc` — sensitive) + **dedikovaný endpoint** `POST
/_mail/senders/{id}/password` (admin only): plaintext heslo → DsSecretCipher
→ `password_enc`. Frontend: akce „Nastavit heslo" v detailu senderu.
Vzor: nastavování API klíčů.

**Docs** — `docs/mail/outbound.md`: architektura, konfigurace relay +
senders (návod Google Workspace app password), cron řádek, stavový
automat, runbook (zaseklá fronta, failed zprávy, `mail-outbox-retry`,
`mail-send-test`); zmínka v `docs/operations/` install postupu (cron) a
`docs/modules.md`.

## Testy

- `TransportResolverTest` — sender hit/miss, dešifrace, relay fallback,
  chybějící konfigurace.
- `MailOutboxServiceTest` — enqueue validace a default from; claim
  (druhý claim téže zprávy selže); stavové přechody; backoff rozvrh;
  6. selhání → failed + last_error; recovery zaseklého `sending`;
  processQueue řazení (priorita, stáří); enqueueAndSend nepropaguje
  výjimku transportu.
- `MailComposerTest` — text+html MIME, přílohy resolve, chybějící
  příloha → fail.
- `MailOutboxAlertCheckTest` — findings pro failed / stárnoucí pending.
- CLI smoke testy (run/retry). Transport všude mock
  (`TransportInterface`), žádný reálný SMTP v testech.

## Commit strategie

1. `mail: outbound schema (senders/outbox/log), relay config, symfony/mailer`
2. `mail: outbox service, queue worker, transport resolution, alerts+doctor`
3. `mail: senders UI, password endpoint, outbound docs`

Po commitu 1: rebuild compiled cfg + `ds-upgrade`. Po commitu 2: cron
záznam na dev DS.

## Hotovo když

- [ ] `mail-send-test` doručí zprávu přes relay i přes custom sender
      (ověřeno na dev proti reálnému SMTP, v testech mock).
- [ ] Zpráva při nedostupném transportu projde backoff řadou a skončí
      `failed`; alert check ji reportuje; `mail-outbox-retry` ji vrátí
      do fronty; log obsahuje řádek per pokus se SMTP odpovědí.
- [ ] `enqueueAndSend` odešle synchronně; při výpadku transportu request
      nespadne a zprávu převezme cron.
- [ ] Heslo senderu nejde přečíst žádným API (sensitive) a nastavuje se
      jen dedikovaným endpointem; v DB je šifrované.
- [ ] Zaseklé `sending` po pádu workeru se samo zotaví.
- [ ] Doctor reportuje stav fronty a konfigurace.
- [ ] PHPUnit zelené (úzké filtry), docs vč. runbooku, cron
      zdokumentován.
