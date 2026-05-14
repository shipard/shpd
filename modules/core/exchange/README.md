# core.exchange

Backend modul implementující **kanonický výměnný formát** pro doklady
(`shpd.docs.document.v1`) — JSON strukturu, kterou lze validovat,
vizualizovat, ukládat do DB, importovat z cizích zdrojů (AI analyzer,
ISDOC, ERP) a exportovat.

Plná specifikace formátu a apply pipeline:
**[docs/exchange-format.md](../../../docs/exchange-format.md)**.

Modul je čistě servisní vrstva — žádné vlastní tabulky, žádné viewers
ani formy. Vystavuje 3 REST endpointy.

## Architektura

```
ExchangeController                    (src/Api/Controller/)
  └─→ DocumentApplier                 (Document/)
       ├─→ SchemaValidator            (Schema/)             ← opis/json-schema
       ├─→ PartyResolver, ItemResolver, UnitResolver,
       │   VatCodeResolver, BankAccountResolver             (Resolve/)
       ├─→ DocumentValidator          (Document/)
       └─→ TransactionlessTableGateway                      (Document/)
            └─→ DocDocument (existing)                      (modules/docs/core/src/)
```

Applier vlastní outer transakci (MariaDB nedoporučuje nested `START TRANSACTION`).
Side-creates → save → lineage update probíhají atomicky pod jedním `$db->begin/commit`.

## REST API

Tři endpointy pod `/api/v1/_exchange/docs/document/`:

| Method | Path | Účel |
|---|---|---|
| POST | `/validate` | Statická validace (schema + povinná pole + totals) |
| POST | `/preview` | Validate + plný resolve referencí. **Žádné DB writes.** |
| POST | `/apply` | Validate + resolve + reconcile + uložit (transakční) |

### Error response shape

```json
{
  "success": false,
  "error": {
    "code": "unresolved_required",
    "message": "Reference „supplier“ vyžaduje rozhodnutí (userAction).",
    "details": {
      "canonical": { /* enriched canonical s _resolve.issues */ }
    }
  }
}
```

Kódy chyb: `schema_invalid` (400), `validation_failed` (422),
`unresolved_required` (422), `conflict` (409), `internal_error` (500).

## Curl příklady

> V dev módu (server.json `mode=development`) jde proxy přes prefix
> `/<ds-id>/` a Host musí být IP adresa. V produkci se DS resolvuje
> podle Host hlavičky.

### `/validate` — minimální payload

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{"format":"shpd.docs.document","formatVersion":"1.0","docType":"invoiceReceived","dates":{"issueDate":"2026-04-15"},"supplier":{"name":"X"},"rows":[{"rowKind":"item"}]}' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/docs/document/validate
```

Odpověď: `{"success":true,"data":{"canonical": {...enriched...}, "savedDocId":null}}`

### `/preview` — vrátí `_resolve` s navrženými matches

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d @tests/Fixtures/Exchange/invoiceReceived_happy.json \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/docs/document/preview
```

V odpovědi je `data.canonical._resolve.supplier.status` jeden ze
`matched | ambiguous | canCreate | notFound`. Klient na základě toho
vyplní `_resolve.supplier.userAction` (jednu z: `useExisting:<id>`,
`create`, `skip`) a pošle do `/apply`.

### `/apply` — uložení dokladu s vytvořením neexistujícího partnera

