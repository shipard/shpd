<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

class JsoncParser
{
    public static function parse(string $content, bool $assoc = true): mixed
    {
        $result = '';
        $len = strlen($content);
        $i = 0;
        $inString = false;
        $pendingComma = null;

        while ($i < $len) {
            $ch = $content[$i];

            if ($inString) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $result .= $ch . $content[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                $result .= $ch;
                $i++;
                continue;
            }

            // Not in string
            if ($ch === '/' && $i + 1 < $len && $content[$i + 1] === '/') {
                // Single-line comment — skip until newline
                $i += 2;
                while ($i < $len && $content[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($ch === '/' && $i + 1 < $len && $content[$i + 1] === '*') {
                // Multi-line comment — skip until */
                $i += 2;
                while ($i < $len) {
                    if ($content[$i] === '*' && $i + 1 < $len && $content[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($ch === ',') {
                // Flush old pending comma, start new one
                if ($pendingComma !== null) {
                    $result .= $pendingComma;
                }
                $pendingComma = ',';
                $i++;
                continue;
            }

            if ($ch === '}' || $ch === ']') {
                // Discard trailing comma
                $pendingComma = null;
                $result .= $ch;
                $i++;
                continue;
            }

            if ($pendingComma !== null && ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r")) {
                $pendingComma .= $ch;
                $i++;
                continue;
            }

            // Any other char — flush pending comma, emit char
            if ($pendingComma !== null) {
                $result .= $pendingComma;
                $pendingComma = null;
            }

            if ($ch === '"') {
                $inString = true;
            }

            $result .= $ch;
            $i++;
        }

        // Flush any remaining pending comma
        if ($pendingComma !== null) {
            $result .= $pendingComma;
        }

        try {
            return json_decode($result, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('JSONC parse error: ' . $e->getMessage());
        }
    }

    public static function parseFile(string $path, bool $assoc = true): mixed
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: $path");
        }
        try {
            return self::parse($content, $assoc);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage() . " (file: $path)");
        }
    }
}
