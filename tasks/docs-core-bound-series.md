# Task A: Číselné řady vázané na pokladnu/sklad, `cash_desk` + `cash_dir` na hlavičce, `resolveTradeDir`

**Stav:** hotovo — 2026-09-06, kód + testy + E2E provisioningu na dev DS; zbývá ruční proklik UI a nasazení na alfě
**Issue:** #59 — rozhodnutí D2–D5, D8 (část: extension na pokladně)
**Návaznost:** předchází `tasks/cash-docs-phase1.md` (Task B — moduly `docs.cashDocs`,
`docs.cashRegister`, účtování). Task B na tomto úkolu staví; bez něj neběží. Exportní
strana je v `old_shipard` (Task C) a nasazuje se **až po** této straně.

## Cíl

Zhmotnit „virtuální" číselné řady starého Shipardu (`%B` pokladna, `%W` sklad, řešené
v kódu `makeDocNumber`) jako běžné záznamy `docs_core_number_series` s vazbou na entitu.
Připravit hlavičku dokladu na pokladní doklady: pokladna (`cash_desk`) a směr
(`cash_dir`), a udělat ze směru obchodu (`trade_dir`) věc dokladu, ne jen typu.
Tento task **nezavádí žádný nový typ dokladu** — jen infrastrukturu; typy `cash` a
`cashreg` přidává Task B. Sklad se teď nedělá, mechanismus musí být na něj připravený
(`series_binding: "warehouse"`) bez dalšího kódu specifického pro sklad.

Před implementací **přečti**:

- issue #59 (`gh issue view 59 --repo shipard/shpd`)
- `docs/docs-mvp.md` §5 (číselné řady, algoritmus přidělení čísla), §6 (hlavička)
- `modules/docs/core/tables/docs_core_number_series.{jsonc,md}`,
  `docs_core_heads.{jsonc,md}`, `docs_core_number_counters.jsonc`
- `modules/docs/core/config/docTypes.jsonc`
- `modules/docs/core/src/NumberSeriesDocument.php`, `NumberSeriesProvisioner.php`,
  `NumberSeriesForm.php`, `NumberSeriesViewer.php`
- `modules/docs/core/src/DocDocument.php` — `denormalizeDocType`,
  `resolveTradeDir` (privátní), `buildSnapshots` / `assignSnapshots`,
  `assignDocumentNumber`
- `modules/docs/core/src/DocRowsForm.php` (~ř. 430, filtr DPH kódů dle `trade_dir`),
  `DocsHeadsViewer.php` (privátní `resolveTradeDir`, `getNumberSeries`,
  `getNewRecordDefaults`), `DocsHeadsFormBase.php` (`resolveNumberSeriesOptions`,
  `payment_method` v hlavičce)
- `modules/core/exchange/src/Document/DocumentApplier.php` — komentář u
  `_importPartnerSnapshot` (trade_dir větvení patří do Document vrstvy)
- `modules/economy/codebooks/tables/economy_codebooks_cash_desks.{jsonc,md}`,
  `economy_codebooks_warehouses.jsonc`, `src/CashDeskDocument.php` (`afterPersist`)
- `modules/economy/bank/extensions/economy_codebooks_bank_accounts.jsonc` — vzor
  extension `accounting_account` (221xxx) na bankovním spojení
- `src/Command/DataSource/DsUpgradeCommand.php` — kde se volá
  `NumberSeriesProvisioner` (~ř. 804)
- `docs/table-definitions.md`, `docs/modules.md` (extensions, provisioners),
  `docs/edit-forms.md`

## Scope

### 1. `docs.core.docTypes` — nové atributy typu

`modules/docs/core/config/docTypes.jsonc` (+ komentář v hlavičce souboru):

| atribut | význam |
|---|---|
| `series_binding` | `"cash_desk"` \| `"warehouse"`; chybí = nevázaný typ. Řada tohoto typu musí mít vyplněný odpovídající FK. |
| `trade_dir_column` | název sloupce hlavičky, ze kterého se čte směr obchodu, když je `trade_dir: 0`. Hodnota sloupce se mapuje 1→1, 2→2. Zatím jediná povolená hodnota `"cash_dir"`. |

Stávající typy (`invno`, `invni`, `cmnbkp`) se nemění. Nové typy přidává Task B.

### 2. `docs_core_number_series` — vazba na entitu (D2)

Nové sloupce, skupina `identity` (za `doc_type`):

