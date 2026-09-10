<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentGuard;
use Shipard\Module\Core\Attachments\AttachmentService;

/** Guard, který odmítne všechno, co se jmenuje jako podaný soubor. */
final class TestableRefusingGuard implements AttachmentGuard
{
    /** @var list<string> operace, na které se guard ptal */
    public static array $asked = [];

    public function __construct(private readonly DataSourceConnection $db) {}

    public function refuse(array $attachment, string $operation): ?string
    {
        self::$asked[] = $operation;
        return str_starts_with((string) ($attachment['name'] ?? ''), 'podano')
            ? 'Tenhle soubor je zamčený.'
            : null;
    }
}

/**
 * Ochrana příloh záznamu (#55 X16): `AttachmentService` se guardů ptá u
 * změn existující přílohy, odmítnutí propadne jako `DomainException`.
 */
class AttachmentGuardTest extends TestCase
{
    protected function setUp(): void
    {
        TestableRefusingGuard::$asked = [];
    }

    public function testDeleteIsRefusedByGuard(): void
    {
        $service = $this->service(['economy_vat_filings' => [TestableRefusingGuard::class]], 'podano.xml');

        try {
            $service->softDelete(7);
            $this->fail('guard měl smazání odmítnout');
        } catch (\DomainException $e) {
            $this->assertSame('Tenhle soubor je zamčený.', $e->getMessage());
        }
        $this->assertSame([AttachmentGuard::OPERATION_DELETE], TestableRefusingGuard::$asked);
    }

    public function testRenameAndReorderAskTheGuardToo(): void
    {
        $service = $this->service(['economy_vat_filings' => [TestableRefusingGuard::class]], 'podano.xml');

        foreach (['rename', 'updateOrder'] as $method) {
            try {
                $method === 'rename' ? $service->rename(7, 'jiny.xml') : $service->updateOrder(7, 2);
                $this->fail("guard měl {$method} odmítnout");
            } catch (\DomainException) {
                // očekáváno
            }
        }
        $this->assertSame(
            [AttachmentGuard::OPERATION_RENAME, AttachmentGuard::OPERATION_REORDER],
            TestableRefusingGuard::$asked,
        );
    }

    /** Guard rozhoduje per příloha — cizí soubory nechává být. */
    public function testUnrelatedAttachmentPassesThrough(): void
    {
        $service = $this->service(['economy_vat_filings' => [TestableRefusingGuard::class]], 'potvrzeni.pdf');

        $this->assertTrue($service->softDelete(7));
        $this->assertSame([AttachmentGuard::OPERATION_DELETE], TestableRefusingGuard::$asked);
    }

    /** Guard registrovaný na jinou tabulku se neptá. */
    public function testGuardOfAnotherTableIsNotConsulted(): void
    {
        $service = $this->service(['core_mail_incoming_messages' => [TestableRefusingGuard::class]], 'podano.xml');

        $this->assertTrue($service->softDelete(7));
        $this->assertSame([], TestableRefusingGuard::$asked);
    }

    /** Bez registrací (CLI, seedery) se neřeší vůbec nic. */
    public function testWithoutGuardsNothingIsChecked(): void
    {
        $this->assertTrue($this->service([], 'podano.xml')->softDelete(7));
        $this->assertSame([], TestableRefusingGuard::$asked);
    }

    /**
     * @param array<string, list<class-string>> $guards
     */
    private function service(array $guards, string $attachmentName): AttachmentService
    {
        $row = [
            'id'         => 7,
            'table_id'   => 443,
            'record_id'  => 12,
            'name'       => $attachmentName,
            'is_deleted' => 0,
        ];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($row);
        // updateWhere je void — mock ho jen zaregistruje (CLAUDE.md).
        $db->method('updateWhere');

        return new AttachmentService($db, sys_get_temp_dir(), $this->tableDefinitions(), $guards);
    }

    /** @return array<string, TableDefinition> */
    private function tableDefinitions(): array
    {
        $minimal = static fn (int $tableId, string $name): TableDefinition => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name'    => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);

        return [
            'economy_vat_filings'         => $minimal(443, 'VAT filings'),
            'core_mail_incoming_messages' => $minimal(303, 'Incoming messages'),
        ];
    }
}
