<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;

class FormRegistry
{
    /** @var array<string, string> table name => FQN class */
    private array $forms = [];

    /** @var array<string, string> table name => form ID */
    private array $formIds = [];

    /**
     * @param ModuleDefinition[] $modules
     */
    public function loadFromModules(array $modules): void
    {
        foreach ($modules as $module) {
            foreach ($module->forms as $form) {
                if (!isset($form['table'])) {
                    continue;
                }
                if (isset($form['class'])) {
                    $this->forms[$form['table']] = $form['class'];
                }
                if (isset($form['id'])) {
                    $this->formIds[$form['table']] = $form['id'];
                }
            }
        }
    }

    public function getFormClass(string $table): ?string
    {
        return $this->forms[$table] ?? null;
    }

    public function getFormId(string $table): ?string
    {
        return $this->formIds[$table] ?? null;
    }

    public function createForm(string $table, ?DataSourceConnection $db = null, ?ConfigRuntime $config = null): ?TableForm
    {
        $class = $this->forms[$table] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }

        $form = new $class($table);
        if ($db !== null) {
            $form->setDb($db);
        }
        if ($config !== null) {
            $form->setConfig($config);
        }
        return $form;
    }
}