| sloupec | typ | popis |
|---|---|---|
| `cash_desk` | int, nullable, reference `economy_codebooks_cash_desks` | Pokladna, pro vázaný typ `cash_desk` |
| `warehouse` | int, nullable, reference `economy_codebooks_warehouses` | Sklad, pro vázaný typ `warehouse` |

Index `idx_binding_cash_desk` (`doc_type`, `cash_desk`), `idx_binding_warehouse`
(`doc_type`, `warehouse`) — lookup „řady pokladny X typu Y".

`displayPattern` zůstává `{name}`; provisioner dává řadě jméno s kódem pokladny
(§4), takže záložky ve vieweru jsou čitelné.

**Validace `NumberSeriesDocument::validate`:**

- typ s `series_binding: cash_desk` → `cash_desk` povinný, `warehouse` musí být NULL
  (a zrcadlově pro `warehouse`);
- nevázaný typ → oba NULL (chyba na příslušném sloupci, kód `binding_not_allowed`);
- odkazovaná pokladna/sklad existuje a není `docState = 90`.

**`NumberSeriesForm`:** pole `cash_desk` / `warehouse` jako lookup, zobrazené jen když
zvolený `doc_type` má odpovídající `series_binding` (reload při změně typu, vzor
podmíněných polí v `DocsHeadsFormBase`). U řady vytvořené provisionerem je vazba
`readOnly` (změna pokladny pod existujícími doklady nedává smysl).

**`NumberSeriesViewer`:** v řádku ukázat kód pokladny/skladu (t2 muted), aby šly řady
rozeznat.

### 3. Hlavička dokladu — `cash_desk`, `cash_dir` (D4, D5)

`modules/docs/core/tables/docs_core_heads.jsonc`:

| sloupec | skupina | typ | popis |
|---|---|---|---|
| `cash_desk` | `payment` | int, nullable, reference `economy_codebooks_cash_desks`, index `idx_cash_desk` | Pokladna. Pro typ s `series_binding: cash_desk` **systémový** (denormalizovaný z řady). Pro ostatní typy uživatelský — má smysl jen při `payment_method = 0`. |
| `cash_dir` | `identity` | enumInt, cfgItem `docs.core.cashDirections`, default 0, nullable false | Směr pokladního dokladu. 0 = nepoužito (faktury, cmnbkp), 1 = příjem, 2 = výdej. |

Nový cfgItem `docs.core.cashDirections` (`config/cashDirections.jsonc`, registrace
v `module.jsonc`):

```jsonc
{
    "0": {"name": "Not applicable", "name:cs": "—",       "name:en": "Not applicable"},
    "1": {"name": "Receipt",        "name:cs": "Příjem",  "name:en": "Receipt"},
    "2": {"name": "Disbursement",   "name:cs": "Výdej",   "name:en": "Disbursement"}
}
```

PHP enum `Shipard\Module\Docs\Core\CashDirection` (backed int) dle konvence
`docs/document-system.md` §10.

Poznámka k `system: true`: sloupec `cash_desk` **není** označen `system` v JSONC,
protože u faktur je uživatelský. Systémovost pro vázané typy vynucuje `DocDocument`
(§5) — stejně jako se `doc_type` přepisuje z řady bez ohledu na payload.

Aktualizovat `docs_core_heads.md` (skupiny `identity`, `payment`).

### 4. Provisioning vázaných řad (D3)

Nová třída `Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner`:

- Vstup: `docs.core.docTypes`. Pro každý typ s `series_binding`:
  - `cash_desk`: pro každou pokladnu `economy_codebooks_cash_desks` s `docState = 40`
    zajistí, že existuje řada (`doc_type`, `cash_desk`) s `docState != 90`. Pokud ne,
    vytvoří: `name` = `"{name typu:cs} — {code pokladny}"`, `doc_number_code` =
    `code` pokladny, `doc_number_pattern` = `doc_number_pattern_default` typu,
    `reset_scope = fiscal_year`, `docState 40 / docStateMain 3`.
  - `warehouse`: totéž nad `economy_codebooks_warehouses` — **implementovat obecně**
    (binding → tabulka + FK sloupec v malé mapě), ne dvě kopie kódu. Teď žádný typ
    `series_binding: warehouse` neexistuje, větev se tedy neprovede; test ji pokryje
    syntetickým cfg.
