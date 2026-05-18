# Task: `docs.core.stale_in_repair` — druhý reálný alert check

Cílem je přidat druhý check do systému Upozornění: detekce dokladů, které visí
ve stavu **80 V opravě** déle než 24 hodin. To vyžaduje:

1. Nový sloupec `doc_state_changed_at` v `docs_core_heads` (a backfill)
2. Hook v `DocDocument::beforeSave`, který sloupec udržuje při změně `docState`
3. Check třídu `StaleInRepairCheck`
4. Registraci v `modules/docs/core/module.jsonc`
5. Aktualizaci dokumentace

Po dokončení bude `core.alerts` mít dva produkční checky — singleton
`base.persons.missing_own_person` a per-row `docs.core.stale_in_repair`.
Tím se ověří, že infrastruktura `AlertCheck`/`AlertFinding`/`AlertReconciler`
funguje pro různé typy nálezů.

---

## 1. Co číst před začátkem

- `modules/docs/core/tables/docs_core_heads.jsonc` — sem se přidává sloupec
  + index
- `modules/docs/core/src/DocDocument.php` — `beforeSave()` orchestrace, sem
  se přidává hook `trackStateChange()`
- `modules/docs/core/config/docStates.jsonc` — referenční seznam stavů
  (80 V opravě je cíl)
- `modules/docs/core/module.jsonc` — sem se přidává `alertChecks` blok
- `modules/base/persons/src/Checks/MissingOwnPersonCheck.php` — vzor pro
  novou check třídu (signatura `AlertCheck::run()`, formát `AlertFinding`)
- `modules/base/persons/module.jsonc` — vzor pro `alertChecks` JSONC zápis
- `docs/alerts.md` — aktualizovat seznam v zásobníku a přidat druhý příklad

---

## 2. Klíčová rozhodnutí (recap z diskuse)

Uzavřené, nediskutovat:

1. **Sloupec `doc_state_changed_at` jen v `docs_core_heads`**, ne generický
   napříč všemi tabulkami s docStates. Když se ukáže potřeba jinde,
   vyextrahuje se v dalším kole.
2. **Práh 24 h** jako `private const STALE_HOURS = 24` v třídě checku.
   Žádný konfig.
3. **Per-doc alerty** — jeden alert na každý stale doklad. `finding_key =
   (string) $row['id']`. Subject odkazuje na `docs_core_heads/{id}`.
4. **Akce "Otevřít doklad"** přes `kind: 'open_form'`, primární variant.
5. **Interval kontroly 1 h.** Práh 24 h × kontrola 1 h = max 25 h v praxi —
   akceptovatelné.
6. **Severity `warning`** (20).
7. **Hlídá se pouze stav 80 V opravě.** Ostatní stavy (10 Koncept, 20 Potvrzeno)
   mohou v budoucnu dostat vlastní checky — žádné mixování v této třídě.

---

## 3. Změna `docs_core_heads.jsonc`

### Nový sloupec

Přidat **na konec sloupců** (před `docState`/`docStateMain` system sloupce —
nebo bezprostředně za `docStateMain`, podle estetiky). Sloupec je system,
nepatří do žádné `columnGroups` skupiny:

```jsonc
{
    "id": "doc_state_changed_at",
    "name": "Doc state changed at",
    "name:cs": "Stav změněn",
    "name:en": "Doc state changed at",
    "type": "datetime",
    "nullable": true,
    "system": true
}
```

`nullable: true` schválně — starší existující řádky před backfill ho budou
mít NULL; backfill je doplní (viz níže).

### Nový index

Přidat do `indexes` pole:

```jsonc
{
    "id": "idx_doc_state_changed",
    "type": "index",
    "columns": [
        {"column": "docState"},
        {"column": "doc_state_changed_at"}
    ]
}
```

Tento index slouží **přímo** check dotazu (`WHERE docState = 80 AND
doc_state_changed_at < ...`). Stávající `idx_doc_state` má jiné druhé pole
(`doc_number`) a checkový dotaz nepokrývá.

### Backfill po `ds-upgrade`

Existující řádky v `docs_core_heads` budou mít po přidání sloupce
`doc_state_changed_at = NULL`. SQL podmínka `< NOW() - 24h` na NULL vrátí
NULL/false → check je přirozeně ignoruje. To je špatně — pokud má někdo
reálný doklad, který už dnes visí v 80 týden, nedostane upozornění.

