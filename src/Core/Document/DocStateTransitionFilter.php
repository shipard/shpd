<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

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
    ): array {
        if ($transitions === [] || $registry === null) {
            return $transitions;
        }

        $doc = $registry->getDocument($table, $row);
        if ($db !== null) {
            $doc->setDb($db);
        }

        return $doc->filterStateTransitions($transitions, $row);
    }
}
