# Task: `docs.core` — Fáze 2: Výpočty a životní cyklus

## Kontext

Pokračujeme z **Fáze 1** (`docs-core-phase1.md` — hotovo), kde vznikla kostra
modulu `docs.core`: tabulky, cfgItem, abstract `DocDocument` se stub metodami,
číselné řady s provisionerem, `OwnCompanyResolver`. Doklad lze v této fázi
uložit jen jako prázdný Koncept.

Fáze 2 **naplní stub metody** v `DocDocument` reálnou logikou a doplní
mechaniky kolem stavových přechodů. Po dokončení této fáze platí, že
v API/CLI lze doklad pořídit, vyplnit řádky, potvrdit (přidělit číslo,
sestavit rekapitulaci, snapshoty), upravit, stornovat, smazat — vše s
konzistentními výpočty.

**Co tato fáze NEdělá:** nepřidává žádné UI. Formulář dokladu (hlavička
+ řádky + rekapitulace + …) přijde ve Fázi 5 (`docs-core-phase3.md`).
Per-typ viewers (vydané/přijaté faktury) přijdou ve Fázi 6 (`docs-invoices.md`).
Tato fáze je **čistě backend logika**, testovaná přes API + PHPUnit.

Před implementací **přečti**:

- `docs/docs-mvp.md` — kompletní designový dokument, zejména:
  - Sekce 4 — DPH model, reverse charge páry, časová logika
  - Sekce 5.3 — algoritmus `assignDocumentNumber` s atomickým counterem
  - Sekce 5.4 — vzorec a placeholdery
  - Sekce 6.3 — default values logic (které defaults a kde)
  - Sekce 6.4 — snapshot logika a struktura JSON
  - Sekce 7.3, 7.4 — výpočet ceny a DPH na řádku
  - Sekce 8.2, 8.3 — sestavení rekapitulace včetně reverse charge páru a
    výpočet hlavičkových součtů
  - Sekce 9.1 – 9.8 — tok dat při všech přechodech stavů
- `tasks/docs-core-phase1.md` (hotovo) — referenční stav po Fázi 1
- `modules/world/vat/src/VatRateResolver.php` — API pro získání kódů,
  procent, kategorií
- `modules/economy/codebooks/src/FiscalYearsProvisioner.php` — jak číst
  `economy_codebooks_fiscal_years/months` (vzor pro resolveFiscalYearId)
- `modules/docs/core/src/DocDocument.php` — aktuální stav po Fázi 1

## Cíl Fáze 4

Po dokončení této fáze platí:

- Insert nového Konceptu vyplní `accounting_date` z `issue_date` (pokud chybí),
  resolved `fiscal_year`/`fiscal_month`, default `home_currency` z DS config
- Doklad s řádky správně počítá `total_price`, `vat_base`, `vat_amount`,
  `vat_total` na každém řádku podle `vat_mode` hlavičky
- `docs_core_vat_recap` se přepočítává v `beforeSave` hlavičky — group by
  `(vat_code, vat_pct)`, pro reverse charge kódy se generuje párový řádek
  `is_reverse_pair = 1`
- `total_base`, `total_vat`, `total_amount` na hlavičce respektují flagy
  `sum_*` z rekapitulace; přepočet do `home_currency` přes `exchange_rate`
- Aplikuje se zaokrouhlení podle `total_rounding_mode` a `vat_rounding_mode`
- Při Koncept (10) → Potvrzeno (20):
  - Atomicky se přidělí `sequence_number` z counteru
  - Resolved `doc_number` ze vzorce řady (s placeholdery `%D %C %y %Y %3..%6`)
  - Sestaví se `supplier_snapshot` a `customer_snapshot` (JSON)
  - Default `variable_symbol` = `sequence_number` (pokud uživatel nezadal)
- Při Potvrzeno (20) → Koncept (10):
  - Pokud je doklad poslední v řadě, dekrementuje counter, vyčistí
    `sequence_number`, vrátí `doc_number` na placeholder
  - Pokud není poslední, vrací 422 INVALID_STATE_TRANSITION
- Při změně `partner` v editovatelných stavech (20, 80) se přebuduje
  `customer_snapshot` (resp. `supplier_snapshot` u FPB)
- Existuje konkrétní třída `DocsHeadsDocument extends DocDocument`,
  registrovaná v `module.jsonc` jako document class pro `docs_core_heads`
- Insert/update přes API funguje end-to-end — doklad lze potvrdit, vrátit,
  upravit, stornovat
- PHPUnit testy pokrývají kritické scénáře

## Návaznost

- Závisí na: Fáze 1 (`docs-core-phase1.md` — hotovo), `world.vat`, hotových
  modulech `economy.codebooks`, `economy.items`, `base.persons`
- Otevírá: Fáze 5 (`docs-core-phase3.md`) — formulář dokladu

## Scope

### V rozsahu

- **Defaults**: `accounting_date`, `vat_duzp`, `vat_dppd`, `due_date`,
  `home_currency`, `partner_address` (resolution z partnerových adres)
- **Resolvers**: `resolveFiscalYearId`, `resolveFiscalMonthId`,
  `resolveVatPeriodId`
- **Výpočty řádků**: `calculateRowPrice` (mode + slevy), `calculateRowVat`
  (3 vat_modes)
- **Rekapitulace**: `buildVatRecapitulation` s podporou reverse charge páru
- **Součty**: `sumTotals` s respektováním `sum_*` flagů + přepočet do
  home currency
- **Zaokrouhlení**: `applyRounding` (3 modes)
- **Snapshoty**: `maintainSnapshots`, `buildPersonSnapshot` (vč. lookup
  adresy, banky, vat registrace)
- **Číslo dokladu**: `assignDocumentNumber` (atomický counter +
  `resolvePattern`), `releaseDocumentNumber` (přechod 20→10)
- **Stavové přechody**: orchestrace v `beforeSave`, kontroly při Potvrzení,
  pravidlo "poslední v řadě" pro 20→10
- **Validace per stav**: Koncept = minimum, Potvrzeno+ = vlastní firma,
  ≥ 1 řádek, exchange_rate u cizí měny, …
- **Konkrétní `DocsHeadsDocument`**: thin extends `DocDocument`,
  registrovaná pro `docs_core_heads`
- **Rozšíření `Document::beforeSave` signatury** o `?array $originalData`
  (default null) — pokud framework dosud nepředává originalData při update
