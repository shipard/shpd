<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Jména souborů příloh v sidecar složce záznamu (`NNNN-<slug>.files/`).
 *
 * Jméno musí být unikátní v rámci záznamu a bezpečné jako komponenta
 * cesty; kolize se řeší suffixem `-2`, `-3`, … před příponou.
 */
final class AttachmentNames
{
    /**
     * @param array<string, true> $used už přidělená jména v tomto záznamu
     */
    public static function unique(string $name, array $used): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '_', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }
        if (!isset($used[$name])) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $base = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
        $ext = $dot === false || $dot === 0 ? '' : substr($name, $dot);
        for ($i = 2; ; $i++) {
            $candidate = "{$base}-{$i}{$ext}";
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
    }
}
