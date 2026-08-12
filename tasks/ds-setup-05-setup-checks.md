# ds-setup — Task 05: Setup checky a služba `SetupChecklist`

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D3, D12, D13**, kontrakt **§5.3**. Začátek Fáze 3.
> Panel je Task 06, karta ve feedu Task 07 — tenhle task dodává jen
> checky a službu, která je vyjmenuje.

## Kontext

Parametry vrstvy C jsou hotové (Tasky 03, 04, `vat-payer-01`), ale stav
nastavení se dnes uživateli nikde neukáže — jen jako `[TODO]` výpis
`ds-upgrade` v konzoli. Fáze 3 to mění.

`MissingOwnPersonCheck` (`base.persons.missing_own_person`) je prototyp:
`AlertCheck` potomek, singleton finding (`findingKey: ''`), `severity:
warning`, akce `open_form` s `preset`, v `module.jsonc` `tags: ["setup"]`.
Tenhle task ho zobecňuje na sedm dalších a přidává nad kolekcí službu.

**Dvě čtecí cesty (D12).** Karta ve feedu čerpá z tabulky alertů, kterou
plní cron (`shpd-server cron --slot=five-minutes` → `alerts-run`). Panel
checklistu spouští checky **naživo**, protože checky mají vlastní
`interval` a runner přeskakuje ty, kde `next_run_at > NOW` — panel by
jinak hlásil chybějící nastavení ještě dlouho poté, co ho uživatel
doplnil. Implementace checku je jedna, čtecí cesty dvě.

## Cíl

1. Sedm nových setup checků.
2. Služba `SetupChecklist` — vyjmenuje checky s `tags: ["setup"]`
   a spustí je naživo.
3. Zákaz `snooze` a `dismiss` pro setup alerty (D13).

## Závislosti

- Závisí na: Tasky 03, 04, `vat-payer-01` (hotové) — checky nad parametry
  potřebují `LayerCParameters` a klíče.
- Otevírá: Task 06 (panel), Task 07 (karta ve feedu).

## Potvrzená designová rozhodnutí (Anna)

1. **D3** — stav se nikde nepamatuje, dopočítává se. Žádný příznak
   „hotovo", žádná tabulka splněných kroků.
2. **D12** — hybrid: feed z tabulky alertů, panel naživo.
3. **D13** — `snooze` a `dismiss` jsou pro `tags: ["setup"]` zakázané.
   Bez toho by si uživatel položku checklistu odklikal, což je proti D3.
4. Checky zůstávají `AlertCheck` potomky se stejným rozhraním —
   `SetupChecklist` je jen druhý volající, ne druhá implementace.

## Před implementací přečti

- `docs/ds-setup.md` §5.3 (tabulka checků), rozhodnutí D3/D12/D13
- `docs/alerts.md` — celé §1 (konvence `id`), sekci o akcích a §12 (cron)
- `modules/base/persons/src/Checks/MissingOwnPersonCheck.php` — **vzor,
  který kopíruj**: `ACTIVE_DOC_STATES = [10, 40]`, lokalizace přes
  `$this->language === 'cs'`, `findingKey: ''` u singletonu
- `modules/base/persons/module.jsonc` ~ř. 118 — `alertChecks[]` položka
  se všemi lokalizovanými poli
- `src/Core/Alerts/AlertFinding.php` — pojmenované parametry konstruktoru
- `src/Core/Alerts/AlertCheckRegistry.php` — `getAll()`, `getEnabled()`,
  `get()`; `AlertCheckDefinition::$tags`
- `src/Core/Alerts/AlertReconciler.php` — `runCheck()`, jak se check
  instancuje (`$db`, `$config`, `$language`)
- `src/Api/Controller/AlertsController.php` — `snooze()`, `dismiss()`,
  `unsnooze()` a jejich state guardy (409)
- `modules/economy/codebooks/src/VatAgendaNavGate.php` — vzor čtení
  settings klíče v runtime kódu
- `src/Core/Settings/LayerCParameters.php` — `SPECS` jako zdroj seznamu
  parametrů

## Rozsah

### Sedm nových checků

Konvence `id` = `<group>.<module>.<slug>`, všechny `severity: warning`,
všechny singleton (`findingKey: ''`), všechny `tags: ["setup"]`.

