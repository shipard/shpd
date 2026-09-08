# Task E: Opravy po prvním importu pokladny — tranzitní účty, archivované pokladny, zálohy na pokladních dokladech

**Stav:** hotovo — 2026-09-07, 4 commity na `stable` (TransitAccountsProvisioner;
archivovaná pokladna v provisioneru řad + applieru; zálohy na `cash`; dokumentace).
Upřesnění proti zadání (David 2026-09-07): odpočet zálohy
(`sale/purchase.advanceDeduction`) se na pokladním dokladu chová **stejně jako na
faktuře** — položkový řádek **s DPH**; „bez DPH“ platí jen pro nové
`advance.received/given`. Partner záloh vynucuje nová vlajka `partnerRequired`
(identityRequired by chtěl i VS). Viewer/form řady 70 nenabízely už dříve (bez změny
kódu). Zbývá: `ds-upgrade` na `btpg`/`e8w1`, stará strana task 32, reimport a kontrola
`is_error` (poslední bod „Hotovo když“).
**Issue:** #59 — komentář „Reimport 689089 → btpg-p a 732084 → e8w1-i (2026-09-07)"
**Návaznost:** vyžaduje hotové Task A–D. Stará strana: `old_shipard`
`modules/imports/newShipard/tasks/32-cash-import-fixes.md` (mapování
`accounting_account` pokladen a záloh) — nasazuje se **po** této straně.

## Kontext (nálezy z reimportu 689089 → `btpg-peg5-b0tr-chln`)

Prodejky sedí přesně. Pokladna má tři systémové problémy na nové straně:

1. **Účty 261100 / 261400 v migrovaném rozvrhu neexistují** → 8 152 + 10 954
   chybových řádků deníku (`is_error`, maska místo účtu) + 4 966 na bankovních
   `transfer.*`. `AccountChartProvisioner` pro `skipProvisioning` DS neběží; 689089 má
   staré `261001/261002`. Clearing 261200/261300 problém nemá — provisionuje ho
   `ClearingInfrastructureProvisioner` bezpodmínečně.
2. **Archivovaná pokladna** (stará 9000 → nová 70) — applier ji nenajde
   (`ACTIVE_STATES`), provisioner řady zakládá jen pro stav 40 → 7 dokladů
   `cash_desk_not_found`. Historické doklady na archivované pokladně jsou legitimní.
3. **Zálohy na `cash`** — 287 dokladů (689089) (používané dodnes) bez pohybu; nová strana
   pohyby `*.advance*` na `cash` nemá. Žádný z dokladů nemá řádek s DPH, jde o
   pokladní zůstatek a saldo záloh.

(Čtvrtý problém — pokladny bez `accounting_account`, 32 587 řádků na `211???` —
je čistě na staré straně, task 32.)

Před implementací **přečti**: `modules/economy/accbal/src/ClearingInfrastructureProvisioner.php`
(+ jeho zapojení v `DsUpgradeCommand`), `modules/docs/core/src/BoundNumberSeriesProvisioner.php`,
`DocumentApplier::resolveCashDeskIdByCode` / `resolveBoundNumberSeries` /
`isImportMode`-ekvivalent (`applyOptions.importNumber`), `rowOperations.jsonc`
(`sale.advanceDeduction`, `purchase.advanceDeduction`, `payment.*`),
`accountingRules.cz.jsonc` (kategorie `advances.received/given`, bloky `invno`/`invni`
s `reverseSign`), `AccountingEngine` — jak se účtuje **záporný** řádek
(vratky, D9) — tuhle konvenci převezmou záporné zálohy.

## Rozhodnutí

- **E1** 261100 a 261400 jsou infrastruktura: provisionují se bezpodmínečně (i pro
  migrované DS), idempotentně podle čísla, stejně jako clearing. `keepOnReset`.
- **E2** Archivovaná pokladna (70) dostane řady ve stavu 70; import (`importNumber`)
  ji i její řady přijme; živé založení dokladu na ní není možné (řady 70 se ve
  vieweru ani formuláři nenabízí).
- **E3** Zálohy na `cash`: existující `sale.advanceDeduction` (příjem) a
  `purchase.advanceDeduction` (výdej) + nové `advance.received` (příjem, 211/324) a
  `advance.given` (výdej, 314/211). Řádky bez DPH (zdanění záloh = samostatný
  daňový doklad, mimo scope), s partnerem a `payment_reference` — identita pro
  budoucí párování záloh (accbal fáze 4+). Záporná částka = odpočet/vrácení,
  účtuje se konvencí záporných řádků (D9). Zálohové faktury (`invpo`/`prfmin`) v
  novém systému nejsou; úhrada zálohové faktury hotově = `advance.received`.

