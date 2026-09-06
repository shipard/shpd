# Task D: Převody peněz — pokladna ↔ banka ↔ pokladna (peníze na cestě)

**Stav:** hotovo — 2026-09-06, 4 commity na `stable` (rozvrh 261400 + seed test;
pohyby `transfer.*` + předpis + karty na 261400 + integrační testy; banka
`txOperations` + validace směru + matcher test; dokumentace). Odchylky od zadání:
provisioner ani `DocRowsForm`/`DocDocument` nepotřebovaly změnu kódu (idempotence per
`number`, partner řízený jen `rowPartner`, VS vynucuje jen `identityRequired`);
formulář bankovní transakce roletku podle směru nefiltruje — směr hlídá validace
`operation_direction_mismatch`; matcher výluku nepotřebuje (261100 není v saldo
skupině). 261400 dostávají jen DS s provisioningem (na 4l3j ověřeno), migrované
DS rozvrhem. Zbývá ruční proklik UI a alfa.
**Issue:** #59 (doplněk k fázi 1 — pohyby chybějící v Task B; rozhodnutí v komentáři
„Převody peněz")
**Návaznost:** vyžaduje hotové `tasks/docs-core-bound-series.md` a
`tasks/cash-docs-phase1.md`. Uzavírá otevřený bod bank.md §10 „převody mezi vlastními
účty a hotovost — až s pokladnou". Exportní mapování starých operací 1030011/1030012
patří do `old_shipard` Task C.

## Cíl

Doplnit operace, které ve starém Shipardu byly „Příjem z převodu peněz" (1030011)
a „Výdej pro převod peněz" (1030012) — na pokladních dokladech **i** bankovních
transakcích. Obě strany převodu jdou přes účet **261100 Peníze na cestě**, takže po
zaúčtování obou stran má 261100 z převodů nulový zůstatek (stejná kontrolní logika
jako clearing 261200/261300). Starý systém k tomu měl saldokontní skupinu 4100 —
tu **nezavádíme**; párování 261 může přijít později, účetně to sedí i bez něj.

Zároveň se **oddělí karty**: `card.transit` přechází z `261100` na novou analytiku
`261400 Platební karty na cestě`. U převodů čekáme nulu, u karet nenulový zůstatek
(tržby dosud nepřipsané bankou, stržené poplatky) — na jednom účtu by kontrola
nefungovala.

Před implementací **přečti**:

- `tasks/cash-docs-phase1.md` §2, §5 (pohyby `payment.*`, `headQuery`, předpis `cash`)
- `modules/docs/core/config/rowOperations.jsonc` — vlajky, zejména `rowSide: 0`
  (kontační layout bez volby strany — kurzové rozdíly) a `rowPaymentId`
- `modules/economy/accounting/config/accountingRules.cz.jsonc`,
  `accountChartDefault.jsonc`, `accountChartNpo.jsonc`,
  `src/AccountChartProvisioner.php` (idempotence doplnění chybějících účtů)
- `modules/economy/bank/config/txOperations.jsonc`,
  `src/BankTransactionAccountingEngine.php` (operation → cat → maska),
  `BankTransactionDocument.php`, formulář transakce (kde se vybírá `operation`)
- `docs/bank.md` §6.2–6.3, §10; `docs/accounting.md` §2, §4

## Rozhodnutí

- **T1** Pohyby `transfer.in` / `transfer.out` jen na `cash` a bankovních transakcích.
  Na `cmnbkp` **ne** — ruční opravy pokryje `acc.record` na 261.
- **T2** Účet převodů `261100` (kategorie `cash.transit`); karty na `261400`
  (kategorie `card.transit`, změna masky).
- **T3** Řádek převodu je bez DPH, bez partnera, s nepovinným `payment_reference`
  (identifikace protistrany převodu — číslo bankovní transakce, datum, pokladna),
  aby zůstala cesta k budoucímu párování 261. Bez saldokontní skupiny.

## Scope

### 1. Pohyby řádků — `docs.core.rowOperations`

