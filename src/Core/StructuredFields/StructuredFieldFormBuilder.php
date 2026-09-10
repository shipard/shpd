<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormElement;

/**
 * Elementy formuláře ze schématu strukturovaného pole (I5 v #74).
 *
 * Vrací běžné `input` / `select` / `separator` elementy s virtuálním
 * sloupcem `<sloupec>.<pole>` (S4) — klient o strukturovaném poli nic neví
 * a nepotřebuje kvůli němu žádnou novou komponentu. Konzument si elementy
 * vloží do libovolné sekce nebo tabu (`TableForm::structuredFieldElements()`,
 * `AutoFormBuilder`).
 *
 * `required` na elementu je jen značka pro UI: server povinnost vynucuje
 * teprve v **neprázdné** hodnoté (viz `StructuredFieldValidator`), takže
 * prázdný profil uložení neblokuje.
 */
final class StructuredFieldFormBuilder
{
    public function __construct(private readonly ?ConfigRuntime $config = null) {}

    /**
     * @param bool $groupSeparators `false` = bez separátorů skupin (konzument
     *        si dělá vlastní sekce)
     * @return list<FormElement>
     */
    public function elements(StructuredSchema $schema, string $column, bool $groupSeparators = true): array
    {
        $out = [];
        foreach ($schema->fieldsByGroup() as $block) {
            if ($groupSeparators && $block['title'] !== null) {
                $out[] = new FormElement(type: 'separator', label: $block['title']);
            }
            foreach ($block['fields'] as $field) {
                $out[] = $this->element($field, $column);
            }
        }
        return $out;
    }

    private function element(StructuredField $field, string $column): FormElement
    {
        $virtualColumn = StructuredSchema::virtualColumn($column, $field->id);

        if ($field->isEnum()) {
            return new FormElement(
                type: 'select',
                column: $virtualColumn,
                label: $field->name,
                required: $field->required,
                readOnly: $field->readOnly,
                hint: $field->hint,
                options: $this->options($field),
            );
        }

        return new FormElement(
            type: 'input',
            column: $virtualColumn,
            label: $field->name,
            required: $field->required,
            readOnly: $field->readOnly,
            hint: $field->hint,
            inputType: $field->inputType ?? self::defaultInputType($field->type),
        );
    }

    /** Deklarovaný `inputType` vyhrává; jinak podle typu pole. */
    private static function defaultInputType(string $type): string
    {
        return match ($type) {
            'boolean'         => 'checkbox',
            'date'            => 'date',
            'int', 'numeric'  => 'number',
            'text'            => 'textarea',
            default           => 'text',
        };
    }

    /** @return list<array{value: int|string, label: string}> */
    private function options(StructuredField $field): array
    {
        $cfgData = $field->cfgItem !== null ? $this->config?->cfgItem($field->cfgItem) : null;
        if (!is_array($cfgData)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfgData, $field->type, $field->cfgItem);
    }
}
