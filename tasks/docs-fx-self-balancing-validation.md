# cmnbkp: FX operace v kontrole vyrovnanosti při uložení

**Stav:** hotovo

> **Status:** implementováno (2026-07-22), zbývá re-run docs fáze
> migrace v old_shipard · **Modul:** docs.accountingDocs
> **Návaznost:** `docs-wave-d-validation-fx.md` (D12), old_shipard
> task 22; nález třetího re-importu 2026-07-22 (3 DS A + ~170 DS B
> FX dokladů selhává na `_form: Doklad není vyrovnaný`)

## Kontext

Kontrola vyrovnanosti cmnbkp při uložení (`AccountingDocument`,
„Má dáti X ≠ Dal Y") sčítá strany z řádků (`acc_side`). Řádek FX operace
(`acc.fxLoss*/fxGain*`) ale stranu nenese — **obě strany účtuje až
předpis** (dva kroky per operace, viz D12) a řádek je z konstrukce
samovyvažující. Kontrola ho dnes počítá jednostranně → doklad s jediným
FX řádkem hlásí `MD 0 ≠ DAL X` a uložení selže. Integrační test to
nechytil (vkládá řádky přímo, obchází save validaci); dry-run migrace
neapplikuje.

## Scope

1. `modules/docs/accountingDocs/src/AccountingDocument.php` (kontrola
   vyrovnanosti): řádky s operací, jejíž **kroky předpisu pokrývají obě
   strany** (FX čtveřice), započítat do MD i DAL stejnou částkou —
   ideálně obecně (dotaz do předpisu/vlajka operace, ne hardcode výčtu;
   navrhnout dle struktury kódu — např. vlajka `selfBalancing: 1`
   v `rowOperations.jsonc` na FX čtveřici, kterou čte validace).
2. Test: save cmnbkp s jediným FX řádkem projde; save s jednostranným
   `acc.record` bez protistrany dál selhává (kontrola se nesmí
   otupit).
3. Rozšířit save-cestu i do integračního testu FX (apply → save →
   accounting), ať mezera test × apply nezůstane.

## Implementace (2026-07-22)

- Vlajka `selfBalancing: 1` na FX čtveřici v `rowOperations.jsonc`;
  čte ji `DocRowOperationRules::isSelfBalancing()`.
- `AccountingDocument::validateBalance`: self-balancing řádek se počítá
  do MD i DAL stejnou částkou, `acc_side_required` se přeskočí a uložený
  `acc_side` ignoruje (migrace ho posílá ze zdroje). `sumTotals` počítá
  self-balancing řádek jednou do Σ MD bez ohledu na `acc_side`.
- Bonus nález: `DocumentApplier::transformRows` detekoval kontační řádek
  jen podle přítomnosti `accSide` — FX řádek bez něj dostal
  `price_calc_mode: 0` a `calculateRowPrice` mu vynuloval částku.
  Detekce rozšířena o vlajku `rowSide` operace.
- Parity guard `RowOperationsSelfBalancingParityTest`: vlajka ⇔ kroky
  předpisu s fixními stranami pokrývají MD i DAL (obousměrně, všechny
  docTypes).
- Integrační testy `AccountingDocumentImportTest` (apply → save 40 →
  zaúčtování): FX řádek bez `accSide` i s migračním `accSide: credit`;
  bez fixu reprodukují přesně produkční `unbalanced`.
- Rebuild cfg (`ds-upgrade`) na 4l3j, btpg i 4dnh.

## Hotovo když

- [x] Doklad s jediným FX řádkem se uloží a zaúčtuje (MD = DAL).
- [x] Jednostranné kontace dál validaci neprojdou.
- [ ] Po re-runu docs fáze: 3 DS A + ~170 DS B FX dokladů
      importováno, cmnbkp počty = zdroj.