```jsonc
// Převody peněz (Task D): obě strany převodu přes 261100 Peníze na cestě,
// druhou stranu nese bankovní transakce (economy.bank.txOperations transfer.*)
// nebo pokladní doklad druhé pokladny. rowSide: 0 — strana z předpisu, částka
// kladná, směr nese operace + cash_dir. payment_reference nepovinný (budoucí
// párování 261).
"transfer.in": {
    "name": "Cash transfer in",
    "name:cs": "Příjem z převodu peněz",
    "name:en": "Cash transfer in",
    "rowSide": 0,
    "rowPaymentId": 1,
    "docTypes": { "cash": {"order": 350, "cashDir": 1} }
},
"transfer.out": {
    "name": "Cash transfer out",
    "name:cs": "Výdej pro převod peněz",
    "name:en": "Cash transfer out",
    "rowSide": 0,
    "rowPaymentId": 1,
    "docTypes": { "cash": {"order": 450, "cashDir": 2} }
}
```

`DocRowsForm`: ověřit, že kombinace `rowSide: 0` + `rowPaymentId: 1` bez
`rowPartner` dá layout: částka, popis, `payment_reference` (nepovinný), bez DPH,
bez partnera, bez položky. Pokud dnešní layout pro `rowSide: 0` partnera vyžaduje
(kurzové rozdíly mají `rowPartner: 1`), upravit tak, aby partner byl řízený
výhradně vlajkou `rowPartner`. `DocDocument::validate`: `payment_reference` u
`transfer.*` **není** povinný (rozdíl proti `payment.*`).

### 2. Účtový rozvrh

`accountChartDefault.jsonc` i `accountChartNpo.jsonc`: přidat
`{"number":"261400","name":"Platební karty na cestě","short_name":"Karty na cestě","account_kind":0}`.
Ověřit, že `AccountChartProvisioner` v `ds-upgrade` doplní chybějící účet na
existující DS (idempotentní doplnění podle `number`); pokud provisioner seeduje jen
prázdnou osnovu, doplnit idempotentní větev „přidej chybějící čísla seedu" (bez
přepisu uživatelských úprav existujících účtů). Test úplnosti seedů vůči maskám
předpisu (vzor `VatAnalyticsCompletenessTest`) — pokud pro 261 neexistuje, přidat
malý test, že každá maska `261xxx` z předpisu má účet v obou seedech.

### 3. Předpis `accountingRules.cz.jsonc`

- `categories`: `cash.transit` {"name:cs": "Převod peněz — peníze na cestě"}.
- `accounts`: `{"cat": "cash.transit", "accountMask": "261100"}`;
  `card.transit` → `"261400"` (změna).
- `documents[cash]`:
  - příjem: `{"headQuery": {"cash_dir": 1}, "cat": "cash.transit", "src": "rows",
    "side": 1, "operation": "transfer.in", "text": "Převod peněz"}` — DAL 261100,
    MD 211 dává head krok pokladny;
  - výdej: `{"headQuery": {"cash_dir": 2}, "cat": "cash.transit", "src": "rows",
    "side": 0, "operation": "transfer.out", "text": "Převod peněz"}` — MD 261100,
    DAL 211 z head kroku.
- Řádek `transfer.*` má nulovou DPH; kroky `src: vat` ho nezasáhnou.

Kontrolní příklady (do `docs/accounting.md` §4):

```
Odvod hotovosti do banky 20 000:
  výdajový PD, transfer.out:   261100 MD 20 000 / 211HP1 DAL 20 000
  bankovní transakce transfer.in: 221xxx MD 20 000 / 261100 DAL 20 000
  → 261100: 0
Dotace pokladny z banky 5 000:
  bankovní transakce transfer.out: 261100 MD 5 000 / 221xxx DAL 5 000
  příjmový PD, transfer.in:   211HP1 MD 5 000 / 261100 DAL 5 000
Převod mezi pokladnami A → B 3 000:
  výdajový PD na A (transfer.out) + příjmový PD na B (transfer.in) → 261100: 0
```

### 4. Banka — `economy.bank.txOperations`

```jsonc
// Převody peněz (Task D): protistrana je vlastní pokladna (nebo vlastní jiný
// bankovní účet). Obě strany přes 261100 — po zaúčtování obou nulový zůstatek.
"transfer.in": {
    "name": "Transfer in (own cash / account)",
    "name:cs": "Příjem z převodu peněz",
    "name:en": "Transfer in (own cash / account)",
    "direction": 1,
    "cat": "cash.transit"
},
"transfer.out": {
    "name": "Transfer out (own cash / account)",
    "name:cs": "Výdej pro převod peněz",
    "name:en": "Transfer out (own cash / account)",
    "direction": 2,
    "cat": "cash.transit"
}
```

