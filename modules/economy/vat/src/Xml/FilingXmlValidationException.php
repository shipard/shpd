<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Document\ValidationError;

/**
 * Podání nejde vygenerovat — chybí nebo nesedí údaj, který úřad vyžaduje
 * (issue #55, X5). Nese **field-level chyby** se sloupci ve tvaru
 * `header.<pole>`, takže je formulář podání umí ukázat u konkrétního
 * inputu (kontrakt `field`, docs/edit-forms.md § 8).
 */
final class FilingXmlValidationException extends \DomainException
{
    /** @param list<ValidationError> $errors */
    public function __construct(private readonly array $errors)
    {
        $messages = array_map(
            static fn (ValidationError $e): string => $e->column . ': ' . $e->message,
            $errors,
        );
        parent::__construct(
            'Podání nelze vygenerovat — ' . (count($errors) === 1
                ? lcfirst($errors[0]->message)
                : count($errors) . ' chyb: ' . implode('; ', $messages)),
        );
    }

    /** @return list<ValidationError> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return list<array<string, mixed>> tvar pro API odpověď */
    public function toArray(): array
    {
        return array_map(
            static fn (ValidationError $e): array => [
                'column'  => $e->column,
                'message' => $e->message,
                'code'    => $e->code,
            ],
            $this->errors,
        );
    }
}