Řešení: jednorázový backfill v rámci migrace.

**Implementace**: doplnit do `DsUpgradeCommand` (nebo místa, kde se aplikují
schema změny) **post-upgrade SQL**:

```sql
UPDATE docs_core_heads
SET doc_state_changed_at = NOW()
WHERE doc_state_changed_at IS NULL;
```

Idempotentní (po prvním běhu už NULL neexistuje, druhý běh nic neudělá).
Jednoduchý `UPDATE` bez transakce, na čerstvém DS se vykoná na prázdné
tabulce — žádný overhead.

**Kam přesně backfill umístit**: pokud `DsUpgradeCommand` má pattern "post-schema
SQL hooks", použij ten. Pokud ne, dej do `DocsCoreProvisioner` (nebo jinde,
kde se provisioning modulu spouští) idempotentní SQL — vyber existující pattern
v projektu, který se vykonává po každém upgrade. Pokud nic takového není,
přijde na to v rámci tasku — implementer ať najde nejmenší možnou cestu
(ideálně 1 řádek SQL v existujícím hook bodu, který už pro tuto tabulku něco
dělá; vyhnout se vytváření nového lifecycle stage).

### Aktualizovat `docs_core_heads.md`

Krátká zmínka v dokumentaci tabulky:

> ### `doc_state_changed_at`
>
> Datum a čas posledního přechodu mezi `docState` hodnotami. Vyplňováno
> automaticky v `DocDocument::beforeSave`, kdykoliv `$data['docState']`
> liší od `$originalData['docState']`. U nových záznamů se nastavuje na
> aktuální čas. Slouží jako vstup pro alert check
> `docs.core.stale_in_repair` (detekce dokladů visících v opravě déle
> než 24 h).

---

## 4. Hook v `DocDocument::beforeSave`

Přidat soukromou metodu `trackStateChange()` a zavolat ji **na úplném začátku**
`beforeSave()`, před `denormalizeDocType`. Důvod: nezávislé na ostatních
hookech, žádné side-effects od nich nepotřebuje, intuitivně patří první.

```php
public function beforeSave(array &$data, ?array $originalData = null): void
{
    $this->trackStateChange($data, $originalData);

    $this->denormalizeDocType($data);
    // ... existing ...
}

/**
 * Eviduje čas poslední změny `docState`. Slouží jako vstup pro alert
 * check `docs.core.stale_in_repair`.
 *
 * - Nové záznamy (`$originalData === null`): nastav `doc_state_changed_at`
 *   na aktuální čas, ať od prvního saveu existuje validní hodnota.
 * - Update s nezměněným `docState`: nesahej (klient může poslat sloupec
 *   v payloadu, ale validní hodnota je ta v `$originalData`).
 * - Update se změněným `docState`: nastav `doc_state_changed_at` na NOW.
 *
 * Klientský payload se přepisuje vždy — sloupec je `system: true`,
 * uživatel ho nemá nastavovat.
 */
private function trackStateChange(array &$data, ?array $originalData): void
{
    if ($originalData === null) {
        $data['doc_state_changed_at'] = date('Y-m-d H:i:s');
        return;
    }

    $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
    $oldState = (int) ($originalData['docState'] ?? 10);

    if ($newState !== $oldState) {
        $data['doc_state_changed_at'] = date('Y-m-d H:i:s');
        return;
    }

    // Žádná změna — explicitně zachovat původní hodnotu, ať klient nemůže
    // sloupec přepsat nevalidním vstupem.
    $data['doc_state_changed_at'] = $originalData['doc_state_changed_at']
        ?? date('Y-m-d H:i:s');
}
```

Pozn. k poslední větvi: pokud `$originalData['doc_state_changed_at']` je
`null` (existující řádek před backfillem, kde backfill z nějakého důvodu
nedoběhl), nastavíme NOW jako bezpečný fallback. Lepší než ponechat NULL
a riskovat že to check ignoruje navždy.

---

## 5. Check class `StaleInRepairCheck`

`modules/docs/core/src/Checks/StaleInRepairCheck.php`:

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Detekuje doklady, které visí ve stavu „V opravě" (docState = 80)
 * déle než `STALE_HOURS` hodin. Jeden alert per stale doklad.
 *
 * `finding_key` = ID dokladu jako string → reconciler dedupuje napříč
 * běhy a auto-resolvuje, jakmile doklad ze stavu 80 odejde (zpět do
 * 40 V pořádku, nebo na 30 Storno, nebo na 90 Smazáno).
 */
