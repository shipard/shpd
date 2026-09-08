# Tabulka: economy_vat_filing_rs_rows

Podání DPH — **výstupní řádky souhrnného hlášení DPHSHV** (issue #55,
rozhodnutí D15/2). Agregace per (kód plnění, DIČ odběratele): počet plnění
a hodnota. Souhrnné hlášení se podává celé (řádné / následné).

Systémová tabulka bez docStates — zapisuje výhradně `FilingComposer`.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `filing` | int, reference `economy_vat_filings` | Podání |
| `kod` | tinyint | Kód plnění: 0 zboží, 3 služby |
| `partner_vat_id` | varchar(20) | DIČ odběratele |
| `count` | int default 0 | Počet plnění (= počet řádků rekapitulace) |
| `value` | numeric(15,2) | Hodnota — přesně |
| `value_filed` | numeric(15,2) | Hodnota — podáno |

## Indexy

- `idx_filing_kod_vatid` **unique** na `filing, kod, partner_vat_id`

## Pravidla

- Podaná hodnota je zaokrouhlená **nahoru** na celé Kč (`ceil`), věrně dle
  starého Shipardu (`VatRSReport`: `sumBaseRounded = ceil(sumBase)`) —
  ne `round`. Zadání Fáze 2 uvádělo `round()`; rozhodl referenční kód
  a podaná XML.
- Chybějící DIČ odběratele je měkká chyba (`economy_vat_filings.messages`),
  řádek se agreguje pod prázdné DIČ.

## Související

- [economy_vat_filings](economy_vat_filings.md) — hlavička podání
- [RecapitulativeStatementCalculator](../src/RecapitulativeStatementCalculator.php) — agregace
