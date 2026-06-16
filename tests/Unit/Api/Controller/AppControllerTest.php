<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AppController;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

class AppControllerTest extends TestCase
{
    private const string PNG_1X1_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_appctrl_' . uniqid('', true);
        mkdir($this->dsDir . '/config', 0755, true);
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'                => 'test-test-test-test',
            'name'              => 'Testovací firma',
            'database_name'     => 'x',
            'database_user'     => 'x',
            'database_password' => 'x',
            'created'           => '2026-01-01T00:00:00+00:00',
            'modules'           => ['core.system'],
        ]));
    }

    protected function tearDown(): void
    {
        unset($_FILES['file']);
        foreach (glob($this->dsDir . '/branding/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dsDir . '/branding');
        @unlink($this->dsDir . '/config/main.json');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    /** @param array[] $settingsRows řádky vrácené z core_system_settings (key, value JSON string) */
    private function makeController(array $settingsRows = [], ?DataSourceConnection $db = null): AppController
    {
        if ($db === null) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchAll')->willReturn($settingsRows);
        }
        return new AppController($db, new DataSourceConfig($this->dsDir));
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_test');
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    // --- info() ---

    public function testInfoFallsBackToMainJsonName(): void
    {
        $resp = $this->makeController()->info();

        $data = $resp->getPayload()['data'];
        $this->assertSame('Testovací firma', $data['name']);
        $this->assertSame('Testovací firma', $data['shortName']);
        $this->assertNull($data['icon']);
        $this->assertNull($data['companyLogo']);
    }

    public function testInfoPrefersAppNameAndShortName(): void
    {
        $resp = $this->makeController([
            ['key' => 'app.name', 'value' => json_encode('Moje firma s.r.o.')],
            ['key' => 'app.shortName', 'value' => json_encode('Moje firma')],
        ])->info();

        $data = $resp->getPayload()['data'];
        $this->assertSame('Moje firma s.r.o.', $data['name']);
        $this->assertSame('Moje firma', $data['shortName']);
    }

    public function testInfoShortNameFallsBackToName(): void
    {
        $resp = $this->makeController([
            ['key' => 'app.name', 'value' => json_encode('Moje firma s.r.o.')],
        ])->info();

        $data = $resp->getPayload()['data'];
        $this->assertSame('Moje firma s.r.o.', $data['shortName']);
    }

    public function testInfoThemeNullWhenUnset(): void
    {
        $data = $this->makeController()->info()->getPayload()['data'];
        $this->assertArrayHasKey('theme', $data);
        $this->assertNull($data['theme']);
    }

    public function testInfoIncludesDsTheme(): void
    {
        $resp = $this->makeController([
            ['key' => 'app.theme', 'value' => json_encode([
                'mode'   => 'custom',
                'custom' => ['base' => 'light', 'sidebar' => ['type' => 'solid', 'color' => '#0E4F5C']],
            ])],
        ])->info();

        $theme = $resp->getPayload()['data']['theme'];
        $this->assertSame('custom', $theme['mode']);
        $this->assertSame('#0E4F5C', $theme['custom']['sidebar']['color']);
    }

    public function testInfoIncludesIconUrlWithHash(): void
    {
        $resp = $this->makeController([
            ['key' => 'app.icon', 'value' => json_encode([
                'filename' => 'logo.png',
                'storedAs' => 'icon.png',
                'mime'     => 'image/png',
                'size'     => 100,
                'hash'     => 'abcd1234abcd1234',
            ])],
        ])->info();

        $icon = $resp->getPayload()['data']['icon'];
        $this->assertSame('/_app/branding/icon?h=abcd1234abcd1234', $icon['url']);
        $this->assertSame('abcd1234abcd1234', $icon['hash']);
    }

    // --- buildBrandingHeaders() ---

    public function testBrandingHeadersImmutableCache(): void
    {
        $headers = $this->makeController()->buildBrandingHeaders('image/png', 123);

        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertSame('public, max-age=31536000, immutable', $headers['Cache-Control']);
        $this->assertSame('123', $headers['Content-Length']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
    }

    public function testBrandingHeadersSvgGetsCspAndNosniff(): void
    {
        $headers = $this->makeController()->buildBrandingHeaders('image/svg+xml', null);

        $this->assertSame("default-src 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    // --- brandingGet() ---

    public function testBrandingGetInvalidSlotReturns404(): void
    {
        $resp = $this->makeController()->brandingGet('evil');
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testBrandingGetEmptySlotReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(null);
        $resp = $this->makeController(db: $db)->brandingGet('icon');

        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testBrandingGetMissingFileReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(json_encode([
            'storedAs' => 'icon.png',
            'mime'     => 'image/png',
            'hash'     => 'abcd1234abcd1234',
        ]));
        $resp = $this->makeController(db: $db)->brandingGet('icon');

        $this->assertSame(404, $this->getStatus($resp));
    }

    // --- brandingUpload() ---

    public function testBrandingUploadRequiresAuth(): void
    {
        $resp = $this->makeController()->brandingUpload('icon', AuthContext::anonymous());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testBrandingUploadInvalidSlotReturns404(): void
    {
        $resp = $this->makeController()->brandingUpload('evil', $this->auth());
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testBrandingUploadWithoutFileReturns400(): void
    {
        unset($_FILES['file']);
        $resp = $this->makeController()->brandingUpload('icon', $this->auth());

        $this->assertSame(400, $this->getStatus($resp));
        $this->assertSame('UPLOAD_ERROR', $resp->getPayload()['error']['code']);
    }

    public function testBrandingUploadRejectsUnsupportedMime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_up_');
        file_put_contents($tmp, 'plain text, not an image');
        $_FILES['file'] = [
            'name'     => 'evil.txt',
            'tmp_name' => $tmp,
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
        ];

        $resp = $this->makeController()->brandingUpload('icon', $this->auth());

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('VALIDATION_ERROR', $resp->getPayload()['error']['code']);
        unlink($tmp);
    }

    public function testBrandingUploadRejectsOversizedFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_up_');
        file_put_contents($tmp, base64_decode(self::PNG_1X1_BASE64));
        $_FILES['file'] = [
            'name'     => 'big.png',
            'tmp_name' => $tmp,
            'size'     => 3 * 1024 * 1024,
            'error'    => UPLOAD_ERR_OK,
        ];

        $resp = $this->makeController()->brandingUpload('icon', $this->auth());

        $this->assertSame(422, $this->getStatus($resp));
        unlink($tmp);
    }

    public function testBrandingUploadStoresFileAndMetadata(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_up_');
        file_put_contents($tmp, base64_decode(self::PNG_1X1_BASE64));
        $size = (int) filesize($tmp);
        $_FILES['file'] = [
            'name'     => 'moje logo.png',
            'tmp_name' => $tmp,
            'size'     => $size,
            'error'    => UPLOAD_ERR_OK,
        ];

        $db = $this->createMock(DataSourceConnection::class);
        // Upsert metadat do core_system_settings
        $db->expects($this->once())->method('execute');

        $resp = $this->makeController(db: $db)->brandingUpload('icon', $this->auth());

        $this->assertSame(201, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame('moje logo.png', $data['filename']);
        $this->assertSame('icon.png', $data['storedAs']);
        $this->assertSame('image/png', $data['mime']);
        $this->assertSame($size, $data['size']);
        $this->assertSame(16, strlen($data['hash']));
        $this->assertStringContainsString('/_app/branding/icon?h=', $data['url']);
        $this->assertFileExists($this->dsDir . '/branding/icon.png');
    }

    // --- brandingDelete() ---

    public function testBrandingDeleteRequiresAuth(): void
    {
        $resp = $this->makeController()->brandingDelete('icon', AuthContext::anonymous());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testBrandingDeleteRemovesFileAndKey(): void
    {
        mkdir($this->dsDir . '/branding', 0755, true);
        file_put_contents($this->dsDir . '/branding/icon.png', base64_decode(self::PNG_1X1_BASE64));

        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('deleteWhere');

        $resp = $this->makeController(db: $db)->brandingDelete('icon', $this->auth());

        $this->assertSame(204, $this->getStatus($resp));
        $this->assertFileDoesNotExist($this->dsDir . '/branding/icon.png');
    }
}