- Idempotentní; vrací `{created, existing}` pro výpis `ds-upgrade`.
- Zapojení: `DsUpgradeCommand` hned za `NumberSeriesProvisioner`. Provisioner běží i
  pro migrované DS (**mimo** gate `skipProvisioning` — vzor
  `ClearingInfrastructureProvisioner`), protože řady jsou infrastruktura, kterou
  import dokladů potřebuje (D12).
- Hook: `CashDeskDocument::afterPersist` — pokud pokladna po uložení je ve stavu 40,
  zavolat provisioner jen pro tuto pokladnu (metoda `provisionForCashDesk(int $id)`).
  Modul `economy.codebooks` nesmí záviset na `docs.core` → hook realizovat jako
  `documentEventHandler` modulu `docs.core` na tabulce
  `economy_codebooks_cash_desks` (event `afterSave`), třída
  `Shipard\Module\Docs\Core\CashDeskSeriesEventHandler`. Registrace v `module.jsonc`
  docs.core + rebuild cfg.

**`NumberSeriesProvisioner`** (stávající): typy s `series_binding` **přeskakuje**
(nesmí vzniknout nevázaná řada vázaného typu).

### 5. `DocDocument` — denormalizace a směr

- `denormalizeDocType` → rozšířit na `denormalizeFromSeries`: kromě `doc_type` načte
  z řady i `cash_desk` a `warehouse`; má-li typ dokladu `series_binding`, hodnotu
  z řady **vždy** zapíše do `$data` (přepíše payload). Pro nevázané typy hodnotu
  z payloadu nechává.
- Validace (`validate`): pro typ s `series_binding: cash_desk` je po denormalizaci
  `cash_desk` povinný (řada bez vazby = chyba `series_binding_missing`, `_form`);
  `cash_dir` musí být 1 nebo 2, když má typ `trade_dir_column: cash_dir`, jinak
  musí být 0. Pro nevázané typy: `cash_desk` smí být vyplněný jen při
  `payment_method = 0` (chyba `cash_desk_requires_cash_payment` na `cash_desk`).
- **`resolveTradeDir(array $data): ?int`** — zveřejnit jako `public static`
  (nebo jako samostatnou service `TradeDirResolver` s injektovaným cfg — zvol podle
  toho, jak se dnes do `DocRowsForm`/`DocsHeadsViewer` dostává `config`; jediná
  implementace, čtyři konzumenti):
  1. `docTypes[docType]` neexistuje → `null`;
  2. `trade_dir` je 1 nebo 2 → vrátit;
  3. `trade_dir` je 0 a existuje `trade_dir_column` → přečíst sloupec z `$data`,
     mapování 1→1, 2→2, jinak `null`;
  4. jinak `null` (typ bez stran — cmnbkp).
- Nahradit privátní implementace: `DocDocument::resolveTradeDir`,
  `DocsHeadsViewer::resolveTradeDir` (pozor: dnes fallback `2`; sjednotit na
  `null` → „bez partnera", ověřit dopad na detail dokladu), `DocRowsForm` (~ř. 433 —
  direction filtru DPH kódů), a v `DocumentApplier` zkontrolovat, že se nikde
  neodvozuje strana přímo z cfg. Snapshoty (`buildSnapshots`) při `trade_dir`
  z `cash_dir`: 1 → partner = customer, 2 → partner = supplier; `null` → beze
  snapshotů (dnešní chování cmnbkp).

### 6. Extension `accounting_account` na pokladně (D8, část)

`modules/economy/accounting/extensions/economy_codebooks_cash_desks.jsonc` —
sloupec `accounting_account` (int, nullable, reference
`economy_accounting_accounts`, skupina `settings`), zrcadlo bankovního vzoru.
Žije v `economy.accounting`, ne v budoucím `docs.cashDocs` — engine ho potřebuje i
pro faktury placené hotově (D8) a nesmí záviset na volitelném modulu.
Formulář pokladny: pole viditelné s nainstalovaným `economy.accounting`, picker
omezený na analytické účty `211%` (vzor pole na položce / bankovním spojení).
Použití v předpisu řeší Task B.

### 7. Formuláře faktur — `cash_desk` (D4)

`DocsHeadsFormBase` (nebo `IssuedInvoiceForm` / `ReceivedInvoiceForm`, podle toho,
kde je `payment_method`): lookup `cash_desk` viditelný jen při `payment_method = 0`,
`payment_method` dostane `triggers: 'reload'`, pokud ho nemá. Default při přepnutí
na Hotovost (recalculate): pokladna s `is_default = 1` pro `doc_currency` dokladu,
`docState = 40`; není-li, prázdné + `hint`.

