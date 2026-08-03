# Přejmenování platebních symbolů: `variable_symbol` → `payment_reference`

**Stav:** hotovo

## Kontext

Variabilní symbol je česká instance obecného evropského konceptu **payment reference**
(severský KID/OCR/viitenumero, belgická strukturovaná reference, SEPA RF Creditor
Reference dle ISO 11649, EndToEndId v ISO 20022). Budoucí saldokonto a modul
bankovních výpisů budou párovat právě na tento údaj.

Specifický a konstantní symbol jsou česko-slovenská specifika (SS = doplňková
identifikace plátce, KS = kód typu platby — do párování nepatří). Zůstávají jako
first-class sloupce s českými názvy, stejně jako `ico`/`dic`.

**Žádná konverze dat** — existují jen testovací zdroje dat, po implementaci se
resetují (`ds-reset`) a otestuje se od začátku.

## Návaznost

- Budoucí: saldokonto, modul bankovních výpisů (stejná trojice sloupců na straně
  řádku výpisu), country config (řízení labelů/validace/viditelnosti polí podle země).
- Souběžný PRD v old_shipard: `modules/imports/newShipard/tasks/09-payment-reference-rename.md`
  (DocsRunner produkuje exchange payload — musí jít v lockstepu se změnou schématu).

## Před implementací přečti