## Scope

### 1. Tranzitní účty — `TransitAccountsProvisioner` (E1)

`modules/economy/accounting/src/TransitAccountsProvisioner.php` — zrcadlo
`ClearingInfrastructureProvisioner` (bez saldo skupiny):

| číslo | název | short_name | account_kind |
|---|---|---|---|
| `261100` | Peníze na cestě — převody | Převody na cestě | 0 |
| `261400` | Platební karty na cestě | Karty na cestě | 0 |

- Idempotentní podle `number`; existující účet (i uživatelský) nepřepisuje.
  Nadřazený syntetický `261` musí existovat — pokud v rozvrhu není, založit i
  ten (vzor clearing provisioneru; ověř, jak řeší `account_level`/parent).
- Zapojení v `DsUpgradeCommand` hned za clearing provisionerem, **mimo** gate
  `skipProvisioning`. Výstup `{created, existing}`.
- Odstranit duplicitu se seedy: 261100/261400 zůstávají v obou seed rozvrzích
  (nové DS), provisioner je jen pojistka pro migrované/starší DS.
- Test: DS bez 261400 → po provisioneru existuje; druhý běh nic nevytvoří;
  přejmenovaný existující účet zůstane.

### 2. Archivovaná pokladna (E2)

- `BoundNumberSeriesProvisioner::provision()` i `provisionForEntity()`: entity ve
  stavu **40 i 70**; založená řada dědí `docState`/`docStateMain` entity (40/3,
  70/4 — ověř mapu `docStateMain` pro 70 v `core.system.docStates`). Existující
  řada 40 pro pokladnu, která mezitím přešla do 70 — neřešit (mimo scope; poznámka
  do docs).
- `CashDeskSeriesEventHandler`: beze změny (jen 40) — archivace pokladny v UI
  řady nezakládá; archivované pokladny s doklady vznikají jen importem.
- `DocumentApplier`: `resolveCashDeskIdByCode` přijme i 70; `resolveBoundNumberSeries`
  přijme řadu 70 **jen v import módu** (`applyOptions.importNumber` vyplněný),
  jinak platí `ACTIVE_STATES`. Fallback provisioning z `a6a8908` se tím rozšíří
  automaticky (provisioner sám rozhodne dle stavu pokladny).
- Viewer/Form: ověřit, že řada 70 se nenabízí (záložky, `resolveNumberSeriesOptions`);
  pokud stávající filtr bere jen 40, nic nedělat, jen test.
- Test integrační: pokladna 70 bez řad → import `cashDocument` projde, řada vznikla
  ve stavu 70, `cash_desk` hlavičky = pokladna; živý apply bez `importNumber` →
  `cash_desk_not_found`.

### 3. Zálohy na `cash` (E3)

**`rowOperations.jsonc`:**

```jsonc
"sale.advanceDeduction":     docTypes += "cash": {"order": 360, "cashDir": 1}
"purchase.advanceDeduction": docTypes += "cash": {"order": 460, "cashDir": 2}

// Zálohy přijaté/poskytnuté v hotovosti (#59 Task E). Bez DPH — zdanění zálohy je
// samostatný daňový doklad. Záporná částka = vrácení / odpočet (konvence D9).
"advance.received": {
    "name": "Advance received", "name:cs": "Přijatá záloha", "name:en": "Advance received",
    "rowPartner": 1, "rowPaymentId": 1,
    "docTypes": { "cash": {"order": 370, "cashDir": 1} }
},
"advance.given": {
    "name": "Advance given", "name:cs": "Poskytnutá záloha", "name:en": "Advance given",
    "rowPartner": 1, "rowPaymentId": 1,
    "docTypes": { "cash": {"order": 470, "cashDir": 2} }
}
```

`DocRowsForm`: layout jako `payment.*` (částka, partner, `payment_reference`,
bez DPH). `DocDocument::validate`: `advance.*` vyžaduje partnera řádku;
`payment_reference` nepovinný (staré doklady ho často nemají).

**Předpis `documents[cash]`** (doplnit do bloků příjem/výdej):

