# ds-setup — Task 07: Agregovaná karta feedu a akce `open_panel`

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D8, D12**, kontrakt **§5.5**. Poslední kus Fáze 3 —
> po něm uživatel na čerstvém DS uvidí, že má něco dodělat, i když
> Nastavení vůbec neotevře.

## Kontext

Task 05 dodal osm setup checků, které cron reconciluje do
`core_alerts_alerts`. Task 06 dodal panel `dsSetup`. Zbývá je propojit
přes dashboard: **jedna karta ve feedu, která vede do panelu.**

`AlertsSource` dnes agreguje `GROUP BY check_id` s `GROUP_THRESHOLD = 3`
(sbalí se až 4+ alertů jednoho checku). Osm setup alertů je osm **různých**
checků po jednom alertu, takže by se nesbalilo nic — dostali bychom osm
samostatných karet. Proto **nová osa agregace podle tagu** (D8).

## Cíl

1. Tagová agregace v `AlertsSource` — setup alerty do jedné karty.
2. Nový druh akce `open_panel` (server → frontend kontrakt).
3. Navigace na panel z dashboardu (přepnutí do režimu Nastavení).

## Závislosti

- Závisí na Tasku 05 (checky s `tags: ["setup"]`) a Tasku 06 (panel
  `dsSetup`) — oba hotové.
- Otevírá: Fázi 4 (průvodce dostane `open_panel` hotové).

## Potvrzená designová rozhodnutí (Anna)

1. **D8** — agregace podle tagu jako **rozšíření `AlertsSource`**, ne nový
   feed zdroj. Primární akce karty otevírá panel.
2. **D12** — karta čerpá z tabulky alertů (plní cron), na rozdíl od panelu,
   který checky spouští naživo. Karta tedy může být až pět minut pozadu
   a to je v pořádku.

## Před implementací přečti

- `docs/ds-setup.md` §5.5, rozhodnutí D8/D12
- `docs/dashboard.md` — sekce o agregaci karet a o kontraktu karty
- `modules/core/alerts/src/Feed/AlertsSource.php` — celý: `collectCards()`
  (dvoufázový sběr), `buildCard()`, `buildGroupCard()`,
  `severityToPresentation()`, `passthroughActions()`
- `src/Core/Alerts/AlertCheckRegistry.php` + `AlertCheckDefinition::$tags`
- `frontend/src/components/dashboard/Dashboard.svelte` ~ř. 130
  `handleCardAction()` — switch nad `action.kind`
- `frontend/src/stores/navigation.svelte.js` — `navigate()`,
  `navigateToViewer()` jako vzor, `enterSettings()`
- `src/Api/Controller/SettingsController.php` ~ř. 635 — jak se skládá
  nav item panelu (`'id' => 'panel:' . $panelId`, `type`, `panelId`,
  lokalizovaný `label`)

## Rozsah

### `AlertsSource` — tagová agregace

Nová **fáze 0** před dnešní fází 1:

1. Z registry si vezmi `check_id` všech checků, které mají `'setup'`
   v `$tags`.
2. Jednou agregační dotaz nad `alert_state = ACTIVE` **omezený na tyhle
   check_id** → `COUNT(*)`, `MAX(severity)`, `MAX(last_seen_at)`,
   `MAX(first_seen_at)`.
3. Je-li `COUNT > 0`, přidej **jednu** kartu (`buildSetupCard()`)
   a všechny tyhle `check_id` **vyřaď** z fáze 1 i 2, aby se neobjevily
   podruhé.

Práh **žádný** — sbaluje se od jedné položky. Osm samostatných karet
nechceme nikdy, ani jednu „normální" a sedm skupinových.

Karta:

```php
'id'         => 'alert-group:setup',
'source'     => 'alerts',
'kind'       => // ze severityToPresentation(MAX(severity)) — bez změny
'category'   => FeedSource::CATEGORY_OTHER,
'title'      => $cs ? 'Dokončit nastavení' : 'Finish setup',
'subtitle'   => // viz níže
'timestamp'  => // MAX(last_seen_at) ?? MAX(first_seen_at)
'context'    => ['tag' => 'setup', 'count' => $count,
                 'severity' => $severity, 'group' => true],
'actions'    => [[
    'id'      => 'open_setup_panel',
    'label'   => $cs ? 'Otevřít nastavení' : 'Open setup',
    'kind'    => 'open_panel',
    'target'  => ['panelId' => 'dsSetup'],
    'primary' => true,
]],
```

**Podtitulek podle počtu.** Jedna nesplněná položka → ukaž její `title`
(karta pak řekne konkrétně, co chybí, což je u posledního zbývajícího
kroku užitečnější než číslo). Dvě a víc → počet, se správným českým
skloňováním: `1 položka` se nepoužije, ale `2–4 položky` a `5+ položek`
ano. Anglicky `N items` / `1 item`.

Pro variantu s jednou položkou potřebuješ i `title` toho alertu —
buď to vytáhni ve stejném dotazu (`MIN(title)` je ošklivé, ale při
`COUNT = 1` korektní), nebo si dej druhý dotaz jen v té větvi. **Druhý
dotaz v jedné větvi je čitelnější než trik s agregační funkcí** —
zvol ho.

**Registry může být `null`** (`AlertsSource::__construct` má
`?AlertCheckRegistry $registry = null` a dnešní kód na to spoléhá u titulků
skupin). Bez registry tag neznáme → **tagovou agregaci přeskoč** a nech
alerty projít jako individuální karty. Fail-open, žádná výjimka.

### Nový druh akce `open_panel`

Serverová strana je jen ten `actions` blok výše — žádná registrace,
`passthroughActions()` propouští cokoli.

