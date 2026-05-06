<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\NumberSeriesDocument;

class NumberSeriesDocumentTest extends TestCase
{
    private function doc(): NumberSeriesDocument
    {
        return new NumberSeriesDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'name'               => 'FVB - tuzemsko',
            'doc_type'           => 'invno',
            'doc_number_code'    => 'A',
            'doc_number_pattern' => '%D%y%C%4',
            'reset_scope'        => 'fiscal_year',
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);

        $errors = $this->doc()->validate($data)->toArray();

        $this->assertContains('name', array_column($errors, 'column'));
        $this->assertContains('required', array_column($errors, 'code'));
    }

    public function testValidateMissingDocTypeFails(): void
    {
        $data = $this->validData();
        unset($data['doc_type']);

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_type' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateMissingPatternFails(): void
    {
        $data = $this->validData();
        unset($data['doc_number_pattern']);

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_number_pattern' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidatePatternWithCcRequiresDocNumberCode(): void
    {
        $data = $this->validData();
        $data['doc_number_code'] = '';
        $data['doc_number_pattern'] = '%D%y%C%4';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_number_code'
                && $e['code'] === 'required_for_pattern',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidatePatternWithoutCcAllowsEmptyDocNumberCode(): void
    {
        $data = $this->validData();
        $data['doc_number_code'] = '';
        $data['doc_number_pattern'] = '%D%y%4';

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateUnknownPlaceholderFails(): void
    {
        $data = $this->validData();
        $data['doc_number_pattern'] = '%D%X%4';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_number_pattern'
                && $e['code'] === 'unknown_placeholder',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateInvalidResetScopeFails(): void
    {
        $data = $this->validData();
        $data['reset_scope'] = 'monthly';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'reset_scope' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateInvalidValidityRangeFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-01';
        $data['valid_to']   = '2026-01-01';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'valid_to' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateValidityRangeNullablePartIsValid(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-01-01';
        $data['valid_to']   = null;

        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
    }

    public function testValidateAllKnownPlaceholdersAreAccepted(): void
    {
        $data = $this->validData();
        $data['doc_number_pattern'] = '%D-%C-%y-%Y-%3-%4-%5-%6';
        $data['doc_number_code'] = 'A';

        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
    }
}
