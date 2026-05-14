<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Schema;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Thin facade around opis/json-schema. Returns a flat list of issues
 * shaped to match the `_resolve.issues` contract from
 * docs/exchange-format.md sekce 9. Issues from this class always have
 * severity = "error" — semantic warnings (totals mismatch, missing
 * partner doc number, …) are produced by DocumentValidator, not here.
 */
final class SchemaValidator
{
    public function __construct(
        private readonly SchemaLoader $loader,
    ) {}

    /**
     * @param array<string, mixed> $payload Canonical document as PHP array.
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public function validate(array $payload, string $formatId, string $version): array
    {
        $schema = $this->loader->load($formatId, $version);

        // Opis expects stdClass-decoded data, not PHP associative arrays
        // (otherwise empty objects are mis-detected as empty arrays).
        $data = json_decode(json_encode($payload, JSON_UNESCAPED_UNICODE), false);

        $validator = new Validator();
        $result = $validator->schemaValidation($data, $validator->loader()->loadObjectSchema($schema));

        if ($result === null) {
            return [];
        }

        $issues = [];
        $this->collectIssues($result, $issues);
        return $issues;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function collectIssues(ValidationError $error, array &$issues): void
    {
        $subErrors = $error->subErrors();
        if ($subErrors !== []) {
            foreach ($subErrors as $sub) {
                $this->collectIssues($sub, $issues);
            }
            return;
        }

        $issues[] = [
            'severity' => 'error',
            'path'     => $this->jsonPathToDotted($error->data()->fullPath()),
            'code'     => $error->keyword(),
            'message'  => $this->formatMessage($error),
        ];
    }

    /**
     * @param array<int, int|string> $path
     */
    private function jsonPathToDotted(array $path): string
    {
        return implode('.', array_map(static fn($p) => (string) $p, $path));
    }

    private function formatMessage(ValidationError $error): string
    {
        $message = $error->message();
        foreach ($error->args() as $key => $value) {
            $message = str_replace('{' . $key . '}', $this->stringifyArg($value), $message);
        }
        return $message;
    }

    private function stringifyArg(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : '';
    }
}