### 8. Frontend

Žádná nová komponenta. Ověřit, že záložky řad (`Viewer.svelte`, `activeSeriesId` →
`number_series` do defaults nového záznamu) a `FormEditor` fungují pro řadu
s vazbou beze změn. `npm run check:i18n` musí projít (nové labely jdou přes PHP
formuláře).

### 9. Dokumentace

- `docs/docs-mvp.md` §5: nová podsekce „Řady vázané na entitu" (D2, D3, `%C` = kód
  pokladny, proč ne nový placeholder), §6: `cash_desk`, `cash_dir`, `trade_dir`
  per doklad.
- `modules/docs/core/tables/*.md` (řady, hlavička), `docs_core_number_series.md`
  sekce Provisioner.
- `docs/accounting.md` §10 „Mimo scope": přesunout „analytiky pokladen" do
  hotového, odkaz na extension.
- `docs/modules.md`: zmínka, že `documentEventHandlers` lze registrovat i na
  číselníkovou tabulku jiného modulu (precedent tohoto tasku).

### 10. Testy

- Unit: `NumberSeriesDocument` — vazba povinná/zakázaná dle `series_binding`;
  `CashDirection` enum; `resolveTradeDir` — všechny čtyři větve (cfg fixture
  se syntetickým typem `trade_dir: 0, trade_dir_column: cash_dir`).
- Unit/Integration: `BoundNumberSeriesProvisioner` — idempotence, přeskočení
  pokladen mimo stav 40, syntetický typ `series_binding: warehouse`; stávající
  `NumberSeriesProvisioner` vázaný typ přeskočí.
- Integration: uložení dokladu vázaného typu (syntetický docType v testovacím cfg,
  nebo až Task B) → `cash_desk` přepsán z řady; faktura s `cash_desk` bez
  `payment_method = 0` → validační chyba.
- Spouštět úzkými filtry (`--filter 'NumberSeries|TradeDir|CashDesk'`),
  `timeout_sec: 120`.

## Commit strategie

1. `docTypes` atributy + `cashDirections` cfg + enum + schéma řad a hlavičky
   (`ds-upgrade` projde na dev DS) + docs tabulek.
2. `NumberSeriesDocument` validace + form/viewer řad.
3. `BoundNumberSeriesProvisioner` + zapojení do `DsUpgradeCommand` + event handler
   na pokladně + úprava `NumberSeriesProvisioner` + testy.
4. `DocDocument` denormalizace/validace + `resolveTradeDir` a náhrada 4 konzumentů +
   testy.
5. Extension `accounting_account` na pokladně + formulář pokladny.
6. Formuláře faktur (`cash_desk` při Hotovosti) + dokumentace.

Před každým commitem: `git diff` (patch_file je tichý), `vendor/bin/phpunit` s úzkým
filtrem, po změnách `.jsonc` rebuild cfg + `ds-upgrade` na dev DS. Push dělá David.

## Mimo scope

- Typy `cash`, `cashreg`, jejich moduly, pohyby a předpis → Task B.
- Sklad, `purchase`, pokladní kniha, otevírací doklady (D10, fáze 2).
- Export ze starého Shipardu (Task C, `old_shipard`).
- Přejmenování pokladny → změna `doc_number_code` se **nepropaguje** (záměr, D3).

## Hotovo když

- [x] `ds-upgrade` na dev DS projde; `docs_core_number_series` má `cash_desk`,
      `warehouse`; `docs_core_heads` má `cash_desk`, `cash_dir`;
      `economy_codebooks_cash_desks` má `accounting_account`.
- [x] Řadu nevázaného typu nelze uložit s pokladnou; řadu vázaného typu bez ní ne
      (ověřeno testem se syntetickým typem).
- [x] `BoundNumberSeriesProvisioner` je idempotentní, běží v `ds-upgrade` mimo
      `skipProvisioning` gate a spustí se při uložení pokladny do stavu 40.
- [x] `resolveTradeDir` je jediná implementace; `grep -rn "\['trade_dir'\]"
      modules src` mimo ni nevrací konzumenty.
- [x] Faktura vydaná/přijatá: `cash_desk` viditelný jen při Hotovosti, default
      z `is_default` pokladny; jinak validace odmítne.
- [x] Stávající testy docs/accounting/exchange procházejí (snapshoty invno/invni
      beze změny chování).
- [x] `npm run check:i18n` projde; dokumentace aktualizována.
