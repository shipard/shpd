# video-runner

Výroba videí z uživatelského rozhraní podle scénáře: Playwright projede
zapsané kroky, vloží do stránky kurzor a ffmpeg z toho složí `.mp4`
s vypálenými titulky. Bez zvuku, bez ruční práce, regenerovatelné jedním
příkazem.

Runner o Shipardu neví nic — Shipard-specifické jsou jen scénáře
v [`demo/scenarios/`](../../demo/scenarios/) a verb `login`.

Rozhodnutí, ze kterých návrh vychází, jsou v issue **shipard/shpd#48**
a v tasku [`tasks/video-runner-spike.md`](../../tasks/video-runner-spike.md).

> **Stav: pilotní spike.** Implementovaná je podmnožina — chybí zoomy
> a rámečky v postprodukci, dabing, verby `fill` / `press` / `scrollTo` /
> `raw`, fixace času přes `page.clock`, WebM a poster.

---

## Instalace

Viz [`INSTALL.md`](INSTALL.md). Zkráceně: `apt install ffmpeg xvfb fonts-liberation
fonts-noto-core`, Node 24+, `npm install`, `npx playwright install chromium`,
`cp .env.example .env` a vyplnit.

---

## Pipeline

```
scénář (.jsonc)
  │  ① interpret     průchod kroků Playwrightem
  │  ② overlay       kurzor, klik, highlight (injektované do stránky)
  ├────────────────► raw.mp4  +  timeline.json
  │  ③ kompozitor    ffmpeg: titulky, škálování, výstupní formát
  └────────────────► out/<id>.mp4
```

Podstatné je, co z toho plyne: **přepsat titulek neznamená přetočit
video.** Text titulku žije v `timeline.json`, ne v obraze — stačí pustit
`compose` nad už uloženým `raw.mp4`. Rozdíl mezi dvěma sekundami a dvěma
minutami při každém ladění formulace.

---

## Verby

| Verb | Co dělá |
|------|---------|
| `login` | Přihlásí se přes formulář a uloží session do `SHPD_STORAGE_STATE`. |
| `check` | Projede scénář bez záznamu. Nenulový exit = scénář nesedí na aplikaci; hláška řekne krok a selektor. Zároveň smoke E2E. |
| `record` | ① + ② → `raw.mp4` + `timeline.json` do `VIDEO_WORK_DIR/<id>/`. |
| `compose` | ③ nad existujícími artefakty → `VIDEO_OUT_DIR/<id>.mp4`. |
| `build` | `record` + `compose`. |

```bash
node bin/video-runner.mjs <verb> [scénář] [--capture=cdp|x11]
node bin/video-runner.mjs --help
```

Ostatní verby než `login` session jen čtou. Když vyprší, `record`
skončí hláškou „session neplatná, spusť `login`" — nikdy nesmí vzniknout
video přihlašovací stránky.

### Varianty záznamu

`--capture=cdp` (výchozí)
: CDP `Page.startScreencast`, framy s timestampy, ffmpeg je složí na
  konstantní FPS. Běží headless, žádná systémová závislost navíc.
  Statická pasáž = jeden frame s dlouhou dobou trvání, což je správně.

`--capture=x11`
: Headed Chromium v Xvfb + `ffmpeg -f x11grab`. Konstantní FPS nativně,
  zachytí CSS transitions. Cena: Xvfb.

Která z nich je lepší, je otevřená otázka — právě to má spike rozhodnout.

---

## Scénář

JSONC v [`demo/scenarios/`](../../demo/scenarios/).

```jsonc
{
  "id": "spike-dashboard",
  "capture": { "w": 2560, "h": 1600, "scale": 2 },
  "output":  { "w": 1280, "h": 800, "fps": 30 },
  "steps": [
    { "goto": "/app/" },
    { "waitFor": ".shpd-shell", "pause": 1 },
    { "caption": "Testovací klip — protažení pipeline", "pause": 2.5 },
    { "hover": ".shpd-sidebar", "pause": 1 },
    { "caption": null }
  ]
}
```

`capture.w`/`capture.h` jsou rozměry **rawu v pixelech**, `scale` je
`deviceScaleFactor`. CSS viewport z toho vychází jako `w/scale × h/scale`
— tady tedy 1280×800 při dvojnásobné hustotě. Výstup 1280×800 vzniká
downscalem 2:1, jinak by byl text rozmazaný.

### Verby scénáře

| Verb | Význam |
|------|--------|
| `goto` | Navigace na cestu relativní k `SHPD_BASE_URL`. |
| `waitFor` | Počká na prvek. Timeout = selhání celého běhu. |
| `hover` | Přejezd kurzoru nad prvek. |
| `click` | Přejezd + kliknutí. |
| `caption` | Nastaví titulek. `null` ho sundá. |
| `highlight` | Rámeček kolem prvku po dobu `for` (sekundy). |
| `pause` | Čas. |

**`caption` neblokuje, `pause` je jediný spotřebič času** a smí být
modifikátorem kteréhokoli kroku (`{ "click": "…", "pause": 1.5 }`).
Titulek tak přirozeně přežije několik akcí. Doba přejezdu kurzoru se do
`pause` nepočítá — je to vlastnost akce, ne vyprávění; výchozí 600 ms,
přebitelné `travel`.

---

## Známé dluhy

- **Selektory jsou CSS, ne `data-testid`.** V aplikaci dnes není ani
  jeden `data-testid` — spike se proto vyhnul zlaté cestě a chytá se
  toho, co existuje (`.shpd-shell`, `#login-name`). Je to křehké:
  CSS třídy se mění s refaktoringem a texty s i18n. Až budou
  `data-testid` na zlaté cestě doplněné, přejdou na ně i scénáře
  a validátor CSS variantu **zakáže**.
- **Datové sady chybí.** Scénáře běží nad tím, co na cílové instanci
  zrovna je. Sady podle #40 přijdou do `demo/datasets/`.
- **Čas není fixovaný.** `page.clock.setFixedTime()` zatím nikde, takže
  „dnes" je ve videu skutečné dnes.

---

## Artefakty a citlivost

`.env`, `.storage-state.json`, `.work/` a `out/` jsou v kořenovém
`.gitignore`. Uložená session je stejně citlivá jako heslo. Do repozitáře
nepatří žádný videosoubor — videa se generují, ne verzují.
