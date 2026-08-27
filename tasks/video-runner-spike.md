# Video runner — pilotní spike (protažení pipeline)

**Stav:** naplánováno — připraveno k implementaci

## Kontext / Cíl

Automatizovaná výroba videí z UI podle scénáře — issue **shipard/shpd#48**,
rozhodnutí D1–D9 v komentáři issue (2026-08). Navazující kontext:
`shpd-web/docs/demo-vyroba.md` (D7–D10 — ukázky jsou automatizované nahrávky
reálné aplikace, bez zvuku, titulky v obraze) a `shpd-web/docs/demo-scenare.md`
(scénáře S1/S2/S5, které se budou točit jako první).

**Tohle je spike, ne první video.** Cílem je protáhnout pipeline prvními daty
a zjistit, co se na ní rozbije. Obsah klipu je nepodstatný — vzniklý testovací
soubor se **zahazuje**, necommituje se ani nepublikuje (běží nad dev DS
s reálnými daty).

**Výstupem spike není video, ale zápis závěrů** (sekce „Zjištění" v tomto
souboru + komentář do #48): která varianta záznamu, jak se chová synchronizace
kurzoru, kolik FPS je dost, jak dlouhá pauza je čitelná, kde je pipeline
křehká.

## Rozsah

Vzniká runner v `tools/video-runner/` s podmnožinou funkcionality: verby
`login`, `record`, `compose`, `build`, `check`; verby scénáře `goto`,
`waitFor`, `hover`, `click`, `caption`, `pause`, `highlight`; vložený kurzor;
**obě** varianty záznamu (A+ i B, viz níže) pro porovnání; `timeline.json`;
kompozitor s burned-in titulky; výstup `.mp4`.

**Mimo rozsah:** zoomy a rámečky v postprodukci (`timeline.json` pro ně jen
připravuje data), dabing, verby `fill` / `press` / `scrollTo` / `raw`,
`page.clock` fixace času, WebM a poster, `data-testid` na zlaté cestě aplikace,
sady z #40, scénáře S1/S2/S5, CI, `install.sh`, kontejner `ns-media` na alfě.

## Potvrzená rozhodnutí (viz #48)

- **D1** — čistá knihovna `playwright` + vlastní tenké CLI, ne
  `@playwright/test`; trace ručně přes `context.tracing.start()`.
- **D2** — čtyřvrstvá pipeline s hmatatelnými artefakty:
  `scénář → ① interpret + ② overlay → raw + timeline.json → ③ kompozitor → výstup`.
  Přepsání titulku znamená jen `compose`, nikdy přetočení.
- **D3** — scénář = JSONC, slovník verbů nad `data-testid`, nikdy nad texty
  ani CSS třídami.
- **D4** — `caption` neblokuje; `pause` je jediný spotřebič času a smí být
  modifikátorem každého kroku; přejezd kurzoru se do `pause` nepočítá
  (výchozí 600 ms, přebitelné `travel`); validátor hlídá čitelnost titulků.
- **D5** — titulky burned-in v postprodukci z `timeline.json`, ne overlay
  v DOMu; `timeline.json` nese i `rect` prvků kvůli budoucím zoomům.
- **D6** — vložený DOM kurzor přes `addInitScript`, X kurzor se nepoužívá.
- **D7** — runner i scénáře v `shpd`; konvence `scripts/` = jednosouborové
  helpery, `tools/<name>/` = podprojekt s vlastními závislostmi.
- **D8** — `INSTALL.md` prostředí-agnostický, konfigurace přes `.env`
  (vzor `.env.example`), Node 24 (aktuální LTS) jako minimum.

## Doplňující rozhodnutí z průzkumu kódu (2026-08-27)

- **R1 — prostý ESM JavaScript, žádný build step.** Repo TypeScript nikde
  nepoužívá (`frontend/` je čisté JS + Svelte), zavádět kvůli spike kompilaci
  do PHP repa nemá smysl. Soubory `.mjs`, typy jen JSDoc, kde to pomůže.
  Závislosti: **jen `playwright`** — `.env` se čte přes `process.loadEnvFile()`
  (Node 24 nativně), argumenty přes `node:util` `parseArgs`.
- **R2 — kurzor se synchronizuje sám přes `mousemove`.** Klíčová technika,
  která odstraňuje hlavní riziko z #48 (rozejití vizuálního kurzoru
  a syntetické myši). `page.mouse.move()` dispatchuje **skutečné DOM eventy**,
  takže overlay nemá vlastní animaci — jen si na `document` zaregistruje
  `mousemove` / `mousedown` / `mouseup` a polohuje se podle nich. Synchronizace
  je pak zaručená konstrukcí, ne časováním. Runner tedy dělá jen easing loop
  s `page.mouse.move()` a overlay ho následuje.
