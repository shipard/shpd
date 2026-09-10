<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Vykreslení PHP šablony tiskového výstupu podání (issue #55, X7).
 *
 * Šablony jsou hloupé: dostanou hotový view model (`$model`) a jen ho
 * vypisují — všechno počítání a formátování zůstává v PHP. Escapování
 * dělá helper `e()`, který je v šabloně k dispozici jako proměnná.
 */
final class FilingPdfTemplate
{
    /** @param array<string, mixed> $model */
    public static function render(string $templateFile, array $model): string
    {
        if (!is_file($templateFile)) {
            throw new \RuntimeException("Šablona '{$templateFile}' neexistuje");
        }

        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        ob_start();
        try {
            (static function (string $__file, array $model, callable $e): void {
                require $__file;
            })($templateFile, $model, $e);
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
