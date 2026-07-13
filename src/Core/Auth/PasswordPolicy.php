<?php

declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Politika lokálních hesel (D21): min. 12 znaků a heslo se nesmí rovnat
 * loginu (case-insensitive). Nic dalšího — komplexitní pravidla jsou
 * security theater.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public static function isValid(string $password, string $login): bool
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return false;
        }

        return mb_strtolower($password) !== mb_strtolower($login);
    }
}
