#!/usr/bin/env python3
"""Vygeneruje cfgItem `world.cz.epoCountries` z číselníku Země daňového portálu.

Vstup:  modules/world/cz/data/zeme.txt — export číselníku „zeme" z
        https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/zeme
        (sloupce oddělené `|`, první řádek hlavička, viz data/README.md).
Výstup: modules/world/cz/config/epoCountries.jsonc — pro každý ISO kód
        (kod2, malými písmeny) záznam platný k referenčnímu datu:
        `name` = naz_zeme_c60, `name:en` = naz_zeme_a60,
        `epoName` = naz_zeme_c25 (hodnota atributu `stat` ve větě P podání).

Číselník nese ke kódu i historické a budoucí záznamy (d_pocpl / d_ukopl);
bere se ten platný k datu, při víc platných ten s nejpozdějším začátkem.
Kód bez platného záznamu se vynechá a vypíše na stderr.

    python3 scripts/epo-countries.py [--date RRRR-MM-DD]
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'modules/world/cz/data/zeme.txt'
TARGET = ROOT / 'modules/world/cz/config/epoCountries.jsonc'


def load_rows() -> list[dict[str, str]]:
    with SOURCE.open(encoding='utf-8', newline='') as handle:
        return list(csv.DictReader(handle, delimiter='|'))


def valid_on(row: dict[str, str], date: str) -> bool:
    begins = row['d_pocpl'] or '1900-01-01'
    ends = row['d_ukopl'] or '9999-12-31'
    return begins <= date <= ends


def pick(rows: list[dict[str, str]], date: str) -> tuple[dict[str, dict[str, str]], list[str]]:
    by_code: dict[str, list[dict[str, str]]] = {}
    for row in rows:
        code = row['kod2'].strip().lower()
        if len(code) == 2:
            by_code.setdefault(code, []).append(row)

    chosen: dict[str, dict[str, str]] = {}
    skipped: list[str] = []
    for code, candidates in sorted(by_code.items()):
        valid = [r for r in candidates if valid_on(r, date)]
        if not valid:
            skipped.append(f"{code.upper()} ({candidates[-1]['naz_zeme_c25']})")
            continue
        row = max(valid, key=lambda r: r['d_pocpl'])
        chosen[code] = {
            'name': row['naz_zeme_c60'].strip(),
            'name:en': row['naz_zeme_a60'].strip(),
            'epoName': row['naz_zeme_c25'].strip(),
        }
    return chosen, skipped


def render(countries: dict[str, dict[str, str]], date: str) -> str:
    lines = [
        '// world.cz.epoCountries — číselník Země daňového portálu (Finanční správa),',
        '// zdroj hodnoty atributu `stat` ve větě P podání DPHDP3 / DPHKH1 / DPHSHV:',
        '// popis struktury říká „vkládá se položka naz_zeme_c25" (nejvýš 25 znaků),',
        '// proto je tu vedle běžného názvu i `epoName` přesně v tomto tvaru.',
        '//',
        '// Klíč = ISO 3166-1 alpha-2 malými písmeny (shodně s `world.base.countries`).',
        '//',
        '// **Generovaný soubor** — neupravovat ručně. Zdroj `data/zeme.txt` (export',
        '// číselníku „zeme" z mojedane.gov.cz, viz data/README.md), generátor',
        f'// `scripts/epo-countries.py`, záznamy platné k {date}.',
        '{',
    ]
    for code, entry in countries.items():
        payload = ', '.join(f'{json.dumps(k, ensure_ascii=False)}: {json.dumps(v, ensure_ascii=False)}' for k, v in entry.items())
        lines.append(f'    "{code}": {{ {payload} }},')
    lines.append('}')
    return '\n'.join(lines) + '\n'


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--date', default=dt.date.today().isoformat(), help='referenční datum platnosti (RRRR-MM-DD)')
    args = parser.parse_args()

    countries, skipped = pick(load_rows(), args.date)
    TARGET.write_text(render(countries, args.date), encoding='utf-8')

    print(f'{TARGET.relative_to(ROOT)}: {len(countries)} zemí platných k {args.date}')
    if skipped:
        print('bez platného záznamu (vynecháno): ' + ', '.join(skipped), file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
