<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Hosting\Core\HostingServerDocument;

class HostingServerDocumentTest extends TestCase
{
    // -------------------------------------------------------------------------
    // validate — provision_default vyžaduje can_provision (hosting-08 D1)
    // -------------------------------------------------------------------------

    public function testDefaultFlagWithoutCanProvisionFails(): void
    {
        $doc = new HostingServerDocument();
        $data = ['name' => 'srv', 'fqdn' => 'srv.example.com', 'can_provision' => 0, 'provision_default' => 1];

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('provision_default', $result->getErrors()[0]->column);
    }

    public function testDefaultFlagWithCanProvisionPasses(): void
    {
        $doc = new HostingServerDocument();
        $data = ['name' => 'srv', 'fqdn' => 'srv.example.com', 'can_provision' => 1, 'provision_default' => 1];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testNoDefaultFlagPasses(): void
    {
        $doc = new HostingServerDocument();
        $data = ['name' => 'srv', 'fqdn' => 'srv.example.com', 'can_provision' => 0, 'provision_default' => 0];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    // -------------------------------------------------------------------------
    // afterPersist — jediný default (D1, poslední uložený vyhrává)
    //
    // afterPersist deleguje DB volání do protected clearOtherDefaults();
    // testujeme přes subclass spy, protože Dibi\Connection::query() je final.
    // -------------------------------------------------------------------------

    public function testSavingDefaultClearsFlagOnOtherServers(): void
    {
        $doc = new TestableHostingServerDocument();
        $doc->afterPersist(['id' => 5, 'can_provision' => 1, 'provision_default' => 1]);

        $this->assertSame(1, $doc->clearCalls);
        $this->assertSame(5, $doc->lastId);
    }

    public function testSavingWithoutDefaultDoesNotTouchOtherServers(): void
    {
        $doc = new TestableHostingServerDocument();
        $doc->afterPersist(['id' => 5, 'can_provision' => 1, 'provision_default' => 0]);

        $this->assertSame(0, $doc->clearCalls);
    }

    public function testAfterPersistSkipsWhenIdMissing(): void
    {
        $doc = new TestableHostingServerDocument();
        $doc->afterPersist(['can_provision' => 1, 'provision_default' => 1]);

        $this->assertSame(0, $doc->clearCalls);
    }

    // -------------------------------------------------------------------------
    // beforeSave — timestampy (paralela k ostatním hosting dokumentům)
    // -------------------------------------------------------------------------

    public function testInsertSetsTimestamps(): void
    {
        $doc = new HostingServerDocument();
        $data = ['name' => 'srv', 'fqdn' => 'srv.example.com'];

        $doc->beforeSave($data);

        $this->assertNotEmpty($data['created']);
        $this->assertNotEmpty($data['modified']);
    }

    public function testUpdateSetsOnlyModified(): void
    {
        $doc = new HostingServerDocument();
        $data = ['id' => 5, 'name' => 'srv'];

        $doc->beforeSave($data, ['id' => 5, 'name' => 'old']);

        $this->assertArrayNotHasKey('created', $data);
        $this->assertNotEmpty($data['modified']);
    }
}

/**
 * Testovací subclass — overriduje protected clearOtherDefaults, aby
 * šlo testovat bez reálného Dibi (final query() nelze mockovat).
 */
class TestableHostingServerDocument extends HostingServerDocument
{
    public int $clearCalls = 0;
    public ?int $lastId = null;

    protected function clearOtherDefaults(int $id): void
    {
        $this->clearCalls++;
        $this->lastId = $id;
    }
}
