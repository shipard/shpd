# Modul: docs.invoicesIn

Modul pro **Faktury přijaté** (`doc_type = 'invni'`). Polymorfní subclass nad
`docs.core`.

## Účel

Specializovaný typ dokladu — faktura, kterou nám vystavil dodavatel. Klíčové
rozlišení: my jsme odběratel (snapshot customer), partner je dodavatel
(snapshot supplier). Trade direction = 2 (input) — viz cfgItem
`docs.core.docTypes`.

## Co modul přidává

- **Document třída** `ReceivedInvoiceDocument extends DocsHeadsDocument` —
  per-typ validace (povinné bankovní spojení dodavatele při Potvrzení)
  + budoucí rozšíření (kontrola DIČ pro intracom faktury, atd.)
- **Viewer** `ReceivedInvoicesViewer extends DocsHeadsViewer` — viewer
  v hlavní navigaci s fixním filtrem `doc_type = 'invni'` přes synthetic
  filter `_doc_type`. `getNewRecordDefaults()` vrací `{doc_type: 'invni'}`,
  takže formulář při kliku „Přidat“ ví, že má předvybrat řadu typu invni.
- **Polymorfní registrace** v `documentClasses` — typeColumn dispatch
  zaroutuje doklad s `doc_type = 'invni'` na `ReceivedInvoiceDocument`.
  Registrace se merge-uje s `docs.core` (defaultClass + typeColumn) a
  `docs.invoicesOut` (`invno`) přes `DocumentLoader::mergeDocumentClasses`.

## Co modul NEpřidává

- Žádné nové tabulky — všechny doklady leží v `docs_core_heads`
- Žádné nové cfgItems — typy dokladů jsou v `docs.core.docTypes`
- Žádné nové forms — používáme `DocsHeadsForm` z `docs.core`

## Vztah k `docs.invoicesOut`

Symetrický modul pro **Faktury vydané** (`doc_type = 'invno'`,
`trade_dir = 1` = output). Oba moduly mají stejnou strukturu, liší se jen
v doc_type a per-typ validacích.
