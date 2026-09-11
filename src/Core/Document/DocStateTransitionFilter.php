<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Prohnání nabídky stavových přechodů hookem
 * Document::filterStateTransitions — sdílené oběma producenty přechodů
 * do UI (CrudController::docStateOptions, FormController form load).
 *
 * Graceful degradace: bez registru nebo bez registrované Document třídy
 * (DefaultDocument) je hook pass-through; bez DB si pass-through hlídá
 * implementace hooku sama (vzor DocDocument).
 */
final class DocStateTransitionFilter
{
    /**
     * @param array<int, array<string, mixed>> $transitions
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    public static function apply(
        string $table,
        array $row,
        array $transitions,
        ?DocumentRegistry $registry,
        ?\Dibi\Connection $db,
        ?ConfigRuntime $config = null,
        ?DataSourceConfig $dsConfig = null,
    ): array {
        if ($transitions === [] || $registry === null) {
            return $transitions;
        }

        // Zamčený záznam (documentLockProviders, #55 D24): žádné přechody.
        // Jen s DB — bez ní by provider neměl nad čím rozhodovat; bariérou
        // zůstává gateway.
        if ($db !== null && $registry->hasLockProviders($table)) {
            $locks = DocumentLockRegistry::forDocuments($registry, $db, $config, $dsConfig);
            if ($locks->reasons($table, $row, $row) !== []) {
                return [];
            }
        }

        $doc = $registry->getDocument($table, $row);
        if ($db !== null) {
            $doc->setDb($db);
        }

        return $doc->filterStateTransitions($transitions, $row);
    }
}
