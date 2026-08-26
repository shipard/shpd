<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * `manifest.jsonc` datové sady (`shpd.dataset.v1`).
 *
 * Datová třída bez business logiky — validace vstupu v konstruktoru,
 * `fromArray()` z rozparsovaného JSONC, `toArray()` v deterministickém
 * pořadí klíčů pro zápis.
 *
 * Fáze 1 podporuje jen `dateMode: fixed`; jiná hodnota je platná syntaxe,
 * ale konstruktor ji odmítne jako „not implemented" (rozhodnutí D4 v #40).
 */
final class DatasetManifest
{
    public const FORMAT = 'shpd.dataset.v1';

    public const DATE_MODE_FIXED = 'fixed';

    /** Sekce sady v pořadí, v jakém se seedují. */
    public const SECTIONS = ['setup', 'persons', 'items', 'docs', 'registry', 'mail'];

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /**
     * @param array<string, int> $counts informativní počty záznamů per sekce
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $dateMode,
        public readonly string $created,
        public readonly array $counts = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw DatasetException::invalidManifest(
                "name '{$name}' must match [a-z0-9][a-z0-9._-]* (max 64 chars)",
            );
        }
        if (trim($title) === '') {
            throw DatasetException::invalidManifest('title must be a non-empty string');
        }
        if ($dateMode !== self::DATE_MODE_FIXED) {
            throw DatasetException::notImplemented(
                "dateMode '{$dateMode}' (phase 1 supports only '" . self::DATE_MODE_FIXED . "')",
            );
        }
        if (trim($created) === '') {
            throw DatasetException::invalidManifest('created must be a non-empty ISO 8601 timestamp');
        }
        try {
            new \DateTimeImmutable($created);
        } catch (\Exception) {
            throw DatasetException::invalidManifest("created '{$created}' is not a valid timestamp");
        }
        foreach ($counts as $section => $count) {
            if (!is_string($section) || $section === '') {
                throw DatasetException::invalidManifest('counts keys must be non-empty section names');
            }
            if (!is_int($count) || $count < 0) {
                throw DatasetException::invalidManifest("counts.{$section} must be a non-negative integer");
            }
        }
    }

    /**
     * @param array<string, mixed> $data rozparsovaný manifest.jsonc
     */
    public static function fromArray(array $data): self
    {
        $format = $data['format'] ?? null;
        if ($format !== self::FORMAT) {
            $shown = is_scalar($format) ? (string) $format : gettype($format);
            throw DatasetException::invalidManifest(
                "format must be '" . self::FORMAT . "', got '{$shown}'",
            );
        }

        foreach (['name', 'title', 'dateMode', 'created'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw DatasetException::invalidManifest("{$key} is required and must be a string");
            }
        }
        if (array_key_exists('description', $data) && $data['description'] !== null && !is_string($data['description'])) {
            throw DatasetException::invalidManifest('description must be a string or null');
        }
        $counts = $data['counts'] ?? [];
        if (!is_array($counts)) {
            throw DatasetException::invalidManifest('counts must be an object');
        }

        /** @var array<string, int> $counts */
        return new self(
            name: $data['name'],
            title: $data['title'],
            description: $data['description'] ?? null,
            dateMode: $data['dateMode'],
            created: $data['created'],
            counts: $counts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'format'   => self::FORMAT,
            'name'     => $this->name,
            'title'    => $this->title,
        ];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        $out['dateMode'] = $this->dateMode;
        $out['created'] = $this->created;
        $out['counts'] = $this->counts === [] ? new \stdClass() : $this->counts;

        return $out;
    }

    /**
     * Kopie manifestu s jinými počty (dump zná počty až po exportu).
     *
     * @param array<string, int> $counts
     */
    public function withCounts(array $counts): self
    {
        return new self(
            $this->name,
            $this->title,
            $this->description,
            $this->dateMode,
            $this->created,
            $counts,
        );
    }
}
