# Provoz PDF rendering služby (Gotenberg)

Vendorovaná infrastruktura (`docs/services.md`): cizí software, žádný
náš kód — jen pinovaná image, deployment descriptor a tento dokument.
Koncept a kontrakt klienta: `docs/render.md`. Issue #34.

**Pinovaná image:** `docker.io/gotenberg/gotenberg:8.34.0` — nikdy
`latest`. Pin žije v deployment descriptoru
(`docs/render/shpd-render.service`), upgrade = změna pinu + restart.
Minimálně 8.32.0 — starší verze mají CVE-2026-42597 (čtení
`file:///tmp/` cizích requestů přes URL routy).

## Bezpečnostní model

- **Žádný WAN forward.** Bind výhradně `127.0.0.1` (single-server)
  nebo interní bridge (multi-kontejner) s firewall/ACL na subnet.
- **Push-only obsah** — klient posílá HTML + assety v requestu, služba
  nikdy nenavštěvuje URL (2026 CVE Gotenbergu se týkají výhradně
  URL/downloadFrom/webhook režimů, které nepoužíváme).
- **JS vypnutý globálně** (`--chromium-disable-javascript=true`).
- **Odchozí síť Chromia zakázaná** env proměnnými
  `CHROMIUM_DENY_PRIVATE_IPS=true` + `CHROMIUM_DENY_PUBLIC_IPS=true`.
  Obě zapnuté = odmítnuto každé síťové URL mimo allow-list (žádný
  nemáme); interní `file://` load nahraného `index.html` hlídají
  vestavěné always-on ochrany (per-request scope pracovního adresáře).
  **Ne `--chromium-deny-list=.*`** — regex matchne i to interní
  `file://` a každá konverze vrátí `403 Forbidden`, přestože
  `/health` hlásí `up`. Detail: gotenberg.dev/docs/outbound-url-filtering.
- Žádná aplikační autentizace — zabezpečení je čistě síťové.

## Single-server (alpha, dev)

Podman na stroji (instaluje `scripts/install-packages.sh`), kontejner
spouští systemd unit:

```bash
sudo cp /opt/shipard/shpd/docs/render/shpd-render.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now shpd-render
curl -s http://127.0.0.1:3000/health   # → {"status":"up",...}
```

Do `/etc/shipard/server.json` doplnit:

```json
"render": { "url": "http://127.0.0.1:3000", "timeoutSec": 30 }
```

Ověření: viz sekce Ověření níže.

## Incus (multi-kontejnerová topologie)

Gotenberg jako samostatný systémový kontejner na interním bridgi,
Shipard kontejnery ho volají přes vnitřní síť (D1 — jedna služba
na ~10 Shipard kontejnerů na HW):

```bash
incus remote add docker https://docker.io --protocol=oci
incus launch docker:gotenberg/gotenberg:8.34.0 shpd-render \
  --network <interní-bridge> \
  -c environment.CHROMIUM_DENY_PRIVATE_IPS=true \
  -c environment.CHROMIUM_DENY_PUBLIC_IPS=true \
  -c oci.entrypoint='gotenberg --chromium-disable-javascript=true --api-timeout=60s'
```

Network ACL na bridgi: port 3000 povolit jen ze Shipard kontejnerů,
žádný forward z WAN. V `server.json` každého Shipard kontejneru pak
`"url": "http://<ip-kontejneru>:3000"`.

### Statická adresa (Incus, nespravovaný bridge)

`incus config device set shpd-render eth0 ipv4.address=…` funguje jen
na sítích, které spravuje Incus (vlastní DHCP). Na externím bridgi
s cizím DHCP serverem se místo toho zafixuje MAC a adresa se rezervuje
na DHCP serveru:

```bash
incus config device override shpd-render eth0 hwaddr=10:66:6a:00:00:10
incus restart shpd-render
# + rezervace MAC → IP na DHCP serveru bridge
```

