<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Jednotný tvar zamykatelné entity (#55 D25/D27): `locked` + `locked_at` +
 * `locked_by`. Při zamknutí z requestu se vyplní čas a uživatel, při
 * odemknutí se obě pole vymažou. Strojový kontext (import, CLI) uživatele
 * nemá — pole zůstanou, jak přišla (typicky NULL → UI ukáže „Uzamčeno
 * (import)"). Payload s vlastními hodnotami se respektuje.
 *
 * Volá se z `Document::beforeSave` zamykatelných tabulek (instance tvrzení
 * DPH, fiskální měsíc).
 */
final class LockStamp
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $originalData
     */
    public static function apply(array &$data, ?array $originalData, ?int $userId, ?string $now = null): void
    {
        if (!array_key_exists('locked', $data)) {
            return;
        }
        $wasLocked = !empty($originalData['locked']);
        $isLocked  = !empty($data['locked']);
        if ($isLocked && !$wasLocked) {
            if ($userId !== null) {
                $data['locked_at'] ??= $now ?? date('Y-m-d H:i:s');
                $data['locked_by'] ??= $userId;
            }
            return;
        }
        if (!$isLocked && $wasLocked) {
            $data['locked_at'] = null;
            $data['locked_by'] = null;
        }
    }
}
