# Modul: docs.invoicesOut

Modul pro **Faktury vydané** (`doc_type = 'invno'`). Polymorfní subclass nad
`docs.core`.

## Účel

Specializovaný typ dokladu — faktura, kterou vystavujeme zákazníkovi.
Klíčové rozlišení: my jsme dodavatel (snapshot supplier), partner je
odběratel (snapshot customer). Trade direction = 1 (output) — viz cfgItem
`docs.core.docTypes`.

## Co modul přidává

- **Document třída** `IssuedInvoiceDocument extends DocsHeadsDocument` —
  per-typ validace (bank_account povinný při Potvrzení) + budoucí rozšíření
  (cashflow integrace, splátkový kalendář, …)
- **Viewer** `IssuedInvoicesViewer extends DocsHeadsViewer` — viewer v hlavní
  navigaci s fixním filtrem `doc_type = 'invno'` přes synthetic filter
  `_doc_type`. `getNewRecordDefaults()` vrací `{doc_type: 'invno'}`, takže
  formulář při kliku „Přidat“ ví, že má předvybrat řadu typu invno.
- **Polymorfní registrace** v `documentClasses` — typeColumn dispatch
  zaroutuje doklad s `doc_type = 'invno'` na `IssuedInvoiceDocument`.
  Registrace se merge-uje s `docs.core` (defaultClass + typeColumn) a
  `docs.invoicesIn` (`invni`) přes `DocumentLoader::mergeDocumentClasses`.

## Co modul NEpřidává

- Žádné nové tabulky — všechny doklady leží v `docs_core_heads`
- Žádné nové cfgItems — typy dokladů jsou v `docs.core.docTypes`
- Žádné nové forms — používáme `DocsHeadsForm` z `docs.core`

## Vztah k `docs.invoicesIn`

Symetrický modul pro **Faktury přijaté** (`doc_type = 'invni'`,
`trade_dir = 2` = input). Oba moduly mají stejnou strukturu, liší se jen
v doc_type a per-typ validacích.