Bez toho se po restartu kontejneru může změnit IP a `url` v
`server.json` Shipard kontejnerů přestane platit.

## Proxmox

LXC kontejner s nesting (`features: nesting=1`) + podman uvnitř,
dál shodné se single-server scénářem (unit template, bind na IP
interního bridge místo 127.0.0.1). Alternativně VM s podmanem.

## Ověření

1. `shpd-server doctor` → sekce Render service ✓ (health + verze).
2. Pozitivní konverze — hlavní soubor **musí** být `index.html`:

   ```bash
   printf '<html><body><h1>test</h1></body></html>' > index.html
   curl -s -o out.pdf -w '%{http_code}\n' -F files=@index.html \
     http://<host>:3000/forms/chromium/convert/html   # → 200, out.pdf začíná %PDF
   ```

3. Negativní testy egressu — obě volání musí vrátit `403 Forbidden`
   (privátní i veřejná adresa); cokoli jiného znamená, že IP-class
   proměnné nejsou aktivní:

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' -F url=http://10.0.0.1/ \
     http://<host>:3000/forms/chromium/convert/url       # → 403
   curl -s -o /dev/null -w '%{http_code}\n' -F url=https://example.com/ \
     http://<host>:3000/forms/chromium/convert/url       # → 403
   ```

4. Integrační test klienta z repa (suite Integration, úzký filter):

   ```bash
   SHIPARD_INTEGRATION_GOTENBERG_URL=http://<host>:3000 \
     vendor/bin/phpunit --testsuite Integration --filter GotenbergIntegrationTest
   ```

## Upgrade image

1. Změnit pin v `docs/render/shpd-render.service` (commit do repa).
2. Na stroji: `sudo cp … /etc/systemd/system/ && sudo systemctl
   daemon-reload && sudo systemctl restart shpd-render` (podman si
   novou image stáhne sám). Incus: `incus launch` nového kontejneru
   z nové image, přepnout `url`, starý smazat.
3. Projít sekci Ověření — hlavně negativní testy egressu.

Před změnou pinu **vždy** projít release notes — flagy a defaulty
outbound filteringu se mezi verzemi mění. Konkrétně: 8.31.0 zpřísnilo
blokování privátních IP u sub-resources (assety, fonty), 8.32.0 vrátilo
permisivní default. Proto egress blokujeme **explicitními** proměnnými
`CHROMIUM_DENY_*`, ne spoléháním na default pinované verze.

## Diagnostika

| Symptom | Kontrola |
|---|---|
| doctor: `render service … is not responding` | `systemctl status shpd-render`, `podman ps`, `curl http://127.0.0.1:3000/health` |
| volání vrací `unreachable` | služba neběží nebo špatná `url` v server.json; v logu je jeden warning `render: …` per proces |
| volání vrací `timeout` | dlouhý dokument / malý `timeoutSec`; `--api-timeout` kontejneru musí být ≥ klientský timeout |
| volání vrací `engineError` | tělo chyby Gotenbergu je v `note`; `journalctl -u shpd-render` |
| `403 Forbidden` na **všech** konverzích, `/health` je `up` | operátorský `--chromium-deny-list` matchuje interní `file://` (typicky `.*`); odstranit, egress řešit `CHROMIUM_DENY_PRIVATE_IPS` + `CHROMIUM_DENY_PUBLIC_IPS` |
| `400 Bad Request` na `/forms/chromium/convert/html` | hlavní soubor se nejmenuje `index.html` (klient `GotenbergEngine` ho tak posílá vždy; při ručním curlu zkontrolovat název) |
| `ping` na kontejner z jiného kontejneru nefunguje | kontejnery nemají `cap_net_raw` — není to výpadek sítě; testovat `curl …/health` |
| chybí `pdfattach`/`pdfunite` (post-processing) | `sudo apt install poppler-utils` — hlídá doctor, sekce Attachment tools |
