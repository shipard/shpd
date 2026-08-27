# Instalace prostředí pro video runner

Runner potřebuje tři věci: **Node 24+**, **Chromium od Playwrightu**
a **ffmpeg**. Varianta záznamu `x11` navíc **Xvfb**.

Dokument je psaný prostředí-agnosticky — konkrétní příkazy jsou pro
Debian/Ubuntu, na jiné distribuci se liší jen názvy balíčků.

---

## 1. Systémové balíčky

```bash
sudo apt install ffmpeg xvfb fonts-liberation fonts-noto-core
```

| Balíček | K čemu |
|---------|--------|
| `ffmpeg` | kompozice raw záznamu, vypálení titulků, škálování. **Povinné.** |
| `xvfb` | virtuální X server pro variantu záznamu `--capture=x11`. Pro `--capture=cdp` není potřeba. |
| `fonts-liberation`, `fonts-noto-core` | viz níže. |

### Fonty jsou součást zadání, ne detail

Videa se musí mezi sebou vzhledově shodovat. Když se aplikace na jednom
stroji vykreslí v Liberation Sans a na druhém spadne na DejaVu Sans,
rozejdou se šířky textů, zalomení a tím i celý layout — a to je vidět
ve chvíli, kdy vedle sebe na webu stojí dvě videa natočená jinde.

Minimální sada je proto **`fonts-liberation` + `fonts-noto-core`**.
Ověření, že v systému je proporcionální bezpatkový font (ne jen
`DejaVu` a `Noto Mono`):

```bash
fc-list : family | tr ',' '\n' | sort -u | grep -Ei 'liberation|noto sans'
```

Titulky se vypalují stejným fontem jako běží aplikace — pokud se sada
fontů změní, přegeneruj i starší videa (`compose` nad uloženým raw,
netočí se znovu).

---

## 2. Node 24+

```bash
node --version   # musí být v24 nebo vyšší
```

Runner používá `process.loadEnvFile()` a `node:util` `parseArgs`, obojí
bez závislostí — starší Node spadne.

> **Pozor na nvm.** Když je Node nainstalovaný přes `nvm`, **není na
> PATH v neinteraktivním shellu** — tedy v cronu, v systemd unitě
> a v čemkoli, co spustí `sh -c`. Projeví se to jako `node: command not
> found` ve chvíli, kdy se runner poprvé pustí jinak než z terminálu.
> Řešení: buď systémový Node (`apt`, NodeSource), nebo v cronu/unitě
> volat plnou cestu (`$(nvm which 24)`), nebo v unitě nastavit
> `Environment=PATH=...`. Až vznikne `install.sh`, musí s tím počítat.

---

## 3. Závislosti podprojektu a Chromium

```bash
cd tools/video-runner
npm install
```

Chromium se instaluje **ve dvou krocích** — systémové knihovny jako root,
samotný prohlížeč pod svým účtem:

```bash
sudo npx playwright install-deps chromium   # systémové .so knihovny
npx playwright install chromium             # prohlížeč do ~/.cache/ms-playwright
```

Proč ne jedním `sudo npx playwright install --with-deps chromium`: pod
`sudo` by se prohlížeč stáhl do `/root/.cache/ms-playwright`, kde ho běh
pod tvým účtem nenajde. Druhý příkaz se pouští **z `tools/video-runner/`**,
aby se stáhla verze Chromia odpovídající zdejšímu připnutému Playwrightu.

---

## 4. Konfigurace

```bash
cp .env.example .env
$EDITOR .env
```

Vyplň `SHPD_BASE_URL`, `SHPD_LOGIN` a `SHPD_PASSWORD`. `.env`
i `.storage-state.json` jsou v `.gitignore` — uložená session je stejně
citlivá jako heslo.

---

## 5. Ověření

```bash
node bin/video-runner.mjs --help          # vypíše verby
node bin/video-runner.mjs login           # vyrobí .storage-state.json
node bin/video-runner.mjs check demo/scenarios/spike-dashboard.jsonc
```

`check` projede scénář bez záznamu a vrátí 0. Nenulový kód znamená, že
scénář na něco nesedí — hláška řekne který krok a jaký selektor.

Nakonec obě varianty záznamu:

```bash
node bin/video-runner.mjs build demo/scenarios/spike-dashboard.jsonc --capture=cdp
node bin/video-runner.mjs build demo/scenarios/spike-dashboard.jsonc --capture=x11
```
