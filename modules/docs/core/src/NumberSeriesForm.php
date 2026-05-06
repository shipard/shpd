<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

class NumberSeriesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew && empty($data['reset_scope'])) {
            $data['reset_scope'] = 'fiscal_year';
        }

        $docTypeOptions    = $this->resolveOptions('docs.core.docTypes');
        $resetScopeOptions = $this->resolveOptions('docs.core.resetScopes');

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->addInput('name', cols: 2, required: true)
            ->addSelect(
                'doc_type',
                cols: 1,
                options: $docTypeOptions,
                triggers: 'reload',
                required: true,
                readOnly: !$isNew,
            )
            ->addInput('doc_number_code', cols: 1)
            ->addInput('doc_number_pattern', cols: 2, required: true, readOnly: !$isNew)
            ->addSelect('reset_scope', cols: 1, options: $resetScopeOptions, required: true)
            ->addSeparator('Platnost')
            ->addDate('valid_from', cols: 1)
            ->addDate('valid_to', cols: 1)
            ->addSeparator('Poznámka')
            ->addTextArea('notice', cols: 4)
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Číselná řada',
            titleNew: 'Nová číselná řada',
            tabs: [$basic, $this->attachmentsTab()],
            fullSize: false,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'doc_type' && !empty($data['doc_type'])) {
            $docTypes = $this->config?->cfgItem('docs.core.docTypes');

            if (is_array($docTypes) && isset($docTypes[$data['doc_type']])) {
                $entry = $docTypes[$data['doc_type']];

                if (empty($data['doc_number_pattern'])
                    && isset($entry['doc_number_pattern_default'])
                ) {
                    $data['doc_number_pattern'] = (string) $entry['doc_number_pattern_default'];
                }

                if (empty($data['name']) && isset($entry['name'])) {
                    $data['name'] = (string) $entry['name'];
                }
            }
        }

        $isNew = empty($data['id']);
        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfg)) {
            return [];
        }

        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}
