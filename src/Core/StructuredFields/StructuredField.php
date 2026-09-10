<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

/**
 * Jedno pole strukturovaného schématu — formát sloupce tabulky bez SQL
 * sémantiky (rozhodnutí S2 v #74). Typy jsou podmnožinou typů z
 * docs/table-definitions.md §6; `length` / `precision` / `scale` / `cfgItem`
 * tady neurčují uložení (to je vždycky JSON), ale **validaci hodnoty**.
 *
 * Data přicházejí z cfgItem už lokalizovaná (`ConfigLocalizer` v
 * `ConfigCompiler`), takže `name` / `hint` jsou holé stringy. Vyčerpávající
 * kontrolu formátu dělá `StructuredSchemaValidator` nad **surovým** cfgItem
 * při kompilaci; `fromArray()` si drží jen tvrdé minimum, aby runtime
 * nespadl na nesmyslném kompilátu.
 */
final class StructuredField
{
    /** Povolené typy polí — podmnožina typů sloupců tabulky. */
    public const ALLOWED_TYPES = [
        'varchar', 'text',
        'int', 'numeric',
        'date', 'boolean',
        'enumInt', 'enumString',
    ];

    /** Typy, které berou hodnoty z cfgItem (a vyžadují ho). */
    public const ENUM_TYPES = ['enumInt', 'enumString'];

    /** Typy, u kterých je `length` povinná (a jinde zakázaná). */
    public const LENGTH_TYPES = ['varchar', 'enumString'];

    /**
     * Klíče, které smí deklarace pole nést. Cokoli jiného = chyba kompilace
     * (I1 „fail loudly") — překlep v `requred` nesmí tiše vypnout validaci.
     * Vícejazyčné varianty (`name:cs`) se kontrolují zvlášť.
     */
    public const ALLOWED_KEYS = [
        'id', 'type', 'group', 'name', 'length', 'precision', 'scale',
        'cfgItem', 'required', 'default', 'hint', 'inputType', 'readOnly',
    ];

    /** Klíče deklarace pole, které mají vícejazyčné varianty `<key>:<lang>`. */
    public const LOCALIZED_KEYS = ['name', 'hint'];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $group = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly ?string $cfgItem = null,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly ?string $hint = null,
        public readonly ?string $inputType = null,
        public readonly bool $readOnly = false,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Structured field: id must not be empty');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Structured field '{$id}': invalid type '{$type}'. Allowed: " . implode(', ', self::ALLOWED_TYPES),
            );
        }
    }

    /** @param array<string, mixed> $data Lokalizovaná deklarace pole z cfgItem. */
    public static function fromArray(array $data): self
    {
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';

        return new self(
            id: $id,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : $id,
            type: isset($data['type']) && is_string($data['type']) ? $data['type'] : '',
            group: isset($data['group']) && is_string($data['group']) && $data['group'] !== ''
                ? $data['group']
                : null,
            length: isset($data['length']) && is_int($data['length']) ? $data['length'] : null,
            precision: isset($data['precision']) && is_int($data['precision']) ? $data['precision'] : null,
            scale: isset($data['scale']) && is_int($data['scale']) ? $data['scale'] : null,
            cfgItem: isset($data['cfgItem']) && is_string($data['cfgItem']) && $data['cfgItem'] !== ''
                ? $data['cfgItem']
                : null,
            required: (bool) ($data['required'] ?? false),
            default: $data['default'] ?? null,
            hint: isset($data['hint']) && is_string($data['hint']) && $data['hint'] !== ''
                ? $data['hint']
                : null,
            inputType: isset($data['inputType']) && is_string($data['inputType']) && $data['inputType'] !== ''
                ? $data['inputType']
                : null,
            readOnly: (bool) ($data['readOnly'] ?? false),
        );
    }

    public function isEnum(): bool
    {
        return in_array($this->type, self::ENUM_TYPES, true);
    }
}
