<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Settings;

use Shipard\Core\Settings\SettingsStore;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Integration test nad reálnou tabulkou core_system_settings.
 * Používá klíče s prefixem `test.settingsStore.` a po sobě uklízí.
 */
class SettingsStoreTest extends IntegrationTestCase
{
    private const string PREFIX = 'test.settingsStore.';

    private SettingsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SettingsStore($this->db);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->deleteWhere('core_system_settings', '`key` LIKE %like~', self::PREFIX);
    }

    private function key(string $suffix): string
    {
        return self::PREFIX . $suffix;
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $this->assertNull($this->store->get($this->key('missing')));
    }

    public function testSetAndGetStringValue(): void
    {
        $this->store->set($this->key('name'), 'Moje firma');

        $this->assertSame('Moje firma', $this->store->get($this->key('name')));

        // Čerstvá instance (bez cache) musí číst totéž z DB.
        $fresh = new SettingsStore($this->db);
        $this->assertSame('Moje firma', $fresh->get($this->key('name')));
    }

    public function testJsonRoundTripForStructuredValue(): void
    {
        $meta = [
            'filename' => 'logo příliš žluťoučké.png',
            'size'     => 12345,
            'nested'   => ['a' => true, 'b' => null],
        ];
        $this->store->set($this->key('meta'), $meta);

        $fresh = new SettingsStore($this->db);
        $this->assertSame($meta, $fresh->get($this->key('meta')));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->store->set($this->key('name'), 'První');
        $this->store->set($this->key('name'), 'Druhý');

        $fresh = new SettingsStore($this->db);
        $this->assertSame('Druhý', $fresh->get($this->key('name')));

        // Upsert nesmí vytvořit duplicitní řádky.
        $count = $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_system_settings WHERE `key` = %s',
            $this->key('name'),
        );
        $this->assertSame(1, (int) $count);
    }

    public function testSetNullDeletesKey(): void
    {
        $this->store->set($this->key('name'), 'Hodnota');
        $this->store->set($this->key('name'), null);

        $fresh = new SettingsStore($this->db);
        $this->assertNull($fresh->get($this->key('name')));

        $count = $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_system_settings WHERE `key` = %s',
            $this->key('name'),
        );
        $this->assertSame(0, (int) $count);
    }

    public function testDelete(): void
    {
        $this->store->set($this->key('name'), 'Hodnota');
        $this->store->delete($this->key('name'));

        $this->assertNull($this->store->get($this->key('name')));
    }

    public function testGetManyMixesExistingAndMissing(): void
    {
        $this->store->set($this->key('a'), 'A');
        $this->store->set($this->key('b'), ['x' => 1]);

        $fresh  = new SettingsStore($this->db);
        $values = $fresh->getMany([$this->key('a'), $this->key('b'), $this->key('missing')]);

        $this->assertSame('A', $values[$this->key('a')]);
        $this->assertSame(['x' => 1], $values[$this->key('b')]);
        $this->assertNull($values[$this->key('missing')]);
    }

    public function testRequestLevelCacheServesRepeatedReads(): void
    {
        $this->store->set($this->key('cached'), 'původní');

        // Změna v DB mimo store — cache instance ji nesmí vidět.
        $this->db->execute(
            'UPDATE core_system_settings SET `value` = %s WHERE `key` = %s',
            json_encode('změněno mimo store'),
            $this->key('cached'),
        );

        $this->assertSame('původní', $this->store->get($this->key('cached')));

        // Čerstvá instance vidí stav DB.
        $fresh = new SettingsStore($this->db);
        $this->assertSame('změněno mimo store', $fresh->get($this->key('cached')));
    }
}
