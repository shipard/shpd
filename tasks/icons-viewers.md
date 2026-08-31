# Task: Ikony prohlížečů — odstranění duplicit, nové ikony (Issue #54)

**Stav:** naplánováno

## Status / cíl

Ikony prohlížečů se dnes hodně opakují (`calculator` 3×, `mail` 6×, `folder` 3×,
`tags` 3×, `robot` 3×, `book`/`wallet`/`bank` 2×) a některé významově nesedí.
Cíl: **každý často používaný prohlížeč má vlastní, významově odpovídající
ikonu**. Duplicity mezi Nastavením a hlavní částí aplikace jsou OK, když
významově sedí (rozhodnutí Anna).

GitHub Issue: shipard/shpd#54.

## Potvrzená designová rozhodnutí (Anna)

1. **Rozsah: všechno najednou** — nejen Účtárna/faktury/položky z Issue,
   ale i mail, registr, AI, štítky.
2. **Faktury směrově**: přijaté `file-arrow-down`, vydané `file-arrow-up`
   (návrat k metafoře ze staré aplikace).
3. **Položky**: `bag-shopping`.
4. **Účtový rozvrh**: `table-list` (ne `sitemap` — příliš technické).
   Vědomá duplicita s obecnými seznamy, případně se později vymyslí lepší.
5. **Saldokontní nav položky (Pohledávky/Závazky…)**: strana se **odvozuje
   z předpisových účtů skupiny** — MD → `circle-arrow-up` (pohledávkový typ),
   DAL → `circle-arrow-down` (závazkový typ), nejednoznačné/žádné →
   fallback `scale-balanced`. Žádný nový sloupec, žádný ds-upgrade.
6. Vědomě ponechané duplicity: `list-check` (Úkoly × Průvodce nastavením DS),
   `settings` 2× uvnitř Nastavení, `bank` (Bankovní výpisy × číselník
   Bankovní účty).

## Před implementací přečti

- `frontend/src/icons.js` — centrální registr, konvence pojmenování
  (icon{Význam}), `iconMap`, `resolveIcon()` s fallbackem `iconTable`.
- `modules/economy/accbal/src/BalancesNavigationProvider.php` — dynamické
  nav položky saldokont (ikona natvrdo na ř. 44).
- `modules/economy/accbal/tables/economy_accbal_balance_accounts.jsonc` —
  sloupce `balance` (int FK na `economy_accbal_balances.id`), `acc_side`
  (0=MD, 1=DAL), `bal_side` (0=předpis, 1=úhrada), `modify_sign`.
