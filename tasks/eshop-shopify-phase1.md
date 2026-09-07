# E-shop — Fáze 1: Shopify jako prodejní kanál (objednávka → kontrola → doklad → odbavení)

**Stav:** naplánováno — návrh k designové diskuzi (2026-09-07); rozhodnutí D1–D9 otevřená, nic neimplementováno

Issue: #65 (tento task), #64 (W4 — regulatorní údaje na dokladu)

> Draft PRD. Vzniklo mimo repozitář jako podklad pro tým, autor návrhu není
> vývojář Shipardu — čeká na zpětnou vazbu k zařazení do roadmapy a k D1.
> Sekce označené ⚠️ je třeba potvrdit s účetní nebo proti aktuální verzi
> Shopify Admin API.

## Kontext

Shopify nevystavuje český daňový doklad — nemá číselnou řadu podle zákona
o DPH, DUZP, opravný doklad ani přílohy v notifikačních e-mailech. Každý
Shopify obchod v ČR proto potřebuje externí fakturační vrstvu; standard na
trhu je Fakturoid / iDoklad přes Shopify app. Tenhle návrh dělá z Shipardu
tu vrstvu — a navíc místo, kde se objednávka **před** vystavením dokladu
zkontroluje a odbaví.

Pilotní nasazení: DS malého e-shopu s elektronikou (plátce DPH, CZK, Shopify
Basic, platby výhradně předem). Návrh je psaný obecně pro další Shopify DS.

Co už v Shipardu je a na co se navazuje:

- **Výměnný formát** `shpd.docs.document.v1` + `/api/v1/_exchange/docs/document/apply`
  — deklarovaný účel č. 4 „Shipard jako automatizovaný fakturační systém"
  a č. 5 „adaptér přečte cizí formát, transformuje na canonical". Shopify
  objednávka je přesně tenhle případ. `docType: invoiceIssued`, lineage
  přes `source.kind`.
- **Hlavička dokladu** má `vat_duzp` i `vat_dppd` — DUZP odlišné od data
  vystavení je podporované, nic se nepřidává.
- **DPH** — `docs/accounting.md` řeší analytiky per vatCode, reverse charge
  i konvenci OSS. Shipard daně **nepočítá znovu**, přebírá je z objednávky.
- **PDF** — `src/Core/Render/` (Gotenberg, `docs/render.md`). Tiskový výstup
  dokladu ale je „samostatný budoucí design" — **závislost, viz D6**.
- **Odchozí pošta** — `MailOutboxService` (`docs/mail/outbound.md`) jako
  fallback pro odeslání dokladu.
- **Vzor samostatné komponenty** — `mail-router` (`docs/services.md`): démon
  mimo repo volající shpd endpoint. Relevantní pro D1.

Fáze 1 pokrývá jeden směr a jeden typ dokladu: **zaplacená objednávka →
faktura vydaná → fulfillment**. Refundace (opravný doklad), dobírka,
synchronizace skladu a produktů jsou pozdější fáze.

## Návaznost

- `docs/exchange-format.md` — sekce 5 (specifikace canonical), 4 (životní
  cyklus), 11 (`source_kind`, cfgItem `docs.core.sourceKinds`). Tento task
  přidává `source.kind = "import.shopify"`.
- `docs/docs-mvp.md` + `modules/docs/core/tables/docs_core_heads.jsonc` —
  `vat_duzp`, `payment_reference`, `partner_doc_number`.
- `docs/accounting.md` — DPH per vatCode, reverse charge, OSS; **nic se
  nemění**, jen se ověřuje, že vstup z Shopify padne do existujících
  kódů DPH (`world.vat`).
- `docs/render.md` — kontrakt `RenderClient`; tisková šablona faktury je
  prerekvizita (D6).
- `docs/mail/outbound.md` — fallback odeslání.
- `docs/services.md` — pokud D1 = samostatná komponenta, platí celý standard
  (struktura repa, `bin/shpd-shopify`, `/etc/shipard-shopify/`, …).
- `docs/roadmap.md` — task nemá milník. Pro pilotní DS je to blokátor
  použitelnosti (kategorie 2); pro Shipard obecně kategorie 4. **Zařazení
  rozhodne tým** — viz Rozhodnutí D8.

## Před implementací přečti

- `docs/exchange-format.md` celý; zejména applier a `_resolve` (osoby
  z objednávky se budou vytvářet on-the-fly → `PartyResolver` + `userAction`
  bez UI, tj. deterministická politika, viz D4)
