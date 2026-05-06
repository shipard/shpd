<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class NumberSeriesDocument extends Document
{
    private const KNOWN_PLACEHOLDERS = ['D', 'C', 'y', 'Y', '3', '4', '5', '6'];
    private const ALLOWED_RESET_SCOPES = ['none', 'fiscal_year'];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název řady je povinný', 'required');
        }
        if (empty($data['doc_type'])) {
            $result->addError('doc_type', 'Typ dokladu je povinný', 'required');
        }
        if (empty($data['doc_number_pattern'])) {
            $result->addError('doc_number_pattern', 'Vzorec čísla dokladu je povinný', 'required');
        }

        $pattern = (string) ($data['doc_number_pattern'] ?? '');

        if (str_contains($pattern, '%C') && empty($data['doc_number_code'])) {
            $result->addError(
                'doc_number_code',
                'Vzorec obsahuje %C — kód řady je povinný',
                'required_for_pattern',
            );
        }

        if ($pattern !== '' && preg_match_all('/%([A-Za-z0-9])/', $pattern, $matches)) {
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, self::KNOWN_PLACEHOLDERS, true)) {
                    $result->addError(
                        'doc_number_pattern',
                        "Neznámý placeholder %{$placeholder}",
                        'unknown_placeholder',
                    );
                    break;
                }
            }
        }

        if (!empty($data['reset_scope'])
            && !in_array($data['reset_scope'], self::ALLOWED_RESET_SCOPES, true)
        ) {
            $result->addError('reset_scope', 'Neplatný typ restartu', 'invalid_value');
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError(
                'valid_to',
                'Konec platnosti musí být později než začátek',
                'invalid_range',
            );
        }

        return $result;
    }
}
