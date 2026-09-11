<?php

declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Request-scoped držák aktuálního uživatele pro vrstvy bez přístupu
 * k AuthContextu (Document hooky — `locked_by` zámku instance tvrzení
 * a fiskálního měsíce, #55 D25/D27). Nastavuje `public/index.php` hned po
 * autentizaci, CLI ho nenastavuje (null = strojový kontext).
 *
 * Stejný vzor jako ErrorLogger::setRequestContext — proces obsluhuje jeden
 * request, statický stav je tu bezpečný. Testy volají reset().
 */
final class CurrentUser
{
    private static ?int $id = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function reset(): void
    {
        self::$id = null;
    }
}