- `modules/docs/invoicesOut/` — `IssuedInvoiceDocument`, `IssuedInvoiceForm`
- `modules/core/mail/` — jak `/_mail/incoming` řeší idempotenci a
  auto-provisioning; webhook endpoint má být postavený stejně
- `tasks/mail-isdoc-import.md` — vzor adaptéru cizí formát → canonical
  (mapovací tabulka); Shopify adaptér má mít stejnou formu
- `tasks/bank-phase1.md` — vzor rozdělení na pracovní balíčky W1–Wn

## Scope

### V rozsahu (Fáze 1)

- Příjem webhooků Shopify (`orders/create`, `orders/paid`, `orders/updated`,
  `orders/cancelled`) s HMAC ověřením a idempotencí
- Evidence objednávek v shpd: fronta „ke kontrole" s viewerem
- Akce **Odbavit**: vystavení faktury vydané přes exchange apply → PDF →
  zápis do Shopify (Files + order metafields) → `fulfillmentCreate`
  s notifikací zákazníka
- DUZP = datum přijetí platby; datum vystavení = den odbavení ⚠️
- Recyklační příspěvek (§ 73 z. 542/2020) jako údaj na dokladu — datový
  model a výpočet; **tisk závisí na D6**
- Link na PDF v Shopify e-mailu *Shipping confirmation* (Liquid, order
  metafield); fallback odeslání z shpd přes outbox

### Mimo rozsah (pozdější fáze)

- Refundace → opravný daňový doklad (`refunds/create`) — Fáze 2
- Dobírka, bankovní převod, proforma — Fáze 2 (mění DUZP logiku)
- Synchronizace produktů / skladu Shopify ↔ `economy_items` — Fáze 3
- Customer Account UI Extension (faktura v zákaznickém účtu) — Fáze 3,
  vyžaduje vlastní Shopify app přes Shopify CLI
- Výkazy pro kolektivní systém (ASEKOL) a EKO-KOM — samostatný task
  `reports-recycling-fee.md`, čerpá z dat této fáze
- Cizí měna na dokladu (Shopify prodej v EUR) — D7

## Architektura

### Rozdělení rolí

| | Shopify | Shipard |
|---|---|---|
| katalog, košík, checkout, platby | ✓ | — |
| potvrzení objednávky zákazníkovi | ✓ | — |
| **kontrola objednávky před dokladem** | — | ✓ |
| daňový doklad, číselná řada, DUZP, PDF | — | ✓ |
| recyklační příspěvek na dokladu | — | ✓ |
| **fulfillment (založení)** | — | ✓ přes Admin API |
| shipping e-mail zákazníkovi (s linkem na PDF) | ✓ (spouští shpd) | — |
| zákaznický účet | ✓ | — (Fáze 3 extension) |

**Klíčový design point:** fulfillment se nezakládá v Shopify adminu, ale
z shpd. Shopify posílá *Shipping confirmation* okamžitě při založení
fulfillmentu — kdyby ho zakládal člověk v adminu, e-mail odejde dřív, než
shpd stihne fakturu zapsat. Když fulfillment zakládá shpd až po zápisu
metafieldů, závod o čas neexistuje. (Fakturoid app tenhle problém obchází
tak, že e-mail s fakturou posílá sama, nezávisle na Shopify.)

### Tok

```
Shopify checkout ── orders/create ──▶ shpd: objednávka [10 nová]
                 ── orders/paid ────▶ shpd: uloží paid_at  → [20 ke kontrole]
                 ── orders/updated ─▶ shpd: promítne změny (jen ve stavu ≤ 20)
                 ── orders/cancelled▶ shpd: [90 zrušeno], doklad nevzniká

uživatel v shpd: viewer „E-shop objednávky" → záznam → **Odbavit**
  1. canonical (invoiceIssued, source.kind=import.shopify, vatDuzp=paid_at)
     → DocumentApplier → docs_core_heads  [faktura vydaná, číslo z řady]
  2. RenderClient → PDF
  3. Shopify: stagedUploadsCreate → PUT → fileCreate        (Files)
  4. Shopify: metafieldsSet(Order)  invoice_pdf / invoice_url / invoice_number / invoice_duzp
  5. Shopify: fulfillmentCreate(fulfillmentOrders, tracking, notifyCustomer:true)
     └─▶ Shopify pošle Shipping confirmation; Liquid v šabloně čte
         order.metafields.custom.invoice_url → tlačítko „Stáhnout fakturu"
  → objednávka [30 odbaveno]
```