final class StaleInRepairCheck extends AlertCheck
{
    private const STALE_HOURS = 24;

    private const DOC_STATE_IN_REPAIR = 80;

    public function run(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_number], [doc_text], [doc_state_changed_at]
             FROM [docs_core_heads]
             WHERE [docState] = %i
               AND [doc_state_changed_at] IS NOT NULL
               AND [doc_state_changed_at] < %t
             ORDER BY [doc_state_changed_at] ASC',
            self::DOC_STATE_IN_REPAIR,
            (new \DateTimeImmutable("-" . self::STALE_HOURS . " hours")),
        );

        $findings = [];
        foreach ($rows as $row) {
            $findings[] = $this->buildFinding($row instanceof \Dibi\Row ? $row->toArray() : (array) $row);
        }
        return $findings;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildFinding(array $row): AlertFinding
    {
        $id          = (int) $row['id'];
        $docNumber   = (string) ($row['doc_number'] ?? '');
        $docText     = (string) ($row['doc_text'] ?? '');
        $changedAt   = (string) ($row['doc_state_changed_at'] ?? '');

        $days = $this->daysSince($changedAt);
        $docLabel = $this->displayLabel($id, $docNumber);

        $title   = $this->buildTitle($docLabel, $days);
        $message = $this->buildMessage($docLabel, $docText, $changedAt);

        return new AlertFinding(
            findingKey: (string) $id,
            title: $title,
            message: $message,
            severity: 'warning',
            subjectTableId: 401,                // docs_core_heads
            subjectRowId: $id,
            actions: [
                [
                    'id'      => 'open_doc',
                    'label'   => $this->actionLabelOpenDoc(),
                    'kind'    => 'open_form',
                    'variant' => 'primary',
                    'primary' => true,
                    'target'  => [
                        'table' => 'docs_core_heads',
                        'mode'  => 'edit',
                        'id'    => $id,
                    ],
                ],
            ],
            context: [
                'doc_number'           => $docNumber,
                'doc_state_changed_at' => $changedAt,
                'days_stale'           => $days,
            ],
        );
    }

    /**
     * "Doklad {label} je v opravě {N} {dní|dny|den}" (cs)
     * "Document {label} has been in repair for {N} day(s)" (en)
     */
    private function buildTitle(string $label, int $days): string
    {
        if ($this->language === 'cs') {
            return sprintf('Doklad %s je v opravě %s', $label, $this->czechDays($days));
        }
        return sprintf('Document %s has been in repair for %d day%s',
            $label, $days, $days === 1 ? '' : 's');
    }

    private function buildMessage(string $label, string $docText, string $changedAt): string
    {
        if ($this->language === 'cs') {
            $textPart = $docText !== '' ? sprintf(' (%s)', $docText) : '';
            return sprintf(
                'Doklad %s%s je ve stavu „V opravě" už od %s. Stojí to za pozornost — '
                . 'buď ho dokončit („V pořádku"), nebo vrátit do Konceptu.',
                $label, $textPart, $this->formatDate($changedAt),
            );
        }
        $textPart = $docText !== '' ? sprintf(' (%s)', $docText) : '';
        return sprintf(
            'Document %s%s has been in "Being edited" state since %s. '
            . 'Either complete it (mark as Done) or revert to Draft.',
            $label, $textPart, $this->formatDate($changedAt),
        );
    }

    private function actionLabelOpenDoc(): string
    {
        return $this->language === 'cs' ? 'Otevřít doklad' : 'Open document';
    }

    /**
     * Doklad obvykle má reálné `doc_number` (přechod do 80 vede přes 40,
     * tedy číslo už je přiděleno), ale defenzivně padáme na "Doklad #{id}",
     * pokud by `doc_number` byl prázdný nebo placeholder (`!0000000...`).
     */
    private function displayLabel(int $id, string $docNumber): string
    {
        if ($docNumber === '' || str_starts_with($docNumber, '!')) {
            return $this->language === 'cs' ? '#' . $id : '#' . $id;
        }
        return $docNumber;
    }

    private function daysSince(string $datetime): int
    {
        if ($datetime === '') {
            return 0;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return 0;
        }
        $diff = time() - $ts;
        return max(0, (int) floor($diff / 86400));
    }

    /** "1 den" / "2 dny" / "5 dnů" */
    private function czechDays(int $n): string
    {
        if ($n === 1) return '1 den';
        if ($n >= 2 && $n <= 4) return $n . ' dny';
        return $n . ' dnů';
    }

    private function formatDate(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        if ($this->language === 'cs') {
            return date('j. n. Y H:i', $ts);
        }
        return date('Y-m-d H:i', $ts);
    }
}
```

**Pozn. k SQL parametru `%t`**: Dibi `%t` formatuje `\DateTimeInterface` jako
`Y-m-d H:i:s`. Ověř existující použití v projektu — pokud `%t` není v use,
fall back na `%s` s předem zformátovaným `->format('Y-m-d H:i:s')`.

**Pozn. k `doc_state_changed_at IS NOT NULL`**: explicitní guard, ač
matematicky redundantní (NULL < timestamp je NULL = falsy). Index
`(docState, doc_state_changed_at)` to využije efektivněji než kdyby
optimizer dohadoval IS NOT NULL z porovnání.

---

## 6. Registrace v `modules/docs/core/module.jsonc`

Přidat `alertChecks` blok (nebo doplnit do existujícího, pokud už `docs.core`
nějaké checky má):

```jsonc
"alertChecks": [
    {
        "id": "docs.core.stale_in_repair",
        "name": "Doklady v opravě déle než 24 h",
        "name:cs": "Doklady v opravě déle než 24 h",
        "name:en": "Documents stale in repair (>24 h)",
        "description": "Detects documents stuck in 'Being edited' state for more than 24 hours.",
        "description:cs": "Detekuje doklady visící ve stavu „V opravě" déle než 24 hodin.",
        "class": "Shipard\\Module\\Docs\\Core\\Checks\\StaleInRepairCheck",
        "severity": "warning",
        "interval": "1h",
        "tags": ["docs", "stale"]
    }
]
```

---

## 7. Testy

### PHPUnit pro `StaleInRepairCheck`

Vytvořit `tests/Module/Docs/Core/Checks/StaleInRepairCheckTest.php` (následuj
strukturu existujících testů checků). Pokrytí:

1. **Žádné stale doklady → prázdné pole.** Vlož doklady ve stavech 10, 20,
   40 (s libovolným `doc_state_changed_at`) + jeden ve stavu 80 s nedávným
   `doc_state_changed_at` (před 1 h). `run()` vrátí `[]`.

2. **Jeden stale doklad → jeden AlertFinding.** Vlož doklad ve stavu 80
   s `doc_state_changed_at = NOW - 36 hours`. `run()` vrátí jeden Finding
   s `findingKey === (string) $id`, `subjectTableId === 401`,
   `subjectRowId === $id`, jeden action s `id === 'open_doc'`,
   `target.table === 'docs_core_heads'`, `target.mode === 'edit'`.

3. **NULL `doc_state_changed_at` se ignoruje.** Doklad ve stavu 80 s NULL
   `doc_state_changed_at` nevyrobí Finding (i kdyby řádek existoval z
   doby před backfillem — defenzivní guard).

4. **Lokalizace title.** Spustit check s `language='cs'` a `language='en'`,
   ověřit příslušný formát title.

5. **Plurály cs.** Title pro 1, 2, 4, 5, 10 dnů — "1 den" / "2 dny" / "4 dny"
   / "5 dnů" / "10 dnů".

### PHPUnit pro `DocDocument::trackStateChange` hook

Doplnit do existujícího `DocDocumentTest` (nebo kde se testují beforeSave
hooky) tři scénáře:

1. **Nový záznam (`originalData === null`)**: po `beforeSave`,
   `$data['doc_state_changed_at']` je nastaveno na NOW (rozumný timestamp,
   né NULL, né distant past).

2. **Update s nezměněným `docState`**: `$originalData['doc_state_changed_at']`
   = `'2024-01-01 10:00:00'`. Po `beforeSave`, `$data['doc_state_changed_at']`
   = `'2024-01-01 10:00:00'` (nezměněno).

3. **Update se změněným `docState` (40 → 80)**: `$originalData['docState']`
   = 40, `$data['docState']` = 80. Po `beforeSave`, `$data['doc_state_changed_at']`
   je NOW.

Pozn.: hook se volá uvnitř `beforeSave`, který má hodně dalších hooků. Pro
izolovaný test mockuj DB a config tak, aby ostatní hooky doběhly bez chyby
(prázdná data), a verifikuj jen `doc_state_changed_at`. Existující testy
ukáží vzor.

---

## 8. Dokumentace

### `docs/alerts.md`

V sekci "Otevřené body / v zásobníku" odstraň `doc_state_changed_at` +
`stale_in_repair` (jsou hotové).

V příkladové sekci (kde je dnes `base.persons.missing_own_person`) přidej
druhý příklad: `docs.core.stale_in_repair`. Popsat:

- Jak se liší od singleton checku (per-row, `finding_key` = ID záznamu).
- Reconciliation chování: jakmile uživatel přepne doklad zpět do 40 nebo
  na 90, reconciler ho při dalším běhu auto-resolvuje (řádek z výsledku
  zmizí, alert přechází na state 70 Resolved).
- Vazba na `doc_state_changed_at` a `DocDocument::trackStateChange` hook.

### `modules/docs/core/README.md`

Krátká zmínka, že modul registruje alert check `docs.core.stale_in_repair`
a udržuje sloupec `doc_state_changed_at`.

---

## 9. Definition of done

- [ ] `docs_core_heads.jsonc` má sloupec `doc_state_changed_at` + index
      `idx_doc_state_changed`
- [ ] `ds-upgrade` na DS s existujícími doklady doplní backfill (žádný řádek
      s NULL `doc_state_changed_at`)
- [ ] `DocDocument::beforeSave` volá `trackStateChange` jako první krok
- [ ] Vytvoření nového dokladu → `doc_state_changed_at` je NOT NULL
- [ ] Změna stavu na uloženém dokladu (40 → 80) → `doc_state_changed_at` je NOW
- [ ] Update bez změny stavu → `doc_state_changed_at` je beze změny
- [ ] `StaleInRepairCheck::run()` projde PHPUnit testy (5 scénářů výše)
- [ ] `docs.core.stale_in_repair` registrovaný v `module.jsonc` — viditelný
      v `GET /_alerts/registry`
- [ ] `shpd-ds alerts-run --check=docs.core.stale_in_repair` v čerstvém DS
      bez dokladů vrátí 0 nálezů
- [ ] V DS s dokladem ve stavu 80 s `doc_state_changed_at` před >24 h
      vznikne alert s title obsahujícím `doc_number` a počet dnů
- [ ] V UI alerts vieweru je alert vidět, klik na "Otevřít doklad" otevře
      FormDialog pro daný doklad
- [ ] Po přepnutí dokladu zpět do 40 a manuálním re-checku (tlačítko
      „Zkontrolovat znovu" na alertu) alert přechází na Resolved
- [ ] `docs/alerts.md` aktualizovaný
- [ ] `modules/docs/core/README.md` doplněn

---

## 10. Out of scope

- Checky pro ostatní docStates (10 Koncept, 20 Potvrzeno) — samostatné checky
  v budoucnu, každý s vlastním prahem
- Konfigurovatelný práh `STALE_HOURS` (per-DS, per-doc-type) — počká si na
  reálnou potřebu
- Generický `doc_state_changed_at` napříč všemi tabulkami s docStates
- Bulk operation "Open all stale documents" / "Mark all as done"
- Notifikace odpovědné osobě (assignee) — `docs_core_heads` nemá assignment
  sloupec a celý concept "kdo má co opravit" je mimo MVP

---

## 11. Pořadí práce

1. JSONC změna `docs_core_heads.jsonc` (sloupec + index). Backfill mechanismus.
2. `ds-upgrade` proběhne čistě, ověřit přes `\\d docs_core_heads`.
3. Hook `trackStateChange` v `DocDocument` + jeho testy.
4. `StaleInRepairCheck` třída + její testy.
5. Registrace v `module.jsonc`. `shpd-ds alerts-run` ověří, že check je
   načtený.
6. End-to-end manuální test:
   - Vytvořit doklad, potvrdit (10→20), V pořádku (20→40), V opravě (40→80).
   - Ručně v DB nastavit `doc_state_changed_at` na NOW - 30 hodin.
   - `shpd-ds alerts-run --check=docs.core.stale_in_repair`.
   - Otevřít alerts viewer v UI, ověřit alert + funkční "Otevřít doklad".
   - V doc form přepnout zpět na 40, uložit.
   - Spustit re-check přes UI tlačítko, ověřit přechod alertu na Resolved.
7. Dokumentace.
