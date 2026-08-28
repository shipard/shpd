# Provoz PDF rendering služby (Gotenberg)

Vendorovaná infrastruktura (`docs/services.md`): cizí software, žádný
náš kód — jen pinovaná image, deployment descriptor a tento dokument.
Koncept a kontrakt klienta: `docs/render.md`. Issue #34.

**Pinovaná image:** `docker.io/gotenberg/gotenberg:8.30.0` — nikdy
`latest`. Pin žije v deployment descriptoru
(`docs/render/shpd-render.service`), upgrade = změna pinu + restart.

## Bezpečnostní model

- **Žádný WAN forward.** Bind výhradně `127.0.0.1` (single-server)
  nebo interní bridge (multi-kontejner) s firewall/ACL na subnet.
- **Push-only obsah** — klient posílá HTML + assety v requestu, služba
  nikdy nenavštěvuje URL (2026 CVE Gotenbergu se týkají výhradně
  URL/downloadFrom/webhook režimů, které nepoužíváme).
- **JS vypnutý globálně** (`--chromium-disable-javascript=true`),
  **odchozí síť Chromia zakázaná** (`--chromium-deny-list=.*`).
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

Ověření: `shpd-server doctor` → sekce Render service.

## Incus (multi-kontejnerová topologie)

Gotenberg jako samostatný systémový kontejner na interním bridgi,
Shipard kontejnery ho volají přes vnitřní síť (D1 — jedna služba
na ~10 Shipard kontejnerů na HW):

```bash
incus remote add docker https://docker.io --protocol=oci
incus launch docker:gotenberg/gotenberg:8.30.0 shpd-render \
  --network <interní-bridge> \
  -c oci.entrypoint='gotenberg --chromium-disable-javascript=true --chromium-deny-list=.* --api-timeout=60s'
```

Network ACL na bridgi: port 3000 povolit jen ze Shipard kontejnerů,
žádný forward z WAN. V `server.json` každého Shipard kontejneru pak
`"url": "http://<ip-kontejneru>:3000"`.

## Proxmox

LXC kontejner s nesting (`features: nesting=1`) + podman uvnitř,
dál shodné se single-server scénářem (unit template, bind na IP
interního bridge místo 127.0.0.1). Alternativně VM s podmanem.

## Upgrade image

1. Změnit pin v `docs/render/shpd-render.service` (commit do repa).
2. Na stroji: `sudo cp … /etc/systemd/system/ && sudo systemctl
   daemon-reload && sudo systemctl restart shpd-render` (podman si
   novou image stáhne sám).
3. `shpd-server doctor` — sekce Render service musí být ✓.

Před změnou pinu projít release notes — flagy
(`--chromium-deny-list`, `--api-timeout`) se mezi verzemi mohou měnit.

## Diagnostika

| Symptom | Kontrola |
|---|---|
| doctor: `render service … is not responding` | `systemctl status shpd-render`, `podman ps`, `curl http://127.0.0.1:3000/health` |
| volání vrací `unreachable` | služba neběží nebo špatná `url` v server.json; v logu je jeden warning `render: …` per proces |
| volání vrací `timeout` | dlouhý dokument / malý `timeoutSec`; `--api-timeout` kontejneru musí být ≥ klientský timeout |
| volání vrací `engineError` | tělo chyby Gotenbergu je v `note`; `journalctl -u shpd-render` |
| chybí `pdfattach`/`pdfunite` (post-processing) | `sudo apt install poppler-utils` — hlídá doctor, sekce Attachment tools |
