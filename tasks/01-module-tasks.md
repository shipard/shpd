# Zadání — Modul `tasks.core` (Úkoly / To-Do)

**Stav:** hotovo

## Cíl

Přidat do aplikace sekci **Úkoly** — to-do list, kde si uživatelé vytvářejí
záznamy o tom, co je potřeba udělat. Sekce má být přístupná jako top-level
položka v hlavním sidebaru, používat stejný **viewer** jako ostatní entity
v aplikaci (Osoby, Faktury) a editovat se ve **vyskakovacím modal
formuláři**, který už v aplikaci existuje.

Implementace má kopírovat strukturní vzor modulu `core.units` (viz
`modules/core/units/`) — jeden modul, jedna tabulka, viewer + form +
document, vlastní docState cfgItem.

## Rozsah (out of scope, ať je jasné)

V první iteraci **nechceme**:

- hierarchii / pod-úkoly
- štítky / kategorie
- opakující se úkoly
- notifikace / připomenutí
- přílohy (attachment tab v editačním formuláři)
- komentáře, historii změn
- přiřazení úkolu jinému uživateli (`assignee_id`) — odloženo
- filtr „Moje úkoly" — všichni vidí všechno

## Co musí vzniknout

```
modules/tasks/core/
├── module.jsonc
├── tables/
│   └── tasks_core_tasks.jsonc
├── config/
│   ├── docStatesTasks.jsonc
│   └── priorities.jsonc
└── src/
    ├── TaskDocument.php
    ├── TasksViewer.php
    └── TasksForm.php
```

Plus úpravy ve sdílených souborech (viz sekce *Plumbing*):

- `modules/install/base/module.jsonc` — přidat `tasks.core` mezi dependencies
- `frontend/src/icons.js` — přidat `iconListCheck` + záznam v `iconMap`
- `public/index.php` — předat `$auth` do `dispatchForm`
- `src/Api/Controller/FormController.php` — `save()` přijme `?AuthContext $auth` a vyplní `created_by`

A aktualizace dokumentace:

- `CLAUDE.md` — krátká zmínka modulu (sekce *Architektura — rychlý přehled* / *Frontend — ikony*)
- `docs/frontend.md` — řádek do tabulky *Existující viewery* (sekce 7)
- `modules/tasks/core/README.md` — krátký popis modulu (vzor: `modules/core/units/README.md`)

---

## 1) `modules/tasks/core/module.jsonc`

```jsonc
{
    "id": "tasks.core",
    "name": "Tasks",
    "name:cs": "Úkoly",
    "name:en": "Tasks",
    "description": "Personal and shared to-do list with priorities, due dates and a simple lifecycle",
    "description:cs": "Osobní i sdílený seznam úkolů s prioritami, termíny a jednoduchým životním cyklem",
    "description:en": "Personal and shared to-do list with priorities, due dates and a simple lifecycle",

    "dependencies": [
        "core.system"
    ],

    "tables": [
        "tasks_core_tasks"
    ],

    "viewers": [
        {
            "id": "tasks.core",
            "name": "Tasks",
            "name:cs": "Úkoly",
            "name:en": "Tasks",
            "icon": "list-check",
            "table": "tasks_core_tasks",
            "class": "Shipard\\Module\\Tasks\\Core\\TasksViewer"
        }
    ],

    "forms": [
        {
            "table": "tasks_core_tasks",
            "class": "Shipard\\Module\\Tasks\\Core\\TasksForm"
        }
    ],

    "documentClasses": [
        {
            "table": "tasks_core_tasks",
            "class": "Shipard\\Module\\Tasks\\Core\\TaskDocument"
        }
    ],

    "config": [
        { "id": "tasks.core.docStatesTasks", "file": "config/docStatesTasks.jsonc" },
        { "id": "tasks.core.priorities",      "file": "config/priorities.jsonc" }
    ]
}
```

**Pozor:** žádný `settingsItems` — úkoly nejsou nastavení, mají být v hlavní
navigaci. Bez `settingsItems` se viewer automaticky objeví v top-level
sidebaru pod skupinou „Úkoly" (label se odvodí z `name` modulu).

