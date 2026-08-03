# Dashboard — filtr kategorií karet feedu

**Stav:** hotovo

## Status

Implementováno (2026-07-16). Návrh schválen Annou téhož dne. Testy + frontend
build zelené, `docs/dashboard.md` aktualizován.

## Cíl

Nad kartami feedu přibude **filtrační pruh** (chip bar) se čtyřmi záložkami,
který karty rozdělí podle obsahu — feed začíná být při větším objemu pošty
nepřehledný:

```
[ Vše (14) ] [ Přijaté faktury (8) ] [ Spisovna (3) ] [ Ostatní (3) ]

▌ 🟢  Přijatá faktura — ČEZ a.s.
▌     4 200,00 CZK · jistota 94 % · e-mail „Faktura 2026000123"
▌     [Použít] [Zkontrolovat] [Zamítnout]
…
```

Kategorizaci řídí **server** (server-driven UI): karta dostane nové volitelné
pole `category`, frontend jen filtruje a kreslí. Filtr je čistě klientský —
žádná změna API endpointů, žádný refetch při přepínání záložek.

## Schválená rozhodnutí

1. **Tři kategorie + Vše**: `invoices` (Přijaté faktury), `registry`
   (Spisovna), `other` (Ostatní). Mapování emituje server v poli `category`:
   - Návrhová karta s `context.target = 'docs'` → `invoices`.
   - Návrhová karta s `context.target = 'registry'` → `registry`.
   - Chybová karta, karta „Není faktura" → `other`.
   - Digest karty a návrhy pravidel odesílatele (`MailDigestSource`) → `other`.
   - **Alert karty → `other`** (rozhodnutí Anny; nejsou z pošty, ale model
     zůstává jednoduchý).
