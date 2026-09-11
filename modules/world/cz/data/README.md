# Zdrojová data číselníků daňového portálu

Exporty číselníků Finanční správy, ze kterých se **generují** cfgItems modulu
`world.cz`. Soubory tu leží kvůli reprodukovatelnosti a kvůli tomu, že
mojedane.gov.cz je JS aplikace a číselník se odtud nedá stáhnout skriptem.

| Soubor | Číselník | Generuje | Staženo |
|---|---|---|---|
| `zeme.txt` | Země (`zeme`) — https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/zeme | `config/epoCountries.jsonc` přes `scripts/epo-countries.py` | 2026-09-11 |

Formát exportu: sloupce oddělené `|`, první řádek hlavička
(`c_zaznamu|d_pocpl|d_ukopl|kod2|kod3|naz_zeme_c60|naz_zeme_c25|…`). Ke kódu
může být víc záznamů s intervalem platnosti (`d_pocpl` – `d_ukopl`), generátor
bere záznam platný k referenčnímu datu.

## Aktualizace

1. Stáhnout nový export z odkazu výše a přepsat soubor beze změn.
2. `python3 scripts/epo-countries.py` (volitelně `--date RRRR-MM-DD`).
3. Doplnit datum stažení v tabulce a spustit `vendor/bin/phpunit --filter EpoCountries`.

Finanční úřady (`config/taxOffices.jsonc`) a územní pracoviště
(`config/taxOfficeBranches.jsonc`) jsou zatím přenesené ze starého Shipardu
ručně — až budou exporty `ufo` / `pracufo`, patří sem stejným způsobem.