- **Extension `base.persons`**: nový sloupec `payment_term_days` (varchar/int),
  default 14 — jako extension z `docs.core` přes `extensions/`
- **Sanity check `cz-000`** — task `world-vat-cz` ho vynechal, takže rekapitulace
  nesmí spadnout, když řádek má `vat_code = null` (textový řádek)
- **PHPUnit testy** pro všechny kritické cesty

### Mimo rozsah

- UI formulář dokladu (Fáze 5)
- Per-typ Document subclasses + viewers (Fáze 6)
- Kontextové `getAvailableTransitions(int, array $context)` v `core.system` —
  zatím necháváme stávající signaturu a pravidlo "poslední v řadě" se
  validuje až v `Document::beforeSave` (UI nabídne tlačítko, server vrátí
  422 pokud nelze). Pokud Fáze 5 ukáže, že je to UX problém, Fáze 5 to
  přidá. Tato fáze nevyžaduje úpravy core.system.
- PDF výstup (mimo MVP)
- Přiznání DPH, Kontrolní hlášení (mimo MVP)

## Architektonické rozhodnutí

### Polymorfismus a `DocsHeadsDocument`

`DocDocument` zůstává **abstract** (kostra logiky, společná pro všechny
typy). V této fázi vzniká konkrétní `DocsHeadsDocument extends DocDocument`
v modulu `docs.core` jako "default" třída pro `docs_core_heads`. Sama o
sobě nedělá nic specifického — jen umožňuje registraci v `module.jsonc`
a tím spuštění hooks při insert/update.

Ve Fázi 6 přijdou `IssuedInvoiceDocument` a `ReceivedInvoiceDocument`
v modulech `docs.invoicesOut` a `docs.invoicesIn`. Až tehdy budeme řešit
**polymorfní dispatch** — buď přes `typeColumn: 'doc_type'` v
`module.jsonc` (pokud framework podporuje), nebo přes overriden
`DocumentRegistry::getDocument()`. Pro Fázi 4 to není potřeba.

### Signatura `beforeSave`

Pro výpočty potřebujeme `originalData` (porovnání partnera, předchozí
docState). Pokud `Document::beforeSave(array &$data)` v `Shipard\Core\Document\Document`
ještě nemá druhý parametr, **rozšiř ho** v rámci této fáze:

```php
public function beforeSave(array &$data, ?array $originalData = null): void
```

Default `null` = backward compatible (existující subclasses ignorují).
Současně rozšiř volání ve frameworku (pravděpodobně `TableGateway::saveDocument`
nebo podobně) — při update musí frame work načíst původní řádek a předat
ho. Při insert je to `null`.

### Orchestrace v `beforeSave`

Hlavní orchestrátor `DocDocument::beforeSave`:

```php
public function beforeSave(array &$data, ?array $originalData = null): void
{
    parent::beforeSave($data, $originalData);  // ChainDocument hooks (attachments, etc.)

    $this->denormalizeDocType($data);          // Phase 1 logic
    $this->applyDateDefaults($data);           // accounting_date, vat_duzp, vat_dppd, due_date
    $this->applyHomeCurrency($data);           // from DS config
    $this->resolveAccountingPeriods($data);    // fiscal_year, fiscal_month, vat_period

    // Process rows: price + vat
    if (!empty($data['rows']) && is_array($data['rows'])) {
        $vatMode = (int) ($data['vat_mode'] ?? 0);
        foreach ($data['rows'] as &$row) {
            $this->calculateRowPrice($row);
            $this->calculateRowVat($row, $vatMode);
        }
        unset($row);
    }

    // Build recap from rows
    $recap = $this->buildVatRecapitulation($data);
    $data['vatRecap'] = $recap;

    // Sum totals using recap flags
    $this->sumTotals($data, $recap);

    // Apply rounding to total_amount
    $this->applyTotalRounding($data);

    // Convert totals to home currency
    $this->applyExchangeRate($data);

    // Handle state-transition specific work
    $this->processStateTransition($data, $originalData);

    // Snapshots (if entering or already in editable confirmed states)
    $this->maintainSnapshots($data, $originalData);

    // Default variable_symbol from sequence_number after Confirm
    $this->applyVariableSymbolDefault($data);
}
```

`processStateTransition` rozhoduje, co se děje podle změny `docState`:

```php
private function processStateTransition(array &$data, ?array $originalData): void
{
    $newState = (int) ($data['docState'] ?? 10);
    $oldState = (int) ($originalData['docState'] ?? $newState);

    // Concept (10) → Confirmed (20): assign number
    if ($oldState === 10 && $newState === 20) {
        $this->assignDocumentNumber($data);
        return;
    }

    // Confirmed (20) → Concept (10): release number (only if last in series)
    if ($oldState === 20 && $newState === 10) {
        $this->releaseDocumentNumber($data, $originalData);
        return;
    }

    // Other transitions: no number changes
}
```

## Implementace metod

### `applyDateDefaults($data)`

```php
private function applyDateDefaults(array &$data): void
{
    if (!empty($data['issue_date'])) {
        if (empty($data['accounting_date'])) {
            $data['accounting_date'] = $data['issue_date'];
        }
        if (empty($data['vat_duzp'])) {
            $data['vat_duzp'] = $data['issue_date'];
        }
    }
    if (!empty($data['vat_duzp']) && empty($data['vat_dppd'])) {
        $data['vat_dppd'] = $data['vat_duzp'];
    }
    if (!empty($data['issue_date']) && empty($data['due_date'])) {
        $days = $this->resolvePartnerPaymentTermDays($data['partner'] ?? null) ?? 14;
        $data['due_date'] = (new \DateTimeImmutable($data['issue_date']))
            ->modify("+{$days} days")
            ->format('Y-m-d');
    }
}

private function resolvePartnerPaymentTermDays(?int $partnerId): ?int
{
    if ($partnerId === null || $this->db === null) {
        return null;
    }
    $row = $this->db->fetchRow(
        'SELECT payment_term_days FROM base_persons_persons WHERE id = %i',
        $partnerId,
    );
    if ($row === null) {
        return null;
    }
    $days = $row['payment_term_days'] ?? null;
    return $days !== null ? (int) $days : null;
}
```

### `applyHomeCurrency($data)`

`home_currency` se kopíruje z DS konfigurace (`config.json` v adresáři DS).
Pokud framework expose-uje `DataSourceConfig`, použij ho přes injekci.
Vzor: `FiscalYearsProvisioner` přebírá `ConfigRuntime` přes konstruktor.