2. **Karty bez `category`** (dnes jen info karta „…a další nezpracovaná
   pošta") se zobrazují **jen v záložce Vše** — bezpečný default i pro
   případné budoucí zdroje, které pole zapomenou nastavit.
3. **Default záložka Vše**; volba se drží jen ve stavu komponenty (nepřežije
   reload aplikace, přežije manuální Obnovit). Žádná persistence.
4. **Varianta A — klientský filtr**: strop `MAX_CARDS` (~30) platí přes
   všechny kategorie dohromady; počty v chipech = počty **doručených** karet,
   ne skutečné DB totály. Serverový parametr `?category=` / pravdivé totály
   jsou případný pozdější aditivní krok (kontrakt se nemění), teď se nedělá.
5. **Prázdná kategorie**: chip zůstává viditelný s `(0)`, není disabled —
   klik ukáže per-záložkový empty stav.
6. **Urgent indikátor**: chip kategorie obsahující kartu `kind=urgent`
   dostane malou červenou tečku, aby urgentní věc nezapadla, když uživatel
   sedí na jiné záložce.
7. **Kontrakt**: nové volitelné top-level pole karty — zpětně kompatibilní
   rozšíření (stejný vzor jako `attachments`).

## Před implementací přečti

- `docs/dashboard.md` §4 (kartový kontrakt), §5 (zdroje karet), §8 (frontend
  komponenty), §10 (empty stavy)
- `src/Core/Feed/FeedSource.php` — rozhraní zdrojů (sem přijdou konstanty)
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` — build metody tří
  druhů karet; `$extractionTarget` v `buildSuggestionCard()` už rozlišuje
  docs/registry
- `modules/core/mail/src/Feed/MailDigestSource.php` — digest + rule karty
- `modules/core/alerts/src/Feed/AlertsSource.php` — alert karty
- `frontend/src/components/dashboard/Dashboard.svelte` + `Feed.svelte`
- `frontend/src/i18n/cs.js` + `en.js` — klíče `dashboard.*` (~ř. 400 v cs)

## Datový tok

```
FeedSource (PHP) ── card.category ──► GET /_ui/dashboard ── cards[] ──►
Dashboard.svelte (feedFilter $state, $derived counts/filtered) ──►
FeedFilter.svelte (chipy)  +  Feed.svelte (vyfiltrované karty)
```

AI shrnutí (`/_ui/dashboard/summary`) zůstává **beze změny** — vzniká nad
celým nefiltrovaným feedem.

## Implementace

### 1. Konstanty kategorií — `src/Core/Feed/FeedSource.php`

Do rozhraní přidej veřejné konstanty (jediné místo pravdy pro klíče):

```php
/** Kategorie karet pro filtr feedu (docs/dashboard.md §4). */
public const string CATEGORY_INVOICES = 'invoices';
public const string CATEGORY_REGISTRY = 'registry';
public const string CATEGORY_OTHER    = 'other';
```

### 2. Backend — emit `category` ve zdrojích

`modules/core/mail/src/Feed/MailSuggestionsSource.php`:

- `buildSuggestionCard()` — pod klíč `stateStyle`/`title` přidej:
  ```php
  'category' => $isRegistry ? FeedSource::CATEGORY_REGISTRY : FeedSource::CATEGORY_INVOICES,
  ```
- `buildErrorCard()` a `buildNotInvoiceCard()` —
  `'category' => FeedSource::CATEGORY_OTHER`.

`modules/core/mail/src/Feed/MailDigestSource.php` — obě karty (digest
`mail_digest:*` i `mail_rule_suggestion:*`) dostanou
`'category' => FeedSource::CATEGORY_OTHER`.

`modules/core/alerts/src/Feed/AlertsSource.php` — alert karta dostane
`'category' => FeedSource::CATEGORY_OTHER`.

`src/Api/Controller/DashboardController.php` — `andMoreCard()` **záměrně bez
category** (viz rozhodnutí 2); doplň to do docbloku metody.

### 3. Frontend — `FeedFilter.svelte` (nová komponenta)

`frontend/src/components/dashboard/FeedFilter.svelte`:

```svelte
<script>
  import { t } from '../../i18n/index.js';

  /**
   * Chip bar filtru feedu. Čistě prezentační — počty i urgent příznaky
   * počítá rodič (Dashboard) z doručených karet.
   * value: 'all' | 'invoices' | 'registry' | 'other'
   * counts: { all, invoices, registry, other }
   * urgent: { invoices: bool, registry: bool, other: bool }
   */
  let { value = 'all', counts = {}, urgent = {}, onChange = () => {} } = $props();

  const TABS = ['all', 'invoices', 'registry', 'other'];
</script>

<div class="shpd-feed-filter" role="tablist">
  {#each TABS as tab (tab)}
    <button
      type="button"
      role="tab"
      class="shpd-feed-filter__chip"
      class:shpd-feed-filter__chip--active={value === tab}
      aria-selected={value === tab}
      onclick={() => onChange(tab)}
    >
      {t(`dashboard.feed.filter.${tab}`)}
      <span class="shpd-feed-filter__count">({counts[tab] ?? 0})</span>
      {#if urgent[tab]}<span class="shpd-feed-filter__dot" aria-hidden="true"></span>{/if}
    </button>
  {/each}
</div>
```

Styly: BEM `shpd-feed-filter`, design-system proměnné (`--shpd-space-*`,
`--shpd-radius-*`, `--shpd-color-*`); aktivní chip plná barva
(`--shpd-color-primary` + kontrastní text), neaktivní ghost/border; tečka
`--shpd-color-danger`, ~6 px, absolutně v pravém horním rohu chipu. Pruh je
horizontálně scrollovatelný na úzkých šířkách (`overflow-x: auto`, bez
zalamování) — mobil.

### 4. Frontend — `Dashboard.svelte`

- Stav: `let feedFilter = $state('all');` — **neresetovat** v `load()`.
- Derived hodnoty (Svelte 5 runes):
  ```js
  const CATEGORIES = ['invoices', 'registry', 'other'];

  let feedCounts = $derived.by(() => {
    const c = { all: data?.cards?.length ?? 0, invoices: 0, registry: 0, other: 0 };
    for (const card of data?.cards ?? []) {
      if (CATEGORIES.includes(card.category)) c[card.category]++;
    }
    return c;
  });

  let feedUrgent = $derived.by(() => {
    const u = { invoices: false, registry: false, other: false };
    for (const card of data?.cards ?? []) {
      if (card.kind === 'urgent' && CATEGORIES.includes(card.category)) u[card.category] = true;
    }
    return u;
  });

  let filteredCards = $derived(
    feedFilter === 'all'
      ? (data?.cards ?? [])
      : (data?.cards ?? []).filter((c) => c.category === feedFilter),
  );
  ```
- Render: `<FeedFilter value={feedFilter} counts={feedCounts} urgent={feedUrgent} onChange={(v) => (feedFilter = v)} />`
  mezi `<AiSummaryCard>` a `<Feed>`; `<Feed cards={filteredCards} …>`.
- Filter bar se kreslí, jen když `data.cards.length > 0` — prázdný feed
  ukazuje globální empty stav bez chipů.

### 5. Frontend — `Feed.svelte` (per-záložkový empty stav)

Nový prop `emptyText = null`; empty větev vypíše `emptyText ??
t('dashboard.feed.empty')`. `Dashboard.svelte` předá
`emptyText={feedFilter !== 'all' && (data?.cards?.length ?? 0) > 0
? t('dashboard.feed.emptyCategory') : null}` — globální „Vše zpracováno"
zůstává jen pro skutečně prázdný feed.

### 6. i18n klíče

`frontend/src/i18n/cs.js` (k blogu `dashboard.feed.*`):

```js
'dashboard.feed.filter.all': 'Vše',
'dashboard.feed.filter.invoices': 'Přijaté faktury',
'dashboard.feed.filter.registry': 'Spisovna',
'dashboard.feed.filter.other': 'Ostatní',
'dashboard.feed.emptyCategory': 'V této kategorii nic nečeká.',
```

`en.js`: `All` / `Received invoices` / `Registry` / `Other` /
`Nothing waiting in this category.`

### 7. Testy

- `tests/Unit/Module/Core/Mail/Feed/MailSuggestionsSourceTest.php` — assert
  `category` na všech třech druzích karet (invoices/registry/other; registry
  případ dle existujícího registry fixture).
- `tests/Unit/Module/Core/Mail/Feed/MailDigestSourceTest.php` +
  `tests/Unit/Module/Core/Alerts/Feed/AlertsSourceTest.php` — assert
  `category === 'other'`.
- `tests/Unit/Api/Controller/DashboardControllerTest.php` — beze změny
  (sortAndCap kategorii nezná); jen ověř, že prochází.

### 8. Dokumentace

- `docs/dashboard.md`:
  - §4 kartový kontrakt — pole `category` (volitelné, výčet, „bez pole =
    jen ve Vše") + ukázkový JSON.
  - §8 frontend komponenty — řádek `FeedFilter.svelte`.
  - §10 empty stavy — řádek pro `dashboard.feed.emptyCategory`.
  - Zmínka ve „Budoucí rozšíření": serverový `?category=` + pravdivé totály,
    až strop MAX_CARDS začne vadit.

## Ověření

1. `php -l` na dotčené PHP soubory
2. `vendor/bin/phpunit --filter 'MailSuggestionsSource|MailDigestSource|AlertsSource|DashboardController'`
3. `cd frontend && timeout 90 npm run build 2>&1 | tail -10`

## Akceptace

- [ ] Každá mail/alert karta v odpovědi `GET /_ui/dashboard` nese `category`
      ∈ {invoices, registry, other}; karta „…a další" pole nemá.
- [ ] Nad feedem jsou 4 chipy s počty; default Vše; přepínání filtruje bez
      refetche.
- [ ] Prázdná kategorie: chip s `(0)` zůstává, klik ukáže „V této kategorii
      nic nečeká."; skutečně prázdný feed ukazuje původní „Vše zpracováno ✓"
      bez chipů.
- [ ] Chip kategorie s urgent kartou má červenou tečku.
- [ ] Akce karet (apply/review/reject/…) fungují ve filtrovaném pohledu beze
      změny; optimistické odebrání karty aktualizuje počty v chipech.
- [ ] AI shrnutí se filtrem nemění (stále celý feed).
- [ ] Manuální Obnovit zachová zvolenou záložku.
- [ ] Testy a frontend build zelené; `docs/dashboard.md` aktualizován.