---

## 2) `modules/tasks/core/tables/tasks_core_tasks.jsonc`

`tableId` je **406** (ověřeno přes `php bin/shpd-server next-table-id` —
v případě, že mezitím přibyla jiná tabulka, použij aktuální výstup).

```jsonc
{
    "tableId": 406,
    "name": "Tasks",
    "name:cs": "Úkoly",
    "name:en": "Tasks",

    "displayPattern": "{title}",

    "docStates": {
        "stateColumn": "docState",
        "mainColumn": "docStateMain",
        "cfgItem": "tasks.core.docStatesTasks"
    },

    "columnGroups": [
        {
            "id": "main",
            "name": "Main",
            "name:cs": "Hlavní",
            "name:en": "Main"
        },
        {
            "id": "schedule",
            "name": "Schedule",
            "name:cs": "Plánování",
            "name:en": "Schedule"
        }
    ],

    "columns": [
        {
            "id": "id",
            "name": "ID",
            "type": "int",
            "autoIncrement": true,
            "primaryKey": true
        },

        // --- main ---

        {
            "id": "title",
            "name": "Title",
            "name:cs": "Název",
            "name:en": "Title",
            "type": "varchar",
            "length": 200,
            "nullable": false,
            "group": "main"
        },
        {
            "id": "description",
            "name": "Description",
            "name:cs": "Popis",
            "name:en": "Description",
            "type": "text",
            "nullable": true,
            "group": "main"
        },
        {
            "id": "priority",
            "name": "Priority",
            "name:cs": "Priorita",
            "name:en": "Priority",
            "type": "enumString",
            "length": 10,
            "nullable": true,
            "cfgItem": "tasks.core.priorities",
            "group": "main"
        },

        // --- schedule ---

        {
            "id": "due_date",
            "name": "Due date",
            "name:cs": "Termín",
            "name:en": "Due date",
            "type": "date",
            "nullable": true,
            "group": "schedule"
        },

        // --- systémové (skryté ve formuláři, vyplňuje server) ---

        {
            "id": "created_by",
            "name": "Created by",
            "name:cs": "Vytvořil",
            "name:en": "Created by",
            "type": "int",
            "nullable": true,
            "reference": "core_system_users",
            "system": true
        },
        {
            "id": "created",
            "name": "Created",
            "name:cs": "Vytvořeno",
            "name:en": "Created",
            "type": "datetime",
            "nullable": true,
            "system": true
        },
        {
            "id": "modified",
            "name": "Modified",
            "name:cs": "Upraveno",
            "name:en": "Modified",
            "type": "datetime",
            "nullable": true,
            "system": true
        },

        // --- doc state ---

        {
            "id": "docState",
            "name": "Document state",
            "name:cs": "Stav dokumentu",
            "name:en": "Document state",
            "type": "tinyint",
            "default": 10,
            "system": true
        },
        {
            "id": "docStateMain",
            "name": "Document state (sort)",
            "name:cs": "Stav dokumentu (řazení)",
            "name:en": "Document state (sort)",
            "type": "tinyint",
            "default": 1,
            "system": true
        }
    ],

    "indexes": [
        {
            "id": "idx_doc_state",
            "type": "index",
            "columns": [
                {"column": "docStateMain", "order": "ASC"},
                {"column": "due_date",     "order": "ASC"},
                {"column": "id",           "order": "ASC"}
            ]
        },
        {
            "id": "idx_created_by",
            "type": "index",
            "columns": [
                {"column": "created_by"}
            ]
        }
    ]
}
```

**Poznámky:**

- `created_by` má `"system": true` — to ho vyřadí ze zapisovatelných polí
  v `CrudController::filterWritableFields` i ve `FormController::filterWritableFields`
  (klient ho nikdy nepodvrhne) a `AutoFormBuilder` ho neukáže.
- `created` / `modified` mají taky `"system": true`, ale `CrudController` /
  `FormController` je obsluhují speciálně (vyplňují, jen pokud sloupec
  existuje) — to už je v kódu.