```php
private function applyHomeCurrency(array &$data): void
{
    if (empty($data['home_currency'])) {
        // Default from DS config; if not available, fall back to czk
        $data['home_currency'] = $this->getDsHomeCurrency() ?? 'czk';
    }
}

protected function getDsHomeCurrency(): ?string
{
    // Implementation depends on how DS config is exposed in Document context.
    // Options:
    //   1) Inject DataSourceConfig via constructor (preferred)
    //   2) Read from cfgItem (e.g. economy.codebooks.fiscalConfig has currency
    //      hint? Check existing structure.)
    //   3) For Phase 4 fallback: hardcoded 'czk'
    // Whatever path is chosen, document it clearly.
    return null;
}
```

**Pozn:** Pokud `Document` base třída nemá přístup k `DataSourceConfig`,
přidej injekci do `DocDocument` přes vlastní konstruktor (musí volat
parent). Vzor: jak je `ConfigRuntime` injectován do provisioneru.

### Resolvery období

```php
protected function resolveFiscalYearId(string $accountingDate): ?int
{
    if ($this->db === null) {
        return null;
    }
    $row = $this->db->fetchRow(
        'SELECT id FROM economy_codebooks_fiscal_years
         WHERE date_begin <= %d AND date_end >= %d
           AND docState IN (10, 40, 80)
         ORDER BY date_begin DESC
         LIMIT 1',
        $accountingDate, $accountingDate,
    );
    return $row !== null ? (int) $row['id'] : null;
}

protected function resolveFiscalMonthId(string $accountingDate): ?int
{
    if ($this->db === null) {
        return null;
    }
    // Pick the regular month (period_type = 1), not opening (0) or closing (2)
    $row = $this->db->fetchRow(
        'SELECT id FROM economy_codebooks_fiscal_months
         WHERE date_begin <= %d AND date_end >= %d AND period_type = 1
         LIMIT 1',
        $accountingDate, $accountingDate,
    );
    return $row !== null ? (int) $row['id'] : null;
}

protected function resolveVatPeriodId(?string $vatDuzp, ?int $vatRegistrationId): ?int
{
    if ($vatDuzp === null || $vatRegistrationId === null || $this->db === null) {
        return null;
    }
    $row = $this->db->fetchRow(
        'SELECT id FROM economy_codebooks_vat_periods
         WHERE vat_registration = %i
           AND date_begin <= %d AND date_end >= %d
           AND docState IN (10, 40, 80)
         LIMIT 1',
        $vatRegistrationId, $vatDuzp, $vatDuzp,
    );
    return $row !== null ? (int) $row['id'] : null;
}

private function resolveAccountingPeriods(array &$data): void
{
    if (!empty($data['accounting_date'])) {
        $data['fiscal_year']  = $this->resolveFiscalYearId($data['accounting_date']);
        $data['fiscal_month'] = $this->resolveFiscalMonthId($data['accounting_date']);
    }
    if (!empty($data['vat_duzp']) && !empty($data['vat_registration'])) {
        $data['vat_period'] = $this->resolveVatPeriodId(
            $data['vat_duzp'],
            (int) $data['vat_registration'],
        );
    }
}
```

### `calculateRowPrice($row)`

```php
protected function calculateRowPrice(array &$row): void
{
    $rowKind = (int) ($row['row_kind'] ?? 1);
    if ($rowKind !== 1) {
        // Text row — no calculation
        $row['total_price'] = null;
        return;
    }

    $qty = (float) ($row['quantity'] ?? 0);
    $mode = (int) ($row['price_calc_mode'] ?? 0);

    if ($mode === 0) {
        // From unit price: total = qty * unit_price
        $unitPrice = (float) ($row['unit_price'] ?? 0);
        $row['total_price'] = round($qty * $unitPrice, 2);
    } else {
        // From total: unit_price = total / qty
        $totalPrice = (float) ($row['total_price'] ?? 0);
        $row['unit_price'] = $qty > 0 ? round($totalPrice / $qty, 4) : 0.0;
    }

    // Apply discount (pct OR amount, not both)
    $totalPrice = (float) ($row['total_price'] ?? 0);
    if (!empty($row['discount_pct'])) {
        $discount = round($totalPrice * ((float) $row['discount_pct']) / 100.0, 2);
        $row['total_price'] = round($totalPrice - $discount, 2);
    } elseif (!empty($row['discount_amount'])) {
        $row['total_price'] = round($totalPrice - (float) $row['discount_amount'], 2);
    }
}
```

### `calculateRowVat($row, $vatMode)`

```php
protected function calculateRowVat(array &$row, int $vatMode): void
{
    $rowKind = (int) ($row['row_kind'] ?? 1);
    if ($rowKind !== 1) {
        $row['vat_base'] = null;
        $row['vat_amount'] = null;
        $row['vat_total'] = null;
        return;
    }

    $totalPrice = (float) ($row['total_price'] ?? 0);

    if ($vatMode === 0 || empty($row['vat_code']) || empty($row['vat_pct'])) {
        // No VAT or no code/pct — base = total, vat = 0
        $row['vat_base'] = $totalPrice;
        $row['vat_amount'] = 0.0;
        $row['vat_total'] = $totalPrice;
        return;
    }

    $pct = (float) $row['vat_pct'];

    if ($vatMode === 1) {
        // From base — total_price is BASE
        $row['vat_base'] = $totalPrice;
        $row['vat_amount'] = round($totalPrice * $pct / 100.0, 2);
        $row['vat_total'] = round($row['vat_base'] + $row['vat_amount'], 2);
    } elseif ($vatMode === 2) {
        // From total — total_price INCLUDES vat
        $row['vat_total'] = $totalPrice;
        $row['vat_base'] = round($totalPrice / (1 + $pct / 100.0), 2);
        $row['vat_amount'] = round($row['vat_total'] - $row['vat_base'], 2);
    }
}
```

### `buildVatRecapitulation($data)`

Zásadní metoda — sestavuje rekapitulaci včetně reverse charge páru. Kompletní
algoritmus z `docs/docs-mvp.md` sekce 8.2:

```php
/** @return array<int, array<string, mixed>> */
protected function buildVatRecapitulation(array &$data): array
{
    $rows = $data['rows'] ?? [];
    if (empty($rows) || !is_array($rows)) {
        return [];
    }

    $vatRegId = $data['vat_registration'] ?? null;
    $countryCode = $this->resolveCountryFromVatRegistration($vatRegId);
    if ($countryCode === null) {
        // No VAT registration → no recap (e.g. doc with vat_mode=0)
        return [];
    }

    $vatCodes = $this->vatRateResolver->getVatCodes(
        $countryCode,
        direction: null,
        place: null,
        includeHidden: true,  // we need hidden for paired generation
    );

    // 1. Group rows by (vat_code, vat_pct), sum base
    $grouped = [];
    foreach ($rows as $row) {
        $rowKind = (int) ($row['row_kind'] ?? 1);
        if ($rowKind !== 1 || empty($row['vat_code']) || empty($row['vat_pct'])) {
            continue;
        }
        $key = $row['vat_code'] . '|' . $row['vat_pct'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'vat_code' => (string) $row['vat_code'],
                'vat_pct'  => (float) $row['vat_pct'],
                'base'     => 0.0,
            ];
        }
        $grouped[$key]['base'] += (float) ($row['total_price'] ?? 0);
    }

    // 2. For each group, build primary line + optional paired line
    $recap = [];
    $sortOrder = 0;
    $vatMode = (int) ($data['vat_mode'] ?? 1);
    $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
    $vatRoundingMode = (int) ($data['vat_rounding_mode'] ?? 2);

    foreach ($grouped as $entry) {
        $code = $entry['vat_code'];
        if (!isset($vatCodes[$code])) {
            // Unknown code in this country's config — skip silently? Or throw?
            // Phase 4: skip with log/warning. Validation should prevent this earlier.
            continue;
        }
        $codeDef = $vatCodes[$code];

        $base = $entry['base'];
        $tax = empty($codeDef['noPayTax'])
            ? $this->applyVatRounding($base * $entry['vat_pct'] / 100.0, $vatRoundingMode)
            : 0.0;
        $base = round($base, 2);

        $primary = [
            'vat_code'        => $code,
            'vat_pct'         => $entry['vat_pct'],
            'base'            => $base,
            'tax'             => $tax,
            'total'           => round($base + $tax, 2),
            'sum_base'        => (int) ($codeDef['sumBase']  ?? 1),
            'sum_tax'         => (int) ($codeDef['sumTax']   ?? 1),
            'sum_total'       => (int) ($codeDef['sumTotal'] ?? 1),
            'is_reverse_pair' => 0,
            'sort_order'      => $sortOrder++,
        ];
        $primary['base_dom']  = round($primary['base']  * $exchRate, 2);
        $primary['tax_dom']   = round($primary['tax']   * $exchRate, 2);
        $primary['total_dom'] = round($primary['total'] * $exchRate, 2);
        $recap[] = $primary;

        // Reverse charge — generate paired (oddanění) row
        if (!empty($codeDef['reverseVatCode']) && isset($vatCodes[$codeDef['reverseVatCode']])) {
            $reverseCodeKey = $codeDef['reverseVatCode'];
            $reverseDef = $vatCodes[$reverseCodeKey];

            // Use DUZP date to resolve reverse percentage
            $reversePct = $this->vatRateResolver->resolveVatPct(
                $countryCode,
                $reverseCodeKey,
                $data['vat_duzp'] ?? date('Y-m-d'),
            );
            $reverseTax = $this->applyVatRounding($base * $reversePct / 100.0, $vatRoundingMode);

            $paired = [
                'vat_code'        => $reverseCodeKey,
                'vat_pct'         => $reversePct,
                'base'            => $base,
                'tax'             => $reverseTax,
                'total'           => round($base + $reverseTax, 2),
                'sum_base'        => (int) ($reverseDef['sumBase']  ?? 1),
                'sum_tax'         => (int) ($reverseDef['sumTax']   ?? 1),
                'sum_total'       => (int) ($reverseDef['sumTotal'] ?? 1),
                'is_reverse_pair' => 1,
                'sort_order'      => $sortOrder++,
            ];
            $paired['base_dom']  = round($paired['base']  * $exchRate, 2);
            $paired['tax_dom']   = round($paired['tax']   * $exchRate, 2);
            $paired['total_dom'] = round($paired['total'] * $exchRate, 2);
            $recap[] = $paired;
        }
    }

    return $recap;
}

private function resolveCountryFromVatRegistration(?int $vatRegId): ?string
{
    if ($vatRegId === null || $this->db === null) {
        return null;
    }
    $row = $this->db->fetchRow(
        'SELECT country FROM economy_codebooks_vat_registrations WHERE id = %i',
        $vatRegId,
    );
    return $row !== null ? (string) $row['country'] : null;
}
```

**Pozn:** `VatRateResolver` je dostupný přes injekci. Pokud framework
neinjectuje servisy do Document, zvol jednu z cest:
- (a) Vyžeň `VatRateResolver` přímo z `ConfigRuntime` v Document base —
  ten je dostupný přes `$this->config`
- (b) Přidej do `DocDocument` `__construct(VatRateResolver $vat, ...)` —
  vyžádej framework, aby resolver injectoval

Doporučuji (a) — méně invazivní.

### `sumTotals($data, $recap)` + zaokrouhlení

```php
protected function sumTotals(array &$data, array $recap): void
{
    $base = 0.0;
    $vat  = 0.0;
    $total = 0.0;

    foreach ($recap as $r) {
        if (!empty($r['sum_base']))  $base  += (float) $r['base'];
        if (!empty($r['sum_tax']))   $vat   += (float) $r['tax'];
        if (!empty($r['sum_total'])) $total += (float) $r['total'];
    }

    $data['total_base']    = round($base, 2);
    $data['total_vat']     = round($vat, 2);
    $data['total_amount']  = round($total, 2);
    $data['total_rounding'] = 0.0;  // updated in applyTotalRounding
}

private function applyTotalRounding(array &$data): void
{
    $original = (float) ($data['total_amount'] ?? 0);
    $mode = (int) ($data['total_rounding_mode'] ?? 0);
    $rounded = $this->applyRounding($original, $mode);
    $data['total_amount']    = $rounded;
    $data['total_rounding']  = round($rounded - $original, 2);
}

protected function applyRounding(float $amount, int $mode): float
{
    return match ($mode) {
        0 => round($amount, 2),                    // No rounding (still 2 decimals)
        1 => (float) round($amount, 0),            // To whole units
        2 => round($amount, 2),                    // To 0.01 (effectively same as default)
        default => round($amount, 2),
    };
}

private function applyVatRounding(float $amount, int $mode): float
{
    return $this->applyRounding($amount, $mode);
}

private function applyExchangeRate(array &$data): void
{
    $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
    if ($exchRate <= 0) {
        $exchRate = 1.0;
    }
    $data['total_base_dom']   = round((float) ($data['total_base'] ?? 0)   * $exchRate, 2);
    $data['total_vat_dom']    = round((float) ($data['total_vat'] ?? 0)    * $exchRate, 2);
    $data['total_amount_dom'] = round((float) ($data['total_amount'] ?? 0) * $exchRate, 2);
}
```