Kroky 1–4 v jedné aplikační transakci z pohledu stavu objednávky: pokud
selže 2–4, doklad existuje (číslo je přidělené, nevrací se — viz
`doc-number-release-on-data-save.md` pro kontext), objednávka zůstává ve
stavu 20 s `last_error`, akce Odbavit je opakovatelná a **idempotentní**
(detekuje existující `doc_head`, existující `invoice_file_gid`, existující
fulfillment).

## Datový model

Nový modul **`economy.eshop`** (skupina `economy`). Prefix tabulek
`economy_eshop_*`.

### `economy_eshop_channels` — napojený obchod

Jeden řádek per Shopify obchod (DS může mít víc kanálů; Fáze 1 počítá
s jedním).

```jsonc
{
  "id": "economy_eshop_channels",
  "tableId": 0,                       // přidělit přes next-table-id
  "columns": [
    { "id": "id",            "type": "int",        "autoIncrement": true, "primary": true },
    { "id": "channel_type",  "type": "enumString", "len": 20,  "values": ["shopify"] },
    { "id": "name",          "type": "varchar",    "len": 120 },
    { "id": "shop_domain",   "type": "varchar",    "len": 160 },          // xxx.myshopify.com
    { "id": "admin_token",   "type": "encrypted_text" },                   // Admin API access token
    { "id": "webhook_secret","type": "encrypted_text" },                   // HMAC klíč
    { "id": "number_series", "type": "int", "reference": "docs_core_number_series" },
    { "id": "own_bank_account", "type": "int", "reference": "economy_codebooks_bank_accounts" },
    { "id": "invoice_note",  "type": "text", "nullable": true },           // text na doklad
    { "id": "is_active",     "type": "bool" }
  ]
}
```

`admin_token` a `webhook_secret` jsou `encrypted_text` — Document class
šifruje v `beforeSave()`, dešifruje controller těsně před použitím
(`docs/operations/secrets.md`). Form: opt-in whitelist
`getEditableSensitiveColumns()`.

### `economy_eshop_orders` — objednávka

```jsonc
{
  "id": "economy_eshop_orders",
  "tableId": 0,
  "columns": [
    { "id": "id",             "type": "int", "autoIncrement": true, "primary": true },
    { "id": "channel",        "type": "int", "reference": "economy_eshop_channels" },
    { "id": "external_id",    "type": "varchar", "len": 80 },   // gid://shopify/Order/…
    { "id": "order_number",   "type": "varchar", "len": 40 },   // #1001 — jde na doklad jako payment_reference
    { "id": "state",          "type": "enumInt", "values": {"10":"new","20":"toReview","30":"fulfilled","90":"cancelled"} },
    { "id": "created_at",     "type": "datetime" },
    { "id": "paid_at",        "type": "datetime", "nullable": true },   // ← DUZP
    { "id": "currency",       "type": "char", "len": 3 },
    { "id": "total",          "type": "numeric", "precision": 15, "scale": 2 },
    { "id": "customer_email", "type": "varchar", "len": 160 },
    { "id": "payload",        "type": "json" },                         // poslední přijatý stav objednávky
    { "id": "doc_head",       "type": "int", "nullable": true, "reference": "docs_core_heads" },
    { "id": "invoice_file_gid","type": "varchar", "len": 80, "nullable": true },
    { "id": "fulfillment_gid", "type": "varchar", "len": 80, "nullable": true },
    { "id": "tracking_number", "type": "varchar", "len": 80, "nullable": true },
    { "id": "tracking_company","type": "varchar", "len": 80, "nullable": true },
    { "id": "last_error",     "type": "text", "nullable": true },
    { "id": "fulfilled_at",   "type": "datetime", "nullable": true }
  ],
  "indexes": [
    { "columns": ["channel", "external_id"], "unique": true },   // idempotence webhooků
    { "columns": ["state"] }
  ]
}
```

Bez FK (konvence) — integrita v Document class.

### `economy_eshop_webhook_log` — příjem

Pro idempotenci a diagnostiku: `channel`, `topic`, `shopify_webhook_id`
(unique), `received_at`, `processed_at`, `result` (enumInt ok/skipped/error),
`error`. Retence přes cron prune (vzor `alerts-prune`).

### Regulatorní údaje na dokladu per země — `economy.items` + extension `docs.core`

Není specifické pro e-shop; platí pro každou vydanou fakturu za
elektrozařízení. Proto žije v `economy.items` / `base.persons`, ne
v `economy.eshop`.

