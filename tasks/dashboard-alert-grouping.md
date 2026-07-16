# Dashboard — agregace alertů do skupinových karet feedu

## Status

Navrženo a schváleno Annou (2026-07-16). Připraveno k implementaci.

## Cíl

Per-row alert checky (např. `docs.core.stale_in_repair`) umí vyrobit desítky
aktivních alertů z jednoho problémového okruhu. Ty dnes ve feedu dashboardu:

1. obsadí pásmo `review`/`urgent`, které je v `KIND_ORDER` **nad** pásmem
   `ready` (extracted faktury k jednokliku),
2. spolu se stropem `MAX_CARDS = 30` vytlačí z feedu veškerou došlou poštu,
3. chipy filtru pak ukazují „Přijaté faktury: 0", což je pravda o feedu,
   ale lež o databázi.

Přitom 30 findings jednoho checku není 30 nezávislých akcí — skutečná akce
uživatele je jedna („jdi si vyřešit účtování"). Řešení: `AlertsSource` sbalí
alerty jednoho `check_id` nad prahem do **jedné skupinové karty**.

Vizuální náčrt (obyčejná karta, title/subtitle fallback, bez headline):

```
▌ 🟡  Doklady dlouho v opravě
▌     27 upozornění
▌     [Otevřít upozornění]
```

Málo alertů (1–3 z jednoho checku) se chová beze změny — individuální karty
ve Vše, nezapadnou v žádné oddělené sekci.

## Schválená rozhodnutí

1. **Práh agregace**: víc než **3** aktivní alerty jednoho `check_id`
   (tj. 4+ → jedna skupinová karta; 1–3 → individuální karty jako dnes).
   Konstanta `GROUP_THRESHOLD = 3` v `AlertsSource`.
2. **Granularita**: per `check_id`. Skupinová karta **nahrazuje** všechny
   individuální karty daného checku (žádné „top 3 + zbytek").
3. **Kind skupinové karty**: dle **nejvyšší severity** ve skupině
   (`MAX(severity)`), stejné mapování jako individuální karta
   (error→urgent, warning→review, info→info). Agregace nemá problém
   zneviditelnit.
4. **Akce**: jediná, primary — `open_viewer` na `core.alerts.alerts`,
   **bez** filtru per check (filtr vieweru přes preset až později,
   samostatně). Label lokalizuje `AlertsSource` (alert akce jsou
   passthrough s hotovými labely, frontend je nelokalizuje).
5. **Titulek**: lokalizovaný **název checku** z `AlertCheckRegistry`
   (`get($checkId)->name`), fallback `check_id` (check mezitím zmizel
   z modulu / registr není k dispozici).
6. **Kontrakt karty se nemění** — skupinová karta je obyčejná karta;
   frontend beze změny.
7. **Kvóta per zdroj v `sortAndCap`**: odloženo — pojistka proti vzájemnému
   vytlačování zdrojů, až se ukáže potřeba (samostatný task).
8. **Pravdivé DB county v chipech filtru**: mimo scope — čeká jako
   „serverový filtr kategorií" v `docs/dashboard.md` §12.

## Před implementací přečti

- `docs/dashboard.md` §4 (kartový kontrakt), §5.2 (AlertsSource),
  §4.1 (KIND_ORDER)
- `docs/alerts.md` §1 (check vs. alert), §5 (finding_key, per-row checky)
- `modules/core/alerts/src/Feed/AlertsSource.php` — celý (nahrazuje se
  jednodotazový sběr)
- `src/Api/Controller/DashboardController.php` — `collectCards()` (~ř. 117),
  signatury `dashboard()` / `summary()`
- `public/index.php` — build registru (~ř. 117,
  `AlertCheckLoader::load(...)`) a `dispatchDashboard()` (~ř. 880)
- `src/Core/Alerts/AlertCheckRegistry.php` — `get(string $checkId):
  ?AlertCheckDefinition` (lokalizovaný `->name`)
- `tests/Unit/Module/Core/Alerts/Feed/AlertsSourceTest.php` — stávající
  helper `context()` mockuje jediný `fetchAll`; dvoufázový sběr ho rozbije

## Implementační kroky

### 1. `AlertsSource` — konstruktor s registrem

```php
use Shipard\Core\Alerts\AlertCheckRegistry;

final class AlertsSource implements FeedSource
{
    public function __construct(
        private readonly ?AlertCheckRegistry $registry = null,
    ) {}
```

`null` = degradace titulků skupinových karet na `check_id` (testy, případné
budoucí callsites bez registru). Nic nepadá.

### 2. `AlertsSource::collectCards()` — dvoufázový sběr

Nahradit dnešní jediný SELECT:

```php
private const int GROUP_THRESHOLD = 3;

public function collectCards(FeedContext $ctx): array
{
    // Fáze 1 — agregát per check. Malý výsledek (počet checků, ne alertů),
    // bez LIMITu → skupinové karty mají pravdivý počet i nad MAX_CARDS.
    $groups = $ctx->db->fetchAll(
        'SELECT `check_id`, COUNT(*) AS `cnt`, MAX(`severity`) AS `max_severity`,'
        . ' MAX(`last_seen_at`) AS `last_at`, MAX(`first_seen_at`) AS `first_at`'
        . ' FROM `' . self::TABLE . '`'
        . ' WHERE `alert_state` = %i'
        . ' GROUP BY `check_id`',
        self::STATE_ACTIVE,
    );

    $cards = [];
    $individualCheckIds = [];
    foreach ($groups as $g) {
        if ((int) $g['cnt'] > self::GROUP_THRESHOLD) {
            $cards[] = $this->buildGroupCard($ctx, $g);
        } else {
            $individualCheckIds[] = (string) $g['check_id'];
        }
    }

    // Fáze 2 — individuální alerty jen pro checky pod prahem.
    if ($individualCheckIds !== []) {
        $rows = $ctx->db->fetchAll(
            'SELECT `id`, `check_id`, `title`, `message`, `severity`, `actions`,'
            . ' `first_seen_at`, `last_seen_at`'
            . ' FROM `' . self::TABLE . '`'
            . ' WHERE `alert_state` = %i AND `check_id` IN %in'
            . ' ORDER BY `severity` DESC, `last_seen_at` DESC, `id` DESC'
            . ' LIMIT %i',
            self::STATE_ACTIVE,
            $individualCheckIds,
            $ctx->maxCards,
        );
        foreach ($rows as $row) {
            $cards[] = $this->buildCard($row);
        }
    }

    return $cards;
}
```

`buildCard()` zůstává beze změny.

### 3. `AlertsSource::buildGroupCard()`

```php
/**
 * @param array<string,mixed> $g  agregátní řádek (check_id, cnt, max_severity, last_at, first_at)
 * @return array<string,mixed>
 */
private function buildGroupCard(FeedContext $ctx, array $g): array
{
    $checkId  = (string) $g['check_id'];
    $count    = (int) $g['cnt'];
    $severity = (int) ($g['max_severity'] ?? self::SEVERITY_WARNING);

    [$kind, $stateStyle, $icon] = match ($severity) {
        self::SEVERITY_ERROR => ['urgent', 'error', 'alert'],
        self::SEVERITY_INFO  => ['info', 'concept', 'info'],
        default              => ['review', 'edit', 'warning'],
    };

    $title = $this->registry?->get($checkId)?->name ?? $checkId;
    $cs    = $ctx->language === 'cs';

    return [
        'id'         => 'alert-group:' . $checkId,
        'source'     => 'alerts',
        'kind'       => $kind,
        'icon'       => $icon,
        'stateStyle' => $stateStyle,
        'category'   => FeedSource::CATEGORY_OTHER,
        'title'      => $title,
        'subtitle'   => $cs ? "{$count} upozornění" : "{$count} alerts",
        'timestamp'  => $this->toAtom($g['last_at'] ?? null) ?? $this->toAtom($g['first_at'] ?? null),
        'context'    => [
            'checkId'  => $checkId,
            'count'    => $count,
            'severity' => $severity,
            'group'    => true,
        ],
        'actions'    => [[
            'id'      => 'open_alerts',
            'label'   => $cs ? 'Otevřít upozornění' : 'Open alerts',
            'kind'    => 'open_viewer',
            'target'  => ['viewerId' => 'core.alerts.alerts'],
            'primary' => true,
        ]],
    ];
}
```

Poznámky:

- Severity → kind mapování je teď na dvou místech (`buildCard`,
  `buildGroupCard`) — vytáhni do privátní helper metody
  `severityToPresentation(int $severity): array`.
- „upozornění" je v češtině tvarově invariantní, počet 4+ funguje bez
  plural logiky. Žádné i18n klíče — label akce jde passthrough cestou
  jako u individuálních alert karet (rozhodnutí 4).

### 4. Wiring — `DashboardController` + `public/index.php`

`DashboardController::dashboard()` a `summary()` — nový volitelný parametr,
propsat do `collectCards()`:

```php
use Shipard\Core\Alerts\AlertCheckRegistry;

public function dashboard(
    ViewerRegistry $registry,
    DataSourceConnection $db,
    ?ConfigRuntime $config = null,
    ?string $language = null,
    ?AlertCheckRegistry $alertRegistry = null,
): Response {
    ...
    [$cards, $truncated] = $this->collectCards($db, $config, $lang, $alertRegistry);
```

```php
private function collectCards(
    DataSourceConnection $db,
    ?ConfigRuntime $config,
    string $lang,
    ?AlertCheckRegistry $alertRegistry = null,
): array {
    ...
    $sources = [
        new MailSuggestionsSource(),
        new MailDigestSource(),
        new AlertsSource($alertRegistry),
    ];
```

`public/index.php` — `$alertCheckRegistry` se buildí už dnes (~ř. 117,
před dispatchem), stačí ho předat:

```php
'dashboard' => dispatchDashboard($route, $db, $viewerRegistry, $configRuntime,
    resolveLanguage($request, $resolved->config), $resolved->config,
    $alertCheckRegistry),
```

a v `dispatchDashboard()` přidat parametr
`?\Shipard\Core\Alerts\AlertCheckRegistry $alertCheckRegistry = null`
a propsat do obou akcí (`$ctrl->dashboard(..., $alertCheckRegistry)`,
`$ctrl->summary(..., $alertCheckRegistry)`). `summary()` sdílí
`collectCards()` — shrnutí musí stát nad týmiž kartami (vč. skupinových).

### 5. Testy

`tests/Unit/Module/Core/Alerts/Feed/AlertsSourceTest.php`:

- **Přepracovat helper `context()`** — mock `fetchAll` teď dostane dva
  různé dotazy (agregát, pak individuální řádky). Použij
  `willReturnCallback` s rozlišením podle SQL (`GROUP BY` v prvním), nebo
  `willReturnOnConsecutiveCalls`. Pozor: fáze 2 se při prázdném
  `$individualCheckIds` **nevolá** — počet volání není konstantní.
- **Stávající testy** (severity mapping, actions passthrough, tvar
  title/subtitle/id, prázdný vstup) zachovat — jen jim podstrč agregátní
  řádek s `cnt <= 3` + individuální řádky.
- **Nové testy**:
  - check s `cnt = 4` → přesně 1 skupinová karta, žádné individuální;
    `id = 'alert-group:{check_id}'`, `subtitle` obsahuje počet,
    `context.count = 4`, `context.group = true`
  - `cnt = 3` → 3 individuální karty (práh je ostrá nerovnost)
  - mix: check A `cnt=5`, check B `cnt=2` → 1 skupinová + 2 individuální
  - `max_severity = 30` → `kind = 'urgent'`; `10` → `'info'`
  - registr s definicí checku → `title = name`; registr `null` /
    neznámý check → `title = check_id` (fallback)
  - akce skupinové karty: `open_viewer`, `viewerId = 'core.alerts.alerts'`,
    `primary = true`, lokalizovaný label dle `ctx->language`
- `tests/Unit/Api/Controller/DashboardControllerTest.php` — `sortAndCap`
  se nemění; jen zkontrolovat, že nové signatury nic nerozbily.

### 6. Dokumentace

- `docs/dashboard.md` §5.2 (AlertsSource) — doplnit odstavec o agregaci:
  práh (> 3 per `check_id`), skupinová karta (titulek = název checku,
  pravdivý počet, kind dle nejvyšší severity, jediná `open_viewer` akce),
  `id = 'alert-group:{check_id}'`.
- `docs/alerts.md` §1 nebo konec — jedna věta s odkazem na
  `dashboard.md` §5.2 (jak se alerty prezentují ve feedu).
- `tasks/README.md` — zaregistrovat do sekce Dashboard.

### 7. Verifikace

1. `php -l` na dotčené soubory (`AlertsSource.php`,
   `DashboardController.php`, `public/index.php`)
2. `vendor/bin/phpunit --filter 'AlertsSource|DashboardController'`
3. Frontend se nemění → build netřeba.

## Akceptace

- [ ] DS s ~27 aktivními alerty jednoho checku + došlou poštou: feed
      ukazuje **1** skupinovou kartu a mail karty se normálně vejdou
      (faktury viditelné, chip „Přijaté faktury" > 0 při existujících
      extracted docs)
- [ ] Check s 1–3 aktivními alerty → individuální karty beze změny
      (severity mapping, actions passthrough)
- [ ] Skupinová karta: titulek = lokalizovaný název checku (jazyk DS),
      podtitulek s pravdivým počtem (správný i kdyby alertů bylo víc než
      `MAX_CARDS`), kind dle nejvyšší severity ve skupině, primary akce
      otevře alerts viewer
- [ ] Kartový kontrakt beze změny, frontend beze změny
- [ ] AI shrnutí (`summary()`) stojí nad týmiž kartami vč. skupinových
- [ ] `phpunit` zelený, `php -l` čistý, dokumentace aktualizovaná

## Rozhodnutí k designu (potvrzená)

- ✓ Práh > 3 aktivní alerty jednoho checku (`GROUP_THRESHOLD = 3`)
- ✓ Granularita per `check_id`; skupinová karta plně nahrazuje individuální
- ✓ Kind dle nejvyšší severity ve skupině (bez degradace o stupeň)
- ✓ Akce skupinové karty: zatím jen `open_viewer` na `core.alerts.alerts`
  bez per-check filtru
- ✓ Titulek z `AlertCheckRegistry::get()->name`, fallback `check_id`
- ✓ Kvóta per zdroj v `sortAndCap` odložena (samostatný task, až bude třeba)
- ✓ Pravdivé DB county chipů filtru mimo scope (viz `dashboard.md` §12 —
  serverový filtr kategorií)