| Check | Modul | Detekce |
|---|---|---|
| `base.persons.missing_own_headquarters` | `base.persons` | vlastní Osoba existuje, ale nemá adresu `address_type = 1` (Sídlo) |
| `economy.accounting.undecided_account_chart` | `economy.accounting` | chybí klíč `economy.accountChart` |
| `economy.codebooks.undecided_fiscal_year_start` | `economy.codebooks` | chybí klíč `economy.fiscalYearStartMonth` |
| `economy.codebooks.undecided_home_currency` | `economy.codebooks` | chybí klíč `economy.homeCurrency` |
| `economy.codebooks.undecided_vat_agenda` | `economy.codebooks` | chybí klíč `economy.vatAgenda` |
| `economy.codebooks.missing_vat_registration` | `economy.codebooks` | `vatAgenda === true` ∧ 0 aktivních registrací |
| `economy.codebooks.missing_own_bank_account` | `economy.codebooks` | 0 aktivních řádků v `economy_codebooks_bank_accounts` |

**Podmíněnost = check vrátí `[]`.** Žádný nový mechanismus:

- `missing_own_headquarters` mlčí, když vlastní Osoba **neexistuje** —
  tehdy svítí `missing_own_person` a dvě položky o tomtéž jsou šum.
- `missing_vat_registration` mlčí u neplátce (`vatAgenda === false`)
  i u nerozhodnutého (`null`) — u nerozhodnutého svítí
  `undecided_vat_agenda`.
- checky nad osnovou mlčí, když je rozhodnuto `none`.

**Akce.** Checky nad chybějícím řádkem → `open_form` s `preset` (vzor
`MissingOwnPersonCheck`). Checky nad nerozhodnutým parametrem → akci
**zatím nedávej žádnou**; `open_panel` a panel dodává Task 06/07.
Prázdné `actions` je legální stav, karta se pak jen zobrazí bez tlačítka.

**Aktivní stavy.** `docState IN (10, 40)` = Koncept / V pořádku, stejně
jako v `MissingOwnPersonCheck`. U bankovních účtů zvaž i `valid_to`
v minulosti — účet s prošlou platností není použitelný. Rozhodni podle
toho, co `BankAccountDocument` považuje za platné, a rozhodnutí napiš
do doc komentáře checku.

Registrace v `module.jsonc` příslušných modulů (`alertChecks[]`),
kompletní `name`/`description` v `cs` i `en`, `interval`. **Interval dej
krátký** (`5m`) — cron slot je pětiminutový a setup checky jsou levné
`COUNT` dotazy; delší interval by jen zvětšoval prodlevu karty ve feedu.

### `SetupChecklist` — služba pro živé spuštění

Umístění: `src/Core/Settings/SetupChecklist.php` (vedle
`LayerCParameters`; setup je vlastnost nastavení DS, ne alertů).

```php
final class SetupChecklist
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
    ) {}

    /** @return list<array{checkId: string, name: string, finding: AlertFinding}> */
    public function collect(): array
    ...
}
```

- Vyjmenuje z registry checky, které mají `'setup'` v `$tags` **a jsou
  enabled**, instancuje je stejně jako `AlertReconciler` a zavolá `run()`.
- **Do tabulky alertů nezapisuje nic.** To je práce cronu.
- **Fail-open po jednotlivých checcích:** výjimka z jednoho checku se
  zaloguje (`ErrorLogger`) a sběr pokračuje. Rozbitý check nesmí
  zneprůchodnit celý panel — a panel je jediné místo, kde uživatel
  uvidí, co má dodělat.
- Pořadí výstupu drž **stabilní a smysluplné**, ne podle registry:
  vlastní Osoba → sídlo → plátcovství DPH → registrace DPH → bankovní
  účet → osnova → fiskální rok → měna. Panel i budoucí průvodce z toho
  dělají kroky, takže pořadí je součást kontraktu; dej ho do konstanty
  a do doc komentáře napiš, že se řídí závislostmi, ne abecedou.
- Neznámý check v pořadí (přidaný později bez zápisu do konstanty) →
  na konec, ne výjimka.

### Zákaz snooze/dismiss pro setup alerty (D13)