**Obecný problém, ne CZ specialita.** Rešerše ukázala, že viditelný
recyklační příspěvek per položka je **česká zvláštnost** (§ 73). Německo
ElektroG nic takového nechce — vyžaduje **WEEE-Reg.-Nr. na nabídkách
a fakturách** (opomenutí je jedna z nejčastějších chyb při vstupu na
DE trh). Rakousko strukturou kopíruje Německo. Model proto má dvě
vrstvy:

1. **Registrační identifikátory vlastní firmy per země** (extension
   `base_persons_persons` pro `is_own`, nebo samostatná tabulka
   `economy_codebooks_epr_registrations`): `country`, `stream`
   (`weee` / `packaging` / `battery`), `register` (Stiftung EAR, LUCID,
   MŽP CZ, …), `number`, `valid_from/to`. Tisková šablona je vypíše podle
   **země dodání** dokladu.
2. **CZ recyklační příspěvek per řádek** — první a zatím jediná
   implementace „per-položkového" údaje, model níže.

Do Fáze 1 patří obě vrstvy datově; tisk vrstvy 1 pro jiné země než CZ
až s expanzí.

```jsonc
// economy_items_recycling_fee_groups
{
  "columns": [
    { "id": "id",          "type": "int", "autoIncrement": true, "primary": true },
    { "id": "code",        "type": "varchar", "len": 20 },     // "ASEKOL 6.05"
    { "id": "name",        "type": "varchar", "len": 200 },
    { "id": "system",      "type": "varchar", "len": 40 },     // kolektivní systém
    { "id": "rate",        "type": "numeric", "precision": 10, "scale": 4 },   // Kč bez DPH
    { "id": "unit",        "type": "enumString", "len": 3, "values": ["pcs", "kg"] },
    { "id": "valid_from",  "type": "date" },
    { "id": "valid_to",    "type": "date", "nullable": true }
  ]
}
// extension → economy_items
{ "id": "recycling_fee_group", "type": "int", "nullable": true, "reference": "economy_items_recycling_fee_groups" }
{ "id": "recycling_fee_coef",  "type": "numeric", "precision": 10, "scale": 4, "nullable": true }
   // pro unit=kg: hmotnost 1 ks v kg; pro pcs: 1 (vzor Pohoda, agenda Recyklační příspěvky)

// extension → docs_core_rows  (snapshot k DUZP, sazba se v čase mění)
{ "id": "recycling_fee_rate",  "type": "numeric", "precision": 10, "scale": 4, "nullable": true }
{ "id": "recycling_fee_unit",  "type": "enumString", "len": 3, "nullable": true }
{ "id": "recycling_fee_qty",   "type": "numeric", "precision": 12, "scale": 4, "nullable": true }  // ks nebo kg
{ "id": "recycling_fee_total", "type": "numeric", "precision": 12, "scale": 2, "nullable": true }
```

Výpočet v `DocDocument::beforeSave()` (nebo hooku `docs.invoicesOut`):
pro řádek s položkou, která má `recycling_fee_group` platnou k `vat_duzp`,
dopočítat `qty = quantity × coef`, `total = rate × qty`. Součet per doklad
do rekapitulace (samostatný řádek, ne do `docs_core_vat_recap`).

Canonical (`shpd.docs.document.v1`) potřebuje volitelné pole na řádku
`recyclingFee: { groupCode, rate, unit, qty, total }` — přidat do schema
jako nepovinné, applier mapuje na extension sloupce. ⚠️ Zvážit, zda
nenechat výpočet jen na DB straně a canonical nerozšiřovat (adaptér by
posílal jen `item` referenci a zbytek dopočte `beforeSave`). **Doporučení:
nerozšiřovat canonical**, dopočet dělá dokument — jeden zdroj pravdy.

## API a kontrakty

### Webhook endpoint (shpd strana)

`POST /_eshop/shopify/webhook` — bez session, autentizace HMAC-SHA256
z hlavičky `X-Shopify-Hmac-Sha256` proti `webhook_secret` kanálu
(kanál identifikován z `X-Shopify-Shop-Domain`). Idempotence přes
`X-Shopify-Webhook-Id` → `economy_eshop_webhook_log`. Odpověď 200 vždy
po zapsání do logu; zpracování synchronní (objednávek jsou jednotky
denně), při chybě `result=error` + alert (`core.alerts` check
`economy.eshop.webhook_failed`).

Vzor: `/_mail/incoming` (`tasks/mail-phase2a.md`).

### Adaptér Shopify → canonical

`Shipard\Module\Economy\Eshop\Shopify\OrderReader::toCanonical(array $order, Channel $ch): array`

