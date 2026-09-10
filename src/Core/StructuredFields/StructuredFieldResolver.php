<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Výběr schématu pro konkrétní hodnotu (I2 v #74). Jediné místo, které zná
 * přednosti — proto ho používá zápisová cesta (`TableGateway`), formulář
 * (`TableForm`) i detail vieweru (`TableViewer`).
 *
 * ## Dvě cesty, dvě pravidla
 *
 * **Zápis a editace** (`forWrite`): hook (`Document::structuredSchemaFor()`,
 * u formuláře `TableForm::structuredSchemaFor()`), jinak statický atribut
 * `schema` sloupce. Default je tedy **aktuální verze** souboru: profil
 * podatele se s novou verzí schématu edituje podle ní a při dalším uložení
 * se přeznačí. Snapshot, který musí zůstat doslovný (hlavička podání per
 * typ tvrzení, Fáze 3 #55), si verzi připne právě tím hookem — to je jeho
 * účel.
 *
 * **Zobrazení** (`forValue`): cfgItem z `_schema` uložené hodnoty, jinak
 * statický atribut. Záznam se tedy čte podle schématu, které nese, a ne
 * podle toho, co by mu dnes vybral resolver. Pole, která novější verze
 * zrušila, ukáže `StructuredFieldRenderer` pod syrovým klíčem (I6) — starší
 * verze schémat se neuchovávají, viz doc-comment `StructuredSchema`.
 *
 * Klientem poslané `_schema` se při zápisu **nikdy** nepoužije; hodnotu
 * verze určuje server.
 */
final class StructuredFieldResolver
{
    public function __construct(private readonly ?ConfigRuntime $config) {}

    /** @param ?string $hookKey výsledek hooku (null = použij statický atribut) */
    public function forWrite(?string $hookKey, ?string $staticKey): ?StructuredSchema
    {
        $key = $hookKey ?? $staticKey;
        return $key !== null ? StructuredSchema::fromCfgItem($this->config, $key) : null;
    }

    /**
     * Jako `forWrite()`, ale chybějící schéma je výjimka: zapisovat sloupec,
     * jehož schéma neumíme načíst, by znamenalo uložit ho bez validace.
     *
     * @param string $origin `tabulka.sloupec` do hlášky
     */
    public function requireForWrite(string $origin, ?string $hookKey, ?string $staticKey): StructuredSchema
    {
        $key = $hookKey ?? $staticKey;
        if ($key === null) {
            throw new \RuntimeException("Column '{$origin}' has no structured schema to write with");
        }
        $schema = StructuredSchema::fromCfgItem($this->config, $key);
        if ($schema === null) {
            throw new \RuntimeException(
                "Column '{$origin}': structured schema '{$key}' is not available"
                . ' (compiled configuration missing? run ds-upgrade)',
            );
        }
        return $schema;
    }

    /**
     * Schéma pro zobrazení uložené hodnoty: podle `_schema` v hodnotě,
     * jinak podle statického atributu sloupce. `null` = nemáme co zobrazit.
     *
     * @param mixed $value hodnota sloupce (string z DB, pole, null)
     */
    public function forValue(mixed $value, ?string $staticKey): ?StructuredSchema
    {
        $decoded = StructuredFieldValues::decode($value);
        $key = StructuredSchema::cfgItemFromKey($decoded[StructuredSchema::SCHEMA_KEY] ?? null) ?? $staticKey;
        return $key !== null ? StructuredSchema::fromCfgItem($this->config, $key) : null;
    }
}
