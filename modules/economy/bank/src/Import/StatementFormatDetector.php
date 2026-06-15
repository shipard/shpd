<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

/**
 * Detekce formátu výpisu + charset konverze — port `createImportObject` ze
 * starého Shipardu. Regexpy se vyhodnocují nad SUROVÝM bytestreamem (před
 * dekódováním); charset se aplikuje až po identifikaci formátu.
 */
final class StatementFormatDetector
{
    /** @param array<string, array<string, mixed>> $formats cfgItem economy.bank.statementFormats */
    public function __construct(private readonly array $formats)
    {
    }

    /**
     * @return array{formatId: string, srcCharset: ?string}
     * @throws ImportException nerozpoznaný formát
     */
    public function detect(string $raw): array
    {
        foreach ($this->formats as $id => $def) {
            foreach (['checkRegExp', 'checkRegExp2'] as $key) {
                $pattern = $def[$key] ?? null;
                if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $raw) === 1) {
                    $charset = $def['srcCharset'] ?? null;
                    return [
                        'formatId'   => (string) $id,
                        'srcCharset' => is_string($charset) && $charset !== '' ? $charset : null,
                    ];
                }
            }
        }
        throw new ImportException(
            'Nerozpoznaný formát bankovního výpisu. Podporované formáty: '
            . implode(', ', array_keys($this->formats)) . '.',
        );
    }

    /**
     * Dekóduje surový text do UTF-8 dle srcCharset (null = už UTF-8).
     *
     * @throws ImportException při selhání konverze
     */
    public function decode(string $raw, ?string $srcCharset): string
    {
        if ($srcCharset === null) {
            return $raw;
        }
        $decoded = @iconv($srcCharset, 'UTF-8//TRANSLIT', $raw);
        if ($decoded === false) {
            throw new ImportException("Selhala konverze znakové sady {$srcCharset} → UTF-8.");
        }
        return $decoded;
    }
}
