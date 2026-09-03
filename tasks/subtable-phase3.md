# Sub-tabulky ve formulářích — fáze 3: přesun řádků nahoru/dolů a automatické `order_pos`

**Stav:** naplánováno — design schválen 2026-09-03 (issue #53), závisí na `subtable-phase1.md`

## Kontext / Cíl

Třetí fáze issue **shipard/shpd#53**. Sebik: „na řádku tabulky … možná
i tlačítka pro přesun řádku nahoru / dolů". Anna: zařadit hned.

Dnešní stav pořadí řádků dokladu (`docs_core_rows.order_pos`):

- `DocRowsForm` má na konci sekci „Pořadí" s ručně editovatelným číslem
  (`DocRowsForm.php` ř. 135–136 a 214) — uživatel si pořadí píše sám;
- **nový řádek přidaný přes sub-formulář `order_pos` nedostane** —
  `DocRowsDocument` nemá `beforeSave`; automatické číslování existuje jen
  v `DocDocument` při zápisu řádků z payloadu (ř. 702, 726 — importy,
  hromadný zápis). Řádky přidané ručně tedy mají `order_pos = 0`/NULL a
  řadí se podle `id`;
- žádný endpoint pro přesun neexistuje.

Cíl: šipky ▲ ▼ v sub-tabulce u tabulek, které mají pořadový sloupec;
přesun je atomický a po něm mají řádky souvislé pořadí 1..N; nový řádek
dostane pořadí automaticky.

## Rozhodnutí k designu

- ✓ **Přesun řeší dedikovaný endpoint, ne dvě PATCH volání z klienta.**
  V diskusi padlo „klient prohodí `order_pos` dvou řádků" — po prozkoumání
  kódu to nefunguje: řádky přidané přes sub-formulář mají všechny
  `order_pos = 0`, takže prohození dvou nul nic nezmění. Server proto při
  každém přesunu **přečísluje celou skupinu** (1..N podle aktuálního pořadí
  `order_pos ASC, id ASC`, pak prohodí sousedy) v jedné transakci.
- ✓ **Pořadový sloupec deklaruje `subtableTab()`** novým parametrem
  `?string $orderColumn = null`; jde na drát jako `order_column`
  (klíč zavedený už ve fázi 1, dosud vždy `null`). Šipky se zobrazí jen
  když není `null` a rodič není read-only. Osoby (kontakty, adresy, účty)
  pořadí nemají → beze změny.
- ✓ **Automatické `order_pos` pro nový řádek** v `DocRowsDocument::beforeSave`:
  prázdné/0 → `MAX(order_pos) + 1` v rámci `doc_head`. Generický
  mechanismus pro jiné tabulky se teď nedělá (YAGNI) — až bude druhá tabulka
  s pořadím, přesune se do `Document` podle `order_column` z definice.
- ✓ **Ruční pole „Pořadí“ z `DocRowsForm` se odstraňuje** (oba layouty). S automatickým
  číslováním a šipkami je matoucí; `order_pos` zůstává ve schématu a v renderu
  sloupce `#`.
- ✓ Přesun **nespouští** přepočet hlavičky (součty ani rekapitulace na
  pořadí nezávisí) — endpoint zapisuje přímo přes DB, ne přes
  `DocRowsDocument::afterSave`. Zapiš to do komentáře endpointu.

## Před implementací přečti

- `tasks/subtable-phase1.md` — kontrakt endpointu `/subtable`, klíč
  `order_column`, `FormSubTable` po fázi 1
- `src/Core/Form/TableForm.php::subtableTab()`, `src/Core/Form/FormTab.php`
  (`$subtable` pole, `toArray()`)
- `src/Api/Router.php::resolveFormRoute()`, `public/index.php::dispatchForm()`
  — přidání routy vedle `subtable` z fáze 1
- `src/Api/Controller/FormController.php::subtable()` (fáze 1) — jak se
  resolvuje tab a dětská tabulka; endpoint přesunu to sdílí
- `src/Core/Document/Document.php` — `beforeSave(array &$data, ?array
  $originalData)` signatura, kdy je `$originalData === null` (nový záznam)
- `modules/docs/core/src/DocRowsDocument.php` — kam přidat `beforeSave`,
  jak `recomputeHeader()` pracuje s `doc_head`
- `modules/docs/core/src/DocDocument.php` ř. 695–730 — existující
  číslování při hromadném zápisu (musí zůstat kompatibilní: 1-based)
- `src/Api/ReadOnlyPolicy.php` — přesun je mutace; musí respektovat
  read-only stav DS i read-only doc state rodiče (viz
  `CrudController::processDocState` ř. 355–390, hláška „Document is
  read-only in state …")
- `modules/docs/core/src/DocRowsForm.php` ř. 135–136, 214 (pole Pořadí)

## Krok 1 — `order_column` v definici tabu

- `TableForm::subtableTab(…, ?string $orderColumn = null)`,
  `FormTab` validuje, že sloupec existuje v dětské tabulce (fail-fast při
  buildu formuláře, stejně jako ostatní kontroly v konstruktoru).
- `FormTab::toArray()` → `subtable.order_column`.
- `FormController::subtable()` (fáze 1) vrací `order_column` z tabu místo
  pevného `null`.
- `DocsHeadsFormBase::buildRowsTab()` → `orderColumn: 'order_pos'`.

## Krok 2 — endpoint přesunu

**`POST /_ui/form/{parentTable}/subtable/{tabId}/{parentId}/move`**
body `{ "id": 4711, "direction": "up" | "down" }`.

`FormController::subtableMove()`:

1. resolve parent + tab stejně jako `subtable()`; tab bez `order_column`
   → 400 `SUBTABLE_NOT_ORDERED`;
2. autorizace zápisu + read-only: DS read-only → stejná odpověď jako
   u `save`; rodič v read-only doc state → 409 se stejnou hláškou jako
   `CrudController::processDocState`; řádek `id` nepatří k `parentId`
   → 404;
3. transakce: načíst ids skupiny `ORDER BY order_col ASC, id ASC`,
   přečíslovat 1..N (jen řádky, kde se hodnota liší — minimalizovat
   UPDATE), najít index `id`, prohodit se sousedem (na kraji → no-op,
   ale přečíslování se provede), commit;
4. response: `{ success: true, data: { order: [ids v novém pořadí] } }`.

Unit test nad fake DB / testovací DS podle toho, jak testuje
`FormController` dnes: (a) všechny nuly → po `down` na prvním je pořadí
1..N s prohozením; (b) `up` na prvním = no-op + přečíslování; (c) cizí
`id` → 404; (d) read-only rodič → 409.

## Krok 3 — `DocRowsDocument::beforeSave`

```php
public function beforeSave(array &$data, ?array $originalData = null): void
{
    parent::beforeSave($data, $originalData);
    if ($originalData === null && (int) ($data['order_pos'] ?? 0) <= 0) {
        $data['order_pos'] = $this->nextOrderPos((int) $data['doc_head']);
    }
}
```

`nextOrderPos`: `SELECT COALESCE(MAX(order_pos), 0) + 1 … WHERE doc_head = ?`.
Ověř, že sub-form save (`FormController::save` → `TableGateway`) hook
`beforeSave` dětského dokumentu skutečně volá (u `afterSave` to funguje,
takže registrace v `DocumentRegistry` existuje — ale zkontroluj pořadí
hooků a že `$originalData` je pro insert `null`).

Test: nový řádek bez `order_pos` do dokladu se třemi řádky (1,2,3) → 4;
nový řádek s explicitním `order_pos = 2` → zůstane 2 (větev zůstává pro importy, které `order_pos` posílají explicitně).

Odstranit `->separator('Pořadí')->number('order_pos')` z obou
layoutů `DocRowsForm`.

**Commit 1:** `feat(forms): přesun řádků sub-tabulky a automatické order_pos`

## Krok 4 — `FormSubTable`: šipky

- Když `orderColumn && !disabled`: u řádku tlačítka ▲ ▼ (`faArrowUp`,
  `faArrowDown`, `iconOnly size="sm"`) před Upravit/Smazat; první řádek
  ▲ disabled, poslední ▼ disabled.
- Klik → `POST …/move` → při úspěchu refetch (nebo lokálně přeuspořádat
  podle `data.order` a refetch až na pozadí — zvol podle toho, jak rychlý
  je endpoint; přeblikávání tabulky je horší než 100 ms čekání).
- Šipky se **při aktivním filtru skryjí** (přesun v zúženém seznamu je
  matoucí — soused ve filtru není soused v pořadí).
- `data-testid`: `subtable-row-up`, `subtable-row-down`.
- i18n: `subtable.moveUp`, `subtable.moveDown` (title tlačítek).

**Commit 2:** `feat(forms): šipky přesunu řádků v sub-tabulce`

## Krok 5 — dokumentace

- `docs/edit-forms.md`: `orderColumn`, endpoint `/move`, chování
  přečíslování, auto `order_pos`.
- Hlavička tohoto tasku → `hotovo`; pokud jsou hotové všechny tři fáze,
  přidej komentář do issue #53 a **navrhni Anně zavření** (nezavírej sám).

**Commit 3:** `docs(forms): pořadí řádků sub-tabulky`

## Hotovo když (E2E na dev DS s fiktivními daty)

1. Faktura v konceptu se 4 řádky přidanými přes sub-formulář (dnes všechny
   `order_pos = 0`): první klik na ▼ u 1. řádku → sloupec `#` ukáže 1..4
   a řádek je druhý; prohlížeč dokladu (DocumentDetail) ukazuje stejné
   pořadí; PDF/export řádků (pokud existuje) totéž.
2. ▲ na prvním a ▼ na posledním jsou disabled.
3. Přidat nový řádek → `#` = 5 bez zásahu uživatele; pole Pořadí ve
   formuláři není.
4. Read-only faktura: šipky chybí.
5. Osoba → Adresy: šipky chybí (žádný `order_column`).
6. Filtr aktivní (> 10 řádků): šipky skryté, po smazání filtru zpět.
7. Alfa (read-only SQL přes `claude_ro`, agregovaně): spočítat, kolik
   dokladů má řádky s duplicitním `order_pos` — jen jako informace pro
   Annu, **žádná oprava dat na alfě** (mutace vyžaduje souhlas, a
   přečíslování si doklady udělají samy při prvním přesunu).
8. `php -l`, `phpunit --filter 'Subtable|DocRowsDocument'`, `npm run build`,
   `tasks-index.py --check`, `check-sensitive.py`.

## Pasti

- **Řazení v `subtable` endpointu vs. `sort` z tabu:** endpoint přesunu
  řadí `order_col ASC, id ASC`; endpoint výpisu řadí podle `sort` z tabu
  (default `order_pos:asc`). Musí být stejné, jinak šipky přesouvají něco
  jiného, než uživatel vidí — sjednoť na `order_col ASC, id ASC`, pokud je
  `order_column` nastaven, a `sort` u takového tabu ignoruj (nebo zakaž
  v `FormTab` kombinaci `sort` + `orderColumn`).
- **Kontační řádky a `DocDocument` řazení účetních zápisů:** ověř, že
  `AccountingEngine` nečte řádky v pořadí, na kterém by přečíslování něco
  změnilo (nemělo by — jen `ORDER BY order_pos`, obsah zápisů nezávisí).
- **`beforeSave` u řádků z importu / výměnného formátu** — `DocDocument`
  posílá `order_pos` explicitně (1-based), větev `<= 0` se nespustí.
  Nezasahuj do `DocDocument` číslování.
- **Souběh dvou uživatelů:** přečíslování v transakci + `SELECT … FOR
  UPDATE` nad řádky skupiny (MariaDB, InnoDB). Bez zámku může souběžný
  insert dostat duplicitní `order_pos` — přijatelné (další přesun srovná),
  ale nezapisuj to jako „nemůže nastat".
- **Citlivá data:** kontrola duplicit na alfě jen jako `COUNT(*)`, žádné
  čísla dokladů ani partneři v odpovědi.
