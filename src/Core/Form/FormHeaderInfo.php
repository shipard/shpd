<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

/**
 * Strukturovaná hlavička editačního formuláře — zobrazuje se v modalu
 * pod hlavním titulkem u existujícího záznamu.
 *
 * - `title` je hlavní řádek (např. název firmy, jméno osoby, jméno
 *   partnera u dokladu).
 * - `info` je seznam štítkovaných hodnot zobrazených na druhém řádku
 *   oddělených tečkou (např. „IČO 68253848 · Kód osoby TEST-0098").
 * - `icon` je volitelný string-klíč ikony (z `icons.js::iconMap`,
 *   stejný registr jako sidebar / viewer řádky). Zobrazí se vlevo
 *   na celou výšku hlavičky.
 * - `summary` je volitelný pravý blok — seznam štítkovaných hodnot
 *   zobrazených vedle stavového badge (např. shrnutí cen u dokladů:
 *   „Bez DPH 10 000,00", „DPH 2 100,00", „Celkem 12 100,00 CZK").
 *   Renderuje se jen pokud má aspoň jednu položku.
 *
 * Sestavuje ji `TableForm::buildHeaderInfo()`; klient ji jen renderuje.
 */
final class FormHeaderInfo
{
    /**
     * @param list<array{label: string, value: string}> $info
     * @param list<array{label: string, value: string}> $summary
     */
    public function __construct(
        public readonly string $title,
        public readonly array $info = [],
        public readonly ?string $icon = null,
        public readonly array $summary = [],
    ) {}

    /**
     * @return array{
     *     title: string,
     *     info: list<array{label: string, value: string}>,
     *     icon: string|null,
     *     summary: list<array{label: string, value: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'title'   => $this->title,
            'info'    => $this->info,
            'icon'    => $this->icon,
            'summary' => $this->summary,
        ];
    }
}