Frontend `Dashboard.svelte::handleCardAction()`:

```js
case 'open_panel':
  return navigationStore.navigateToPanel(target.panelId, action.label);
```

### `navigationStore.navigateToPanel()`

Nová funkce vedle `navigateToViewer()`. Musí udělat **dvě** věci:

```js
function navigateToPanel(panelId, label = null) {
  enterSettings();
  settingsActiveItem = {
    id: 'panel:' + panelId,
    label: label ?? panelId,
    type: 'panel',
    table: null,
    viewerId: null,
    pageId: null,
    panelId,
    filter: null,
    fixedViewGroup: null,
  };
}
```

- **`enterSettings()` je nutné.** Dashboard běží v režimu `app`, panel
  `dsSetup` je položka `settingsItems`. `navigate()` samo by zapsalo do
  `appActiveItem`, protože se rozhoduje podle aktuálního `mode` — karta
  by tedy nic neudělala. Tohle je hlavní past tasku.
- **`id` musí být `'panel:' + panelId`** — přesně to, co skládá
  `SettingsController` (`'id' => 'panel:' . $panelId`), jinak se položka
  v sidebaru nezvýrazní.
- Label ber z akce karty (je lokalizovaný serverem). Kdyby chyběl, padni
  na `panelId` — stejná degradace jako u `navigateToViewer`.
- Exportuj v `navigationStore`.

### Dokumentace

- `docs/dashboard.md` — do sekce o agregaci doplnit tagovou osu: kdy se
  použije, že nemá práh a že vyřazuje dotčené checky z obou dalších fází.
- `docs/alerts.md` — do výčtu druhů akcí doplnit `open_panel`
  s payloadem `{panelId}`.
- `docs/ds-setup.md` — §5.5 srovnat s realitou (podtitulek podle počtu,
  fail-open bez registry).

## Testy

`tests/Unit/Module/Core/Alerts/Feed/AlertsSourceTest.php` (existuje):

- osm aktivních setup alertů → **jedna** karta `alert-group:setup`
  s `count = 8`, žádná individuální karta žádného z těch checků
- jeden setup alert → jedna karta, podtitulek = `title` toho alertu
- dva setup alerty → podtitulek s počtem a správným skloňováním
  (otestuj i 5, ať se pokryje `položek`)
- setup alerty **plus** běžné alerty → setup karta + individuální/skupinové
  karty ostatních checků, bez překryvu
- `severity` karty = `MAX` ze skupiny (přidej jeden `error` mezi
  `warning` a ověř, že karta je `urgent`)
- `registry === null` → tagová agregace se přeskočí, alerty projdou
  individuálně
- žádný aktivní setup alert → žádná setup karta

Frontend: `cd frontend && npm run build` (timeout 90–120 s).

Spuštění PHP: `vendor/bin/phpunit --filter 'AlertsSource'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS, spusť checky („Run due checks") → na dashboardu **jedna**
   karta „Dokončit nastavení" s počtem 8, ne osm karet.
2. Klikni primární akci → aplikace přepne do Nastavení a otevře panel
   `dsSetup`; položka je v sidebaru zvýrazněná.
3. V panelu dorozhoduj vše kromě jedné položky, spusť checky znovu →
   karta ukazuje `title` té poslední položky místo počtu.
4. Dorozhoduj i tu → po dalším běhu checků karta z feedu zmizí.
5. Vyvolej jeden běžný alert (např. účetní chybu) vedle setup alertů →
   ve feedu jsou obě karty a nic se nezdvojuje.

## Hotovo když

- [ ] Osm setup alertů dává jednu kartu, ne osm
- [ ] Karta vede z dashboardu do panelu `dsSetup` v Nastavení
- [ ] Položka panelu je po navigaci v sidebaru zvýrazněná
- [ ] Podtitulek u jedné položky ukazuje, co chybí; u víc počet
      se správným skloňováním
- [ ] Setup a běžné alerty se ve feedu nepřekrývají
- [ ] Bez registry se nic nerozbije
- [ ] `npm run build` prochází, PHP testy zelené
- [ ] `docs/dashboard.md` a `docs/alerts.md` doplněné

## Pasti / na co pozor

- **`navigate()` sám nestačí.** Zapisuje do `appActiveItem` /
  `settingsActiveItem` / `accountActiveItem` podle aktuálního `mode`.
  Bez `enterSettings()` karta zapíše panel do `appActiveItem` a nestane
  se nic viditelného. Ověřuj to bodem 2, ne jen unit testem.
- **Vyřazení check_id z fází 1 a 2.** Když se zapomene, dostaneš setup
  kartu **i** osm individuálních. Je to v testech jako explicitní případ
  („žádná individuální karta žádného z těch checků").
- **`LIMIT %i` ve fázi 2** používá `$ctx->maxCards`. Setup karta se
  přidává mimo tento limit (stejně jako dnešní skupinové karty), takže
  se nemůže „vytlačit" pod nápor jiných alertů. Nepřesouvej ji do
  limitované fáze.
- **Skloňování napiš správně, ne přes `count + ' položek'`.** Čeština má
  tři formy a karta je na dashboardu vidět pořád. Podívej se, jestli
  frontend nebo backend už nějakou pomůcku na plurály má, a použij ji;
  pokud ne, napiš malou funkci na jednom místě, ne inline ternár.
- **Nespoléhej na to, že `tags` obsahuje jen `setup`.** Check může mít
  víc tagů; filtruj `in_array('setup', $def->tags, true)`, ne rovnost.
- Karta má `category: CATEGORY_OTHER` jako ostatní alertové karty —
  filtr kategorií na dashboardu ji tedy schová spolu s nimi. To je
  zamýšlené; nezavádět pro setup vlastní kategorii.
