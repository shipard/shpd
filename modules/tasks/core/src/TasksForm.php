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
            ->addTextArea('description', cols: 2)
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