```bash
# Pošli canonical, ve kterém pro každou neresolved referenci je
# vyplněn _resolve.<klíč>.userAction. Pro draft (docState=10) stačí
# Applieru jen vlastní firma (is_own=1) v base_persons_persons.

curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{
    "format":"shpd.docs.document",
    "formatVersion":"1.0",
    "source":{"kind":"manual","extractedAt":"2026-05-14T10:30:00Z"},
    "docType":"invoiceReceived",
    "docNumber":"SUPP-2026-001",
    "selfParty":"customer",
    "supplier":{
      "name":"New Vendor s.r.o.","country":"CZ","companyId":"12349999",
      "bankAccount":{"iban":"CZ65...","accountNumber":"123/0100","currency":"CZK"}
    },
    "dates":{"issueDate":"2026-04-15","accountingDate":"2026-04-15","taxPointDate":"2026-04-15"},
    "currency":"CZK",
    "vat":{"mode":"fromBase","place":"domestic","registrationCountry":"CZ"},
    "payment":{"method":"bankTransfer"},
    "rows":[{
      "rowKind":"item","orderPos":1,
      "item":{"name":"Konzultace","supplierCode":"KONZ-1"},
      "unit":"h","quantity":10,"unitPrice":1000,"totalPrice":10000,
      "priceCalcMode":"fromUnitPrice",
      "vat":{"code":"cz-110","pct":21}
    }],
    "_resolve":{
      "supplier":{"userAction":"create"},
      "supplierBank":{"userAction":"create"},
      "rows":[{"item":{"userAction":"create"}}]
    },
    "applyOptions":{"targetDocState":10}
  }' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/docs/document/apply
```

V odpovědi:
- `data.savedDocId` — id nového záznamu v `docs_core_heads`
- `data.canonical._resolve.supplier.status = "matched"` s nově přiřazeným `matchedId`
- `data.canonical._resolve.summary.status = "ok"` (pokud žádné `notFound`)

## docType mapping

Canonical používá popisné názvy (`invoiceReceived`, `invoiceIssued`).
Applier je při transformu mapuje na interní krátké kódy v cfgItem
`docs.core.docTypes` (`invni`, `invno`). Krátký kód lze poslat i přímo.

## Currency case

Canonical: uppercase ISO 4217 (`"CZK"`, `"EUR"`). Interní sloupec
`docs_core_heads.doc_currency` je lowercase (`"czk"`). Mapování dělá
Applier v transform fázi — resolvery a validátory pracují s canonical
formátem.

## Stav (M1–M4 — Fáze 1)

- ✅ DB schema: `economy_items` (sku/ean), `economy_items_supplier_codes`,
  `docs_core_heads` (partner_doc_number + lineage skupina), cfgItem
  `docs.core.sourceKinds`
- ✅ JSON Schema (`shpd.docs.document.v1.{json,jsonc}`) + opis/json-schema validátor
- ✅ 5 resolverů: Party, Item, Unit, VatCode, BankAccount
- ✅ DocumentApplier s plnou pipeline §10 (validate → resolve → reconcile → side-creates → transform → save → lineage)
- ✅ REST endpointy `/validate`, `/preview`, `/apply`
- ✅ PHPUnit pokrytí (51 unit testů + 5 integration testů)

## Limity Fáze 1

- **Fuzzy matching** pro `PartyResolver` step 4 je jen `LIKE %name%` —
  Levenshtein/trigram score přijde v iteraci.
- **Schema kompilace JSONC → JSON** je ruční. Drift hlídá
  `SchemaDriftTest`. CLI příkaz `shpd-server compile-schemas` je
  follow-up.
- **Attachment re-link** z `extracted_doc` na nový doc_head je SQL UPDATE
  v Applieru s `TODO` — `AttachmentService` API pro re-link přijde,
  až bude víc volajících.
- **Update existujícího dokladu** přes `/apply` není v Fázi 1; jen create.
- **Batch apply** (víc dokladů v jednom requestu) není v Fázi 1.

## Navazující fáze

- **Fáze 2** — napojení AI analyzeru: AnalyzerProvisioner přepíše prompt
  tak, aby produkoval canonical, a `core_mail_extracted_documents.apply`
  flow zavolá Exchange `/apply` automaticky.
- **Fáze 3** — frontend náhled canonical dokumentu s `_resolve` interakcí
  (Svelte komponenta).

## Související

- [docs/exchange-format.md](../../../docs/exchange-format.md) — kanonická specifikace
- [tests/Fixtures/Exchange/](../../../tests/Fixtures/Exchange/) — fixture JSON soubory
- [tests/Integration/Exchange/](../../../tests/Integration/Exchange/) — E2E test
