# ds-setup — Task 10: Nabídka účetních položek

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D18, D19**, §10. Fáze 5 a poslední task oblasti.

## Kontext

`accountingRules.cz.jsonc` má u operace `acc.entry` předpis
`accountSrc: "item"`. `AccountingEngine::resolveItemAccount()` proto
vyžaduje položku typu **Účetní položka** (`item_type = 2`) s vyplněným
`accounting_account`; bez ní přidá hlášku `item_account_missing` a účet
nahradí `??????`. Na čerstvém DS žádná taková položka není, takže kdo
chce z řádku dokladu zaúčtovat přímo na účet — bankovní poplatek, kurzový
rozdíl, zaokrouhlení — nemá co vybrat.

Tenhle task nabídne vygenerování krátké sady takových položek.

**Není to provisioner (D18).** Provisionery jsou idempotentní
a dorovnávají; u nabídky je to špatně, protože smazané položky by
`ds-upgrade` uživateli vracel. Nabídka je **jednorázová akce z panelu**.

**Není to alert (D19).** Nesplněná nabídka není problém a nemá nikdy nic
rozsvítit — ani v checklistu, ani ve feedu. V panelu žije jako samostatná
sekce „Volitelné" pod checklistem.

Pohodlné položky pro fakturaci (typu Služba — „Práce", „Doprava")
**vědomě neděláme**: `docs_core_rows.item` je nullable a účet běžného
řádku se bere z masek podle operace, takže bez nich nic nespadne.

## Cíl

1. Dvě sady účetních položek — pro podnikatelskou a pro neziskovou osnovu.
2. Jednorázová akce v sekci „Volitelné" panelu.
3. Nový `source_kind` pro rozpoznání vygenerovaných položek.

## Závislosti

- Závisí na Tasku 06 (panel) a na rozhodnuté `economy.accountChart` —
  položky se nedají vygenerovat, dokud osnova neexistuje. **První nabídka
  v oblasti, která má předpoklad.**
- Otevírá: nic. Poslední task oblasti `ds-setup`.

## Potvrzená designová rozhodnutí (Anna)

1. **Jen účetní položky** (`item_type = 2`), pohodlné fakturační položky
   ne.
2. **D18** — jednorázová akce, ne provisioner.
3. **D19** — nabídky nejsou alerty, žijí mimo checklist.
4. Konkrétní seznam navrhuje tenhle task; Anna ho odsouhlasí při review.

## Před implementací přečti

- `docs/ds-setup.md` §10
- `modules/economy/accounting/config/accountingRules.cz.jsonc` ~ř. 199 —
  `{"src": "rows", "accountSrc": "item", "sideSrc": "row", "operation": "acc.item"}`
- `modules/economy/accounting/src/AccountingEngine.php` ~ř. 494 —
  `resolveItemAccount()`, konstanta `ITEM_TYPE_ACC_ENTRY = 2`
- `modules/economy/accounting/extensions/economy_items.jsonc` — sloupec
  `accounting_account` je **extension**, ne přímý sloupec `economy.items`
- `modules/economy/items/tables/economy_items.jsonc` — `code` varchar(25)
  **not null** + `unq_code`, `item_kind` **not null**, `unit` **not null**,
  `sales_price_no_vat` nullable
- `modules/economy/items/config/itemKindsSeed.jsonc` — druh se
  `system_code = 'accounting'` má `item_type = 2`
- `modules/economy/items/config/sourceKinds.jsonc` — dnes `manual`,
  `aiExtraction`, `import.oldShipard`, `import.csv`,
  `import.supplierCatalog`
