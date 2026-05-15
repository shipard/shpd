<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

/**
 * Strukturovaná hlavička editačního formuláře — zobrazuje se v modalu
 * pod hlavním titulkem u existujícího záznamu.
 *
 * `title` je hlavní řádek (např. název firmy nebo celé jméno osoby).
 * `info` je seznam štítkovaných hodnot zobrazených na druhém řádku oddělených
 * tečkou (např. „IČO 68253848 · Kód osoby TEST-0098").
 *
 * Sestavuje ji `TableForm::buildHeaderInfo()`; klient ji jen renderuje.
 */
final class FormHeaderInfo
{
    /**
     * @param list<array{label: string, value: string}> $info
     */
    public function __construct(
        public readonly string $title,
        public readonly array $info = [],
    ) {}

    /**
     * @return array{title: string, info: list<array{label: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'info'  => $this->info,
        ];
    }
}