- **R3 — `data-testid` v aplikaci neexistuje** (ověřeno: v `frontend/src`
  není ani jeden výskyt). Spike se zlaté cestě proto vyhýbá a používá to,
  co v aplikaci je. Přihlašovací obrazovka má stabilní `id`:
  `#login-name`, `#login-password`, a `handleKeydown` odesílá na Enter
  (`frontend/src/components/auth/LoginScreen.svelte`) — verb `login` tedy
  vyplní obě pole a stiskne Enter, žádné klikání na tlačítko.
  Doplnění `data-testid` na zlatou cestu je samostatná položka před prvním
  skutečným videem.
- **R4 — dvě varianty záznamu se implementují obě**, přepínač
  `--capture=cdp|x11`:
  - **A+ (`cdp`)** — `Page.startScreencast` přes `page.context().newCDPSession(page)`,
    framy se ukládají jako JPEG a jejich `metadata.timestamp` se zapisuje;
    ffmpeg je pak složí přes `-f concat` se `duration` řádky na konstantní FPS.
    Framy vznikají jen při změně obrazu, takže statické pasáže dají jeden
    frame s dlouhou `duration` — což je správně a je to důvod, proč tahle
    varianta nesekne (na rozdíl od `recordVideo`).
    Běží headless, žádná systémová závislost.
  - **B (`x11`)** — `Xvfb :99 -screen 0 <w>x<h>x24`, headed Chromium
    s `DISPLAY=:99`, `ffmpeg -f x11grab -draw_mouse 0 -framerate 30`.
    Konstantní FPS nativně, zachytí CSS transitions. Synchronizace časové
    osy: ffmpeg se spustí, počká se 500 ms na rozběhnutí a až pak se vezme
    `t0` — případný offset se ošetří v `compose` ořezem.
- **R5 — titulky jako ASS, ne SRT.** Kvůli kontrole nad stylem (pozice,
  podklad, font) bez nutnosti druhého filtru. `compose` generuje
  `captions.ass` z `timeline.json` a přiloží ho jako `-vf subtitles=`.

## Struktura

```
tools/video-runner/
  package.json              # name shipard-video-runner, engines node>=24, dep: playwright
  README.md                 # co to je, jak se to pouští
  INSTALL.md                # závislosti prostředí (prostředí-agnosticky)
  .env.example              # vzor konfigurace
  bin/video-runner.mjs      # CLI, parseArgs
  src/config.mjs            # načtení .env + validace
  src/scenario.mjs          # načtení a validace scénáře (JSONC → objekt)
  src/interpret.mjs         # ① průchod kroků
  src/overlay.mjs           # ② injektovaný kurzor + highlight (addInitScript)
  src/capture-cdp.mjs       # A+
  src/capture-x11.mjs       # B
  src/timeline.mjs          # zápis timeline.json
  src/compose.mjs           # ③ ffmpeg, ASS titulky
  src/verbs/login.mjs …     # jednotlivé verby CLI

demo/scenarios/spike-dashboard.jsonc
demo/datasets/README.md     # „sady sem přijdou po dokončení tasks/dataset-phase1.md"
```

