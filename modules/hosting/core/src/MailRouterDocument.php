<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `hosting_core_mail_routers` (Fáze 3, D4).
 *
 * Normalizuje `domains` — čárkami oddělený seznam mail domén se na
 * serveru trimuje a lowercasuje, prázdné položky se zahazují. Lookup
 * endpoint pak seznam jen splitne bez další úpravy.
 */
class MailRouterDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (array_key_exists('domains', $data)
            && self::normalizeDomains((string) $data['domains']) === '') {
            $result->addError('domains', 'Alespoň jedna doména je povinná.', 'REQUIRED');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (array_key_exists('domains', $data)) {
            $data['domains'] = self::normalizeDomains((string) $data['domains']);
        }

        $now = date('Y-m-d H:i:s');
        if (empty($data['id']) && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }

    /** Trim + lowercase položek, zahození prázdných, join čárkou. */
    public static function normalizeDomains(string $domains): string
    {
        $items = array_filter(array_map(
            static fn (string $item): string => mb_strtolower(trim($item)),
            explode(',', $domains),
        ), static fn (string $item): bool => $item !== '');

        return implode(',', $items);
    }
}