`BankTransactionAccountingEngine` se nemění (operation → cat → maska). Ověřit:
formulář transakce nabízí nové operace dle `direction`; matcher (accbal) transakce
s `transfer.*` **nepáruje** a nepřepisuje jim `operation` na `payment.*.matched`
(kandidáti na párování jsou jen `payment.in/out`) — pokud matcher filtruje jinak,
doplnit explicitní výluku. Pozdější automatická detekce převodu (protiúčet = náš
vlastní účet / text) zůstává v bank.md §10 jako refinement.

`docs/bank.md`: §6.2 tabulka operací, §10 vyškrtnout „hotovost jako protistrana",
ponechat „detekce převodu mezi vlastními účty" jako refinement; rozhodnutí do logu.

### 5. Import (poznámka pro Task C, `old_shipard`)

Mapování starých operací: `1030011` → `transfer.in`, `1030012` → `transfer.out` —
na `cash` (řádek pokladního dokladu) i `bank` (operace bankovní transakce).
Starý `symbol1`/`symbol2` řádku → `payment_reference` (pokud je), partner se
**nepřenáší** (T3). Na nové straně exchange applier už mapu pohybů umí (Task B §6)
— jen doplnit klíče; kontrola, že `transfer.*` je povolený pro `cash_dir` řádku
podle staré operace (1030011 jen příjem, 1030012 jen výdej).

### 6. Dokumentace

- `docs/accounting.md` §2 (tabulka pohybů), §4 (předpis, příklady), §5 (261100 vs
  261400 — proč oddělené), log rozhodnutí T1–T3.
- `docs/bank.md` dle §4 výše.
- `modules/docs/cashDocs/README.md`: sekce „Převody peněz".

### 7. Testy

- Unit: `transfer.in` povolený jen pro `cash_dir = 1`, `transfer.out` jen pro 2;
  řádek bez `payment_reference` projde; bez partnera projde.
- Integration accounting: tři kontrolní příklady výše — včetně **nulového zůstatku
  261100** po zaúčtování obou stran (PD + bankovní transakce ve stavu 40) a
  nenulového 261400 po prodejce kartou.
- Integration bank: transakce s `transfer.in/out` účtuje 221 / 261100; matcher ji
  ignoruje.
- Seed completeness test pro 261.
- Filtry `--filter 'Transfer|CashTransit|AccountChart'`, `timeout_sec: 120`.

## Commit strategie

1. Rozvrh 261400 + provisioner (idempotentní doplnění) + seed test.
2. `rowOperations` `transfer.*` + `DocRowsForm`/`DocDocument` + předpis `cash`
   + změna masky `card.transit` + integrační testy účtování.
3. Bank `txOperations` + ověření matcheru + testy.
4. Dokumentace.

Před každým commitem `git diff`, úzké PHPUnit filtry, po `.jsonc` změnách rebuild
cfg + `ds-upgrade` na dev DS (vč. ověření, že 261400 přibyl do existující osnovy).
Push dělá David.

## Mimo scope

- Párování 261 (obě strany převodu jako spárovaná dvojice), saldokontní skupina.
- Automatická detekce převodů z bankovního výpisu.
- Připsání karetních tržeb bankou (operace `card.settlement` na 261400 s poplatkem
  568) — samostatný malý task, až budou reálná data.
- `transfer.*` na `cmnbkp` (T1).

## Hotovo když

- [x] Na dev DS jde v roletce Pohyb příjmového PD vybrat „Příjem z převodu peněz",
      u výdajového „Výdej pro převod peněz"; řádek bez partnera a DPH se uloží
      a doklad potvrdí. *(nabídka + validace pokryté unit testy; ruční proklik
      v prohlížeči zbývá)*
- [x] Bankovní transakce má v operacích `transfer.in/out`; zaúčtuje 221 / 261100.
- [x] Po zaúčtování PD + protistrany v bance je zůstatek 261100 nulový (test).
- [x] Prodejka kartou účtuje na 261400; 261400 existuje v obou seedech a na
      existujícím dev DS po `ds-upgrade`.
- [x] Matcher accbal transakce `transfer.*` nepáruje.
- [x] Stávající testy procházejí; `docs/accounting.md` a `docs/bank.md`
      aktualizovány.
