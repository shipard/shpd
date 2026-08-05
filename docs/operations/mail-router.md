# Připojení mail-routeru k hostingu (D4)

Runbook pro napojení mail-router stroje na hosting: hosting brokeruje
per-DS `shpd_ak_` tokeny (`mail_token`, `encrypted_text`) a servíruje
lookup endpointem; na routeru je oneshot `lookup-sync` (systemd timer),
který atomicky přepisuje `lookup.json`. Design: `docs/hosting.md` §5.3,
zadání `tasks/hosting-04-mail-router.md`. Deployment routeru samotného:
`deploy/README.md` v repu `mail_router`.

## 1. Řádek routeru + klíč (na hostingu)

1. Nastavení → Hosting → **Mail-routery** → nový záznam: název +
   obsluhované mail domény (čárkami oddělené; server je trimuje
   a lowercasuje). Domény musí odpovídat `virtual_mailbox_domains`
   Postfixu na router stroji.
2. Klíč routeru (z adresáře hosting DS):

   ```bash
   cd /opt/shipard/data-sources/<hosting-ds-id>
   sudo shpd-ds hosting-router-key --router <ndx> --generate
   ```

   Token `shpd_hk_…` se vytiskne **jednou** — na hostingu zůstává jen
   prefix + SHA-256 hash. Revokace: `--revoke` (router pak jede na
   poslední stažený lookup, pošta se neztrácí).

## 2. Konfigurace na mail-router stroji

`/etc/shipard-mail-router/config.yaml` — přidat sekci:

```yaml
lookup_sync:
  url:     https://portal.example.com/api/v1/_hosting/mail/lookup
  api_key: shpd_hk_XXXX
  # timeout: 10        # volitelné, sekundy
```

Bez sekce `lookup_sync` proces odmítne běžet (exit 2, jasná hláška) —
`lookup.json` zůstává ručně spravovaný.

## 3. Timer + ověření

```bash
sudo systemctl enable --now shipard-mail-router-lookup-sync.timer

# ruční první běh + kontrola:
sudo -u shipard-mail-router /opt/shipard-mail-router/venv/bin/shipard-mail-router-lookup-sync
cat /etc/shipard-mail-router/lookup.json
journalctl -u shipard-mail-router-lookup-sync -n 10
```

Timer běží à 2 minuty (`OnUnitActiveSec=2min`, `OnBootSec=30s`).
ETag cache (`lookup.json.etag`) drží nezměněné běhy na 304. Eventy
v journalu: `lookup_sync_updated` / `lookup_sync_unchanged` /
`lookup_sync_failed` (stale lookup, exit 0). Změněný `lookup.json`
načte běžící router mtime-watchem, bez restartu.

Chování při výpadku hostingu: sync loguje warning a končí exit 0 —
router jede na poslední stažený (stale) lookup. Nevalidní odpověď
(JSON, chybějící `api_url`/`api_token`) soubor **nikdy** nepřepíše.

## 4. Nový DS z portálu

Nic ručního: provisioning agent (`shpd-server hosting-sync`) po
`user-create` spustí `mail-router-setup --json` (jen když má DS aktivní
`core.mail`), token nahlásí confirmem a hosting ho uloží šifrovaně.
Do 2 minut ho stáhne lookup-sync → DS přijímá poštu. V lookupu je DS
pod `ds_id` i pod `web_id` slugem (pokud je vyplněný).

## 5. Ruční backfill existujícího DS

Plaintext tokeny nejsou nikde uložené — DS založené před Fází 3 je
třeba nahlásit hostingu ručně:

1. Na DS (rotace je bezpečná, router si nový token stáhne):

   ```bash
   cd /opt/shipard/data-sources/<ds-id>
   sudo shpd-ds mail-router-setup --force --json
   # → {"api_key": "shpd_ak_…", "user_id": N}
   ```

2. Na hostingu: Nastavení → Hosting → Zdroje dat → řádek DS → sekce
   **Pošta** → pole „Mail token" (prázdné, placeholder `●●●●●●`) →
   vložit `api_key` → Uložit. Prázdný submit hodnotu nemění; uložení
   šifruje `HostingDataSourceDocument`.
3. Ověřit, že DS je `lifecycle = active` a má vyplněné `url_app` —
   jinak se v lookupu neobjeví.
4. Po dalším běhu lookup-sync zkontrolovat `lookup.json` na routeru
   (nebo curl-em endpoint, viz níže).

Během okna mezi krokem 1 a stažením nového lookupu router doručuje
starým tokenem → shpd vrací 401 → worker zprávu **retryuje** (backoff),
pošta se neztrácí.

## 6. Diagnostika endpointu (curl)

```bash
URL='https://portal.example.com/api/v1/_hosting/mail/lookup'
KEY='shpd_hk_…'

curl -sS -H "Authorization: Bearer $KEY" "$URL" | python3 -m json.tool
# 304 test:
ETAG=$(curl -sSI -H "Authorization: Bearer $KEY" "$URL" | grep -i '^etag' | cut -d' ' -f2 | tr -d '\r')
curl -sS -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $KEY" -H "If-None-Match: $ETAG" "$URL"
```

- `401` — špatný/revokovaný klíč routeru (`hosting-router-key --generate`).
- `404` — DS nemá aktivní modul `hosting.core` (chybí tabulky).
- DS chybí v odpovědi — nemá `mail_token`, není `active`, nebo je
  archivovaný; jeden nedešifrovatelný token přeskočí jen dotčený DS
  (warning v error logu hostingu).

Endpoint je jediné místo (vedle queue payloadu provisioningu), kde
secret opouští hosting dešifrovaný — provozovat výhradně přes https.