- `reference: "core_system_users"` je deklarativní vazba na úrovni aplikace —
  Shipard nepoužívá DB FOREIGN KEY.

---

## 3) `modules/tasks/core/config/docStatesTasks.jsonc`

Vlastní stavový automat pro úkoly. **Nepoužíváme** sdílený
`core.system.docStatesArchive`, protože jeho stavy („Koncept / V pořádku")
nesedí na to-do list („Nový / V práci / Hotovo").

```jsonc
{
    // tasks.core.docStatesTasks
    //
    // Stavový automat pro úkoly. Pipeline:
    //   Nový → V práci → Hotovo → V archívu
    //   ↘ Pozastaveno (libovolně tam a zpět mezi aktivními stavy) ↗
    //   Hotovo → V práci (opětovné otevření)
    //   Cokoli aktivního → Smazáno (v rámci aktivních); zpět přes „V práci"

    "10": {
        "stateName": "Nový",
        "stateName:cs": "Nový",
        "stateName:en": "New",
        "actionName": "Vrátit jako nový",
        "actionName:cs": "Vrátit jako nový",
        "actionName:en": "Mark as new",
        "stateStyle": "concept",
        "mainState": 1,
        "viewGroup": "active",
        "closeForm": 0,
        "goto": [20, 30, 40, 90]
    },

    "20": {
        "stateName": "V práci",
        "stateName:cs": "V práci",
        "stateName:en": "In progress",
        "actionName": "V práci",
        "actionName:cs": "V práci",
        "actionName:en": "Start",
        "stateStyle": "confirmed",
        "mainState": 2,
        "viewGroup": "active",
        "closeForm": 0,
        "goto": [10, 30, 40, 90]
    },

    "30": {
        "stateName": "Pozastaveno",
        "stateName:cs": "Pozastaveno",
        "stateName:en": "On hold",
        "actionName": "Pozastavit",
        "actionName:cs": "Pozastavit",
        "actionName:en": "Pause",
        "stateStyle": "edit",
        "mainState": 3,
        "viewGroup": "active",
        "closeForm": 0,
        "goto": [10, 20, 40, 90]
    },

    "40": {
        "stateName": "Hotovo",
        "stateName:cs": "Hotovo",
        "stateName:en": "Done",
        "actionName": "Hotovo",
        "actionName:cs": "Hotovo",
        "actionName:en": "Mark done",
        "stateStyle": "done",
        "mainState": 4,
        "viewGroup": "active",
        "readOnly": 1,
        "closeForm": 1,
        "goto": [20, 70, 90]
    },

    "70": {
        "stateName": "V archívu",
        "stateName:cs": "V archívu",
        "stateName:en": "Archived",
        "actionName": "Archivovat",
        "actionName:cs": "Archivovat",
        "actionName:en": "Archive",
        "stateStyle": "archive",
        "mainState": 5,
        "viewGroup": "archive",
        "readOnly": 1,
        "closeForm": 1,
        "goto": [20]
    },

    "90": {
        "stateName": "Smazáno",
        "stateName:cs": "Smazáno",
        "stateName:en": "Deleted",
        "actionName": "Smazat",
        "actionName:cs": "Smazat",
        "actionName:en": "Delete",
        "stateStyle": "trash",
        "mainState": 6,
        "viewGroup": "trash",
        "readOnly": 1,
        "closeForm": 1,
        "goto": [20]
    }
}
```

**Konvence stateStyle**: viz `docs/doc-states.md` sekce 6. `concept`
(žlutá) = Nový, `confirmed` (modrá) = V práci, `edit` (oranžová) =
Pozastaveno (využíváme existující CSS třídu), `done` (světle zelená) =
Hotovo, `archive` (šedá) = V archívu, `trash` (černá / přeškrtnutá) =
Smazáno.

---

## 4) `modules/tasks/core/config/priorities.jsonc`

```jsonc
{
    "low": {
        "name": "Nízká",
        "name:cs": "Nízká",
        "name:en": "Low",
        "order": 1,
        "spanClass": "muted"
    },
    "medium": {
        "name": "Střední",
        "name:cs": "Střední",
        "name:en": "Medium",
        "order": 2,
        "spanClass": "primary"
    },
    "high": {
        "name": "Vysoká",
        "name:cs": "Vysoká",
        "name:en": "High",
        "order": 3,
        "spanClass": "warning"
    },
    "critical": {
        "name": "Kritická",
        "name:cs": "Kritická",
        "name:en": "Critical",
        "order": 4,
        "spanClass": "danger"
    }
}
```

`spanClass` je nepovinné rozšíření, které využívá `TasksViewer::renderRow()`
pro obarvení priority badge v t2.

---

## 5) `modules/tasks/core/src/TaskDocument.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Tasks\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class TaskDocument extends Document
{
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $result->addError('title', 'Název je povinný', 'required');
        } elseif (mb_strlen($title) > 200) {
            $result->addError('title', 'Název může mít maximálně 200 znaků', 'invalid');
        }

        $priority = $data['priority'] ?? null;
        if ($priority !== null && $priority !== '' && !in_array($priority, self::PRIORITIES, true)) {
            $result->addError('priority', 'Neznámá priorita', 'invalid');
        }

        if (!empty($data['due_date'])) {
            $date = $data['due_date'];
            $valid = false;
            if ($date instanceof \DateTimeInterface) {
                $valid = true;
            } elseif (is_string($date)) {
                $valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $date);
            }
            if (!$valid) {
                $result->addError('due_date', 'Neplatný termín', 'invalid');
            }
        }

        return $result;
    }
}
```

`created_by` se v `beforeSave()` **nenastavuje** — vyplňuje ho
`FormController::save()` z `AuthContext` (viz *Plumbing*). Document o auth
kontextu neví a nemá k němu přístup.

---

## 6) `modules/tasks/core/src/TasksViewer.php`

Vzorem je `modules/core/units/src/UnitsViewer.php`. Liší se ve dvou
věcech:

- **JOIN na `core_system_users`** kvůli zobrazení autora v t3
- Vlastní cfgItem `tasks.core.docStatesTasks` (místo `core.system.docStatesArchive`)

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Tasks\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class TasksViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'tasks.core.docStatesTasks';

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT t.`id`, t.`title`, t.`description`, t.`priority`, t.`due_date`,'
            . ' t.`created`, t.`docState`, t.`docStateMain`, t.`created_by`,'
            . ' u.`full_name` AS `creator_name`, u.`login` AS `creator_login`'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `core_system_users` u ON u.`id` = t.`created_by`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            // `title` ani `description` se v core_system_users nevyskytují —
            // nekvalifikovaný název sloupce ze základní helperu funguje.
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['title', 'description'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        // Pořadí: stav (Nový/V práci nahoru), pak termín (nejbližší první),
        // pak id pro deterministické řazení.
        $sql .= ' ORDER BY t.`docStateMain` ASC, t.`due_date` IS NULL ASC,'
            . ' t.`due_date` ASC, t.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['title'] ?? ''),
            'stateStyle' => $stateStyle,
        ];

        // ── t2: [priorita, termín, stav] ─────────────────────────────────
        $t2 = [];

        $priority = (string) ($rowData['priority'] ?? '');
        if ($priority !== '') {
            $cfg = $this->config?->cfgItem('tasks.core.priorities');
            if (is_array($cfg) && isset($cfg[$priority])) {
                $t2[] = [
                    'text'  => (string) ($cfg[$priority]['name'] ?? $priority),
                    'class' => (string) ($cfg[$priority]['spanClass'] ?? 'muted'),
                ];
            }
        }

        $dueDate = $rowData['due_date'] ?? null;
        if ($dueDate !== null && $dueDate !== '') {
            $formatted = $this->formatDate($dueDate);
            $isOverdue = $this->isOverdue($dueDate, $docState);
            $t2[] = [
                'text'  => $formatted,
                'class' => $isOverdue ? 'danger' : 'muted',
            ];
        }

        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem(
                $this->config?->cfgItem($this->docStatesCfgItem),
            );
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => (string) ($stateData['stateName'] ?? ''),
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2 !== [] ? $t2 : null;

        // ── t3: kdo úkol vytvořil ────────────────────────────────────────
        $creator = $this->resolveCreatorLabel($rowData);
        $row['t3'] = $creator !== '' ? 'Vytvořil: ' . $creator : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $sql = 'SELECT t.*, u.`full_name` AS `creator_name`, u.`login` AS `creator_login`'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `core_system_users` u ON u.`id` = t.`created_by`'
            . ' WHERE t.`id` = %i';
        $record = $this->db->fetchRow($sql, $recordId);

        if ($record === null) {
            return ['tabs' => []];
        }

        $items = [];
        $this->addItem($items, 'Název', $record['title'] ?? null);
        $this->addItem($items, 'Priorita', $this->resolvePriorityLabel((string) ($record['priority'] ?? '')));
        $this->addItem($items, 'Termín', $this->formatDate($record['due_date'] ?? null));
        $this->addItem($items, 'Stav', $this->resolveStateLabel((int) ($record['docState'] ?? 10)));
        $this->addItem($items, 'Vytvořil', $this->resolveCreatorLabel($record));
        $this->addItem($items, 'Vytvořeno', $this->formatDateTime($record['created'] ?? null));

        $description = trim((string) ($record['description'] ?? ''));

        $groups = [['title' => 'Úkol', 'items' => $items]];
        if ($description !== '') {
            $groups[] = [
                'title' => 'Popis',
                'items' => [['label' => '', 'value' => $description]],
            ];
        }

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => [
                    'type'   => 'properties',
                    'groups' => $groups,
                ],
            ]],
        ];
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveStateLabel(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return '';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return (string) ($cfg->getState($docState)['stateName'] ?? '');
    }

    private function resolvePriorityLabel(string $key): string
    {
        if ($key === '' || $this->config === null) {
            return $key;
        }
        $cfg = $this->config->cfgItem('tasks.core.priorities');
        if (is_array($cfg) && isset($cfg[$key]['name'])) {
            return (string) $cfg[$key]['name'];
        }
        return $key;
    }

    private function resolveCreatorLabel(array $row): string
    {
        $name = trim((string) ($row['creator_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $login = trim((string) ($row['creator_login'] ?? ''));
        return $login;
    }

    private function isOverdue(mixed $dueDate, int $docState): bool
    {
        // Done / Archived / Deleted už není „po termínu" — jen aktivní stavy.
        if (in_array($docState, [40, 70, 90], true)) {
            return false;
        }
        $ts = $this->parseDateTs($dueDate);
        if ($ts === null) {
            return false;
        }
        $today = (int) strtotime(date('Y-m-d') . ' 00:00:00');
        return $ts < $today;
    }

    private function formatDate(mixed $value): string
    {
        $ts = $this->parseDateTs($value);
        return $ts !== null ? date('j. n. Y', $ts) : '';
    }

    private function formatDateTime(mixed $value): string
    {
        $ts = $this->parseDateTs($value);
        return $ts !== null ? date('j. n. Y H:i', $ts) : '';
    }

    private function parseDateTs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        $ts = is_int($value) ? $value : strtotime((string) $value);
        return $ts !== false ? $ts : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
```

