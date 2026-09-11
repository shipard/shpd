# Referenční soubory zlatého testu (zdroj 689089)

Sem patří XML, která se za zdroj **doopravdy podala** ze starého Shipardu —
`GoldenFilingXmlTest` proti nim porovnává, co dnes vygeneruje Shipard nový
(#55 X9). Bez nich se test přeskočí.

## Co sem dát

Přiznání za 01–04/2026 a kontrolní hlášení za 01/2026.

## Jak soubory pojmenovat

Přesně tak, jak soubor pojmenuje Shipard — z názvu si test najde odpovídající
podání na zdroji dat:

```
DPHDP3-<dic>-<rok>-<MM|Qn>[-<druh>-<pořadí>].xml
```

- `<dic>` — číselná část DIČ (bez `CZ`),
- `<MM>` měsíc dvojmístně (`01`), `Qn` čtvrtletí (`Q3`),
- `<druh>` a `<pořadí>` jen u jiného než řádného podání
  (`corrective`, `supplementary`, `subsequent`).

Příklad: `DPHDP3-12345678-2026-01.xml`, `DPHKH1-12345678-2026-01.xml`.

## Co se porovnává

`EpoXmlDiff` porovnává **věty a atributy po normalizaci** — na pořadí
atributů, pořadí řádků v sekci, na zápisu čísla ani na tom, jestli je nula
vypsaná nebo vynechaná, nezáleží (EPO bere chybějící hodnotu jako nulu).
Neporovnávají se atributy, které se legitimně liší: `nazevSW`, `verzeSW`,
`d_poddp`, `sest_*`, `c_telef`, `email`.

Zlatý test k tomu přidává tolerance proti tomu, jak podával starý Shipard
(`GoldenFilingXmlTest::comparisonOptions()`, odvozené z mapovací
konfigurace):

- **odpočet v plné výši + krácený** (ř. 40–48) se porovnává jako součet
  proti plnému sloupci podaného — starý všechno sléval do plného, nový dělí
  dle § 76 (rozhodnutí F3-6 (a) v `tasks/vat-filing-xml.md`);
- **ř. 52** (`odp_uprav_kf`, `koef_p20_nov`) se neporovnává — starý ho
  nepodával;
- **`id_dats`** — ze struktury DPHKH1 zmizel (verze 03.01.14), podané KH ho
  nese ze starší verze.

## Data v repozitáři

Soubory jdou do veřejného repozitáře — obsahují jen to, co v podání být
musí (DIČ firmy je veřejný údaj, částky jsou souhrny za období). Jméno
a telefon toho, kdo výstup sestavil, se **neporovnávají**, takže je
z referenčních souborů před uložením smažte.

Jak se spouští: viz doc-comment `GoldenFilingXmlTest`.
