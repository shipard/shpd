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

Viz [`INSTALL.md`](INSTALL.md). Zkráceně: `apt install ffmpeg fonts-liberation
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
| `compose` | ③ nad existujícími artefakty → `VIDEO_OUT_DIR/<id>.mp4`. Cestou zkontroluje čitelnost titulků. |
| `build` | `record` + `compose`. |

```bash
node bin/video-runner.mjs <verb> [scénář]
node bin/video-runner.mjs --help
```

Ostatní verby než `login` session jen čtou. Když vyprší, `record`
skončí hláškou „session neplatná, spusť `login`" — nikdy nesmí vzniknout
video přihlašovací stránky.

### Jak vzniká obraz

CDP `Page.startScreencast`: framy s timestampy, ffmpeg je složí přes concat
na konstantní FPS. Běží headless, žádná systémová závislost navíc.

Klíčová vlastnost je, že **Chrome posílá framy jen při změně obrazu**.
Statická pasáž tedy dá jeden frame s dlouhou dobou trvání místo stovky
identických — proto tahle cesta neseká tam, kde Playwrightí `recordVideo`
ano. Průměrná snímková frekvence za celý klip je z téhož důvodu nesmyslné
číslo; ve statistice po záznamu je proto i **špička**, tedy nejvyšší počet
framů v jednosekundovém okně. To je jediné číslo, které odpovídá na otázku
„stíhá záznam v pohybu".

Zvažovala se ještě varianta s headed Chromiem ve Xvfb a `ffmpeg -f x11grab`
(konstantní FPS nativně). Ve spike prohrála — měla stejný obraz za cenu dvou
systémových závislostí a nedala se v ní schovat lišta prohlížeče. Důvody
jsou v `tasks/video-runner-spike.md` a v shipard/shpd#48.

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
    { "waitFor": "@app-shell", "pause": 1 },
    { "caption": "Testovací klip — protažení pipeline", "pause": 2.5 },
    { "hover": "@sidebar", "pause": 1 },
    { "caption": null }
  ]
}
```

`capture.w`/`capture.h` jsou rozměry **rawu v pixelech**, `scale` je
`deviceScaleFactor`. CSS viewport z toho vychází jako `w/scale × h/scale`
— tady tedy 1280×800 při dvojnásobné hustotě.

`output` **nemusí obsahovat rozměr** a pak se nezmenšuje: video vyjde
v rozlišení záznamu. Zmenšit na 1280×800 znamenalo zahodit přesně ten
detail, kvůli kterému se nahrává na dvojnásobnou hustotu — takové video
vidí každý návštěvník s retina displejem jako dvojnásobný upscale.
Zmenšit se dá vždycky, zpátky se to nedodělá.

Geometrie **nesmí** používat Playwrightí `deviceScaleFactor`: ten hustotu
jen emuluje a CDP screencast pak posílá framy ve velikosti emulovaného
zařízení v DIP, tedy 1×. Skutečný raster dělá teprve
`--force-device-scale-factor` s `viewport: null`.

### Verby scénáře

| Verb | Význam |
|------|--------|
| `goto` | Navigace na cestu relativní k `SHPD_BASE_URL`. |
| `waitFor` | Počká na prvek. Timeout = selhání celého běhu. |
| `hover` | Přejezd kurzoru nad prvek. |
| `click` | Přejezd + kliknutí. |
| `scroll` | Posun v CSS px (kladný dolů) za dobu `over`. Scrolluje tam, kde je kurzor. |
| `caption` | Nastaví titulek. `null` ho sundá. |
| `highlight` | Rámeček kolem prvku po dobu `for` (sekundy). |
| `pause` | Čas. |

**`caption` neblokuje, `pause` je jediný spotřebič času** a smí být
modifikátorem kteréhokoli kroku (`{ "click": "…", "pause": 1.5 }`).
Titulek tak přirozeně přežije několik akcí. Doba přejezdu kurzoru se do
`pause` nepočítá — je to vlastnost akce, ne vyprávění; výchozí 0,6 s,
přebitelná modifikátorem `travel`.