---

## 7) `modules/tasks/core/src/TasksForm.php`

Vzor `modules/core/units/src/UnitsForm.php`, ale **bez `attachmentsTab()`**
(v tomto kole nepotřebujeme přílohy).

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Tasks\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class TasksForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->addInput('title', cols: 2, required: true)
            ->addTextArea('description', cols: 2, rows: 5)
            ->addSelect('priority', cols: 1, options: $this->resolvePriorityOptions())
            ->addDate('due_date', cols: 1)
            ->build();

        return new FormDefinition(
            table:    $this->table,
            title:    'Úkol',
            titleNew: 'Nový úkol',
            tabs:     [$basic],
            fullSize: false,
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolvePriorityOptions(): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem('tasks.core.priorities');
        if (!is_array($cfgData)) {
            return [];
        }

        // Seřaď podle `order` (vzestupně), pak vrať jako [{value, label}].
        $entries = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $entries[] = [
                'key'   => (string) $key,
                'name'  => (string) $entry['name'],
                'order' => (int) ($entry['order'] ?? 999),
            ];
        }
        usort($entries, fn(array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(
            fn(array $e) => ['value' => $e['key'], 'label' => $e['name']],
            $entries,
        );
    }
}
```

**Ověř**, že `TabBuilder` opravdu má metody `addInput`, `addTextArea`,
`addSelect`, `addDate` (vzor podle `UnitsForm` a `IncomingMessagesForm`).
Pokud `addDate` neexistuje pod tímto jménem, použij to, co existuje pro
date sloupce (může se jmenovat jinak — `addDateInput`).

---

## 8) Aktualizace `modules/install/base/module.jsonc`

V poli `dependencies` přidat `"tasks.core"`:

```jsonc
"dependencies": [
    "core.system",
    "core.attachments",
    "core.mail",
    "core.units",
    "base.persons",
    "economy.codebooks",
    "economy.items",
    "world.vat",
    "docs.core",
    "docs.invoicesOut",
    "docs.invoicesIn",
    "tasks.core"
],
```

---

## 9) `frontend/src/icons.js`

Přidat tři věci:

```js
// V importu z @fortawesome/free-solid-svg-icons:
import {
  // ... existující ikony ...
  faListCheck,
} from '@fortawesome/free-solid-svg-icons';

