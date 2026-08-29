# Oprava nasazení PDF rendering služby (Gotenberg 8.34.0)

**Stav:** hotovo — descriptor + docs srovnány 2026-08-29, integrační test
i ruční curl ověření (200 / 400 bez index.html / 403 egress) prošly proti
`10.199.6.210:3000`; reprodukovatelnost čerstvého nasazení dle návodu
ověří příští instalace na novém hostu

**Issue:** shipard/shpd#34 (follow-up k `tasks/pdf-rendering-service.md`)

## Cíl

Původní deployment návod je rozbitý: flag `--chromium-deny-list=.*`
blokuje **všechna** URL včetně interního `file://`, přes které Gotenberg
načítá nahraný `index.html` — každá Chromium konverze tak vrací
`403 Forbidden`, přestože health endpoint hlásí `up`. Zároveň je pin
8.30.0 zranitelný (CVE-2026-42597, oprava v 8.32.0). Nasazení bylo
2026-08-29 ručně překlopeno na 8.34.0 s novým mechanismem blokování
egressu; tento úkol uvádí repo (descriptor + docs) do souladu a uzavírá
živé E2E z původního PRD.

## Návaznost

- `tasks/pdf-rendering-service.md` — původní PRD (D1–D7 platí dál,
  mění se jen technika vynucení D4/D5).
- Živá instance: Incus kontejner `shpd-render`, `10.199.6.210:3000`,
  ověřeno: konverze HTML→PDF 200, egress testy 403, doctor ✓ (dev).
- Gotenberg docs: `/docs/outbound-url-filtering` (precedence deny-list /
  allow-list / IP-class checks, always-on ochrany `file://`).

## Rozhodnutí (odsouhlaseno 2026-08-29)

- **D1** Pin image `8.30.0` → **`8.34.0`**. Důvody: CVE-2026-42597
  (čtení `file:///tmp/` cizích requestů přes URL routy, fix v 8.32.0),
  dostupnost IP-class proměnných (D2). Poznámka do upgrade sekce:
  8.31.0/8.32.0 měnily default chování privátních IP — před každou
  změnou pinu číst release notes (pravidlo už v docs je, doplnit
  konkrétní příklad).
- **D2** Blokování egressu Chromia: místo `--chromium-deny-list=.*`
  (rozbité — matchne i interní `file://`) použít env proměnné
  **`CHROMIUM_DENY_PRIVATE_IPS=true` + `CHROMIUM_DENY_PUBLIC_IPS=true`**.
  Obě zapnuté = odmítnuto každé síťové URL mimo allow-list (žádný
  nemáme), `file://` řeší vestavěné always-on ochrany (per-request
  scope pracovního adresáře). Flagy `--chromium-disable-javascript=true`
  a `--api-timeout=60s` zůstávají.
- **D3** Dokumentace doplnit o provozní poznatky z nasazení (viz Scope).

## Před implementací přečti

- `docs/render/shpd-render.service` — descriptor k úpravě
- `docs/operations/render-service.md` — provozní dokument k úpravě
- `docs/render.md` — kontrakt klienta (jen ověření, viz Scope)
- `src/Core/Render/` — Gotenberg adaptér: ověřit, že HTML soubor
  posílá pod jménem `index.html` (jiný název → HTTP 400)
- https://gotenberg.dev/docs/outbound-url-filtering — mechanismus
  IP-class proměnných a precedence

## Scope

### 1. `docs/render/shpd-render.service`

- Pin `docker.io/gotenberg/gotenberg:8.30.0` → `8.34.0`.
- Odstranit `--chromium-deny-list=.*`; do `podman run` přidat
  `-e CHROMIUM_DENY_PRIVATE_IPS=true -e CHROMIUM_DENY_PUBLIC_IPS=true`.
- Přepsat komentář „Bezpečnostní model" v hlavičce: deny-list `.*`
  nahrazen IP-class proměnnými + jedna věta proč (deny-list `.*`
  blokuje i interní `file://` load → 403 na všech konverzích).

### 2. `docs/operations/render-service.md`

