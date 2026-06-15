<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

/**
 * Společné rozhraní pro key-value úložiště nastavení. Sjednocuje DS-scoped
 * `SettingsStore` (klíče `app.*`) a per-user `UserSettingsStore` (klíče
 * `account.*`), aby `SettingsController` nezáviselo na konkrétní třídě —
 * scope volí podle definice settings page.
 */
interface KeyValueStore
{
    /** Vrátí dekódovanou hodnotu klíče, nebo null pokud klíč neexistuje. */
    public function get(string $key): mixed;

    /**
     * Načte více klíčů jedním dotazem.
     *
     * @param  string[] $keys
     * @return array<string, mixed> mapa key → value; neexistující klíče mají null
     */
    public function getMany(array $keys): array;

    /** Upsert hodnoty. `set($key, null)` klíč smaže. */
    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;
}