Do root `.gitignore` přidat: `/tools/video-runner/node_modules/`,
`/tools/video-runner/.env`, `/tools/video-runner/.storage-state.json`,
`/tools/video-runner/.work/`, `/tools/video-runner/out/`.
Uložená session je stejně citlivá jako heslo.

### `.env.example`

```bash
# Cílová instance Shipardu (bez koncového lomítka)
SHPD_BASE_URL=https://demo.example.dev

# Uživatel v cílovém DS — normální přihlášení formulářem
SHPD_LOGIN=
SHPD_PASSWORD=

# Uložená session z verbu `login` (relativně k tools/video-runner/)
SHPD_STORAGE_STATE=.storage-state.json

# Artefakty (raw + timeline) a hotová videa
VIDEO_WORK_DIR=./.work
VIDEO_OUT_DIR=./out

# Ladění: 1 = viditelný prohlížeč, bez záznamu
VIDEO_HEADFUL=0
```

### Scénář — podporovaná podmnožina

```jsonc
{
  "id": "spike-dashboard",
  "capture": { "w": 2560, "h": 1600, "scale": 2 },
  "output":  { "w": 1280, "h": 800, "fps": 30 },
  "steps": [
    { "goto": "/app" },
    { "waitFor": "#app-shell", "pause": 1 },
    { "caption": "Testovací klip — protažení pipeline", "pause": 2.5 },
    { "hover": "#some-existing-element", "pause": 1 },
    { "caption": null }
  ]
}
```

Pozn.: dokud v aplikaci nejsou `data-testid`, přijímá `waitFor`/`hover`/`click`
CSS selektor. Až budou, přejde se na `data-testid` a CSS varianta se zakáže
validátorem — v README to musí být napsané jako známý dluh.

### `timeline.json`

```jsonc
{ "scenario": "spike-dashboard", "capture": "cdp", "duration": 12.4, "fps": 30,
  "events": [
    { "t": 1.2, "type": "caption", "text": "Testovací klip — protažení pipeline" },
    { "t": 3.7, "type": "caption", "text": null },
    { "t": 4.1, "type": "hover", "selector": "#some-existing-element",
                "rect": [820, 340, 360, 96] }
  ] }
}
```

`t` je v sekundách od začátku záznamu. `rect` je `[x, y, w, h]` v souřadnicích
záznamu (tj. před škálováním na `output`).

## Kroky implementace

Commit po každém kroku.

1. **Skelet podprojektu.** `package.json`, `README.md`, `INSTALL.md`,
   `.env.example`, `.gitignore` v rootu, `demo/datasets/README.md`.
   INSTALL.md obsahuje: seznam apt balíčků (`ffmpeg`, `xvfb`, fonty),
   Node 24, `npx playwright install --with-deps chromium`, a **upozornění,
   že nvm Node není na PATH v neinteraktivním shellu** (cron, systemd,
   budoucí `install.sh`).
2. **CLI skelet + `config.mjs`.** `parseArgs`, verby zaregistrované,
   nenalezený verb → nenulový exit. Chybějící povinná položka `.env` →
   jasná hláška, ne stack trace.
3. **Verb `login`.** Headless Chromium → `SHPD_BASE_URL` → `#login-name`,
   `#login-password`, Enter → čekání na app shell → `context.storageState()`
   do `SHPD_STORAGE_STATE`. Ostatní verby session jen načítají; při expiraci
   selže `record` hláškou „session neplatná, spusť `login`", nikdy nesmí
   vzniknout video přihlašovací stránky.
4. **Overlay (`overlay.mjs`) + interpret (`interpret.mjs`).** Kurzor přes
   `addInitScript` (`position: fixed; pointer-events: none; z-index: 2147483647`),
   polohování podle `mousemove` (R2), efekt kliknutí podle `mousedown`/`mouseup`,
   `highlight` jako rámeček kolem prvku po dobu `for`. Easing přejezdu
   `easeInOutCubic`, výchozí 600 ms, ~60 kroků/s. Verby dle rozsahu, `pause`
   jako modifikátor kteréhokoli kroku. Ověřit verbem `check` (průchod bez
   záznamu) a s `VIDEO_HEADFUL=1`.