### `assignDocumentNumber($data)` — atomický counter

Algoritmus z `docs-mvp.md` sekce 5.3, transakční:

```php
protected function assignDocumentNumber(array &$data): void
{
    if ($this->db === null) {
        throw new \LogicException('No DB connection available');
    }

    $seriesId = (int) ($data['number_series'] ?? 0);
    if ($seriesId === 0) {
        throw new \LogicException('Cannot assign number — number_series missing');
    }

    $series = $this->db->fetchRow(
        'SELECT * FROM docs_core_number_series WHERE id = %i',
        $seriesId,
    );
    if ($series === null) {
        throw new \LogicException("Number series id={$seriesId} not found");
    }

    $resetScope = (string) ($series['reset_scope'] ?? 'fiscal_year');
    $fyId = ($resetScope === 'fiscal_year')
        ? $this->resolveFiscalYearId($data['accounting_date'])
        : null;

    $this->db->begin();
    try {
        // 1. Idempotently ensure counter row exists
        $this->db->query(
            'INSERT IGNORE INTO docs_core_number_counters
             (number_series, fiscal_year, last_assigned)
             VALUES (%i, %iN, 0)',
            $seriesId, $fyId,
        );

        // 2. Lock + read counter
        $row = $this->db->fetchRow(
            'SELECT last_assigned FROM docs_core_number_counters
             WHERE number_series = %i AND fiscal_year <=> %iN
             FOR UPDATE',
            $seriesId, $fyId,
        );
        $current = (int) ($row['last_assigned'] ?? 0);
        $newSeq = $current + 1;

        // 3. Increment
        $this->db->query(
            'UPDATE docs_core_number_counters
             SET last_assigned = %i
             WHERE number_series = %i AND fiscal_year <=> %iN',
            $newSeq, $seriesId, $fyId,
        );

        // 4. Update data
        $data['sequence_number'] = $newSeq;
        $data['fiscal_year']     = $fyId;
        $data['doc_number']      = $this->resolvePattern(
            (string) $series['doc_number_pattern'],
            $data,
            $series,
        );

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}

protected function resolvePattern(string $pattern, array $data, array $series): string
{
    return preg_replace_callback(
        '/%(D|C|y|Y|3|4|5|6)/',
        function (array $m) use ($data, $series): string {
            return match ($m[1]) {
                'D' => $this->getDocIdCode((string) ($data['doc_type'] ?? '')),
                'C' => (string) ($series['doc_number_code'] ?? ''),
                'y' => substr($this->getFiscalYearLabel($data), -2),
                'Y' => $this->getFiscalYearLabel($data),
                '3' => str_pad((string) ($data['sequence_number'] ?? 0), 3, '0', STR_PAD_LEFT),
                '4' => str_pad((string) ($data['sequence_number'] ?? 0), 4, '0', STR_PAD_LEFT),
                '5' => str_pad((string) ($data['sequence_number'] ?? 0), 5, '0', STR_PAD_LEFT),
                '6' => str_pad((string) ($data['sequence_number'] ?? 0), 6, '0', STR_PAD_LEFT),
                default => $m[0],
            };
        },
        $pattern,
    ) ?? $pattern;
}

private function getDocIdCode(string $docType): string
{
    if ($docType === '' || $this->config === null) {
        return '';
    }
    $cfg = $this->config->cfgItem('docs.core.docTypes');
    return is_array($cfg) && isset($cfg[$docType]['doc_id_code'])
        ? (string) $cfg[$docType]['doc_id_code']
        : '';
}

private function getFiscalYearLabel(array $data): string
{
    if (empty($data['fiscal_year']) || $this->db === null) {
        // Fallback to calendar year from accounting_date
        if (!empty($data['accounting_date'])) {
            return substr($data['accounting_date'], 0, 4);
        }
        return date('Y');
    }
    $row = $this->db->fetchRow(
        'SELECT doc_number_prefix, name FROM economy_codebooks_fiscal_years WHERE id = %i',
        (int) $data['fiscal_year'],
    );
    if ($row === null) {
        return date('Y');
    }
    // doc_number_prefix is 2-char (e.g. "26"); for %Y placeholder we need 4-char
    // Use the year embedded in name (e.g. "2026" or "2026-2027")
    $name = (string) ($row['name'] ?? '');
    if (preg_match('/^(\d{4})/', $name, $matches)) {
        return $matches[1];
    }
    return date('Y');
}
```

**Pozn k `%iN` placeholderu:** to je dibi syntax pro nullable int. Pokud
projekt používá jinou konvenci (např. `%?i`), použij ji. Klíčový je
**NULL-safe equality** v WHERE klauzuli (`<=>`) pro správnou shodu při
`fiscal_year IS NULL` (řady s `reset_scope = 'none'`).

### `releaseDocumentNumber($data, $originalData)` — pro 20→10