// V sekci „Číselníky / moduly":
export const iconListCheck = faListCheck;

// V `iconMap`:
'list-check': iconListCheck,
```

---

## 10) Plumbing — `created_by` z `AuthContext`

Tato část je nejvíc invazivní (mění shared kód) — postupuj opatrně a před
patchováním si soubory přečti celé.

### 10a) `public/index.php`

V `dispatch()` přidat `$auth` do volání `dispatchForm()`:

```php
'form' => dispatchForm(
    $route, $request, $auth, $tables, $db,
    $formRegistry ?? new FormRegistry(),
    $configRuntime, $modulesBasePath,
    $documentRegistry ?? new \Shipard\Core\Document\DocumentRegistry(),
    resolveLanguage($request, $resolved->config),
    $resolved->config,
),
```

A v deklaraci funkce `dispatchForm`:

```php
function dispatchForm(
    Route $route,
    Request $request,
    AuthContext $auth,                          // ← nový parametr
    array $tables,
    \Shipard\Core\Database\DataSourceConnection $db,
    FormRegistry $formRegistry,
    ?\Shipard\Core\Config\ConfigRuntime $configRuntime = null,
    string $modulesBasePath = '',
    ?\Shipard\Core\Document\DocumentRegistry $documentRegistry = null,
    string $language = 'en',
    ?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
): Response {
    // ... (zbytek beze změny, jen volání save:)
    'save' => $ctrl->save(
        $table, $route->id, $request, $tables, $db,
        $configRuntime, $documentRegistry, $dsConfig,
        $auth,                                  // ← nový argument
    ),
    // ...
}
```

### 10b) `src/Api/Controller/FormController.php`

Do `save()` přidat poslední parametr `?AuthContext $auth = null` a hned
za auto-managementem `created` / `modified` doplnit `created_by`:

```php
use Shipard\Api\AuthContext;   // ← přidat import

