<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Schéma strukturovaného pole — obsah cfgItem, na který ukazuje atribut
 * `schema` sloupce typu `json` (rozhodnutí S1–S3 v #74, docs/structured-fields.md).
 *
 * Tvar cfgItem souboru:
 *
 *     {
 *         "version": "2026",
 *         "groups": [ {"id": "office", "name": "Tax office", "name:cs": "Finanční úřad"} ],
 *         "fields": [ {"id": "c_ufo", "type": "enumString", "length": 5, ...} ]
 *     }
 *
 * Data se čtou z **kompilované** konfigurace, takže `name` je už lokalizované.
 * Vyčerpávající kontrolu formátu dělá `StructuredSchemaValidator` při kompilaci.
 *
 * ## Verze schématu
 *
 * `key()` = `<cfgItem>/<version>` se ukládá do dat jako `_schema` (S3).
 * Starší verze schémat se v systému **neuchovávají** — kompilovaná
 * konfigurace nese vždy jen aktuální obsah souboru. Čtení uloženého záznamu
 * proto vybírá **cfgItem** podle jeho `_schema` (to je podstatné tam, kde se
 * schéma volí podle typu záznamu — hlavička podání per typ tvrzení) a pole,
 * která novější verze zrušila, zobrazí `StructuredFieldRenderer` pod syrovým
 * klíčem, místo aby je zahodil. Kdo potřebuje starou verzi zachovat
 * doslovně, vydá ji jako **nový cfgItem** (`filingProfileCz2026`) — `_schema`
 * ho pojmenuje a záznamy na něm zůstanou.
 */
final class StructuredSchema
{
    /**
     * Oddělovač virtuálních sloupců formuláře: `<sloupec>.<pole>` (S4).
     *
     * ROZHODNUTÍ (`.` vs `__`, otevřený bod #74): zůstává **tečka**.
     * Klient snese tečku v klíči dat i v `field` validační chyby na všech
     * místech, kudy hodnota teče:
     *
     *  - `FormElement.svelte` váže `formData[element.column]` — bracket
     *    přístup, žádné rozpadání cesty na segmenty;
     *  - `sanitizeFormData()` a `extractValidationErrors()` ve `FormEditor`
     *    jdou přes plochý `Object.entries` / mapu `fieldErrors[column]`;
     *  - `buildElementMap()` klíčuje celým stringem, takže tečkový sloupec
     *    projde kontraktem `field` (docs/edit-forms.md §8) jako každý jiný;
     *  - id inputu (`shpd-<column>-<n>`) se používá jen v atributech
     *    `id`/`for`, nikde v CSS selektoru — tečka v něm nikomu nevadí.
     *
     * Tečka je navíc konzistentní s už zavedenou notací chyb v řádcích
     * (`rows.0.unit_price`). Proto se `id` polí schématu omezuje na
     * `[a-z][a-z0-9_]*` — tečka v nich by cestu učinila nejednoznačnou.
     */
    public const PATH_SEPARATOR = '.';

    /** Klíč v datech, který nese verzi schématu (S3). */
    public const SCHEMA_KEY = '_schema';

    /** Klíče, které smí nést cfgItem se schématem. */
    public const ALLOWED_KEYS = ['version', 'groups', 'fields'];

    /** Klíče deklarace skupiny. */
    public const ALLOWED_GROUP_KEYS = ['id', 'name'];

    /**
     * @param array<string, string>          $groups id => lokalizovaný název, v pořadí deklarace
     * @param array<string, StructuredField> $fields id => pole, v pořadí deklarace
     */
    private function __construct(
        public readonly string $cfgItem,
        public readonly string $version,
        public readonly array $groups,
        public readonly array $fields,
    ) {}

    /**
     * Schéma z kompilované konfigurace; `null` když cfgItem na tomto DS není
     * (neaktivní modul, nezkompilovaná konfigurace). Volající rozhoduje:
     * zápisová cesta z toho dělá výjimku, renderovací nezobrazí nic.
     */
    public static function fromCfgItem(?ConfigRuntime $config, string $cfgItem): ?self
    {
        $data = $config?->cfgItem($cfgItem);
        if (!is_array($data)) {
            return null;
        }
        return self::fromArray($cfgItem, $data);
    }

    /** @param array<string, mixed> $data Lokalizovaný obsah cfgItem. */
    public static function fromArray(string $cfgItem, array $data): self
    {
        $version = isset($data['version']) && (is_string($data['version']) || is_int($data['version']))
            ? (string) $data['version']
            : '';
        if ($version === '') {
            throw new \InvalidArgumentException("Structured schema '{$cfgItem}': missing 'version'");
        }

        $groups = [];
        foreach ($data['groups'] ?? [] as $group) {
            if (!is_array($group) || !isset($group['id']) || !is_string($group['id']) || $group['id'] === '') {
                continue;
            }
            $groups[$group['id']] = isset($group['name']) && is_string($group['name'])
                ? $group['name']
                : $group['id'];
        }

        $fields = [];
        foreach ($data['fields'] ?? [] as $fieldData) {
            if (!is_array($fieldData)) {
                continue;
            }
            $field = StructuredField::fromArray($fieldData);
            $fields[$field->id] = $field;
        }
        if ($fields === []) {
            throw new \InvalidArgumentException("Structured schema '{$cfgItem}': no fields");
        }

        return new self($cfgItem, $version, $groups, $fields);
    }

    /** Hodnota `_schema` v datech: `<cfgItem>/<version>`. */
    public function key(): string
    {
        return $this->cfgItem . '/' . $this->version;
    }

    /**
     * cfgItem z hodnoty `_schema`. Verze se záměrně nevrací — starší verze
     * schémat se neuchovávají (viz doc-comment třídy).
     */
    public static function cfgItemFromKey(mixed $schemaKey): ?string
    {
        if (!is_string($schemaKey) || $schemaKey === '') {
            return null;
        }
        $pos = strrpos($schemaKey, '/');
        $cfgItem = $pos === false ? $schemaKey : substr($schemaKey, 0, $pos);
        return $cfgItem !== '' ? $cfgItem : null;
    }

    public function field(string $id): ?StructuredField
    {
        return $this->fields[$id] ?? null;
    }

    /** Lokalizovaný název skupiny; neznámé id → samo id. */
    public function groupName(string $id): string
    {
        return $this->groups[$id] ?? $id;
    }

    /**
     * Pole seskupená pro render (formulář i detail): nejdřív pole bez
     * skupiny (klíč `null`), pak skupiny v pořadí deklarace. Prázdné skupiny
     * se vynechávají.
     *
     * @return array<int, array{group: ?string, title: ?string, fields: list<StructuredField>}>
     */
    public function fieldsByGroup(): array
    {
        // Pole bez skupiny (i pole ukazující na neexistující skupinu) jdou
        // první, pod prázdným klíčem; pak skupiny v pořadí deklarace.
        $buckets = ['' => []];
        foreach (array_keys($this->groups) as $groupId) {
            $buckets[(string) $groupId] = [];
        }

        foreach ($this->fields as $field) {
            $key = $field->group !== null && isset($buckets[$field->group]) ? $field->group : '';
            $buckets[$key][] = $field;
        }

        $out = [];
        foreach ($buckets as $groupId => $fields) {
            if ($fields === []) {
                continue;
            }
            $id = (string) $groupId !== '' ? (string) $groupId : null;
            $out[] = [
                'group'  => $id,
                'title'  => $id !== null ? $this->groupName($id) : null,
                'fields' => $fields,
            ];
        }
        return $out;
    }

    /** Virtuální sloupec formuláře pro pole tohoto schématu (S4). */
    public static function virtualColumn(string $column, string $fieldId): string
    {
        return $column . self::PATH_SEPARATOR . $fieldId;
    }
}