| canonical | zdroj v Shopify order | poznámka |
|---|---|---|
| `docType` | — | `invoiceIssued` |
| `source.kind` | — | `import.shopify` (přidat do cfgItem `docs.core.sourceKinds`) |
| `source.externalId` | `id` (GID) | |
| `issueDate` | den odbavení | ne den objednávky |
| `vatDuzp` | `economy_eshop_orders.paid_at` | ⚠️ D2 |
| `dueDate` | = issueDate | zaplaceno |
| `paymentReference` | `order_number` bez `#` | VS = číslo objednávky (zákazník ho zná) |
| `partnerDocNumber` | `name` (`#1001`) | |
| `customer` | `billing_address` + `customer.email`; firma: `company`, `vat_number`/`tax_exemptions` | resolve viz D4 |
| `rows[]` | `line_items[]` | `sku` → `economy_items` (resolve podle SKU; D5), `quantity`, `price`, `tax_lines[]` |
| `rows[].vatCode` | z `tax_lines[].rate` + země | mapování na `world.vat` kódy; 0 % + `tax_exempt` + EU VAT ID → reverse charge kód |
| `rows[]` doprava | `shipping_lines[]` | jako řádek s vlastní sazbou |
| `currency` | `currency` / `presentment_currency` | D7 |
| `bankAccount` | `channel.own_bank_account` | náš účet |
| `paidAmount` | `total_price` | doklad vzniká jako uhrazený |

**Shipard daně nepočítá znovu.** Sazby a částky přebírá z `tax_lines`
tak, jak je Shopify vyhodnotilo; zdroj pravdy pro DPH je nastavení
Shopify (Settings → Taxes and duties; OSS a VAT-ID validace jsou tam).
Adaptér jen mapuje sazbu + zemi na `vatCode`. Nesouhlas součtů → validace
canonicalu selže, objednávka zůstane ve stavu 20 s chybou — **správně**,
protože rozdíl znamená nesoulad konfigurací, který má vidět člověk.

### Shopify Admin API (shpd volá)

Custom app registrovaná na obchodě, scopes: `read_orders`, `write_orders`,
`write_files`, `write_fulfillments`, `read_fulfillments`, `read_customers`.

| krok | mutace | poznámka |
|---|---|---|
| upload PDF | `stagedUploadsCreate` → HTTP PUT → `fileCreate` | vrátí file GID + CDN URL (URL může být dostupná až po chvíli — poll `fileStatus`) |
| metafields | `metafieldsSet` (`ownerId` = Order GID) | `custom.invoice_pdf` file_reference, `custom.invoice_url` url, `custom.invoice_number` single_line_text_field, `custom.invoice_duzp` date; definice metafieldů založit jednorázově přes `metafieldDefinitionCreate` |
| fulfillment | `fulfillmentOrders` (query) → `fulfillmentCreate` | `trackingInfo {number, company}`, `notifyCustomer: true` |

⚠️ Ověřit názvy a tvar vstupů proti verzi API, na kterou je app pinnutá
(`fulfillmentCreate` nahradil `fulfillmentCreateV2`; fulfillment se váže
na `FulfillmentOrder`, ne na `Order`).

Klient: `Shipard\Module\Economy\Eshop\Shopify\AdminClient` — tenký GraphQL
klient (Guzzle nebo curl), retry na 429/`THROTTLED` podle
`extensions.cost.throttleStatus`, žádná business logika.

### Shopify Liquid (mimo shpd, dokumentovat v `help/`)

Šablona *Shipping confirmation* (Settings → Notifications) dostane blok:

```liquid
{% if order.metafields.custom.invoice_url != blank %}
  <a href="{{ order.metafields.custom.invoice_url }}">Stáhnout fakturu {{ order.metafields.custom.invoice_number }}</a>
{% endif %}
```

⚠️ Ověřit dostupnost `order.metafields` v notification Liquid a chování
typu `url`. Pokud nefunguje → fallback D3b.

## Zákonné náležitosti, které tok musí splnit ⚠️

Sekce je shrnutí pro vývojáře, ne právní stanovisko. Potvrdit s účetní.

**DUZP vs. vystavení.** U úplaty přijaté před dodáním vzniká povinnost
přiznat daň dnem přijetí platby. Doklad vystavený při odbavení proto nese
`vat_duzp = paid_at`, `issue_date = dnes`. Lhůta pro vystavení je 15 dnů
od DUZP — odbavení do 1–2 dnů to plní s rezervou; stav 20 starší než
10 dnů → alert (`economy.eshop.review_overdue`).

**Opravný doklad, ne editace.** Plátce DPH při refundaci vystavuje
opravný daňový doklad; existující faktura se nepřepisuje. Fáze 2.