**Totéž platí pro `scroll`:** doba `over` se do `pause` nepočítá. Easing
řídí runner, ne prohlížeč — jeden velký `wheel` delta by animaci nechal na
kompozitoru Chromia, který se může chovat jinak v jiné verzi. Scrolluje se
tam, kde je kurzor, takže `scroll` musí následovat po `hover` nebo `click`
nad obsahem; když se nic neposune, je to chyba, ne tiše nehybné video.

**Všechny časy jsou v sekundách** — `pause`, `travel`, `for` i `over`. Dvě
jednotky v jednom souboru by spolehlivě vyráběly překlepy typu
`"travel": 1` v domnění, že jde o vteřinu.

### Selektory

Selektor začínající `@` je zkratka za `data-testid`: `@viewer-rows`
znamená `[data-testid="viewer-rows"]`. Jen přesná shoda, žádný Playwrightí
dialekt — co smí být za zavináčem, hlídá validátor už při `check`
(`A-Z a-z 0-9 _ . : -`). Testidy aplikace a jejich konvence popisuje
`docs/frontend.md` § 9; navigace je má odvozené ze serverových id
(`@nav-viewer:core.mail.incoming`).

**`@` je primární volba.** Plné CSS zůstává povolené pro okrajové případy,
na které testid není — s vědomím, že třídy se mění s refaktoringem.
`timeline.json` nese selektor tak, jak byl ve scénáři, takže krok jde
zpětně dohledat i po přejmenování tříd.

### Kurzor se synchronizuje sám

Overlay nemá vlastní animaci. `page.mouse.move()` dispatchuje **skutečné
DOM eventy** a vložený kurzor se polohuje výhradně z nich — takže nemůže
dorazit dřív ani později než hover stav, protože oboje spouští tentýž
event. Runner dělá jen easing smyčku, kurzor jede za ní.

Rozejití vizuálního kurzoru a syntetické myši bylo hlavní technické
riziko v #48; tímhle mizí konstrukcí, ne laděním časování.

### Validátor čitelnosti

`compose` varuje u titulku, který je vidět kratší dobu než
`max(1,2 s; znaky/15)`, a u titulku delšího než 90 znaků. Kontroluje se
**skutečná doba zobrazení** — titulek zmizí, až ho vystřídá další, což ze
samotného scénáře vidět není. Varování běh nezastaví.

---

## Známé dluhy

- **Testidy pokrývají jen zlatou cestu prvního videa.** Login, shell,
  navigace, dashboard + feed, viewer, paleta — viz `docs/frontend.md` § 9.
  Scénář mimo ni si musí vypomoct CSS (povolené, viz Selektory), nebo
  testid nejdřív doplnit.
- **Datové sady chybí.** Scénáře běží nad tím, co na cílové instanci
  zrovna je. Sady podle #40 přijdou do `demo/datasets/`.
- **Čas není fixovaný.** `page.clock.setFixedTime()` zatím nikde, takže
  „dnes" je ve videu skutečné dnes.
- **Jazyk aplikace se neřídí ze scénáře.** Aplikace se vykreslí v jazyce,
  který si Chromium řekne přes `Accept-Language` — tedy anglicky. Pro
  česká videa bude potřeba `locale` v kontextu, případně jako pole
  scénáře.

---

## Testy

```bash
npm test
```

Pokrývají to, co jde ověřit bez prohlížeče: parser JSONC (komentáře
uvnitř řetězců, zbytkové čárky, zachování čísel řádků) a validaci
scénáře (překlep v názvu verbu, dva verby v kroku, modifikátor u verbu,
kam nepatří). Průchod scénáře se testuje verbem `check` proti živé
instanci.

---

## Artefakty a citlivost

`.env`, `.storage-state.json`, `.work/` a `out/` jsou v kořenovém
`.gitignore`. Uložená session je stejně citlivá jako heslo. Do repozitáře
nepatří žádný videosoubor — videa se generují, ne verzují.
