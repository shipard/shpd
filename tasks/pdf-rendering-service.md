# PDF rendering služba (Gotenberg) + RenderClient

**Stav:** naplánováno — rozhodnutí D1–D8 potvrzena (designová diskuze 18. 8. 2026)

**Issue:** shipard/shpd#34

## Cíl

Zavést sdílenou schopnost renderovat HTML → PDF a konvertovat Office
dokumenty → PDF. Řešení: **Gotenberg** (kontejner s Chromium + LibreOffice
+ PDF engines, stateless HTTP API) jako externí služba + engine-agnostický
PHP klient `src/Core/Render/` v shpd. Konzumenti (mimo scope tohoto PRD):
preprocess pošty Fáze 2 (#33, `renderBodyToPdf`), HTML renditions příloh
(#34, follow-up PRD), tiskové výstupy a reporty (samostatný budoucí design).

## Návaznost

- Issue #34 — koncept D7 z #33; volba nástroje tímto rozhodnuta: Gotenberg.
- `tasks/mail-preprocess.md` — tento úkol odblokuje Fázi 2.
- Vzor degradace: `pdfdetach` / `TextExtractor` (best-effort, nikdy
  neblokuje volajícího, doctor check).
- Vzor config objektu: `MailRelayConfig` v `ServerConfig`.
- Starý Shipard (reference, nic se nepřenáší 1:1):
  `src/_deprecated/lib/pdf/PdfCreator.php` (kontrakt pdfOptions,
  header/footer šablony, append souborů), `pdfRenderer.js` (Chromium
  `page.pdf()` — headerTemplate/footerTemplate = tentýž mechanismus,
  který exponuje Gotenberg).

## Rozhodnutí

- **D1** Jedna rendering služba **per fyzický stroj**. Multi-kontejnerová
  topologie (Incus/Proxmox, ~10 Shipard kontejnerů na HW): Gotenberg jako
  samostatný systémový kontejner na interním bridgi, Shipard kontejnery ho
  volají přes vnitřní síť. Single-server instalace (alpha, dev): podman
  na stroji, bind `127.0.0.1:3000`. Žádná aplikační autentizace —
  zabezpečení je síťové (žádný WAN forward, firewall/ACL na subnet bridge).
- **D2** Engine v1 = **Gotenberg** (pinovaná verze image), ne vlastní
  puppeteer služba. Chromium lifecycle a upgrady vlastní upstream;
  LibreOffice routa pokrývá Office → PDF bez vlastní práce.
- **D3** Engine-agnostický kontrakt v `src/Core/Render/`. Volající nikdy
  nemluví s Gotenbergem přímo. Gotenberg specifika v jednom adaptéru;
  budoucí enginy (Apache FOP pro XSL-FO sestavy) = další adaptér za týmž
  kontraktem.
- **D4** Obsah se **vždy pushuje** (HTML + assety jako soubory
  v requestu). Nikdy URL konverze (`page.goto` model starého řešení) —
  render kontejner nevidí do aplikace, odpadá SSRF třída problémů
  (2026 CVE Gotenbergu se týkají výhradně URL/downloadFrom/webhook režimů).
- **D5** Dva profily: `untrusted` (e-mailová HTML těla, stažené HTML —
  JS off, síť ven zakázaná, tvrdé limity) a `report` (vlastní šablony —
  header/footer, printBackground, delší timeout). JS vypnut **globálně**
  flagem kontejneru (per-request to Gotenberg neumí); šablony jsou
  server-side, JS nepotřebují. Kdyby ho budoucí sestavy vyžadovaly →
  druhá instance s jinými flagy, kontrakt se nemění.
- **D6** Degradace dle vzoru `pdfdetach`: služba nedostupná → typované
  selhání, volající přeskočí s poznámkou, warning jednou do logu. Doctor
  check (health endpoint). URL služby v `server.json` (per-server infra,
  ne per-DS).
- **D7** Scope = služba + klient. Tiskové sestavy (šablony faktur, hlavní
  kniha, výsledovka) jsou samostatný design, tady jen požadavky na
  kontrakt (formát, orientace, okraje, header/footer, číslování stran).
- **D8** **Post-processing řetěz** na straně shpd nad vráceným PDF:
  v1 `embedIsdoc` (primárně Gotenberg embed/attachments routa, fallback
  poppler `pdfattach`) a `appendPdfs` (náhrada `PdfCreator::appendFiles`,
  `pdfunite`). Budoucí kroky (el. podpis — poběží vždy u nás, klíče
  neopouštějí kontejner zákazníka) = další implementace téhož rozhraní.

Poznámky z diskuze: ikony v šablonách řešit inline SVG, ne ikonovým
fontem. Fonty: Gotenberg image balí Noto stack + metricky kompatibilní
náhrady MS fontů (Liberation, Carlito) — pro CZ výstupy dostačuje;
firemní font lze přiložit jako asset requestu.

## Scope

### 1. Konfigurace

`src/Core/Config/ServerConfig.php`:

- Nový getter `getRender(): ?RenderConfig` (vzor `getMailRelay`).
  Klíč `render` v `server.json`:
  ```json
  "render": { "url": "http://127.0.0.1:3000", "timeoutSec": 30 }
  ```
  Chybějící klíč = služba nekonfigurována (validní stav, vše degraduje).
- `src/Core/Config/RenderConfig.php` — readonly datová třída
  (`url`, `timeoutSec` default 30). Validace URL.

### 2. Klient `src/Core/Render/`

- `RenderProfile.php` — enum `Untrusted | Report`. Mapuje na výchozí
  omezení/parametry requestu (timeout, limity; header/footer a
  printBackground povoleny jen u Report).
- `PdfOptions.php` — readonly: `paperFormat` (default A4), `orientation`
  (portrait/landscape), okraje (4×, default dle profilu), `headerTemplate`,
  `footerTemplate`, `printBackground`. Sémantika převzata ze starého
  `PdfCreator` (kompatibilita budoucí migrace šablon).
- `RenderResult.php` — readonly: `ok`, `pdfContent` (string|null),
  `errorKind` (enum: `unconfigured | unreachable | timeout | engineError |
  invalidInput`), `note`. Nikdy výjimka ven z klienta pro provozní stavy;
  výjimky jen pro programátorské chyby (nevalidní kombinace parametrů).
- `RenderClient.php` — fasáda pro volající:
  - `renderHtml(string $html, array $assets, RenderProfile $profile, PdfOptions $opts): RenderResult`
    — `$assets` = mapa `filename => content` (obrázky, CSS, fonty;
    referencované relativně z HTML).
  - `convertOffice(string $fileName, string $content): RenderResult`
  - `postProcess(string $pdf, array $steps): RenderResult` — řetěz kroků
    `{step, params}`, viz §3.
  - `isConfigured(): bool`, `health(): bool` (GET health endpoint,
    krátký timeout — používá doctor).
  - Konstruktor bere `?RenderConfig` + engine (DI pro testy).
    Warning-once: první selhání za proces zaloguje warning, další jen
    debug (vzor TextExtractor).
- `Engine/RenderEngineInterface.php` — kontrakt engine adaptéru
  (`renderHtml`, `convertOffice`, `health`).
- `Engine/GotenbergEngine.php` — jediná třída znající Gotenberg:
  multipart request na `forms/chromium/convert/html` (index.html +
  assety + header.html/footer.html dle PdfOptions), resp.
  `forms/libreoffice/convert`. HTTP přes curl (žádná nová composer
  závislost; `gotenberg/gotenberg-php` nepřidávat — potřebujeme zlomek
  API a vlastní error mapping). Mapování HTTP chyb → `errorKind`.
  Přesné názvy form polí a endpointů ověřit proti dokumentaci
  aktuální pinované verze při implementaci.

### 3. Post-processing `src/Core/Render/PostProcess/`

- `PostProcessStepInterface.php` — `apply(string $pdf, array $params): string`
  (vrací nové PDF, při selhání výjimka → klient mapuje na
  `errorKind=engineError` s poznámkou kroku).
- `EmbedIsdocStep.php` — vloží ISDOC (obecně soubor) jako PDF attachment.
  v1: Gotenberg PDF engines routa pro embed/attachments; pokud se při
  implementaci ukáže nevyhovující (formát PDF/A-3 apod.), fallback
  `pdfattach` (poppler-utils, na serverech už je). Rozhodnutí zapsat
  do docs.
- `AppendPdfsStep.php` — `pdfunite` (poppler-utils) — připojení PDF
  souborů za dokument (staré `filesToAppend`).

### 4. Doctor + instalace + deployment

- `src/Command/Server/DoctorCommand.php`: sekce render — nekonfigurováno
  → info; konfigurováno a `health()` selže → warning s URL. Kontrola
  binárek `pdfattach`, `pdfunite` (poppler-utils — už instalováno,
  jen doplnit do checků, pokud chybí).
- `scripts/install-packages.sh`: instalace `podman` (jen single-server
  scénář; skript nespouští kontejner).
- `docs/render/shpd-render.service` — verzovaný systemd unit template
  (vzor docs/nginx/*): `podman run` s pinovanou image
  `docker.io/gotenberg/gotenberg:<pinned 8.x>`, flagy: disable JS,
  zákaz odchozí sítě Chromia (deny-list / deny private IPs — přesné
  flagy dle dokumentace pinované verze), api timeout, bind
  `127.0.0.1:3000`.
- `docs/render/README.md` nebo `docs/operations/render-service.md` —
  provoz: single-server (podman + unit), Incus (nativní OCI:
  `incus launch docker:gotenberg/... `, interní bridge, network ACL),
  Proxmox (LXC + podman, nesting), upgrade image (změna pinu + restart),
  bezpečnostní model (žádný WAN forward, push-only obsah).

### 5. Dokumentace

- `docs/render.md` — koncept: architektura (per-stroj služba, klient),
  kontrakt RenderClient, profily a jejich záruky, post-processing,
  degradace, jak přidat engine (FOP výhled). Odkaz z `docs/README.md`
  (sekci doplní David, pokud nedeleguje).
- Zmínka v `docs/mail/`? Ne — konzumenti si doplní při svých PRD.

## Testy

- `RenderClient`: nekonfigurováno → `errorKind=unconfigured` bez HTTP
  volání; mock engine — úspěch, timeout, engineError; warning-once
  logika; profil Untrusted odmítne header/footer (invalidInput).
- `GotenbergEngine`: sestavení multipart těla (index.html, assety,
  header/footer jen když zadané, mapování PdfOptions na form pole) —
  bez reálného HTTP (subclass/mock transportu).
- PostProcess: `AppendPdfsStep` a `EmbedIsdocStep` s dočasnými PDF
  (vygenerovat fixture minimální PDF); skip testu, když binárka chybí
  (vzor stávajících testů závislých na CLI nástrojích).
- Integrační test proti živému Gotenbergu: gated env proměnnou
  (`SHPD_TEST_RENDER_URL`) — mimo běžný CI běh; render jednoduchého
  HTML → validní PDF (magic bytes, >0 stran přes pdfinfo).
- PHPUnit s úzkými `--filter`, timeout 120 s.

## Commit strategie

1. Config: `RenderConfig` + `ServerConfig::getRender` (+ testy)
2. Klient: kontrakt, profily, PdfOptions, RenderResult, mock-engine
   testy; GotenbergEngine (+ testy)
3. PostProcess kroky (+ testy)
4. Doctor + install-packages + unit template + docs (render.md,
   operations)

Commity referencují #34.

## Hotovo když

- [ ] `server.json` bez klíče `render`: vše funguje, `renderHtml` vrací
      `unconfigured`, doctor hlásí info, nic nepadá
- [ ] Na dev stroji s běžícím Gotenbergem (podman, unit template):
      `renderHtml` s profile Report + header/footer šablonou vrátí
      validní vícestránkové PDF s číslováním stran
- [ ] Profil Untrusted: JS v HTML se neprovede (globální flag),
      externí URL v HTML nezpůsobí odchozí request (ověřit deny
      konfigurací), header/footer odmítnuty
- [ ] `convertOffice` s .docx vzorkem vrátí PDF
- [ ] `postProcess` embedIsdoc: výsledné PDF obsahuje attachment
      (ověřitelné `pdfdetach -list`); appendPdfs spojí dokumenty
- [ ] Zastavená služba: volání vrací `unreachable`, v logu právě jeden
      warning, doctor warning
- [ ] Testy zelené (integrační gated), dokumentace kompletní
