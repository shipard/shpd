<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

class ConfigRuntime
{
    private function __construct(private readonly array $items) {}

    public static function load(string $dataSourcePath, string $language): self
    {
        $file = $dataSourcePath . '/config/configuration/compiled.' . $language . '.json';
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Cannot read compiled config: $file");
        }
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        return new self($data['items']);
    }

    public function cfgItem(string $id): mixed
    {
        return $this->items[$id] ?? null;
    }
}