```php
protected function releaseDocumentNumber(array &$data, ?array $originalData): void
{
    if ($this->db === null || $originalData === null) {
        throw new \LogicException('Cannot release number without original data');
    }

    $seriesId = (int) ($originalData['number_series'] ?? 0);
    $fyId     = $originalData['fiscal_year'] ?? null;
    $sequence = (int) ($originalData['sequence_number'] ?? 0);

    if ($seriesId === 0 || $sequence === 0) {
        // Already released or never assigned — nothing to do
        $data['sequence_number'] = null;
        $data['doc_number']      = '';
        return;
    }

    // Verify "last in series" rule
    $maxRow = $this->db->fetchRow(
        'SELECT MAX(sequence_number) AS max_seq
         FROM docs_core_heads
         WHERE number_series = %i AND fiscal_year <=> %iN',
        $seriesId, $fyId,
    );
    $maxSeq = (int) ($maxRow['max_seq'] ?? 0);

    if ($maxSeq !== $sequence) {
        // Not the last — refuse the transition
        throw new \DomainException(
            "Doklad #{$sequence} není poslední v řadě (poslední je #{$maxSeq}). " .
            "Vrácení do Konceptu by vytvořilo díru v sekvenci.",
        );
    }

    // Decrement counter atomically (with safety check on current value)
    $this->db->begin();
    try {
        $affected = $this->db->query(
            'UPDATE docs_core_number_counters
             SET last_assigned = last_assigned - 1
             WHERE number_series = %i AND fiscal_year <=> %iN AND last_assigned = %i',
            $seriesId, $fyId, $sequence,
        );

        // Reset doc state
        $data['sequence_number'] = null;
        $data['fiscal_year']     = null;
        $data['doc_number']      = !empty($data['id'])
            ? '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT)
            : '';
        $data['supplier_snapshot'] = null;
        $data['customer_snapshot'] = null;

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

**Důležité:** `\DomainException` z `releaseDocumentNumber` se musí na úrovni
controlleru (`CrudController`) zachytit a převést na HTTP 422
INVALID_STATE_TRANSITION s lokalizovanou zprávou. Pokud framework má
specifický exception type pro tohle, použij ho. Zkontroluj, jak existující
moduly hlásí "ne dovolený přechod stavu".

### `maintainSnapshots($data, $originalData)`

```php
protected function maintainSnapshots(array &$data, ?array $originalData): void
{
    $newState = (int) ($data['docState'] ?? 10);

    // Snapshots only in editable confirmed states
    if (!in_array($newState, [20, 80], true)) {
        return;
    }

    // Build if empty (first transition into 20) or partner changed
    $partnerChanged = ($data['partner'] ?? null) !== ($originalData['partner'] ?? null);
    $needsBuild = empty($data['supplier_snapshot'])
                || empty($data['customer_snapshot'])
                || $partnerChanged;

    if (!$needsBuild) {
        return;
    }

    $this->buildSnapshots($data);
}

private function buildSnapshots(array &$data): void
{
    $docTypeKey = (string) ($data['doc_type'] ?? '');
    $docTypes = $this->config?->cfgItem('docs.core.docTypes') ?? [];
    $tradeDir = (int) ($docTypes[$docTypeKey]['trade_dir'] ?? 0);
    if ($tradeDir === 0) {
        return;  // unknown doc type
    }

    $partnerSnap = $this->buildPersonSnapshot(
        personId:  (int) ($data['partner'] ?? 0),
        addressId: $data['partner_address'] ?? null,
        bankAccountId: null,  // partner bank is on the head (string)
        vatRegistrationId: null,
    );

    $ownPersonId = $this->ownCompanyResolver->getOwnPersonId();
    if ($ownPersonId === null) {
        throw new \DomainException(
            'Není nastavena vlastní firma (base_persons_persons.is_own = 1).',
        );
    }
    $ownHqAddress = $this->ownCompanyResolver->getOwnHeadquartersAddress();

    $ownSnap = $this->buildPersonSnapshot(
        personId:  $ownPersonId,
        addressId: $ownHqAddress !== null ? (int) $ownHqAddress['id'] : null,
        bankAccountId: $data['bank_account'] ?? null,
        vatRegistrationId: $data['vat_registration'] ?? null,
    );

    if ($tradeDir === 1) {
        // Output (issued invoice) — we are supplier
        $data['supplier_snapshot'] = $ownSnap;
        $data['customer_snapshot'] = $partnerSnap;
    } else {
        // Input (received invoice) — we are customer
        $data['supplier_snapshot'] = $partnerSnap;
        $data['customer_snapshot'] = $ownSnap;
    }
}

/**
 * @return array<string, mixed>
 */