public function save(
    string $table,
    ?int $id,
    Request $request,
    array $tables,
    DataSourceConnection $db,
    ?ConfigRuntime $config,
    ?DocumentRegistry $documentRegistry = null,
    ?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
    ?AuthContext $auth = null,                 // ← nový parametr
): Response {
    // ... (beze změny až po existující blok timestampů)

    // Auto-manage timestamps — only add if column exists in table definition
    $now = date('Y-m-d H:i:s');
    if ($id === null && $this->hasColumn($def, 'created')) {
        $inputData['created'] = $now;
    }
    if ($this->hasColumn($def, 'modified')) {
        $inputData['modified'] = $now;
    }

    // Auto-manage created_by — only set on insert, only if column exists,
    // only when we know the user (authenticated session/api key).
    if ($id === null
        && $this->hasColumn($def, 'created_by')
        && $auth !== null
        && $auth->isAuthenticated
        && $auth->userId !== null
    ) {
        $inputData['created_by'] = $auth->userId;
    }

    // ... (zbytek beze změny)
}
```

**Pozn.:** `?AuthContext $auth = null` jako poslední parametr s defaultem
znamená, že stávající testy `FormControllerTest`, které save() volají bez
auth, projdou beze změny.

---

## 11) Dokumentace

### 11a) `CLAUDE.md`

V sekci *Architektura — rychlý přehled* doplnit do stromu `modules/`
zmínku o skupině `tasks`. V *Frontend — ikony* doplnit `iconListCheck`
do příkladů. Krátká poznámka (1–2 věty), žádný velký blok.

### 11b) `docs/frontend.md`

V sekci 7 *Viewer systém*, podsekce *Existující viewery*, přidat řádek do
tabulky:

```markdown
| `tasks.core` | `tasks.core` | `TasksViewer` | Vlastní docStates (`tasks.core.docStatesTasks`), JOIN na `core_system_users` kvůli zobrazení autora, indikace po termínu v t2 |
```

### 11c) `modules/tasks/core/README.md`

Krátký popis (~20 řádků). Vzor: `modules/core/units/README.md`. Obsah:
co modul dělá, jaké stavy podporuje, příklad rozšíření (přidání priority).

---

## 12) Ověření

V tomto pořadí:

```bash
# 1. Backend testy (musí všechny projít)
vendor/bin/phpunit 2>&1