- `modules/docs/core/tables/docs_core_heads.jsonc` (řádky ~488–512, group `payment`)
- `modules/docs/core/src/DocDocument.php` (`applyVariableSymbolDefault`, ~ř. 198, 1227)
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` (sekce `payment`, ~ř. 92–100)
- `modules/core/exchange/src/Document/DocumentApplier.php` (~ř. 866–868)
- `modules/core/mail/profiles/default_czech_invoices.jsonc` (prompt_template + output_schema, ~ř. 27, 276–288)

## Scope

**In:**
- Rename sloupce `variable_symbol` → `payment_reference` v tabulce `docs_core_heads`,
  prodloužení na varchar(35).
- Rename pole `payment.variableSymbol` → `payment.paymentReference` v exchange
  formátu `shpd.docs.document.v1` — **zůstáváme na v1**, změna in-place.
- Všechny návazné výskyty: PHP, Svelte, i18n, JSON schémata, AI profil, fixtures,
  testy, dokumentace.

**Out:**
- `specific_symbol` a `constant_symbol` — beze změny (názvy, délky, sémantika).
- Country config (labely podle země) — později.
- Bankovní výpisy / saldokonto — neexistují, nic k úpravě.
- Konverze dat / migrace hodnot — neřeší se.
- Historické `tasks/*.md` — nesahat.

## Co implementovat

### 1. Tabulka `docs_core_heads.jsonc`

Sloupec `variable_symbol` přejmenovat na `payment_reference`, délka **35**
(pokryje RF referenci 25 znaků i EndToEndId 35 znaků):

```jsonc
{
    "id": "payment_reference",
    "name": "Payment reference",
    "name:cs": "Variabilní symbol",
    "name:en": "Payment reference",
    "type": "varchar", "length": 35,
    "nullable": true, "group": "payment"
}
```

Český UI label zůstává „Variabilní symbol" — technický identifikátor je obecný,
label je (zatím natvrdo) český. `specific_symbol` a `constant_symbol` beze změny.

### 2. `DocDocument.php`

- `applyVariableSymbolDefault()` → `applyPaymentReferenceDefault()`, uvnitř klíč
  `variable_symbol` → `payment_reference`. Sémantika beze změny (default
  ze `sequence_number`, jen pokud uživatel nezadal).
- Aktualizovat docblock save pipeline (krok 10).
- Pozor na testovací subclass (`...Pub` metoda v `DocDocumentValidateTest`).

### 3. Formuláře

`->input('variable_symbol')` → `->input('payment_reference')` v:
- `modules/docs/core/src/DocsHeadsFormBase.php` (~ř. 393)
- `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php` (~ř. 149)
- `modules/docs/invoicesOut/src/IssuedInvoiceForm.php` (~ř. 162)

### 4. `DocsHeadsViewer.php`

Meta výstup detailu (~ř. 678): klíč `variable_symbol` → `payment_reference`.

### 5. Exchange schéma `shpd.docs.document.v1` (`.json` i `.jsonc`)

- `payment.variableSymbol` → `payment.paymentReference`, přidat `maxLength: 35`.
- `specificSymbol`, `constantSymbol` beze změny.
- Opravit zmínku v description u `sequenceNumber` („…and variable_symbol default")
  → `payment_reference`.

### 6. `DocumentApplier.php`

Mapování canonical → sloupce:

```php
'payment_reference' => $canonical['payment']['paymentReference'] ?? null,
```

SS/KS řádky beze změny.

### 7. AI profil `default_czech_invoices.jsonc`

Symboly figurují **dvakrát** — obě místa musí být konzistentní:
- `prompt_template`: JSON příklad pro model (`"variableSymbol": "..."` → `"paymentReference": "..."`).
- `output_schema`: pole `variableSymbol` → `paymentReference` (~ř. 276).

**Kritické:** analyzer nic nepřejmenovává — názvy v output_schema musí být identické
s exchange schématem, jinak skončí extrakce prázdným `_rawOutput`. Po nasazení je
nutný reload profilu (CLI `ai-profile-reload` zatím neexistuje → restart analyzeru
nebo ruční reload).

### 8. Frontend

- `frontend/src/components/viewer/DocumentDetail.svelte` (~ř. 30): dvojice
  `['variable_symbol', 'viewer.document.meta.variableSymbol']` →
  `['payment_reference', 'viewer.document.meta.paymentReference']`.
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` (~ř. 366):
  `canonical.payment?.variableSymbol` → `paymentReference`, i18n klíč
  `exchange.preview.field.paymentReference`.
- `frontend/src/i18n/cs.js`: přejmenovat klíče
  `viewer.document.meta.variableSymbol` → `...paymentReference` a
  `exchange.preview.field.variableSymbol` → `...paymentReference`;
  **hodnota zůstává „Variabilní symbol"**.
- `frontend/src/i18n/en.js`: tytéž klíče, hodnota „Payment reference".
- Klíče pro SS/KS beze změny.
- Rebuild frontendu (`public/app/assets/*` je build artefakt, neupravovat ručně).

### 9. Testy a fixtures

- `tests/Unit/Module/Docs/Core/DocDocumentValidateTest.php` — rename metod
  i klíčů (`testApplyPaymentReferenceDefault...`).
- `tests/Unit/Module/Docs/Core/DocsHeadsViewerAccountingTabTest.php`,
  `DocsHeadsViewerDetailTest.php` — klíče v test datech a asercích.
- `tests/Fixtures/Exchange/invoiceReceived_happy.json`,
  `invoiceReceived_ambiguousSupplier.json` — `variableSymbol` → `paymentReference`.

### 10. Dokumentace

- `docs/exchange-format.md` (~ř. 180, 248).
- `docs/docs-mvp.md` (~ř. 954, 1107–1112, 1206, 1725).
- `modules/docs/core/tables/docs_core_heads.md` (~ř. 64).

## Hotovo když

- `grep -rn "variable_symbol\|variableSymbol"` v repu (mimo `tasks/`, `vendor/`,
  `node_modules/`, build artefakty) nevrací nic.
- `bin/shpd-ds ds-reset` + `ds-upgrade` projde, tabulka má sloupec
  `payment_reference` varchar(35).
- PHPUnit projde s úzkým filtrem, např.
  `--filter 'DocDocument|DocsHeadsViewer|DocumentApplier'` (široké filtry
  způsobují timeouty).
- Formulář faktury zobrazuje pole s labelem „Variabilní symbol", hodnota se
  ukládá do `payment_reference`, default ze `sequence_number` funguje.
- Exchange `/validate` + `/apply` projde s aktualizovanou fixture.

## Doporučené pořadí

1. Tabulka (jsonc) + `ds-reset`/`ds-upgrade`
2. PHP backend (DocDocument, formuláře, viewer, DocumentApplier)
3. Exchange schémata + fixtures + testy
4. AI profil
5. Frontend + i18n + rebuild
6. Dokumentace
7. Commity logicky oddělit: schema/backend → exchange+AI → frontend → docs

## Rozhodnutí ✓

- `variable_symbol` → `payment_reference`, varchar(35). Žádný odvozený slepovaný
  sloupec — VS *je* payment reference.
- `specific_symbol` a `constant_symbol` zůstávají beze změny (české koncepty,
  upřímně české názvy). KS do párování nepatří.
- Exchange format zůstává **v1**, rename pole in-place (jen testovací data,
  oba producenti pod kontrolou).
- `applyPaymentReferenceDefault` (default = sequence_number) zůstává globální
  chování; označení za CZ-specifické až s country configem.
- Žádná datová migrace — testovací datasources se resetují.

## Otevřené body

- Country config: labely, validace (CZ: max 10 číslic) a viditelnost polí podle
  země datasource — samostatný budoucí úkol.
- Strana bankovního výpisu: stejná trojice sloupců (`payment_reference` + SS + KS),
  až vznikne modul výpisů — párování pak bude symetrické porovnání.
- `ai-profile-reload` CLI (ve frontě jako `tasks/ai-profile-reload.md`) — do té
  doby reload profilu ručně.
