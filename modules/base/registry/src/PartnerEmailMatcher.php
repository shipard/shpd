<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Match e-mailu odesílatele na živou osobu (docState 10/40/80) — přes
 * primární e-mail osoby i kontakty. Použije se **jen při právě jednom**
 * distinct matchi; jinak null (žádné hádání, žádné auto-zakládání).
 *
 * Sdílí ruční cesta ({@see FileFromMessageService}) i AI cesta
 * ({@see RegistryApplier} jako fallback za PartyResolver match).
 */
final class PartnerEmailMatcher
{
    public static function match(DataSourceConnection $db, string $senderEmail): ?int
    {
        $email = strtolower(trim($senderEmail));
        if ($email === '') {
            return null;
        }

        $rows = $db->fetchAll(
            'SELECT DISTINCT p.`id`'
            . ' FROM `base_persons_persons` p'
            . ' LEFT JOIN `base_persons_contacts` c'
            . '   ON c.`person` = p.`id` AND c.`docState` != 90'
            . ' WHERE (LOWER(p.`email`) = %s OR LOWER(c.`email`) = %s)'
            . '   AND p.`docState` IN (10, 40, 80)'
            . ' LIMIT 2',
            $email,
            $email,
        );

        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }
}
