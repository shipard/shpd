<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Hosting\Core\HostingAiTokenDocument;

/**
 * beforeSave kontrakt token_encrypted — stejná třístavová sémantika jako
 * mail_token na HostingDataSourceDocument (D5).
 */
class HostingAiTokenDocumentTest extends TestCase
{
    private DsSecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));
    }

    private function createDocument(): HostingAiTokenDocument
    {
        $doc = new HostingAiTokenDocument();
        $doc->setSecretCipher($this->cipher);
        return $doc;
    }

    public function testTokenIsEncryptedOnSave(): void
    {
        $doc = $this->createDocument();
        $token = 'shpd_gw_' . str_repeat('a', 43);
        $data = ['data_source' => 1, 'token_encrypted' => $token];

        $doc->beforeSave($data);

        $this->assertNotSame($token, $data['token_encrypted']);
        $this->assertSame($token, $this->cipher->decrypt((string) $data['token_encrypted']));
    }

    public function testEmptyTokenSubmitIsRemoved(): void
    {
        $doc = $this->createDocument();

        $data = ['id' => 5, 'token_encrypted' => ''];
        $doc->beforeSave($data, ['id' => 5]);
        $this->assertArrayNotHasKey('token_encrypted', $data);

        $data = ['id' => 5, 'token_encrypted' => null];
        $doc->beforeSave($data, ['id' => 5]);
        $this->assertArrayNotHasKey('token_encrypted', $data);
    }

    public function testAbsentTokenIsNotTouched(): void
    {
        $doc = $this->createDocument();
        $data = ['id' => 5, 'note' => 'Edit'];

        $doc->beforeSave($data, ['id' => 5, 'note' => 'Old']);

        $this->assertArrayNotHasKey('token_encrypted', $data);
    }

    public function testTimestampsAreSet(): void
    {
        $doc = $this->createDocument();
        $data = ['data_source' => 1];

        $doc->beforeSave($data);

        $this->assertNotEmpty($data['created']);
        $this->assertNotEmpty($data['modified']);
    }

    public function testThrowsWithoutCipher(): void
    {
        $doc = new HostingAiTokenDocument();
        $data = ['data_source' => 1, 'token_encrypted' => 'shpd_gw_x'];

        $this->expectException(\RuntimeException::class);
        $doc->beforeSave($data);
    }
}