5. **Záznam A+ (`capture-cdp.mjs`) + `timeline.mjs`.** Framy + timestampy
   do `VIDEO_WORK_DIR/<id>/`, `timeline.json`, `frames.txt` pro concat.
6. **Záznam B (`capture-x11.mjs`).** Xvfb + x11grab podle R4, přepínač
   `--capture`.
7. **Kompozitor (`compose.mjs`).** `captions.ass` z `timeline.json`,
   škálování na `output`, `libx264 -crf 20 -pix_fmt yuv420p -movflags +faststart -an`,
   výstup do `VIDEO_OUT_DIR`. Validátor čitelnosti: titulek zobrazený kratší
   dobu než `max(1.2 s; znaky/15)` → warning, nad 90 znaků → warning.
8. **Pilotní scénář + zápis.** `demo/scenarios/spike-dashboard.jsonc`,
   `build` oběma variantami, doplnit sekci „Zjištění" do tohoto souboru.

## Před implementací přečti

- `shpd-web/docs/demo-vyroba.md` (D7–D10) a `demo-scenare.md` (S1/S2/S5) —
  k čemu runner nakonec bude
- Komentář v **shipard/shpd#48** — plné znění rozhodnutí D1–D9
- `frontend/src/components/auth/LoginScreen.svelte` — id polí, `handleKeydown`
- `frontend/package.json` — vzor Node podprojektu v tomto repu (prostý ESM,
  bez TypeScriptu, commitnutý lock file)
- `scripts/check-sensitive.py` — aby `.env` a `.storage-state.json` neprošly
- `DEVELOPERS.md` — konvence repa; konvenci `scripts/` vs `tools/` do něj
  **nedoplňovat** bez zadání (rozhodne se po spike)
- Playwright: `BrowserContext.storageState`, `Page.addInitScript`,
  `Page.mouse`, `BrowserContext.newCDPSession`, CDP `Page.startScreencast`

## Hotovo když

- [ ] `tools/video-runner/` existuje, `npm install` projde, `--help` vypíše verby
- [ ] `INSTALL.md` popisuje instalaci od čistého Debianu/Ubuntu včetně fontů
      a poznámky o nvm a PATH; `.env.example` je kompletní
- [ ] `.env` ani `.storage-state.json` nejsou v gitu a `check-sensitive.py`
      je nehlásí
- [ ] `login` vyrobí `storageState`; `record` s neplatnou session selže
      jasnou hláškou, ne videem přihlašovací stránky
- [ ] `check` projde pilotní scénář bez záznamu a vrátí 0; při neexistujícím
      selektoru vrátí nenulový kód a řekne který krok
- [ ] vložený kurzor se v `VIDEO_HEADFUL=1` pohybuje plynule a hover stavy
      naskakují ve chvíli, kdy k prvku opticky dorazí (R2)
- [ ] `build --capture=cdp` i `build --capture=x11` vyrobí přehratelný
      `.mp4` s vypáleným titulkem ve správném čase
- [ ] `compose` se dá pustit samostatně nad existujícím `raw` +
      `timeline.json` a změna textu titulku nevyžaduje přetočení
- [ ] `timeline.json` obsahuje `rect` u akcí nad prvky
- [ ] validátor čitelnosti titulků hlásí příliš krátké i příliš dlouhé
- [ ] **sekce „Zjištění" v tomto souboru vyplněná** — doporučená varianta
      záznamu s odůvodněním, chování synchronizace kurzoru, doporučené FPS
      a délky pauz, seznam křehkých míst
- [ ] testovací klip smazán, v repu není žádný videosoubor

## Zjištění

*(doplní se po provedení spike; závěry se pak zrcadlí do komentáře v #48)*
