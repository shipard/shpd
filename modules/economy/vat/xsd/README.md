# XSD schémata podání DPH (EPO)

Oficiální XML schémata Finanční správy pro elektronická podání DPH. Slouží
jako **zdroj pravdy pro strukturu** generovaných souborů (#55 Fáze 3) a jako
validátor v testech — proto leží v repozitáři a CI je nestahuje ze sítě.

| Soubor | Písemnost | Popis struktury |
|---|---|---|
| `dphdp3_epo2.xsd` | `DPHDP3` — přiznání k DPH | [popis](https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHDP3) |
| `dphkh1_epo2.xsd` | `DPHKH1` — kontrolní hlášení | [popis](https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHKH1) |
| `dphshv_epo2.xsd` | `DPHSHV` — souhrnné hlášení | [popis](https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHSHV) |

- **Zdroj:** `https://adisspr.mfcr.cz/adis/jepo/schema/<soubor>`
- **Staženo:** 2026-09-10, beze změny obsahu (žádné ruční úpravy)
- **Verze popisu struktury:** DPHDP3 03.01.03 (9. 3. 2026)
- **Kontrolní součty (MD5):**
  `0bf651b2f0b12d554f6149715fa0b00f` dphdp3_epo2.xsd ·
  `1145c9bd07648cde1c09c232dab2b3b5` dphkh1_epo2.xsd ·
  `b09e39ac01863e6f01ff99bc02e9c06a` dphshv_epo2.xsd

Číselníky, na které se popis odkazuje (NACE, finanční úřady, územní
pracoviště, země), jsou na
`https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/{okec,ufo,pracufo,zeme}`;
v Shipardu žijí jako cfgItems (`world.cz.taxOffices`,
`world.cz.taxOfficeBranches`, `world.cz.epoCountries` — generovaný
z exportu číselníku Země, viz `modules/world/cz/data/README.md`).

## Co schéma ohlídá a co ne

Validace proti XSD je **tenká síť**: kontroluje jména vět a atributů, jejich
povinnost, počty výskytů, délky a číselné rozsahy. Hodnoty jednoznakových
kódů (`dapdph_forma`, `typ_platce`, `khdph_forma`, `shvies_forma`, `zdph_44`,
`pomer`, `kod_rezim_pl`) jsou v XSD jen `maxLength=1` bez výčtu — jejich
správnost hlídá až validace podání (`FilingXmlValidator`) a číselníky
v `config/filingHeaderCz*.jsonc`.

Atributy `dokument` a `k_uladis` jsou v XSD `fixed` (`DP3`/`KH1`/`SHV`
a `DPH`), takže jinou hodnotu schéma odmítne.

## Aktualizace

Finanční správa vydává nové verze popisu struktury k začátku roku. Postup:

1. stáhnout soubor z URL výše (beze změn) a přepsat ten v repozitáři,
2. aktualizovat datum, verzi a MD5 v této tabulce,
3. spustit `vendor/bin/phpunit --filter 'VatXml'` — `VatXmlMappingCompletenessTest`
   ohlásí každý atribut, který ve schématu přibyl nebo zmizel proti mapovací
   konfiguraci `config/vat-xml-cz.jsonc`,
4. doplnit mapování a případně novou `version` schématu hlavičky
   (`config/filingHeaderCz*.jsonc`) — viz `docs/structured-fields.md` § 4.
