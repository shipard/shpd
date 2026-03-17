<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DefaultDocument;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentRegistry;

// Fixture stubs
class PersonDocument extends Document {}
class IssuedInvoiceDocument extends Document {}
class ReceivedInvoiceDocument extends Document {}
class GenericDocDocument extends Document {}

class DocumentRegistryTest extends TestCase
{
    public function testSingleClassForTable(): void
    {
        $registry = new DocumentRegistry([
            ['table' => 'base_persons_persons', 'class' => PersonDocument::class],
        ]);

        $doc = $registry->getDocument('base_persons_persons');
        $this->assertInstanceOf(PersonDocument::class, $doc);
    }

    public function testTypeColumnReturnsCorrectClass(): void
    {
        $registry = new DocumentRegistry([
            [
                'table' => 'economy_docs_heads',
                'typeColumn' => 'doc_type',
                'classes' => [
                    'inv_issued' => IssuedInvoiceDocument::class,
                    'inv_received' => ReceivedInvoiceDocument::class,
                ],
                'defaultClass' => GenericDocDocument::class,
            ],
        ]);

        $doc = $registry->getDocument('economy_docs_heads', ['doc_type' => 'inv_issued']);
        $this->assertInstanceOf(IssuedInvoiceDocument::class, $doc);

        $doc = $registry->getDocument('economy_docs_heads', ['doc_type' => 'inv_received']);
        $this->assertInstanceOf(ReceivedInvoiceDocument::class, $doc);
    }

    public function testUnknownTypeValueFallsBackToDefaultClass(): void
    {
        $registry = new DocumentRegistry([
            [
                'table' => 'economy_docs_heads',
                'typeColumn' => 'doc_type',
                'classes' => [
                    'inv_issued' => IssuedInvoiceDocument::class,
                ],
                'defaultClass' => GenericDocDocument::class,
            ],
        ]);

        $doc = $registry->getDocument('economy_docs_heads', ['doc_type' => 'unknown_type']);
        $this->assertInstanceOf(GenericDocDocument::class, $doc);
    }

    public function testUnknownTypeValueWithoutDefaultClassReturnsDefaultDocument(): void
    {
        $registry = new DocumentRegistry([
            [
                'table' => 'economy_docs_heads',
                'typeColumn' => 'doc_type',
                'classes' => [
                    'inv_issued' => IssuedInvoiceDocument::class,
                ],
            ],
        ]);

        $doc = $registry->getDocument('economy_docs_heads', ['doc_type' => 'unknown_type']);
        $this->assertInstanceOf(DefaultDocument::class, $doc);
    }

    public function testUnregisteredTableReturnsDefaultDocument(): void
    {
        $registry = new DocumentRegistry([]);

        $doc = $registry->getDocument('some_unknown_table');
        $this->assertInstanceOf(DefaultDocument::class, $doc);
    }

    public function testMissingTypeColumnValueReturnsDefaultClass(): void
    {
        $registry = new DocumentRegistry([
            [
                'table' => 'economy_docs_heads',
                'typeColumn' => 'doc_type',
                'classes' => [
                    'inv_issued' => IssuedInvoiceDocument::class,
                ],
                'defaultClass' => GenericDocDocument::class,
            ],
        ]);

        $doc = $registry->getDocument('economy_docs_heads', []);
        $this->assertInstanceOf(GenericDocDocument::class, $doc);
    }
}
