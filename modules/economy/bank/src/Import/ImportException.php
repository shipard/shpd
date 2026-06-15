<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

/**
 * Doménová výjimka importu výpisu (nerozpoznaný formát, charset konverze,
 * nenalezený vlastní účet apod.) — s uživatelsky srozumitelnou hláškou.
 */
final class ImportException extends \RuntimeException
{
}