- `modules/core/units/config/unitsSeed.jsonc` — `system_code = 'pcs'`
  („Kus")
- `modules/economy/items/src/ItemDocument.php` — validace a `beforeSave`
- `modules/economy/items/src/ItemKindsProvisioner.php` — vzor čtení seedu
- `src/Api/Controller/SetupController.php`,
  `frontend/src/components/settings/DsSetup.svelte`

## Rozsah

### Dvě seed sady, ne jedna s filtrem

**Tohle je nejdůležitější rozhodnutí tasku.** Nezakládej jednu sadu
a nefiltruj podle toho, jestli účet v osnově existuje — obě osnovy
používají **stejná čísla pro jiné účty**:

| Číslo | Podnikatelská | Nezisková |
|---|---|---|
| `548100` | Ostatní provozní náklady | **Manka a škody** |
| `648100` | Ostatní provozní výnosy | **Zúčtování fondů** |

Filtrování podle existence čísla by v neziskové osnově vyrobilo položku
„Zaokrouhlení" účtující na Manka a škody. Proto **dva soubory** vedle
osnov, které zrcadlí:

`modules/economy/items/config/accountingItemsDefault.jsonc`

| `code` | `name:cs` | účet |
|---|---|---|
| `UP-BANK` | Bankovní poplatky | `568201` |
| `UP-URN` | Úroky placené | `562100` |
| `UP-URV` | Úroky přijaté | `662100` |
| `UP-KZN` | Kurzová ztráta | `563100` |
| `UP-KZV` | Kurzový zisk | `663100` |
| `UP-ZAON` | Zaokrouhlení (náklad) | `548100` |
| `UP-ZAOV` | Zaokrouhlení (výnos) | `648100` |

`modules/economy/items/config/accountingItemsNpo.jsonc`

| `code` | `name:cs` | účet |
|---|---|---|
| `UP-BANK` | Bankovní poplatky | `549100` (Jiné ostatní náklady) |
| `UP-URN` | Úroky placené | `544100` |
| `UP-URV` | Úroky přijaté | `644100` |
| `UP-KZN` | Kurzová ztráta | `545100` |
| `UP-KZV` | Kurzový zisk | `645100` |
| `UP-ZAON` | Zaokrouhlení (náklad) | `549100` |
| `UP-ZAOV` | Zaokrouhlení (výnos) | `649100` |

Dvě položky na týž účet (`549100`) jsou v neziskové sadě v pořádku —
nezisková osnova dedikovaný účet pro bankovní poplatky ani zaokrouhlení
nemá.

**Vědomě vynechané:** mzdy (`521100`), odvody (`524100`, `524200`), daň
z příjmů (`591900`). Účtují se sice taky přes `acc.entry`, ale mzdová
agenda v aplikaci není a seznam by se rozrostl o věci, které uživatel
v prvním týdnu nepotřebuje. Doplnit se dají později přidáním řádku do
seedu.

**Zaokrouhlení je konvence, ne pravidlo.** Ani jedna osnova dedikovaný
účet nemá; `548100`/`648100` (resp. `549100`/`649100`) je pragmatická
volba. Napiš to do komentáře v obou seed souborech, ať to příští čtenář
nebere jako danost.

Formát seedu drž jako u `itemKindsSeed.jsonc`: pole objektů,
`name:cs` / `name:en`, komentář v hlavičce s vysvětlením, co provisioner
doplní.

### Generování

`GET /_setup/accounting-items-offer` — stav nabídky:

```json
{
  "available": true,
  "chartVariant": "default",
  "candidates": [{"code": "UP-BANK", "name": "Bankovní poplatky",
                  "accountNumber": "568201", "exists": false}],
  "unavailableReason": null
}
```

- `economy.accountChart` nerozhodnuté → `available: false`,
  `unavailableReason: 'chart_undecided'`.
- `economy.accountChart = 'none'` → `available: false`,
  `unavailableReason: 'chart_none'`. Bez osnovy není na co účtovat.
- `exists` podle `code` v `economy_items` (kvůli `unq_code`).

`POST /_setup/accounting-items` s `{"codes": ["UP-BANK", "UP-KZN"]}`:

- Zakládej **přes `ItemDocument`**, ne přímým INSERTem — validace
  a `beforeSave` musí proběhnout stejně jako u ručně pořízené položky.
- `item_kind` = druh se `system_code = 'accounting'`. **Když ten druh
  neexistuje, skonči chybou**, negeneruj s jiným druhem —
  `resolveItemAccount()` kontroluje `item_type = 2` a položka se špatným
  druhem by tiše nefungovala.
- `unit` = jednotka se `system_code = 'pcs'`. Sloupec je not null a pro
  účetní položku je jednotka bezvýznamná; `pcs` je nejneutrálnější, co
  seed nabízí.
- `accounting_account` = `id` účtu podle čísla ze seedu. **Účet nenalezen
  → tu položku přeskoč** a vrať ji v odpovědi jako přeskočenou s důvodem;
  nezakládej položku s prázdným účtem, byla by nefunkční.
- `sales_price_no_vat` nechej `null`.
- `docState` jako u běžně pořízené položky (`ItemDocument` rozhodne;
  nekopíruj `docState = 40` z provisioneru osnovy).
- Už existující `code` → přeskoč, ne chyba.
- Odpověď: co vzniklo, co se přeskočilo a proč.

### Nový `source_kind`

Do `modules/economy/items/config/sourceKinds.jsonc` přidej:

```jsonc
"setup.accountingItems": {
    "name": "Setup — accounting items",
    "name:cs": "Nastavení — účetní položky",
    "name:en": "Setup — accounting items"
}
```

Vygenerované položky ho dostanou do `source_kind`, `source_ref` = `code`
ze seedu, `source_imported_at` = teď. Bez toho se nedá rozpoznat, co
uživatel pořídil sám a co přišlo z nabídky — a to je informace, kterou
budeš chtít při první podpoře.

`manual` **nepoužívej**; bylo by to nepravdivé.

### Panel — sekce „Volitelné"

`DsSetup.svelte` dostane pod checklistem a pod sbalenými rozhodnutými
parametry třetí sekci:

- Nadpis „Volitelné" + jedna věta, že nejde o nic chybějícího.
- Karta nabídky: co to je (položky pro účtování bankovních poplatků,
  kurzových rozdílů a zaokrouhlení přímo z řádku dokladu), zaškrtávací
  seznam kandidátů s čísly účtů, tlačítko „Vygenerovat".
- Už existující kandidáty zašedni a nezaškrtávej — stejný vzor jako
  u můstku bankovních účtů v Tasku 09.
- `available: false` → sekci ukaž, ale nabídku nech neaktivní s vysvětlením
  (`chart_undecided` → „nejdřív vyber účtovou osnovu výše"; `chart_none`
  → „bez účtové osnovy nemá nabídka smysl"). **Neschovávej ji** — uživatel
  má vědět, že existuje a proč teď nejde.
- Po vygenerování ukaž souhrn (vzniklo N, přeskočeno M a proč) a nabídku
  překresli. **Nezavírej sekci ani nic neschovávej** — když se něco
  přeskočilo, uživatel to má vidět.

Sekce se **nesmí** promítnout do `items` z `/_setup/checklist` ani do
`SetupChecklist::ORDER`. Je to samostatná část odpovědi, ne položka
checklistu (D19).

### Dokumentace

- `docs/ds-setup.md` — §10 přepiš: body o nabídkách jsou rozhodnuté
  (D18, D19), fakturační položky mimo scope, obsah seedu popsán;
  §8 fázování — Fáze 5 hotová.
- `docs/rest-api.md` — dvě nové routy.
- `modules/economy/items/README.md` — sekce o účetních položkách:
  k čemu jsou (`acc.entry`, `accountSrc: item`), že účet je extension
  z `economy.accounting` a že sadu lze vygenerovat z panelu.
- `docs/accounting.md` — pokud popisuje `acc.entry` / `accountSrc: item`,
  odkaz na nabídku.

## Testy

`SetupControllerTest`:

- `available: false` + `chart_undecided`, když klíč chybí
- `available: false` + `chart_none` u `none`
- kandidáti se berou z **odpovídající** sady podle variantu osnovy —
  ověř explicitně, že `UP-ZAON` míří v `default` na `548100` a v `npo`
  na `549100` (regresní test na tu past se stejnými čísly)
- generování vytvoří položky s `item_type = 2` (přes druh `accounting`),
  vyplněným `accounting_account` a `source_kind = 'setup.accountingItems'`
- účet ze seedu v osnově chybí → položka přeskočena s důvodem, ostatní
  vzniknou
- druh `accounting` chybí → chyba, nic nevznikne
- opakované generování téhož kódu nezduplikuje a není chyba

`AccountingEngine` — regresní test: řádek s operací `acc.entry`
a vygenerovanou položkou se zaúčtuje na správný účet **bez** hlášky
`item_account_missing`. To je jediný test, který doopravdy dokazuje, že
nabídka řeší to, kvůli čemu vznikla.

Frontend: `cd frontend && npm run build` (timeout 90–120 s).

PHP: `vendor/bin/phpunit --filter 'SetupController|Item|AccountingEngine'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS bez rozhodnuté osnovy → sekce „Volitelné" je vidět, nabídka
   neaktivní s vysvětlením.
2. Rozhodni `default` → nabídka aktivní, sedm kandidátů s čísly účtů.
3. Vygeneruj vše → sedm položek, každá s druhem Účetní položka a účtem;
   ověř v DB `source_kind`.
4. Vystav interní účetní doklad s řádkem `acc.entry` a položkou
   „Bankovní poplatky" → zaúčtuje se na `568201`, žádná hláška
   `item_account_missing`.
5. Otevři nabídku znovu → všech sedm zašedlých.
6. Druhý DS s osnovou `npo` → kandidáti mají neziskové účty
   (`549100`, `544100`, …), ne podnikatelské.
7. DS s osnovou `none` → nabídka neaktivní s druhým důvodem.

## Hotovo když

- [ ] Nabídka je v panelu v sekci „Volitelné", mimo checklist a mimo feed
- [ ] Kandidáti odpovídají variantě osnovy, ne shodě čísel
- [ ] Vygenerované položky fungují v `acc.entry` bez hlášky
- [ ] Vygenerované položky jsou rozpoznatelné podle `source_kind`
- [ ] Nedostupná nabídka je vidět i s důvodem
- [ ] Opakované generování je bezpečné
- [ ] `npm run build` prochází, PHP testy zelené
- [ ] `docs/ds-setup.md` §8 a §10 uzavřené

## Pasti / na co pozor

- **Stejná čísla, jiné účty.** `548100` je v podnikatelské osnově Ostatní
  provozní náklady, v neziskové **Manka a škody**; `648100` je Ostatní
  provozní výnosy versus **Zúčtování fondů**. Jedna sada s filtrem podle
  existence čísla je proto **chyba**, ne optimalizace. Dvě sady, každá
  vedle své osnovy.
- **Ne provisioner.** Kdyby to skončilo v `ds-upgrade`, smazané položky
  by se uživateli vracely. Nabídka je jednorázová akce (D18).
- **Ne alert.** Žádný check, žádná položka v `SetupChecklist::ORDER`,
  žádná karta ve feedu (D19). Nesplněná nabídka není problém.
- **Zakládej přes `ItemDocument`.** Přímý INSERT obejde validaci
  a `beforeSave`; položka z nabídky má být nerozeznatelná od ručně
  pořízené — až na `source_kind`.
- **`accounting_account` je extension.** Sloupec přidává
  `modules/economy/accounting/extensions/economy_items.jsonc`. Pokud
  `economy.accounting` není aktivní modul, sloupec neexistuje — ověř to
  a nabídku v tom případě neaktivuj (stejný vzor jako `chart_none`).
- **`unit` a `item_kind` jsou not null.** Chybějící druh `accounting`
  musí být hlasitá chyba; položka s jiným druhem by v `resolveItemAccount()`
  tiše neprošla, protože se kontroluje `item_type = 2`.
- Nepřidávej mzdové ani daňové položky „když už u toho jsme". Seznam je
  vědomě krátký a rozšíření je jeden řádek v seedu.