```jsonc
// příjem
{"headQuery": {"cash_dir": 1}, "cat": "advances.received", "src": "rows", "side": 1,
    "operation": "advance.received", "text": "Přijatá záloha"},
{"headQuery": {"cash_dir": 1}, "cat": "advances.received", "src": "rows", "side": 0,
    "reverseSign": 1, "operation": "sale.advanceDeduction"},
// výdej
{"headQuery": {"cash_dir": 2}, "cat": "advances.given", "src": "rows", "side": 0,
    "operation": "advance.given", "text": "Poskytnutá záloha"},
{"headQuery": {"cash_dir": 2}, "cat": "advances.given", "src": "rows", "side": 1,
    "reverseSign": 1, "operation": "purchase.advanceDeduction"},
```

Head krok pokladny (211 z `total`) už záporné řádky započítá — celkem dokladu je
o odpočet nižší. Kategorie `advances.received/given` s `query: {vat_amount: 0}`
→ 324/314 (ne 3249/3149), protože řádky DPH nemají — ověř, že řetěz masek
takhle vyhodnotí.

Kontrolní příklady (`docs/accounting.md` §4):

```
Příjmový PD: prodej 10 000 + odpočet přijaté zálohy −4 000 (sale.advanceDeduction):
  602xxx DAL 10 000   324xxx MD 4 000   211HP1 MD 6 000
Příjmový PD: přijatá záloha 5 000 (advance.received, partner, VS):
  324xxx DAL 5 000 (partner, payment_reference)   211HP1 MD 5 000
Příjmový PD: přijatá záloha −5 000 (vrácení):
  324xxx MD 5 000   211HP1 DAL 5 000  (přes konvenci záporného řádku — test!)
Výdajový PD: poskytnutá záloha 3 000 (advance.given):
  314xxx MD 3 000   211HP1 DAL 3 000
```

**Exchange:** `operation` je passthrough — jen doplnit klíče do
`docs/exchange-format.md` (cashDocument) a mapy default pohybů.

### 4. Dokumentace

- `docs/accounting.md`: §2 pohyby, §4 předpis + příklady, §5 tranzitní účty
  (261100/261400 provisioner), §10 zálohy na PD vyškrtnout z mimo-scope (zdanění
  záloh zůstává).
- `docs/docs-mvp.md` §5: řady archivovaných pokladen.
- `modules/docs/cashDocs/README.md`: sekce Zálohy.
- Issue #59: komentář s E1–E3 (David potvrdil 2026-09-07).

### 5. Testy

- `TransitAccountsProvisioner` idempotence (viz §1).
- Archivovaná pokladna (viz §2).
- Účtování: 4 kontrolní příklady; `advance.received` bez partnera → validační
  chyba; pohyb `advance.received` na výdeji → chyba `cashDir`.
- Úzké filtry (`--filter 'Transit|ArchivedCashDesk|Advance'`), `timeout_sec: 120`,
  `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/4l3j-z0bz-kz39-echj`.

## Commit strategie

1. Provisioner tranzitních účtů + zapojení + test.
2. Archivovaná pokladna: provisioner + applier + test.
3. Zálohy: rowOperations + form/validace + předpis + testy účtování.
4. Dokumentace + komentář do #59.

Po nasazení na dev: `ds-upgrade` na `btpg-peg5-b0tr-chln` a `e8w1-iu9x-82vy-9ye7`
(261100/261400 vzniknou, řady pokladny 3), pak stará strana task 32 a znovu fáze
`docs` (repost deníku; `accounting-repost` CLI až po M1).

## Mimo scope

- `shpd-ds accounting-repost --errors` (po M1).
- Zdanění záloh (daňový doklad k přijaté platbě), párování záloh v accbal.
- Přechod pokladny 40 → 70 s existujícími řadami 40.

## Hotovo když

- [x] `ds-upgrade` na migrovaném DS založí 261100 a 261400 (druhý běh 0 created).
      *(unit test provisioneru + 4l3j: existing 3 / created 0; btpg/e8w1 po nasazení)*
- [x] Pokladna ve stavu 70 má po `ds-upgrade` řady ve stavu 70; import dokladu na ni
      projde; UI ji nenabízí. *(integrační test na 4l3j)*
- [x] Příjmový PD s `advance.received` a `sale.advanceDeduction` se zaúčtuje podle
      kontrolních příkladů; záporná záloha se účtuje konvencí D9 (záporné částky na
      stranách kroku, saldo účtu odpovídá otočenému zápisu).
- [x] Všechny stávající testy procházejí; dokumentace aktualizována.
- [ ] Po reimportu 689089: `SELECT COUNT(*) FROM economy_accounting_journal WHERE
      is_error=1 AND doc_type IN ('cash','cashreg')` = 0 (po task 32); počet
      `cash` ve stavu 40 = 16 680 − (3 `cashBox=0` + 1 partner + 2 pohyby)
      = 16 674 ± fix-source.