**Recyklační příspěvek (§ 73 z. 542/2020 Sb.).** Výrobce, distributor
i poslední prodejce elektrozařízení uvádí na dokladu odděleně od ceny
náklady na zpětný odběr — per položka: sazba na ks/kg, množství, výše
za položku, souhrn za doklad. Obecná věta „v ceně je zahrnut příspěvek"
nestačí (stanovisko MŽP). Uvádí se bez DPH, DPH se na něj vztahuje.
Dozor ČOI. Cizí měna: lze přepočítat, ale doklad musí nést kurz
a původní výši v CZK. U baterií se podle jednoho zdroje příspěvek na
dokladu **neuvádí** — ověřit u kolektivního systému, model musí umět
skupinu s `rate` ale bez tisku (flag `print_on_invoice`).

**Reverse charge / OSS.** Pokrývá `docs/accounting.md`. Shopify od 2026
validuje EU VAT ID přes VIES nativně a exempci aplikuje sám (podmínka:
cílová země ≠ země fulfillmentu); Shipard jen mapuje na správný vatCode
a doklad nese text o přenesení daňové povinnosti + obě DIČ.

**Doručení dokladu.** Do 15 dnů od DUZP, forma libovolná — link
v e-mailu stačí, tisk do balíku není nutný.

## Poučení od existujících integrátorů

Prošli jsme CZ vrstvu (Digismoothie/Fakturoid, Shoptet, Pohoda),
středoevropský integrátor MakeItEasy (iDoklad, SuperFaktúra, Fakturownia,
FastBill), EU etalon Sufio (SK, 7 500+ obchodů, Peppol) a německé
WaWi/ERP konektory (JTL-Wawi, Billbee, Xentral, ERPNext). Co převzít:

- **„ERP je vedoucí systém"** — JTL-Wawi to říká doslova: změny se dělají
  jen v ERP, ne v Shopify adminu; stavy a tracking se posílají zpět do
  shopu. Náš D1/„Shipard je master fulfillmentu" je standardní DACH
  architektura, ne naše konstrukce.
- **Shopify je zdroj pravdy pro daňový režim** — Digismoothie i MakeItEasy
  přebírají OSS/reverse-charge flag z objednávky a jen ho předají
  fakturačnímu SW. Nikdo v regionu nepočítá DPH znovu. Platí i pro W3.
- **Reverse charge závisí na fulfillment location, ne na adrese firmy**
  (Sufio): Shopify exempci aplikuje, když je fulfillment lokace v jiné
  zemi než zákazník. DS musí mít v Shopify lokaci v EU.