private function buildPersonSnapshot(
    int $personId,
    ?int $addressId,
    ?int $bankAccountId,
    ?int $vatRegistrationId,
): array {
    if ($this->db === null || $personId === 0) {
        return [];
    }

    $person = $this->db->fetchRow(
        'SELECT * FROM base_persons_persons WHERE id = %i',
        $personId,
    );
    if ($person === null) {
        return [];
    }

    $snap = [
        'name'               => (string) ($person['full_name'] ?? ''),
        'company_id'         => $person['company_id']        ?? null,
        'tax_id'             => $person['tax_id']            ?? null,
        'vat_id'             => $person['vat_id']            ?? null,
        'court_registration' => $person['court_registration'] ?? null,
        'contact'            => [
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
        ],
    ];

    if ($addressId !== null) {
        $address = $this->db->fetchRow(
            'SELECT * FROM base_persons_addresses WHERE id = %i',
            $addressId,
        );
        if ($address !== null) {
            $snap['address'] = [
                'street'        => $address['street'] ?? null,
                'house_number'  => $address['house_number'] ?? null,
                'city'          => $address['city'] ?? null,
                'city_part'     => $address['city_part'] ?? null,
                'zip'           => $address['zip'] ?? null,
                'country'       => $address['country'] ?? null,
                'display_block' => $address['display_block'] ?? null,
                'display_line'  => $address['display_line'] ?? null,
            ];
        }
    }

    if ($bankAccountId !== null) {
        // bank_account on header references economy_codebooks_bank_accounts
        $bank = $this->db->fetchRow(
            'SELECT * FROM economy_codebooks_bank_accounts WHERE id = %i',
            $bankAccountId,
        );
        if ($bank !== null) {
            $snap['bank_account'] = [
                'name'           => $bank['name'] ?? null,
                'account_number' => $bank['account_number'] ?? null,
                'iban'           => $bank['iban'] ?? null,
                'bic'            => $bank['bic'] ?? null,
                'currency'       => $bank['currency'] ?? null,
            ];
        }
    }

    if ($vatRegistrationId !== null) {
        $reg = $this->db->fetchRow(
            'SELECT * FROM economy_codebooks_vat_registrations WHERE id = %i',
            $vatRegistrationId,
        );
        if ($reg !== null) {
            $snap['vat_registration'] = [
                'country' => $reg['country'] ?? null,
                'vat_id'  => $reg['vat_id'] ?? null,
            ];
        }
    }

    return $snap;
}
```

### Default `variable_symbol` po Potvrzení

```php
private function applyVariableSymbolDefault(array &$data): void
{
    if (!empty($data['variable_symbol'])) {
        return;  // user-set, keep
    }
    if (!empty($data['sequence_number'])) {
        $data['variable_symbol'] = (string) $data['sequence_number'];
    }
}
```

## Validace per stav

V `DocDocument::validate` rozlišíme podle cílového `docState`:

```php
public function validate(array &$data): ValidationResult
{
    $result = parent::validate($data);  // base required: number_series, issue_date, accounting_date

    $newState = (int) ($data['docState'] ?? 10);

    // Concept — minimum
    if ($newState === 10) {
        return $result;
    }

    // Confirmed and beyond — stricter
    if (in_array($newState, [20, 40, 80], true)) {
        if (empty($data['partner'])) {
            $result->addError('partner', 'Partner je povinný', 'required');
        }
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        if ($vatMode !== 0 && empty($data['vat_registration'])) {
            $result->addError('vat_registration', 'Registrace DPH je povinná', 'required');
        }
        if (empty($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
            $result->addError('rows', 'Doklad musí mít alespoň jeden řádek', 'no_rows');
        }
        if (!empty($data['doc_currency']) && !empty($data['home_currency'])
            && $data['doc_currency'] !== $data['home_currency']
            && empty($data['exchange_rate'])) {
            $result->addError('exchange_rate', 'Kurz je povinný pro cizí měnu', 'required');
        }
        // Own company must be configured
        if ($this->ownCompanyResolver !== null
            && $this->ownCompanyResolver->getOwnPersonId() === null) {
            $result->addError(
                '_form',
                'Není nastavena vlastní firma. Otevři Osoby a označ záznam jako vlastní firmu.',
                'no_own_company',
            );
        }
    }

    // Storno: no extra validation, what was OK in V pořádku is OK in Storno

    return $result;
}
```

## Konkrétní `DocsHeadsDocument`

Nový soubor `modules/docs/core/src/DocsHeadsDocument.php`:

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Default Document class for docs_core_heads.
 *
 * In Phase 4 this is the only concrete subclass — it inherits all logic
 * from DocDocument unchanged. Phase 6 introduces type-specific subclasses
 * (IssuedInvoiceDocument, ReceivedInvoiceDocument) in modules
 * docs.invoicesOut and docs.invoicesIn.
 */
class DocsHeadsDocument extends DocDocument
{
    // Empty body — pure inheritance.
    // Override hooks here only if there's behavior shared by all doc types
    // that's specific to the heads table (rare).
}
```

A registrace v `module.jsonc`:

```jsonc
"documentClasses": [
    {
        "table": "docs_core_number_series",
        "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesDocument"
    },
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Docs\\Core\\DocsHeadsDocument"
    }
]
```

## Extension `base.persons` — `payment_term_days`

Přidat sloupec přes `extensions/` v `docs.core` (extension pattern, nikoli
přímá úprava `base.persons` JSONC). Důvod: je to ekonomická informace,
která nepatří přímo do `base.persons`, ale `docs.core` ji vlastní.

Vytvoř `modules/docs/core/extensions/base_persons_persons.jsonc`:

```jsonc
{
    "table": "base_persons_persons",
    "columns": [
        {
            "id": "payment_term_days",
            "name": "Payment term (days)",
            "name:cs": "Splatnost (dny)",
            "name:en": "Payment term (days)",
            "type": "smallint",
            "default": 14,
            "nullable": true,
            "group": "status"
        }
    ]
}
```

A do `modules/docs/core/module.jsonc` přidat:

```jsonc
"extensions": ["base_persons_persons"]
```

(Vzor pro extension formát: zkontroluj existující extension v jiných
modulech nebo `docs/table-definitions.md`.)

**Aktualizace `PersonsForm`:** v existujícím `modules/base/persons/src/PersonsForm.php`
ideálně přidat `payment_term_days` do basic tab (sekce status nebo nová
sekce "Platba"). Ale pozor: pokud ho přidáme jako extension, je možné
zobrazení automatizovat přes default form generation. Pokud chceš mít
explicit kontrolu, drobně rozšiř `PersonsForm`. Detail řeší implementace.

## Hooks na controller / framework

Možné, že Fáze 4 vyžaduje úpravy mimo modul `docs.core`:

1. **Document::beforeSave signature** — rozšíření o `?array $originalData`.
   Soubor: `Shipard\Core\Document\Document` + caller v `TableGateway`.

2. **DomainException → 422** — pokud `releaseDocumentNumber` vyhodí
   `\DomainException`, `CrudController` musí to převést na HTTP 422 s
   error payload. Zkontroluj, jak existující moduly toto dělají
   (např. validace v Document → ValidationResult → 422 bývá automaticky;
   exception během beforeSave může být separátní cesta).

3. **VatRateResolver injection** — pokud Document base nemá přístup k
   `ConfigRuntime` přímo (přes `$this->config`), musí to být řešitelné
   kvůli `buildVatRecapitulation`. V `DocDocument` instanciuj
   `VatRateResolver` přes `new VatRateResolver($this->config)`.

4. **OwnCompanyResolver injection** — analogicky, instanciuj v `DocDocument`
   přes `new OwnCompanyResolver($this->db)` v konstruktoru / lazy.

## Hotovo když

- [ ] Insert prázdného Konceptu projde (kontrola Fáze 1 stále platí)
- [ ] Insert Konceptu s 1 řádkem (`row_kind=1`, `quantity=2`, `unit_price=100`,
      `vat_code=cz-110`) přepočítá `total_price=200`, `vat_base=200`,
      `vat_amount=42` (21 % v 2026), `vat_total=242`; rekapitulace má 1 řádek;
      hlavičkové součty `total_base=200`, `total_vat=42`, `total_amount=242`
- [ ] Stejný doklad s `vat_mode=2` (z ceny celkem), unit_price=242 →
      `vat_base=200`, `vat_amount=42`, `vat_total=242`
- [ ] Doklad s reverse charge kódem (`vat_code=cz-115` PDP4) generuje
      v rekapitulaci 2 řádky: primární cz-115 (`tax=0`, `sum_tax=0`) a
      párový cz-203 (`tax=42`, `is_reverse_pair=1`, všechny `sum_*=0`).
      Hlavičkové součty: `total_base=200`, `total_vat=0`, `total_amount=200`.
- [ ] Přechod Koncept → Potvrzeno přidělí `sequence_number=1`,
      `doc_number="126A0001"` (nebo podle pattern), naplní snapshoty
      (`supplier_snapshot` má naši firmu, `customer_snapshot` má partnera
      pro `invno`)
- [ ] Druhý doklad ze stejné řady má `sequence_number=2`
- [ ] Atomicita counteru: simultaneous insert dvou Potvrzení nikdy neudělí
      stejné číslo (test přes 2 paralelní procesy nebo přes mock s
      kontrolovaným pořadím; lze nahradit testem že `unq_series_seq` UNIQUE
      vyhodí duplicate key error pokud by k tomu došlo)
- [ ] Přechod Potvrzeno → Koncept u **posledního** dokladu v řadě uvolní
      číslo: `sequence_number=null`, `doc_number='!0000000123'`,
      counter dekrementován
- [ ] Přechod Potvrzeno → Koncept u **NE-posledního** dokladu v řadě vrátí
      `\DomainException` resp. HTTP 422
- [ ] Přechod Potvrzeno → V pořádku → Storno: doklad si drží své číslo,
      `mainState=4`, data včetně rekapitulace zůstávají
- [ ] Změna partnera ve V opravě (80) přebuduje `customer_snapshot`
      (resp. `supplier_snapshot` u FPB)
- [ ] Validace při Potvrzení odmítne doklad bez vlastní firmy (chyba
      `no_own_company`), bez partnera (chyba `required`), bez řádků
      (chyba `no_rows`), s cizí měnou bez kurzu (chyba `required` na
      `exchange_rate`)
- [ ] Time-travel: doklad s `vat_duzp=2012-06-01` a `vat_code=cz-110`
      použije procento 20 % (ne aktuální 21 %)
- [ ] Default `accounting_date` se vyplní z `issue_date` pokud chybí
- [ ] Default `due_date` = `issue_date + payment_term_days` z partnera
      (fallback 14)
- [ ] PHPUnit testy pokrývají všechny výše uvedené scénáře (kromě
      atomicity counteru — tu lze ověřit jen integračně, není striktně
      povinné v PHPUnit)
- [ ] `modules/docs/core/extensions/base_persons_persons.jsonc` přidá
      sloupec `payment_term_days` (smallint default 14, nullable)
- [ ] `bin/shpd-ds ds-upgrade` aplikuje extension bez chyb

## Konvence

- **PHP 8.3** strict_types
- **Vícejazyčnost**: chyby v UI češtinou, error code (3. parametr `addError`)
  v angličtině jako stable identifier
- **Floats**: zaokrouhlovat **všude** přes `round(..., 2)` resp. `round(..., 4)`
  pro `unit_price`. Nikdy nenechat výsledky operací bez round (vznikají
  artefakty typu `0.30000000000000004`)
- **Transakce**: `assignDocumentNumber` a `releaseDocumentNumber` musí být
  v `$db->begin()` / `commit()` / `rollback()`. Při exception
  `$db->rollback()` v `catch` bloku
- **NULL-safe equality `<=>`** pro `fiscal_year` v WHERE klauzulích
- **Komentáře** v PHP: anglicky, stručně; klíčová logika jako proč daný
  algoritmus, ne co dělá řádek po řádku

## Doporučené pořadí implementace

1. **Resolvery období** (`resolveFiscalYearId`, `resolveFiscalMonthId`,
   `resolveVatPeriodId`) + PHPUnit testy pro každý — nezávislé, izolovatelné
2. **`applyDateDefaults`, `applyHomeCurrency`** — defaulty, nezávislé
3. **`calculateRowPrice`** + 4-5 testovacích scénářů (mode 0/1, sleva %,
   sleva absolutní, textový řádek)
4. **`calculateRowVat`** + testy 3 vat_modes
5. **`buildVatRecapitulation`**:
   - Bez reverse charge (cz-110 → 1 řádek)
   - S reverse charge (cz-115 → 2 řádky)
   - Více kódů v dokladu (groupování)
   - `cz-000` / `vat_code = null` / `vat_pct = null` → fallback bez crash
6. **`sumTotals` + zaokrouhlení + exchange rate** + testy
7. **`assignDocumentNumber` + `resolvePattern`** — testy s různými vzory,
   různými reset_scope, atomicita přes 2 sekvenční volání
8. **`releaseDocumentNumber`** — test posledního/ne-posledního
9. **`maintainSnapshots` + `buildPersonSnapshot`** — test s různým
   `trade_dir`, snapshot prázdný vs. update
10. **`processStateTransition` orchestrace** — návaznost na 7-9
11. **Validace per stav** + testy
12. **`DocsHeadsDocument`** thin wrapper + registrace v `module.jsonc`
13. **Extension `payment_term_days`** + `ds-upgrade`
14. **End-to-end test** přes API: create Koncept → add row → set partner →
    Confirm → check snapshots → toggle to V opravě → modify → V pořádku →
    Storno → ověř, že číslo zůstává

## Otevřené body (ne-blokující)

- **Pattern resolveFiscalYearLabel pro `%y` placeholder** — současná
  implementace bere `name` z `economy_codebooks_fiscal_years` a regex hledá
  4-ciferný rok. Pokud hospodářský rok 2026-2027 vrátí "2026" pro `%y`,
  je to OK pro CZ kontext (kde většina firem má kalendářní rok). Pokud
  by někdo používal `name: "2026-2027"`, mohlo by být překvapivé.
  Alternativa: použít `doc_number_prefix` přímo (ten už obsahuje
  2-cifernou variantu — pro %y stačí, pro %Y musíme najít jinde).
  Doporučení: pro Fázi 4 stačí pojetí "vezmi první 4 cifry z name".
  Pokud se ukáže jako problém, upřesníme.
- **Validace `exchange_rate > 0`** — pokud chce Fáze 4 přijatá kontrola,
  přidat do validate. Negativní kurz nedává smysl.
- **Kontrola, že `bank_account` existuje a má `currency = doc_currency`**
  pro vydané faktury — nice-to-have, můžeme přidat pokud je to v UI
  triviální. Pro Fázi 4 stačí ověřit existenci.
- **Editace v Storno (30)** — task zatím podle docs.core.docStates
  Storno = readOnly (`readOnly: 1`). Pokud uživatel chce editovat Storno,
  musí přejít přes V opravě (30 → 80 → … → 30 nebo 40). Pokud se ukáže
  potřeba editace přímo z 30, to je věc pozdější iterace.