V `AlertsController::snooze()` a `dismiss()`, k existujícím state guardům:
najdi definici checku v registry podle `check_id` alertu a pokud má
`'setup'` v `$tags`, vrať **409** s vysvětlující zprávou („Setup alerts
cannot be snoozed or dismissed — the item disappears once the setting is
filled in"). `unsnooze()` nechej být (nemá co dělat, když snooze nejde).

Chybějící definice v registry → **nezakazuj** (fail-open, stejná logika
jako u nav gate).

### Dokumentace

- `docs/alerts.md` — do sekce o akcích a stavech doplnit, že setup alerty
  snooze/dismiss nepodporují a proč (odkaz na `ds-setup.md` D13); do §12
  poznámku, že setup checky mají krátký interval kvůli feedu.
- `docs/ds-setup.md` — §5.3 srovnat s realitou (akce u parametrových
  checků přijdou až s Taskem 06/07); pokud se cokoli odchýlí, uprav spec.

## Testy

Pro **každý** check vlastní test: pozitivní případ (svítí), negativní
(nesvítí), a u podmíněných i případ, kdy má mlčet kvůli jiné položce:

- `missing_own_headquarters` mlčí bez vlastní Osoby
- `missing_vat_registration` mlčí u `vatAgenda === false` i u `null`
- checky nad osnovou mlčí u `none`

`SetupChecklistTest`:

- vrátí právě checky s tagem `setup`, v definovaném pořadí
- check, který vyhodí výjimku, sběr nezastaví a ostatní položky přijdou
- prázdný výsledek, když je vše nastaveno
- **nezapíše nic do `core_alerts_alerts`** (regresní test na D12)

`AlertsControllerTest`:

- snooze i dismiss setup alertu → 409
- snooze i dismiss běžného alertu → dál funguje
- alert s `check_id`, který v registry není → nezakazuje se

Spuštění: `vendor/bin/phpunit --filter 'SetupChecklist|AlertsController|Check'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS: spusť checky tlačítkem „Run due checks" ve vieweru alertů
   → osm setup alertů (sedm nových + `missing_own_person`).
2. Založ vlastní Osobu bez adresy → `missing_own_person` zmizí (resolved),
   `missing_own_headquarters` se rozsvítí.
3. `ds-setting set economy.vatAgenda false` → `undecided_vat_agenda`
   zmizí a `missing_vat_registration` se **nerozsvítí**.
4. Přepni na `true` → `missing_vat_registration` se rozsvítí; založ
   registraci → zmizí.
5. Zkus setup alert snoozovat a dismissovat ve vieweru → 409, chybová
   zpráva se ukáže uživateli srozumitelně.
6. Nastav vše → po dalším běhu žádný setup alert.

Panel zatím neexistuje, takže `SetupChecklist` ověř přes unit testy;
pokud si chceš potvrdit živý běh, přidej ho **do už existujícího**
`/_alerts/checks/{checkId}/run` výstupu jen na dobu ladění a před
commitem to zase odstraň.

## Hotovo když

- [ ] Osm setup checků svítí na čerstvém DS a mizí po doplnění
- [ ] Podmíněné checky mlčí, když má mluvit jiná položka
- [ ] `SetupChecklist::collect()` vrací položky v definovaném pořadí
      a do tabulky alertů nepíše
- [ ] Rozbitý check nezastaví sběr
- [ ] Setup alerty nejdou snoozovat ani dismissovat (409)
- [ ] `docs/alerts.md` doplněná
- [ ] Testy zelené

## Pasti / na co pozor

- **Nerozhodnutý klíč není `false`.** `SettingsStore::get()` vrací `null`
  u chybějícího klíče; `undecided_*` checky reagují na `null`,
  `missing_vat_registration` na `=== true`. Ani jeden nesmí použít
  `?? false` — jinak nerozhodnutý DS vypadá jako rozhodnutý.
- **Dvě položky o jedné věci jsou horší než žádná.** Podmíněnost není
  kosmetika: na čerstvém DS má svítit „chybí vlastní Osoba", ne „chybí
  vlastní Osoba" + „chybí sídlo" + „chybí registrace DPH". Ověř to bodem
  1 a 2.
- **`SetupChecklist` nesmí zapisovat do tabulky alertů.** Vypadá to jako
  užitečný vedlejší efekt („když je stejně spouštíme, ať se to uloží"),
  ale panel by pak psal do DB při každém načtení a míchal by si to
  s cronem. Je to v testech jako regresní případ.
- **Interval `5m` u osmi checků** znamená osm `COUNT` dotazů každých pět
  minut per DS. Drž dotazy triviální a bez JOINů; kdyby některý check
  potřeboval složitější dotaz, napiš to a probereme interval.
- `AlertCheckRegistry` bere v konstruktoru `array $modules` a `string
  $language`. Podívej se, jak si ji obstarává `AlertsController`, a použij
  tentýž způsob — neinstancuj ji z resolvovaných modulů znovu vlastní
  cestou.
- Popisy checků v `module.jsonc` se ukazují uživateli ve vieweru alertů.
  Piš je jako **návod, co doplnit**, ne jako popis detekce („Účtová osnova
  ještě nebyla vybrána; vyber variantu v Nastavení", ne „Chybí klíč
  economy.accountChart").