- Pin 8.30.0 → 8.34.0 všude (úvod, incus launch příklad).
- Sekce Bezpečnostní model: nahradit odrážku o `--chromium-deny-list=.*`
  popisem IP-class proměnných (obě `true` = žádný egress, `file://`
  hlídají always-on ochrany per-request).
- Incus příklad: doplnit `-c environment.CHROMIUM_DENY_PRIVATE_IPS=true`
  `-c environment.CHROMIUM_DENY_PUBLIC_IPS=true`, odstranit deny-list flag.
- Nová podsekce „Statická adresa (Incus, nespravovaný bridge)":
  `ipv4.address` na NIC funguje jen na Incusem spravovaných sítích;
  na externím bridgi s vlastním DHCP → fixní MAC
  (`incus config device override shpd-render eth0 hwaddr=…`)
  + rezervace na DHCP serveru.
- Diagnostika (tabulka): doplnit řádky
  - `403 Forbidden` na všech konverzích → operátorský deny-list matchuje
    interní `file://` (typicky `.*`); řešení = IP-class proměnné
  - `400 Bad Request` na `/forms/chromium/convert/html` → hlavní soubor
    se nejmenuje `index.html`
  - `ping` na kontejner nefunguje z jiného kontejneru (chybí
    `cap_net_raw`) → testovat `curl …/health`
- Sekce Ověření: k doctoru doplnit negativní testy egressu
  (`/forms/chromium/convert/url` na privátní i veřejné URL → očekávané
  403) a pozitivní test konverze (curl s `index.html`).
- Upgrade sekce: konkretizovat příklad změn chování mezi verzemi
  (8.31.0 zpřísnilo privátní IP u sub-resources, 8.32.0 vrátilo
  permisivní default — proto explicitní IP-class proměnné, ne spoléhání
  na defaulty).

### 3. `docs/render.md` + `src/Core/Render/` (jen ověření)

- Ověřit, že adaptér posílá HTML pod jménem `index.html`. Pokud ano,
  žádná změna; pokud kontrakt jméno souboru nezmiňuje, doplnit jednu
  větu do `docs/render.md`.

### 4. `tasks/pdf-rendering-service.md`

- Aktualizovat řádek **Stav:** Gotenberg běží (Incus `shpd-render`,
  8.34.0), živé E2E viz tento task.

### 5. Živé E2E (integrační test)

- Spustit integrační test gated na `SHIPARD_INTEGRATION_GOTENBERG_URL`
  proti `http://10.199.6.210:3000`
  (`SHIPARD_INTEGRATION_GOTENBERG_URL=http://10.199.6.210:3000 vendor/bin/phpunit --filter …`
  — úzký filter dle názvu testu, ne broad).

## Testy

- Žádný nový kód → žádné nové unit testy.
- Integrační test viz Scope 5 — musí projít proti živé instanci.

## Commit strategie

1. `docs(render): fix deployment — Gotenberg 8.34.0, IP-class egress
   blocking instead of broken deny-list` (descriptor + render-service.md)
2. `docs(render): operational notes — static IP on unmanaged bridge,
   index.html requirement, egress verification` (zbytek docs, stav
   původního tasku)
3. Případná drobná úprava `docs/render.md` dle Scope 3 součástí commitu 2.

## Hotovo když

- [x] `docs/render/shpd-render.service`: pin 8.34.0, IP-class env
      proměnné, žádný `--chromium-deny-list`
- [x] `docs/operations/render-service.md`: všechny body ze Scope 2
- [x] Ověřeno pojmenování `index.html` v adaptéru (Scope 3)
- [x] `tasks/pdf-rendering-service.md` má aktuální Stav
- [x] Integrační test prošel proti `http://10.199.6.210:3000`
- [ ] Postup z dokumentace je reprodukovatelný: čerstvé nasazení podle
      návodu projde health, pozitivní konverzí i negativními egress testy
      — ověřit při příští instalaci na novém hostu (živá instance vznikla
      ručním překlopením, ne podle finálního návodu)
