<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormRegistry;
use Shipard\Tests\Fixtures\Core\Form\StubFormA;
use Shipard\Tests\Fixtures\Core\Form\StubFormB;
use Shipard\Tests\Fixtures\Core\Form\StubFormDefault;

class FormRegistryTest extends TestCase
{
    public function testCreatesSimpleClassForm(): void
    {
        $registry = new FormRegistry([
            ['table' => 'simple_t', 'class' => StubFormA::class],
        ]);

        $form = $registry->createForm('simple_t', []);
        $this->assertInstanceOf(StubFormA::class, $form);
    }

    public function testDispatchesByTypeColumn(): void
    {
        $registry = new FormRegistry([
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => StubFormDefault::class,
                'classes'      => [
                    'invno' => StubFormA::class,
                    'invni' => StubFormB::class,
                ],
            ],
        ]);

        $this->assertInstanceOf(
            StubFormA::class,
            $registry->createForm('docs_core_heads', ['doc_type' => 'invno']),
        );
        $this->assertInstanceOf(
            StubFormB::class,
            $registry->createForm('docs_core_heads', ['doc_type' => 'invni']),
        );
    }

    public function testFallsBackToDefaultClassForUnknownType(): void
    {
        $registry = new FormRegistry([
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => StubFormDefault::class,
                'classes'      => ['invno' => StubFormA::class],
            ],
        ]);

        $form = $registry->createForm('docs_core_heads', ['doc_type' => 'unknown_type']);
        $this->assertInstanceOf(StubFormDefault::class, $form);
    }

    public function testFallsBackToDefaultClassForMissingTypeKey(): void
    {
        $registry = new FormRegistry([
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => StubFormDefault::class,
                'classes'      => ['invno' => StubFormA::class],
            ],
        ]);

        // $data neobsahuje doc_type klíč vůbec
        $form = $registry->createForm('docs_core_heads', []);
        $this->assertInstanceOf(StubFormDefault::class, $form);
    }

    public function testReturnsNullForUnregisteredTable(): void
    {
        $registry = new FormRegistry([
            ['table' => 'known_t', 'class' => StubFormA::class],
        ]);

        $this->assertNull($registry->createForm('unknown_t', []));
    }

    public function testReturnsNullForNonexistentClass(): void
    {
        $registry = new FormRegistry([
            ['table' => 't', 'class' => 'NoSuch\\Form\\ThatDoesntExist'],
        ]);

        $this->assertNull($registry->createForm('t', []));
    }

    public function testReturnsNullForTypeColumnRegistrationWithoutMatch(): void
    {
        // typeColumn registrace bez defaultClass a bez match → null
        $registry = new FormRegistry([
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => StubFormA::class],
            ],
        ]);

        $this->assertNull($registry->createForm('docs_core_heads', ['doc_type' => 'invni']));
    }

    public function testGetFormIdReturnsRegisteredId(): void
    {
        $registry = new FormRegistry([
            ['table' => 'subtable_t', 'id' => 'mod.sub', 'class' => StubFormA::class],
            ['table' => 'simple_t', 'class' => StubFormA::class],
        ]);

        $this->assertSame('mod.sub', $registry->getFormId('subtable_t'));
        $this->assertNull($registry->getFormId('simple_t'));
        $this->assertNull($registry->getFormId('nonexistent'));
    }

    public function testIgnoresRegistrationWithoutTable(): void
    {
        $registry = new FormRegistry([
            ['class' => StubFormA::class], // no table — silently skipped
            ['table' => 'good_t', 'class' => StubFormB::class],
        ]);

        $this->assertInstanceOf(StubFormB::class, $registry->createForm('good_t', []));
    }
}
