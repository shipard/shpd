# Tabulka: economy_vat_filing_return_rows

Podání DPH — **výstupní řádky přiznání DPHDP3** (issue #55, rozhodnutí
D15/2). To, co šlo (nebo půjde) do XML: u každého řádku formuláře přesná
hodnota z kalkulátoru i **podaná** hodnota po zaokrouhlení.

Systémová tabulka bez docStates — zapisuje výhradně `FilingComposer`.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `filing` | int, reference `economy_vat_filings` | Podání |
| `row` | smallint | Číslo řádku formuláře |
| `is_computed` | boolean default 0 | 1 = dopočtený řádek (46, 52, 62–66), 0 = sumace z dokladů |
| `base` | numeric(15,2) | Základ — přesně |
| `tax_full` | numeric(15,2) | Daň / plný odpočet — přesně |
| `tax_reduced` | numeric(15,2) | Krácený odpočet — přesně |
| `base_filed` | numeric(15,2) | Základ — podáno |
| `tax_full_filed` | numeric(15,2) | Daň / plný odpočet — podáno |
| `tax_reduced_filed` | numeric(15,2) | Krácený odpočet — podáno |

## Indexy

- `idx_filing_row` **unique** na `filing, row`

## Pravidla zaokrouhlení (D17, `FilingRounding`)

Zaokrouhlení je pravidlo **podání**, ne živého reportu — živý report
zůstává přesný. Převzato ze starého Shipardu a ověřeno proti podaným XML:

- každý řádek se zaokrouhlí **samostatně** na celé Kč (`roundingUnit: 1`);
- dopočty **46 / 62 / 63 se počítají ze zaokrouhlených řádků**, ne
  zaokrouhlením přesného součtu — v tom je celý smysl pravidla, součet
  zaokrouhlených se od zaokrouhleného součtu běžně liší o jednotky Kč;
- ř. 52 = `round(Σ krácený odpočet × zálohový koeficient)`;
- ř. 64 / 65 = 62 − 63 podle znaménka (vlastní daň / nadměrný odpočet).

U **dodatečného** přiznání (`supplementaryMode: diff`) jsou podané hodnoty
rozdílem zaokrouhlených řádků proti `previous_filing`, ř. 64/65 jsou
nulové a změnu daňové povinnosti nese **ř. 66**.

## Související

- [economy_vat_filings](economy_vat_filings.md) — hlavička podání
- [VatReturnCalculator](../src/VatReturnCalculator.php) — přesný výpočet (týž jako živý report)