# 2. Frontend build (musí projít bez warningů)
cd frontend && npm run build 2>&1 && cd ..

# 3. i18n lint (musí projít)
cd frontend && npm run check:i18n 2>&1 && cd ..

# 4. Aplikace ds-upgrade v dev DS
#    (cesta DS závisí na konkrétním vývojovém prostředí —
#    typicky /opt/shipard/data-sources/{ds-id})
cd /opt/shipard/data-sources/<dev-ds-id>
vendor/bin/shpd-ds ds-upgrade 2>&1
```

Po `ds-upgrade` musí v DB vzniknout tabulka `tasks_core_tasks` a v
`config/configuration/compiled.{cs,en}.json` být cfgItems
`tasks.core.docStatesTasks` a `tasks.core.priorities`.

Funkční ověření v UI:

1. Sidebar má položku „Úkoly" pod skupinou stejného jména s ikonou check-listu
2. Klik → otevře se viewer se 4 taby (Aktivní / Archív / Koš / Vše)
3. „Přidat" otevře modal s polemi: Název, Popis, Priorita, Termín
4. Nová úloha se uloží jako „Nový" (žlutý badge); sloupec `created_by` v DB
   odpovídá ID přihlášeného uživatele; v seznamu se v t3 ukáže „Vytvořil:
   <jméno>"
5. V detailu jdou všechny stavové přechody odpovídající `goto[]` z cfgItem

---

## 13) Známá rizika a poznámky

- **`TabBuilder::addDate`** — pokud metoda neexistuje pod tímto jménem,
  vyhledej, jak se v `UnitsForm` / `IncomingMessagesForm` přidávají date
  inputy, a použij stejný název. Případně použij `addInput` s typem `date`.
- **`stateStyle: edit` pro „Pozastaveno"** — využíváme existující oranžovou
  „V opravě" CSS třídu. Pokud bude vizuálně sporné, dá se snadno přejmenovat
  na vlastní `paused` styl, ale to znamená přidat CSS pravidlo v
  `ViewerRow.svelte` (mimo scope této úlohy).
- **Workflow:** držet se rytmu *přečíst → patchovat → ověřit*. Před každým
  `patch_file` načíst soubor celý, ne jen z paměti — `FormController.php`
  a `public/index.php` mají em-dashy a kreslené komentáře, na kterých se
  patch snadno rozbije.
- **Pořadí:** nejdřív moduly + cfgItems + tabulka (samotné JSONC + PHP třídy)
  → ověřit `phpunit` → potom plumbing v `FormController` + `public/index.php`
  → znovu ověřit → frontend + dokumentace na konec.
