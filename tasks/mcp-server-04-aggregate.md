# Task: MCP nástroj `documents_aggregate` (agregace dokladů)

**Stav:** hotovo

**Cíl:** dát vnitřnímu chatu schopnost odpovídat na agregační dotazy nad doklady
(„10 největších dodavatelů za 2025", „obrat po měsících", „kolik jsme fakturovali
firmě X"). Dnes to model nesvede — má jen `documents_search`, takže by musel
listovat stránky a sčítat sám: nespolehlivě, drahé a bez záruky, že něco
nevynechal. Agregace patří do SQL.

Součástí zadání jsou dva menší úklidy, které se stejné oblasti dotýkají
(popisky čipů, zastaralý popis `documents_search`) — viz Scope.

## Návaznost

- Navazuje na `mcp-server-02-read-tools.md` (čtecí tier) a `chat-phase2b-tools.md`
  (tool-use smyčka). Žádné schéma se nemění, žádný `ds-upgrade` není potřeba.
- Referenční dokumenty: [`docs/mcp-server.md`](../docs/mcp-server.md) §6 („jak
  přidat nový nástroj"), [`docs/ai.md`](../docs/ai.md) §3 (katalog a tiery).
- **Nezahrnuje** saldokonto (`economy.accbal`) — to je samostatný nástroj
  v navazujícím tasku. Tady se stav úhrady neřeší vůbec.

## Před implementací přečti

- `modules/docs/core/src/Mcp/DocumentsSearchTool.php` — vzor pro tento nástroj:
  whitelistovaný WHERE builder, `limit + 1` pro `has_more`, doménová obálka,
  čtení `docs.core.docStates` přes `DocStateConfig`.
- `src/Api/Mcp/McpTool.php`, `src/Api/Mcp/McpInvocationContext.php` — rozhraní
  a co je v kontextu k dispozici (`auth`, `db`, `tables`, `config`).
- `modules/docs/core/tables/docs_core_heads.jsonc` — sekce Totals, Accounting,
  Currency. **Pozor na skutečná jména sloupců** (viz Scope, bod 1).
- `modules/economy/codebooks/tables/economy_codebooks_fiscal_years.jsonc`,
  `…_fiscal_months.jsonc`, `…_vat_periods.jsonc` — cílové tabulky FK sloupců.
- `public/index.php`, funkce `buildMcpRegistry()` (~ř. 325) — místo registrace.
- `tests/Unit/Api/Controller/McpControllerTest.php` — vzor unit testu nástroje
  přes `createMock(DataSourceConnection::class)` s `willReturnCallback`, které
  zachytí SQL a parametry (viz `callTool()` a testy okolo ř. 300–340).

## Scope

**Ano:**

1. Nový nástroj `documents_aggregate` v `modules/docs/core/src/Mcp/`.
2. Registrace v `buildMcpRegistry()`.
3. Popisek čipu v UI pro `documents_aggregate` **a pro chybějící
   `registry_search`** (dnes padá na generický popisek se surovým jménem).
4. Oprava zastaralého popisu `documents_search` (tvrdí, že systém saldokonto
   nemá — to už neplatí).
5. Aktualizace katalogu nástrojů v `docs/ai.md` §3 a `docs/mcp-server.md` §5.

**Ne:**

- Nástroj nad `economy.accbal` (samostatný task).
- Agregace nad řádky dokladů (`docs_core_rows`) — položkové žebříčky až podle
  potřeby; hlavičky pokrývají 90 % dotazů.
- Změna doménové obálky nebo `McpTool` rozhraní.
- Module-driven registrace přes `module.jsonc` (samostatný úklid).

---

## 1. `modules/docs/core/src/Mcp/DocumentsAggregateTool.php` (nový)

Namespace `Shipard\Module\Docs\Core\Mcp`, `final class DocumentsAggregateTool
implements McpTool`, `isReadOnly(): true`, `name(): 'documents_aggregate'`.

### Skutečná jména sloupců — nepřehlédnout

V domácí měně mají sloupce suffix **`_dom`**, ne `_home`:
`total_base_dom`, `total_vat_dom`, `total_amount_dom`. (`home_currency` je kód
domácí měny — ten `_dom` suffix nemá.)

`fiscal_year`, `fiscal_month`, `vat_period` jsou **`int` FK** do codebooků, ne
čísla roku/měsíce. `fiscal_year = 2025` je tedy chyba — je potřeba resolve
(bod „Filtry", `fiscal_year`).

### `inputSchema()`

| Argument | Typ | Default | Popis |
|---|---|---|---|
| `dimension` | enum, **required** | — | `partner` \| `doc_type` \| `fiscal_month` \| `vat_period` |
| `measure` | enum | `total_base` | `total_base` (bez DPH) \| `total_amount` (s DPH) \| `count` |
| `order` | enum | `measure_desc` | `measure_desc` (žebříček) \| `dimension_asc` (časová řada) |
| `doc_type` | string | — | filtr typu dokladu (`invni` = přijaté → dodavatelé, `invno` = vydané → odběratelé) |
| `partner` | integer | — | filtr na jednu osobu (ID z `persons_search`) |
| `fiscal_year` | string | — | označení fiskálního roku, např. `"2025"` |
| `accounting_date_from` / `_to` | string | — | účetní datum (YYYY-MM-DD) |
| `state` | enum | `active` | `active` \| `done` \| `all` — stejná semantika jako `documents_search` |
| `limit` | integer 1–50 | 10 | počet vrácených skupin (top N) |

`offset` se **nevystavuje** — u top-N žebříčku nemá smysl; v obálce se vrací
`offset: 0` kvůli zachování tvaru.

### `description()` — je to prompt, piš ho pečlivě

Musí obsahovat:

- K čemu je: součty a počty dokladů seskupené podle dimenze — žebříčky
  („největší dodavatelé"), časové řady („obrat po měsících"), součet za partnera.
- **Kdy ho použít místo `documents_search`:** když uživatel chce součet, počet,
  žebříček nebo vývoj — ne když chce seznam konkrétních dokladů.
- **Že `invni` = přijaté faktury = dodavatelé, `invno` = vydané = odběratelé.**
  Bez toho model plete směr obchodu; „největší dodavatel" bez `doc_type=invni`
  vrátí nesmysl.
- Že `measure` je defaultně **základ bez DPH** (účetně relevantní obrat) a že
  `total_amount` je s DPH — použij jen když se uživatel ptá na částku k úhradě.
- Že součty jsou **vždy v domácí měně** (přepočtené), takže se nemíchají měny.
- Že pro obrat je vhodnější `state='done'` (jen doklady ve stavu V pořádku);
  default `active` zahrnuje i koncepty.
- Že položkové/řádkové žebříčky neumí a saldokonto (stav úhrady) neřeší.

### `call()` — implementace

**Bezpečnost:** `dimension`, `measure` i `order` se převádějí přes `match`
(hard-coded whitelist) na SQL fragmenty. Do SQL se nikdy nevkládá hodnota
argumentu jako řetězec — jen skalární parametry přes `%i` / `%s`, jako
v `DocumentsSearchTool`.

**Měra** → agregační výraz:

| `measure` | výraz |
|---|---|
| `total_base` | `SUM(`h`.`total_base_dom`)` |
| `total_amount` | `SUM(`h`.`total_amount_dom`)` |
| `count` | `COUNT(*)` |

**Dimenze** → GROUP BY + join + label + `ref`:

| `dimension` | GROUP BY | label | `ref` |
|---|---|---|---|
| `partner` | `h`.`partner` | `p`.`full_name` (LEFT JOIN `base_persons_persons` `p`) | `{type:'person', id}` |
| `doc_type` | `h`.`doc_type` | z cfgItem `docs.core.docTypes` (`[$code]['name']`) | `null` |
| `fiscal_month` | `h`.`fiscal_month` | `YYYY-MM` z `calendar_year`/`calendar_month` (JOIN `economy_codebooks_fiscal_months` `fm`) | `null` |
| `vat_period` | `h`.`vat_period` | `vp`.`name` (JOIN `economy_codebooks_vat_periods` `vp`) | `null` |

- Compiled config je per-jazyk (`compiled.{lang}.json`), takže `cfgItem(…)['name']`
  je **už lokalizovaný** — žádné ruční řešení `name:cs`. Vzor: `NumberSeriesViewer`
  (~ř. 188). Chybí-li `config`, fallback na surový kód.
- Skupina s `NULL` klíčem (doklad bez partnera / bez zařazení) se **nezahazuje** —
  vrátí se s labelem „(nezařazeno)" a `ref: null`, aby součty souhlasily.
- `order`: `measure_desc` → `ORDER BY <měra> DESC`; `dimension_asc` → řadí podle
  přirozeného pořadí dimenze (`fm`.`date_begin`, `vp`.`date_begin`, u `partner`
  a `doc_type` podle labelu).

**Filtry** — stejný whitelistovaný WHERE builder jako `DocumentsSearchTool`:

- `state`: `done` → `docState = 40`; `all` → bez podmínky; jinak `docState != 90`.
- `partner`, `doc_type`, `accounting_date_from/to` — 1:1 jako v `documents_search`.
- `fiscal_year`: **resolve přes codebook.**
  `SELECT id FROM economy_codebooks_fiscal_years WHERE name = %s AND docState != 90`.
  Nenalezeno → `throw new \InvalidArgumentException(…)` s výpisem dostupných
  označení (controller to zmapuje na `-32602` a model se z toho umí zotavit sám;
  proto do hlášky ten seznam patří).

**Grand total a podíly.** Kromě agregačního dotazu spusť druhý, skalární, se
**stejným WHERE a bez GROUP BY** → celkový součet přes všechny skupiny (ne jen
vrácených top N). Z něj u každé položky spočítej `share_pct` (zaokrouhleno na
1 desetinné místo) a v `summary` uveď celek. Bez toho je „10 největších" údaj
bez kontextu.

**`has_more`:** načti `limit + 1` skupin a přebývající zahoď — přesně jako
`DocumentsSearchTool`.

**Kontrola domácí měny.** Do agregačního dotazu přidej
`COUNT(DISTINCT `h`.`home_currency`)` a `MIN(`h`.`home_currency`)`. Je-li
distinct count > 1, připoj do `summary` upozornění, že výsledek míchá víc
domácích měn a součet není spolehlivý. (Nemělo by nastat — v jedné DS je domácí
měna jedna — ale tichý špatný součet je horší než hlasitá poznámka.)

### Návratová obálka

```php
[
  'summary' => 'Top 10 partnerů podle základu bez DPH (invni, 2025): '
             . 'celkem 4 812 300 CZK, největší … (1 204 000 CZK, 25,0 %).',
  'items' => [
    [
      'ref'        => ['type' => 'person', 'id' => 123], // nebo null
      'full_name'  => 'Acme s.r.o.',   // label skupiny
      'value'      => '1204000.00',
      'currency'   => 'CZK',
      'doc_count'  => 42,
      'share_pct'  => 25.0,
    ],
  ],
  'pagination' => ['limit' => 10, 'offset' => 0, 'returned' => 10, 'has_more' => true],
]
```

- `full_name` je záměrně stejný klíč jako u ostatních nástrojů — model tak má
  napříč katalogem jeden konzistentní „popisek položky".
- `value` u `measure='count'` = počet, `currency` pak `null`.
- **Každá položka s `ref`** umožní návaznost: „největší dodavatel" →
  `persons_get` nebo `documents_search` s `partner` = to ID. To je vlastnost,
  která z žebříčku dělá konverzaci, ne jednorázovou tabulku.
- Prázdný výsledek → `summary` „Pro zadané filtry nejsou žádné doklady.",
  `items: []`. Ne chyba.

## 2. `public/index.php` — registrace

Do `buildMcpRegistry()` k ostatním čtecím nástrojům:

```php
$registry->register(new \Shipard\Module\Docs\Core\Mcp\DocumentsAggregateTool());
```

Bezstavový, bez závislostí — kontext dostane až v `call()`.

## 3. `modules/docs/core/src/Mcp/DocumentsSearchTool.php` — oprava popisu

V `description()` i v popisu argumentu `overdue` je dnes tvrzení, že *„systém bez
saldokonta stav úhrady nezná, nelze říct, jestli je faktura zaplacená"*. To je
neaktuální — `economy.accbal` (ledger, allocations, matcher) existuje.

Přeformuluj **věcně a bez slibů**: `overdue` znamená po splatnosti, což není
totéž jako neuhrazené; **tento nástroj** stav úhrady nevrací. Nezmiňuj
neexistující nástroj — cross-reference na saldokonto doplní až navazující task,
který ten nástroj přinese. Doplň odkaz na `documents_aggregate` pro součty
a žebříčky.

Totéž v hlavičkovém docblocku třídy (obsahuje stejnou zastaralou větu).

## 4. Frontend — popisky čipů

- `frontend/src/components/chat/toolLabels.js` — do `TOOL_LABEL_KEYS` přidat
  `registry_search: 'chat.tool.registrySearch'` (dnes chybí) a
  `documents_aggregate: 'chat.tool.documentsAggregate'`.
- `frontend/src/i18n/cs.js` a `en.js` — nové klíče vedle stávajících
  `chat.tool.*` (~ř. 528 v `cs.js`, ~ř. 515 v `en.js`), stejný tvar
  „emoji + průběhový tvar":
  - `chat.tool.registrySearch`: „🔍 Prohledávám Spisovnu…" / „Searching the registry…"
  - `chat.tool.documentsAggregate`: „📊 Počítám součty…" / „Computing totals…"

## 5. Dokumentace

- `docs/ai.md` §3 — do tabulky čtecího tieru přidat `documents_aggregate`.
- `docs/mcp-server.md` §5 — totéž v tabulce tierů (a všimni si, že tam dnes
  chybí i `registry_search` v prvním sloupci výčtu — dorovnat).
- Obě místa uvádějí katalog jako výčet; nikde jinde se nevypisuje, takže víc
  souborů to nevyžaduje.

## Testy

Nový `tests/Unit/Module/Docs/Core/Mcp/DocumentsAggregateToolTest.php`
(mock `DataSourceConnection`, `willReturnCallback` zachytí SQL + parametry —
vzor v `McpControllerTest::callTool()`):

1. **Chybějící `dimension`** → `InvalidArgumentException`.
2. **Neplatná `dimension` / `measure`** → `InvalidArgumentException` (whitelist
   drží, nic se nepropíše do SQL).
3. **`measure='total_base'`** → v SQL je `total_base_dom`; **`total_amount`** →
   `total_amount_dom`. (Přímý test na ten `_dom` suffix, ať se regrese nedá
   přehlédnout.)
4. **`dimension='partner'`** → `GROUP BY` na `h`.`partner` + JOIN na
   `base_persons_persons`; položky mají `ref` typu `person`.
5. **`fiscal_year='2025'`** → proběhl resolve dotaz do
   `economy_codebooks_fiscal_years`; nenalezeno → `InvalidArgumentException`
   se seznamem dostupných.
6. **`share_pct` a `summary`** proti známému grand totalu (druhý dotaz
   namockovaný).
7. **`has_more`** — mock vrátí `limit + 1` skupin, obálka hlásí `true`
   a `returned == limit`.
8. **Prázdný výsledek** → `items: []`, ne chyba.
9. **Míchané `home_currency`** (distinct > 1) → `summary` obsahuje upozornění.
10. **`state`**: `done` → `docState = 40`; `all` → žádná podmínka na `docState`.

Rozšířit `tests/Unit/Api/Mcp/McpToolReadOnlyTest.php` o
`assertTrue((new DocumentsAggregateTool())->isReadOnly())`.

Spouštěj úzkým filtrem (broad filtry v tomto repu timeoutují):
`vendor/bin/phpunit --filter 'DocumentsAggregate|McpToolReadOnly'`.

## Commit strategie

1. `docs.core: MCP nástroj documents_aggregate` — nástroj + registrace + testy.
2. `docs.core: aktualizace popisu documents_search (saldokonto)` — bod 3.
3. `core.chat: popisky čipů pro registry_search a documents_aggregate` — bod 4.
4. `docs: documents_aggregate v katalogu nástrojů` — bod 5.

## Hotovo když

- [ ] `documents_aggregate` je v `tools/list` a v čtecím tieru
      (`isReadOnly() === true`), tedy chat ho nabízí.
- [ ] Všechny čtyři dimenze fungují; `partner` vrací položky s `ref`.
- [ ] Součty jedou výhradně nad `_dom` sloupci; `measure` defaultuje na
      `total_base`.
- [ ] `fiscal_year` se resolvuje přes codebook; neznámé označení vrátí
      `-32602` s výpisem dostupných.
- [ ] `share_pct` + celkový součet v `summary`; `has_more` u víc skupin než
      `limit`.
- [ ] `documents_search` už netvrdí, že systém nemá saldokonto.
- [ ] Čip pro `registry_search` i `documents_aggregate` má lidský popisek
      (cs + en), nikoli generický fallback.
- [ ] Katalog v `docs/ai.md` §3 a `docs/mcp-server.md` §5 je aktuální.
- [ ] Testy zelené.

## Rozhodnutí ✓

- **D1** — jeden nástroj s enum dimenzemi, ne rodina `top_suppliers` +
  `revenue_by_month` + … Katalog se nezaplevelí a model si s jedním dobře
  popsaným nástrojem poradí lépe.
- **D2** — vše v domácí měně (`*_dom`). Míchání měn v `SUM` je tichá chyba.
- **D3** — `measure` defaultuje na `total_base` (bez DPH): většina firem jsou
  plátci DPH a částka s DPH obrat nevyjadřuje. `total_amount` zůstává dostupné
  (neplátci, dotazy na částku k úhradě), jen se nevybírá samo.
- **D4** — fiskální rok i rozsah účetního data, volbu řídí popis pro model.
  *Korekce:* `fiscal_year` je FK do codebooku, ne číslo roku → resolve.
- **D5** — `state` enum shodný s `documents_search`; smazané (90) mimo,
  koncepty v `active` zahrnuté, pro obrat doporučeno `done`.
- **D6** — obálka `{summary, items, pagination}` zachovaná; `pagination` nese
  top-N + `has_more`, `offset` se nevystavuje. Každá položka má `ref`.

## Otevřené body

- Žebříčky nad **položkami** (`docs_core_rows`) — „co od nás nejvíc kupují".
  Jiná dimenze i jiný join; až podle reálné poptávky.
- Saldokonto (`accbal_*`) jako navazující nástroj. Až bude, doplní se do
  `documents_search` a `documents_aggregate` cross-reference, aby model věděl,
  kam poslat dotaz na neuhrazené.