- `modules/economy/accbal/config/balancesDefault.cz.jsonc` — seed; ukazuje,
  proč je nutné z odvození vyloučit řádky s `modify_sign=1` (dobropisové
  řádky mají obrácenou stranu — bez vyloučení by Závazky vyšly „smíšené").

## Rozsah

### 1. `frontend/src/icons.js` — nové ikony + iconMap

Nové importy z `@fortawesome/free-solid-svg-icons` (všechny ověřeny, že
ve free-solid existují):

| iconMap klíč | FA import | export | použití |
|---|---|---|---|
| `invoice-in` (remap) | `faFileArrowDown` | `iconInvoiceIn` (přepsat) | Přijaté faktury |
| `invoice-out` (nový) | `faFileArrowUp` | `iconInvoiceOut` | Vydané faktury |
| `items` | `faBagShopping` | `iconItems` | Položky |
| `kinds` | `faShapes` | `iconKinds` | Druhy položek |
| `shuffle` | `faShuffle` | `iconShuffle` | Pravidla štítků |
| `table-list` | `faTableList` | `iconTableList` | Účtový rozvrh |
| `balance` | `faScaleBalanced` | `iconBalance` | Saldokonta (nastavení) + fallback nav |
| `movements` | `faRightLeft` | `iconMovements` | Saldo pohyby |
| `receivable` | `faCircleArrowUp` | `iconReceivable` | nav položka pohledávkového typu |
| `payable` | `faCircleArrowDown` | `iconPayable` | nav položka závazkového typu |
| `money-transfer` | `faMoneyBillTransfer` | `iconMoneyTransfer` | Bankovní pohyby |
| `cash-register` | `faCashRegister` | `iconCashRegister` | Pokladny |
| `archive` | `faBoxArchive` | `iconArchive` | Spisovna |
| `chart-pie` | `faChartPie` | `iconChartPie` | Střediska |
| `mail-out` | `faPaperPlane` | `iconMailOut` | Odeslaná pošta |
| `inbox` | `faInbox` | `iconInbox` | Schránky |
| `address-book` | `faAddressBook` | `iconAddressBook` | Odesílatelé |
| `magic` | `faWandMagicSparkles` | `iconMagic` | Preprocess pravidla |
| `chip` | `faMicrochip` | `iconChip` | AI backends |

Pozn.: `iconInvoiceIn` se **remapuje** (faFileInvoice → faFileArrowDown),
klíč `invoice-in` v jsonc zůstává — Přijaté faktury tedy nevyžadují změnu
v module.jsonc. `invoice` (faFileInvoiceDollar) zůstává pro `doc-accounting`
a obecné použití. Zařadit exporty do stávajících sekcí registru dle významu.

### 2. `module.jsonc` — změny `"icon"` (identifikuj podle id vieweru, ne jen podle řádku)

| soubor | viewer | teď → nově |
|---|---|---|
| `modules/docs/invoicesOut/module.jsonc` (ř. 18) | invoicesOut | `invoice` → `invoice-out` |
| `modules/economy/items/module.jsonc` (ř. 46) | items | `box` → `items` |
| `modules/economy/items/module.jsonc` (ř. 57) | kinds | `tags` → `kinds` |
| `modules/economy/items/module.jsonc` (ř. 36) | contentTags | `tags` — **beze změny** |
| `modules/core/exchange/module.jsonc` (ř. 55) | tag_rules | `tags` → `shuffle` |
| `modules/economy/accounting/module.jsonc` (ř. 28) | accounts (rozvrh) | `calculator` → `table-list` |
| `modules/economy/accounting/module.jsonc` (ř. 39) | journal | `book` — **beze změny** |
| `modules/economy/accbal/module.jsonc` (ř. 31) | balances | `book` → `balance` |
| `modules/economy/accbal/module.jsonc` (ř. 49) | ledger | `calculator` → `movements` |
| `modules/economy/bank/module.jsonc` (ř. 28) | transactions | `wallet` → `money-transfer` |
| `modules/economy/bank/module.jsonc` (ř. 39) | statements | `bank` — **beze změny** |
| `modules/economy/codebooks/module.jsonc` (ř. 61) | cash_desks | `wallet` → `cash-register` |
| `modules/economy/codebooks/module.jsonc` (ř. 88) | cost_centers | `folder` → `chart-pie` |
| `modules/base/registry/module.jsonc` (ř. 35) | documents (Spisovna) | `folder` → `archive` |
| `modules/base/registry/module.jsonc` (ř. 46) | binders | `folder` — **beze změny** |
| `modules/core/mail/module.jsonc` (ř. 67) | outbound | `mail` → `mail-out` |
| `modules/core/mail/module.jsonc` (ř. 101) | mailboxes | `mail` → `inbox` |
| `modules/core/mail/module.jsonc` (ř. 110) | senders | `mail` → `address-book` |
| `modules/core/mail/module.jsonc` (ř. 128) | sender_rules | `mail` → `filter` |
| `modules/core/mail/module.jsonc` (ř. 137) | preprocess_rules | `mail` → `magic` |
| `modules/core/mail/module.jsonc` (ř. 90, 119) | incoming, ai_profiles | `mail`, `robot` — **beze změny** |
| `modules/core/ai/module.jsonc` (ř. 36) | backends | `robot` → `chip` |
| `modules/core/chat/module.jsonc` (ř. 31) | conversations | `robot` → `chat` |

### 3. `BalancesNavigationProvider.php` — odvození strany

- Rozšířit dotaz o agregaci předpisových účtů (jeden dotaz, žádné N+1):

```sql
SELECT b.`code`, b.`name`, b.`short_name`,
       MIN(a.`acc_side`) AS side_min, MAX(a.`acc_side`) AS side_max,
       COUNT(a.`id`) AS side_cnt
FROM `economy_accbal_balances` b
LEFT JOIN `economy_accbal_balance_accounts` a
       ON a.`balance` = b.`id`
      AND a.`bal_side` = 0        -- předpis
      AND a.`modify_sign` = 0     -- dobropisové řádky vynechat
      AND a.`docState` != 90
WHERE b.`show_in_navigation` = 1 AND b.`docState` != 90
GROUP BY b.`id`
ORDER BY b.`sort_order` ASC, b.`name` ASC
```

- Ikona: `side_cnt > 0 && side_min == side_max`
  → `acc_side 0` ⇒ `'receivable'`, `acc_side 1` ⇒ `'payable'`;
  jinak `'balance'`.
- Zachovat stávající try/catch chování (DS před ds-upgrade → prázdný seznam)
  a `_order` logiku.
- Ověřit join klíč proti skutečné tabulce (`balance` je int reference na
  `economy_accbal_balances` — dle jsonc jde o `id`, ne `code`; potvrdit
  v kódu, který balance_accounts zapisuje).

### Mimo rozsah

- Volitelná ikona per saldokontní skupina v konfiguraci (budoucí rozšíření,
  pokud heuristika nebude stačit).
- Ikony stavů/akcí (feed karty, toolbary) — beze změny.
- Náhrada `table-list` u rozvrhu za něco lepšího (vědomé rozhodnutí č. 4).

## Očekávané chování po seedu (kontrolní tabulka odvození)

| skupina | předpisové účty (bez modify_sign) | ikona |
|---|---|---|
| Pohledávky | MD | circle-arrow-up |
| Závazky | DAL (dobropisový řádek 311/MD vyloučen přes modify_sign) | circle-arrow-down |
| Poskytnuté zálohy | MD | circle-arrow-up |
| Přijaté zálohy | DAL | circle-arrow-down |
| Nespárované platby | žádné předpisové řádky | scale-balanced (fallback) |
| Náklady příštích období | MD | circle-arrow-up |
| Úvěry | DAL | circle-arrow-down |

## Testy

- PHPUnit: doplnit/rozšířit test provideru (odvození: čistě MD, čistě DAL,
  smíšené, žádné předpisové řádky, dobropisový řádek s `modify_sign=1`
  se ignoruje). Vzor: stávající `tests/Unit/Api/Controller/NavigationControllerTest.php`.
- `php -l` po každé editaci PHP.
- Frontend: `cd frontend && timeout 90 npm run build 2>&1 | tail -4`.

## E2E ověření (Anna v prohlížeči)

1. Sidebar: žádné dva sousední prohlížeče se stejnou ikonou v hlavní části;
   Přijaté/Vydané faktury na první pohled odlišené.
2. Pohledávky mají šipku nahoru, Závazky dolů.
3. Kontrola fallbacku: v konzoli nesmí být viewer, který spadl na `iconTable`
   kvůli překlepu v názvu ikony (viz Pasti).
4. Nastavení: ikony číselníků a nastavení sedí, vědomé duplicity dle
   rozhodnutí č. 6.

## Pasti

- **`resolveIcon()` tiše fallbackuje na `iconTable`** — překlep v iconMap
  klíči nebo v `module.jsonc` se neprojeví chybou, jen „tabulkovou" ikonou.
  Po implementaci projít všechny nové klíče proti `iconMap` (grep obou stran).
  Zvážit dev-only `console.warn` v `resolveIcon` při neznámém názvu.
- **`modify_sign` je v odvození klíčový** — bez jeho vyloučení vyjdou Závazky
  jako smíšené (seed má dobropisový řádek 311/MD) a spadnou na fallback.
- **Join klíč `balance`** — jsonc říká int reference na balances; nepárovat
  přes `code`.
- **Řádková čísla v tabulkách výše můžou uplavat** — identifikuj položky
  podle id vieweru, čísla jsou jen vodítko.
- **`iconInvoiceIn` remap**: nechat klíč `invoice-in` beze změny, měnit jen
  FA import — jinak zbytečný zásah do invoicesIn module.jsonc.
- Ikony ve `iconMap` používá i server-driven navigace — po změně zkontrolovat
  i horní menu classic shellu, nejen sidebar.