- **Opravný doklad, ne editace** — Sufio to má jako hlavní odlišení od
  konkurence („většina apps přepíše tu samou fakturu"). Fáze 2.
- **Customer Account UI Extension** — Sufio byla launch partner; link
  „Zobrazit / Stáhnout fakturu" na Orders a Order status page přes
  app block. Náš Fáze 3 návrh je přesně tato cesta.
- **Dva dokumenty, dva okamžiky** (ERPNext connector): sales invoice při
  platbě, delivery note při shipmentu. Alternativa k jedné faktuře při
  odbavení — viz D2.
- **Archivace dokladů** (Billbee, DE GoBD): doklady archivované
  s prokazatelnou neměnností po zákonnou lhůtu. Shipard to má z povahy
  věci; jen ověřit, že PDF z Odbavit se ukládá jako příloha dokladu.
- **E-fakturace přichází** — Sufio podporuje Peppol (BE B2B povinné 2026)
  a DE/FR e-invoicing od 2025. Shipard má `IsdocReader`, ne writer;
  Peppol UBL export je v `exchange-format.md` future work. **Poznámka
  pro roadmapu**, ne Fáze 1.

Z původní CZ rešerše:

- **Pravidla per platební metoda** — okamžik vystavení (při objednávce /
  po platbě / proforma → finální / při vyřízení). Fáze 1 má jen „při
  vyřízení + platba předem"; model kanálu má mít pole `invoice_trigger`
  připravené pro Fázi 2.
- **Zpoždění před vystavením** kvůli apps, které objednávku upravují —
  u nás odpadá (vystavení je ruční akce), ale `orders/updated` se musí
  zpracovávat až do stavu 30.
- **Skip tag** na objednávce (`shpd-no-invoice`) — testovací a interní
  objednávky bez dokladu.
- **Ruční retry** — Odbavit je idempotentní a opakovatelné.
- **Notifikace chyb** — alert, ne tichý log.
- **SKU na dokladu** — u technického sortimentu zásadní pro reklamace.
- **Recyklační příspěvek jako skupina → produkt → doklad → export** —
  Shoptet i Pohoda mají identický model; přebíráme.
- **Špatně nastavené DPH v e-shopu** je podle Digismoothie nejčastější
  příčina neúspěšného vystavení — validace canonicalu to má hlásit
  srozumitelně (mapování `tax_lines` → vatCode selhalo: sazba X pro zemi Y
  nemá kód).

## Task breakdown

- **W1 — modul `economy.eshop`**: skeleton, `module.jsonc`, tabulky
  `channels` / `orders` / `webhook_log`, Document classes, form kanálu
  (encrypted sloupce), viewer objednávek s `navSection` Prodej, `.md`
  popisy tabulek. *Hotovo když:* `ds-upgrade` projde, kanál lze založit
  přes UI, token se neukládá v plaintextu.
- **W2 — webhook endpoint**: `/_eshop/shopify/webhook`, HMAC, idempotence,
  stavový automat 10→20→90, `orders/updated` merge, alert check. Testy
  s fixture payloady (anonymizované). *Hotovo když:* replay téhož
  webhooku je no-op; nezaplacená objednávka nevzniká ve stavu 20.
- **W3 — adaptér `OrderReader`** → canonical, mapování `tax_lines` →
  vatCode, resolve zákazníka (D4) a položek podle SKU (D5),
  `source.kind = import.shopify`. Testy: fixture objednávky CZ B2C, CZ B2B,
  EU B2B reverse charge, EU B2C OSS. *Hotovo když:* `/_exchange/…/validate`
  vrací 0 issues pro všechny fixture.
- **W4 — recyklační příspěvek** (`economy.items` + extension `docs.core`):
  tabulka skupin, extension položek a řádků, dopočet v `beforeSave`,
  form a viewer skupin v Nastavení. Nezávislé na W1–W3, může jít
  paralelně a **má hodnotu i bez e-shopu**. *Hotovo když:* ručně
  vystavená faktura s položkou ve skupině má vyplněné
  `recycling_fee_*` na řádku a součet v hlavičce.
- **W5 — Shopify `AdminClient`** + `FulfillmentService`: upload, metafields,
  fulfillment, throttling, testy proti mock HTTP. *Hotovo když:* na dev
  obchodě projde celá sekvence a shipping e-mail obsahuje funkční link.
- **W6 — akce Odbavit**: orchestrace W3 → apply → render (D6) → W5,
  idempotence, `last_error`, přechod 20→30, `help/` stránka
  „Odbavení objednávky z e-shopu". *Hotovo když:* selhání v kroku 4
  nechá doklad, opakované Odbavit nevytvoří druhý.
- **W7 — dokumentace**: `docs/eshop.md` (architektura, kontrakty),
  aktualizace `docs/exchange-format.md` (nový sourceKind),
  `help/eshop/*.md`, `help/co-dnes-nejde.md` (refundace, dobírka,
  zákaznický účet).

## Rozhodnutí k designu

- **D1 — kde konektor žije.** (a) modul `economy.eshop` v shpd, webhook
  endpoint v PHP, Admin API klient v PHP; nebo (b) samostatná komponenta
  `shopify-connector` (Python, `docs/services.md`) volající
  `/_exchange/…/apply` a Shopify. **Doporučení: (a).** Odbavení je UI
  akce v shpd nad stavem objednávky, stav tedy musí být v DS tak či tak;
  webhook je obyčejný HTTP endpoint jako `/_mail/incoming`; (b) by
  zdvojilo stav a přidalo démona bez přínosu. (b) dává smysl, až bude
  potřeba polling nebo víc platforem se sdílenou logikou. **Otevřeno.**
- **D2 — DUZP = `paid_at`** u plateb předem; `issue_date` = odbavení. ⚠️
  Potvrdit s účetní. Alternativa (vzor ERPNext): **dodací list** při
  odbavení + faktura s DUZP platby — účetně čistší oddělení dodání a
  platby, ale dva dokumenty pro zákazníka. Předložit účetní obě varianty.
  **Otevřeno.**
- **D3 — doručení dokladu zákazníkovi.** (a) link v Shopify *Shipping
  confirmation* přes order metafield (jeden e-mail, žádná duplicita);
  (b) fallback: shpd pošle vlastní e-mail s PDF přílohou přes
  `MailOutboxService`. Doporučení (a), (b) implementovat jako přepínač
  kanálu `send_invoice_email`. **Otevřeno — závisí na ověření Liquid.**
- **D4 — resolve zákazníka.** Deterministická politika bez UI: match
  podle e-mailu (B2C) resp. IČO/DIČ (B2B) → existující osoba; jinak
  vytvořit novou (`userAction: create`). Adresy z objednávky jako snapshot
  na dokladu, ne update osoby. **Otevřeno.**
- **D5 — resolve položek.** Podle SKU → `economy_items`. Neznámé SKU →
  chyba validace (ne tichá textová řádka), protože bez položky nelze
  dopočítat recyklační příspěvek. Předpokládá, že položky v shpd existují
  (Fáze 3 je bude synchronizovat; do té doby ručně). **Otevřeno.**
- **D6 — tisk faktury: blocker.** Tisková šablona faktury vydané přes
  `RenderClient` zatím není; je to známá a plánovaná práce mimo tento
  task. W6 na ni čeká. Jediný požadavek odsud: šablona musí umět vypsat
  údaje z W4 (recyklační příspěvek per řádek, registrační identifikátory
  podle země dodání).
- **D7 — cizí měna.** Fáze 1 jen CZK (kanál `currency = CZK` jako
  guard). EUR vyžaduje kurz ČNB k DUZP na dokladu + CZK původní výši
  recyklačního příspěvku. **Otevřeno.**
- **D9 — EPR povinnosti per cílová země (mimo shpd, ale ovlivňuje
  konfiguraci kanálu).** Shipping zóny v Shopify by neměly obsahovat
  zemi, kde DS nemá hotové EPR registrace — první zásilka spouští
  povinnost (DE: LUCID před prvním odesláním). Kanál má mít pole
  `allowed_countries` nebo aspoň validaci v Odbavit (země dodání ∉
  registrované země → varování). Přehled pro sousední trhy ⚠️ k ověření
  u poradce:

  | Země | Obaly | EEZ | Baterie | Poznámka |
  |---|---|---|---|---|
  | CZ | EKO-KOM (MŽP seznam osob) | Seznam výrobců MŽP přes kolektivní systém | dtto, přes kolektivní systém | viditelný recyklační příspěvek na dokladu (§ 73) |
  | SK | register MŽP SR + OZV (např. NATUR-PACK) | samostatný register | samostatný register | tři oddělené registry, roční hlášení |
  | DE | LUCID (ZSVR) + smlouva s duálním systémem | Stiftung EAR, WEEE-Reg.-Nr. | Stiftung EAR (BattG/BattDG) | Bevollmächtigter jen pro non-EU; §7 insolvenční garance u B2C EEZ (jen DE); WEEE číslo na fakturách; registrace 1–4 týdny |
  | AT | ARA aj. + EDM portál | EAK přes EDM | EAK přes EDM | struktura jako DE; obalová registrace až 12–14 týdnů; od 2023 zahraniční prodejci zástupce |
  | PL | BDO | BDO — **napřed obaly, pak WEEE** | BDO | zahraniční prodejce pro WEEE/baterie polský zástupce; digitální podpis, polština |

  **Otevřeno — cílové země potvrdí provozovatel DS.**
- **D8 — zařazení do roadmapy.** Nový milník „M5 — Prodejní kanály"
  po M4, nebo mimo milníky jako klientský task? Podle pravidla
  prioritizace se kategorie 4 nezačíná, dokud je otevřená kategorie 1 —
  M0 je uzavřené, M1 aktivní. W4 (recyklační příspěvek) je ale věcná
  správnost dokladu pro každého, kdo prodává elektro — kandidát na
  zařazení dřív než zbytek. **Otevřeno.**

## Zdroje

- Zákon č. 542/2020 Sb., o výrobcích s ukončenou životností — § 73
- Shoptet Blog *Recyklační příspěvek: jak ho uvést na účtenku?*; Shoptet
  Podpora *Recyklační příspěvky*; Portál POHODA *Recyklační příspěvek
  musí být uváděn odděleně*
- Ekolamp *Recyklační příspěvek musí být vidět!* (metodika MŽP)
- Digismoothie Help Center: *Jak nastavit aplikaci Fakturoid pro Shopify*,
  *Pravidla vytváření faktur*, *Chování při změně objednávky*
- Fakturoid Almanach *Fakturace e-shopu krok za krokem (2026)*
- Shopify Admin GraphQL API: `stagedUploadsCreate`, `fileCreate`,
  `metafieldsSet`, `metafieldDefinitionCreate`, `fulfillmentOrders`,
  `fulfillmentCreate`, webhook topics; Shopify Dev: Customer Account UI
  Extensions
