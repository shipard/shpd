# Vlna D — validace partner_bank a kurzové operace (nová strana)

> **Status:** hotovo (2026-07-22) · **Moduly:** docs.invoicesIn, docs.core,
> economy.accounting · **Design:** `docs/design-import-wave-d.md`
> (D11 rozhodnuto, D12 varianta A potvrzena)
> **Návaznost:** old_shipard task 22 závisí na tomto PRD (operace musí
> applier znát dřív, než je migrace pošle).

## Scope

### 1. D11 — `partner_bank` z erroru na warning

`modules/docs/invoicesIn/src/ReceivedInvoiceDocument.php`: stávající
`addError('partner_bank', …, 'partner_bank_required')` při stavech
20/40/80 a `paymentMethod = 1` nahradit warningem
(`addWarning(..., 'partner_bank_recommended')`, stejný text). Podmínky
beze změny. Do docbloku poznámka: hard požadavek patří do budoucího
platebního flow (tvorba platebního příkazu) — až modul vznikne, převzít
z tohoto místa.

### 2. D12 — čtyři kurzové operace

`modules/docs/core/config/rowOperations.jsonc` (docTypes: cmnbkp;
všechny `rowPartner: 1`, `rowPaymentId: 1`, bez `rowAccount`):

| klíč | cs |
|---|---|
| `acc.fxLossReceivable` | Kurzová ztráta — pohledávka |
| `acc.fxGainReceivable` | Kurzový zisk — pohledávka |
| `acc.fxLossPayable` | Kurzová ztráta — závazek |
| `acc.fxGainPayable` | Kurzový zisk — závazek |

Částky řádků **kladné** — směr nese volba operace.

### 3. D12 — kategorie a kroky předpisu

`accountingRules.cz.jsonc`:

- nové kategorie: `{"cat": "fx.loss", "accountMask": "563"}`,
  `{"cat": "fx.gain", "accountMask": "663"}`; protistrany saldo použijí
  **tytéž kategorie jako `acc.balanceReceivable/Payable`** (311/321 —
  převzít přesné názvy z existujících kroků).
- kroky (cmnbkp), dva na operaci:
  - `acc.fxLossReceivable`: MD `fx.loss` / DAL saldo-pohledávky,
  - `acc.fxGainReceivable`: MD saldo-pohledávky / DAL `fx.gain`,
  - `acc.fxLossPayable`: MD `fx.loss` / DAL saldo-závazky,
  - `acc.fxGainPayable`: MD saldo-závazky / DAL `fx.gain`.
- oba zápisy operace nesou identitu řádku (partner,
  `payment_reference`) — saldo strana je párovatelná accbal FX fází.

Vzor ze zdroje (lefreal doc 719): řádek 50 806,73, person 11,
symbol1 1300001 → MD 563100 / DAL 311100.

### 4. Nasazení a testy

- Rebuild cfg + `ds-upgrade` na obou dev DS.
- Unit: kategorie `fx.loss`/`fx.gain` maskami (563/663), operace bez
  rowAccount nabízejí partnera + platební identitu.
- Integrace (vzor stávajících cmnbkp testů): doklad s
  `acc.fxLossReceivable` (50 806,73; partner; payment_reference
  „1300001") → MD 563xxx / DAL 311xxx, vyrovnáno, identita na obou
  zápisech; zrcadlově `acc.fxGainPayable`. PHPUnit úzké filtry.

## Hotovo když

- [x] invni bez bankovního spojení se uloží (warning, ne error);
      text/UX beze změny jinak. Warning kanál: `ValidationResult::addWarning`
      → `DocumentResult::ok(…, $validation)` → success response `warnings[]`
      (FE zatím nezobrazuje; viz `docs/edit-forms.md` sekce 8).
- [x] Čtyři FX operace na cmnbkp, účty výhradně kategoriemi
      (msi 563xxx/663xxx dle rozvrhu, lefreal 563100/663100).
      Nová sémantika `rowSide: 0` = kontační layout bez volby strany
      (viz `docs/accounting.md` sekce 2).
- [x] Integrační scénáře zelené, identita řádku na obou zápisech
      (`testCmnbkpFxLossReceivableTwoLinesWithIdentity`,
      `testCmnbkpFxGainPayableMirrorsSides` — 4l3j).
- [x] ds-upgrade + rebuild cfg na btpg i 4dnh (+ 4l3j pro integrační testy).
