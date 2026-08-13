<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Module\Hosting\Core\HostingDataSourceDocument;

class HostingDataSourceDocumentTest extends TestCase
{
    private DsSecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));
    }

    /**
     * @param array<string, mixed> $settings dekódované hodnoty core_system_settings
     * @param list<string> $takenWebIds obsazené web_id v evidenci
     * @param array<int, string> $originalWebIds web_id existujících řádků dle id
     */
    private function createDocument(
        array $settings = ['hosting.baseDomain' => 'shpd.dev'],
        array $activeUserIds = [7],
        array $existingDsIds = [],
        array $takenWebIds = [],
        array $originalWebIds = [],
    ): HostingDataSourceDocument {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchSingle')->willReturnCallback(
            function (mixed ...$args) use ($settings, $activeUserIds, $existingDsIds, $takenWebIds, $originalWebIds): mixed {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_system_settings')) {
                    $key = (string) $args[1];
                    return array_key_exists($key, $settings) ? json_encode($settings[$key]) : null;
                }
                if (str_contains($sql, 'core_system_users')) {
                    return in_array((int) $args[1], $activeUserIds, true) ? (int) $args[1] : null;
                }
                if (str_contains($sql, 'SELECT web_id FROM hosting_core_data_sources')) {
                    return $originalWebIds[(int) $args[1]] ?? null;
                }
                if (str_contains($sql, 'WHERE web_id')) {
                    return in_array((string) $args[1], $takenWebIds, true) ? 1 : null;
                }
                if (str_contains($sql, 'hosting_core_data_sources')) {
                    return in_array((string) $args[1], $existingDsIds, true) ? 1 : null;
                }
                return null;
            },
        );

        $doc = new HostingDataSourceDocument();
        $doc->setDb($db);
        $doc->setSecretCipher($this->cipher);
        return $doc;
    }

    /** @return array<string, mixed> */
    private function requestData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nová firma',
            'web_id' => 'nova',
            'server' => 3,
            'install_module' => 'install.base',
            'language' => 'cs',
            'country' => 'cz',
            'lifecycle' => 'request',
            'owner' => 7,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // beforeSave — generování
    // -------------------------------------------------------------------------

    public function testRequestInsertGeneratesAllDerivedFields(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData();

        $doc->beforeSave($data);

        $this->assertMatchesRegularExpression(IdGenerator::ID_PATTERN, $data['ds_id']);
        $this->assertSame('https://nova.shpd.dev', $data['url_app']);
        $this->assertSame('https://nova.shpd.dev/api/v1/_auth/oidc/callback', $data['oidc_redirect_uri']);

        // Secret je zašifrovaný a po dešifrování má formát --generate (43 znaků).
        $plain = $this->cipher->decrypt((string) $data['oidc_client_secret']);
        $this->assertSame(43, strlen($plain));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $plain);

        $this->assertNotEmpty($data['created']);
        $this->assertNotEmpty($data['modified']);
    }

    public function testRequestInsertRespectsPrefilledValues(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData([
            'ds_id' => 'aaaa-bbbb-cccc-dddd',
            'url_app' => 'https://custom.example.com',
            'oidc_redirect_uri' => 'https://custom.example.com/cb',
            'oidc_client_secret' => 'my-own-secret',
        ]);

        $doc->beforeSave($data);

        $this->assertSame('aaaa-bbbb-cccc-dddd', $data['ds_id']);
        $this->assertSame('https://custom.example.com', $data['url_app']);
        $this->assertSame('https://custom.example.com/cb', $data['oidc_redirect_uri']);
        $this->assertSame('my-own-secret', $this->cipher->decrypt((string) $data['oidc_client_secret']));
    }

    public function testGeneratedDsIdAvoidsExistingIds(): void
    {
        // Kolizi nelze deterministicky vynutit — ověřit aspoň, že se
        // generátor ptá evidence a vrátí id mimo seznam existujících.
        $doc = $this->createDocument(existingDsIds: ['xxxx-xxxx-xxxx-xxxx']);
        $data = $this->requestData();

        $doc->beforeSave($data);

        $this->assertNotSame('xxxx-xxxx-xxxx-xxxx', $data['ds_id']);
        $this->assertMatchesRegularExpression(IdGenerator::ID_PATTERN, $data['ds_id']);
    }

    public function testNonRequestInsertDoesNotGenerate(): void
    {
        $doc = $this->createDocument();
        $data = [
            'name' => 'Ruční evidence',
            'lifecycle' => 'active',
            'ds_id' => 'eeee-ffff-gggg-hhhh',
            'url_app' => 'https://old.example.com',
        ];

        $doc->beforeSave($data);

        $this->assertArrayNotHasKey('oidc_client_secret', $data);
        $this->assertArrayNotHasKey('oidc_redirect_uri', $data);
    }

    public function testUpdateDoesNotGenerate(): void
    {
        // Řádek s vyplněným ds_id (normální stav — adoptované i vzniklé
        // z requestu ho mají) editovaný ve stavu request negeneruje nic.
        $doc = $this->createDocument();
        $data = ['id' => 5, 'lifecycle' => 'request', 'name' => 'Edit'];

        $doc->beforeSave($data, ['id' => 5, 'name' => 'Old', 'ds_id' => 'aaaa-bbbb-cccc-dddd']);

        $this->assertArrayNotHasKey('ds_id', $data);
        $this->assertArrayNotHasKey('oidc_client_secret', $data);
    }

    // -------------------------------------------------------------------------
    // beforeSave — přechod existujícího řádku do request (fix 6d7ea84,
    // past z fáze 7 adopce: řádek omylem založený v jiném stavu)
    // -------------------------------------------------------------------------

    public function testTransitionToRequestWithEmptyDsIdGenerates(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData(['id' => 5]);

        $doc->beforeSave($data, ['id' => 5, 'name' => 'Nová firma', 'lifecycle' => 'draft', 'ds_id' => '']);

        $this->assertMatchesRegularExpression(IdGenerator::ID_PATTERN, $data['ds_id']);
        $this->assertNotEmpty($data['oidc_client_secret']);
        $this->assertSame('https://nova.shpd.dev', $data['url_app']);
    }

    public function testTransitionToRequestWithFilledDsIdDoesNotGenerate(): void
    {
        // Adoptovaný řádek (ds_id vždy vyplněné) přepnutý do request nesmí
        // dostat nové ds_id ani secret — přegenerování by odpojilo živý DS.
        $doc = $this->createDocument();
        $data = ['id' => 5, 'lifecycle' => 'request', 'name' => 'Adoptovaný'];

        $doc->beforeSave($data, [
            'id' => 5,
            'name' => 'Adoptovaný',
            'lifecycle' => 'active',
            'ds_id' => 'aaaa-bbbb-cccc-dddd',
            'oidc_client_secret' => 'stored-encrypted-secret',
        ]);

        $this->assertArrayNotHasKey('ds_id', $data);
        $this->assertArrayNotHasKey('oidc_client_secret', $data);
        $this->assertArrayNotHasKey('url_app', $data);
    }

    public function testEmptySecretSubmitIsRemoved(): void
    {
        $doc = $this->createDocument();
        $data = ['id' => 5, 'oidc_client_secret' => ''];

        $doc->beforeSave($data, ['id' => 5]);

        $this->assertArrayNotHasKey('oidc_client_secret', $data);
    }

    // -------------------------------------------------------------------------
    // beforeSave — mail_token (D4, stejný kontrakt jako oidc_client_secret)
    // -------------------------------------------------------------------------

    public function testMailTokenIsEncryptedOnSave(): void
    {
        $doc = $this->createDocument();
        $data = ['id' => 5, 'mail_token' => 'shpd_ak_' . str_repeat('a', 32)];

        $doc->beforeSave($data, ['id' => 5]);

        $this->assertNotSame('shpd_ak_' . str_repeat('a', 32), $data['mail_token']);
        $this->assertSame(
            'shpd_ak_' . str_repeat('a', 32),
            $this->cipher->decrypt((string) $data['mail_token']),
        );
    }

    public function testEmptyMailTokenSubmitIsRemoved(): void
    {
        $doc = $this->createDocument();

        $data = ['id' => 5, 'mail_token' => ''];
        $doc->beforeSave($data, ['id' => 5]);
        $this->assertArrayNotHasKey('mail_token', $data);

        $data = ['id' => 5, 'mail_token' => null];
        $doc->beforeSave($data, ['id' => 5]);
        $this->assertArrayNotHasKey('mail_token', $data);
    }

    public function testAbsentMailTokenIsNotTouched(): void
    {
        $doc = $this->createDocument();
        $data = ['id' => 5, 'name' => 'Edit'];

        $doc->beforeSave($data, ['id' => 5, 'name' => 'Old']);

        $this->assertArrayNotHasKey('mail_token', $data);
    }

    // -------------------------------------------------------------------------
    // validate
    // -------------------------------------------------------------------------

    public function testValidRequestPasses(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData();

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testRequestRequiresMandatoryFields(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData([
            'web_id' => '',
            'server' => null,
            'install_module' => '',
            'language' => '',
            'country' => '',
            'owner' => null,
        ]);

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());

        $columns = array_map(fn($e) => $e->column, $result->getErrors());
        $this->assertContains('web_id', $columns);
        $this->assertContains('server', $columns);
        $this->assertContains('install_module', $columns);
        $this->assertContains('language', $columns);
        $this->assertContains('country', $columns);
        $this->assertContains('owner', $columns);
    }

    public function testRequestRejectsInactiveOwner(): void
    {
        $doc = $this->createDocument(activeUserIds: []);
        $data = $this->requestData();

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('owner', $result->getErrors()[0]->column);
    }

    public function testRequestRequiresBaseDomainWhenUrlAppEmpty(): void
    {
        $doc = $this->createDocument(settings: []);
        $data = $this->requestData();

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame(ValidationError::FIELD_FORM, $result->getErrors()[0]->column);
    }

    public function testRequestWithPrefilledUrlAppDoesNotNeedBaseDomain(): void
    {
        $doc = $this->createDocument(settings: []);
        $data = $this->requestData(['url_app' => 'https://custom.example.com']);

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testNonRequestInsertSkipsRequestValidation(): void
    {
        $doc = $this->createDocument(settings: [], activeUserIds: []);
        $data = ['name' => 'Ruční evidence', 'lifecycle' => 'active'];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testUpdateSkipsRequestValidation(): void
    {
        $doc = $this->createDocument(settings: [], activeUserIds: []);
        $data = ['id' => 5, 'lifecycle' => 'request', 'name' => 'Edit'];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    // -------------------------------------------------------------------------
    // validate — web_id formát, blocklist, duplicita (hosting-08 D3)
    // -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function invalidWebIdProvider(): array
    {
        return [
            'příliš krátké'    => ['ab'],
            'příliš dlouhé'    => [str_repeat('a', 51)],
            'podtržítko'       => ['nova_firma'],
            'pomlčka na kraji' => ['-nova'],
            'pomlčka na konci' => ['nova-'],
            'diakritika'       => ['firmička'],
            'tečka'            => ['nova.firma'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWebIdProvider')]
    public function testInvalidWebIdFormatFails(string $webId): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData(['web_id' => $webId]);

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('web_id', $result->getErrors()[0]->column);
    }

    public function testUppercaseWebIdIsNormalizedAndPasses(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData(['web_id' => '  NoVa-Firma ']);

        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame('nova-firma', $data['web_id']);
    }

    public function testReservedWebIdFailsOnInsert(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData(['web_id' => 'home']);

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('RESERVED', $result->getErrors()[0]->code);
    }

    public function testReservedWebIdFailsOnChange(): void
    {
        $doc = $this->createDocument(originalWebIds: [5 => 'stara-firma']);
        $data = ['id' => 5, 'name' => 'Edit', 'web_id' => 'home'];

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('RESERVED', $result->getErrors()[0]->code);
    }

    public function testUnchangedWebIdPassesEvenWhenReserved(): void
    {
        // Historický řádek s dnes už rezervovanou/nevyhovující hodnotou —
        // editace beze změny web_id musí projít (PRD 5c).
        $doc = $this->createDocument(originalWebIds: [5 => 'home']);
        $data = ['id' => 5, 'name' => 'Edit', 'web_id' => 'home'];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testDuplicateWebIdFailsOnInsert(): void
    {
        $doc = $this->createDocument(takenWebIds: ['nova']);
        $data = $this->requestData(['web_id' => 'nova']);

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('DUPLICATE', $result->getErrors()[0]->code);
    }

    public function testDuplicateWebIdFailsOnChange(): void
    {
        $doc = $this->createDocument(takenWebIds: ['obsazene'], originalWebIds: [5 => 'stara-firma']);
        $data = ['id' => 5, 'name' => 'Edit', 'web_id' => 'obsazene'];

        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('DUPLICATE', $result->getErrors()[0]->code);
    }

    // -------------------------------------------------------------------------
    // beforeSave — normalizace web_id před odvozením url_app
    // -------------------------------------------------------------------------

    public function testBeforeSaveNormalizesWebIdBeforeDerivingUrlApp(): void
    {
        $doc = $this->createDocument();
        $data = $this->requestData(['web_id' => ' NoVa ']);

        $doc->beforeSave($data);

        $this->assertSame('nova', $data['web_id']);
        $this->assertSame('https://nova.shpd.dev', $data['url_app']);
    }

    // -------------------------------------------------------------------------
    // checkWebIdRules — sdílená pravidla pro portálový check-web-id
    // -------------------------------------------------------------------------

    public function testCheckWebIdRules(): void
    {
        $this->assertNull(HostingDataSourceDocument::checkWebIdRules('nova-firma'));
        $this->assertSame('format', HostingDataSourceDocument::checkWebIdRules('ab'));
        $this->assertSame('format', HostingDataSourceDocument::checkWebIdRules('nova_firma'));
        $this->assertSame('reserved', HostingDataSourceDocument::checkWebIdRules('www'));
    }
}
