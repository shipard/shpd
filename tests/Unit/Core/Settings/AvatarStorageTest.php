<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Settings\AvatarStorage;

class AvatarStorageTest extends TestCase
{
    // Validní 2x2 PNG (ne 1x1 — vipsthumbnail smartcrop potřebuje plochu).
    private const string PNG_2X2_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEUlEQVR4nGNkYGD4z8DAwAAAEAYBAQ7uw9wAAAAASUVORK5CYII=';

    private string $dsPath;
    private AvatarStorage $storage;

    protected function setUp(): void
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_avatar_' . uniqid('', true);
        mkdir($this->dsPath, 0755, true);
        $this->storage = new AvatarStorage($this->dsPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dsPath . '/branding/avatars/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dsPath . '/branding/avatars');
        @rmdir($this->dsPath . '/branding');
        @rmdir($this->dsPath);
    }

    private function tmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd_av_');
        file_put_contents($path, $content);
        return $path;
    }

    private function pngContent(): string
    {
        return (string) base64_decode(self::PNG_2X2_BASE64);
    }

    public function testDetectMimePng(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $this->assertSame('image/png', $this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testDetectMimeRejectsSvg(): void
    {
        // Avatar je fotka, ne vektor — SVG záměrně nepovolujeme (XSS plocha).
        $tmp = $this->tmpFile('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->assertNull($this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testDetectMimeRejectsPlainText(): void
    {
        $tmp = $this->tmpFile('definitely not an image');
        $this->assertNull($this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testValidateUploadAcceptsPng(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $result = $this->storage->validateUpload($tmp, (int) filesize($tmp));

        $this->assertIsArray($result);
        $this->assertSame('image/png', $result['mime']);
        $this->assertSame('png', $result['ext']);
        unlink($tmp);
    }

    public function testValidateUploadRejectsOversizedFile(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $result = $this->storage->validateUpload($tmp, AvatarStorage::MAX_FILE_SIZE + 1);

        $this->assertIsString($result);
        unlink($tmp);
    }

    public function testValidateUploadRejectsSvg(): void
    {
        $tmp = $this->tmpFile('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        $result = $this->storage->validateUpload($tmp, (int) filesize($tmp));
        $this->assertIsString($result);
        unlink($tmp);
    }

    public function testStoreCreatesUserFileAsJpeg(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $result = $this->storage->store(42, $tmp);

        $this->assertSame('42.jpg', $result['storedAs']);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertFileExists($this->dsPath . '/branding/avatars/42.jpg');
        // tmp soubor se po store smaže (rename/unlink).
        $this->assertFileDoesNotExist($tmp);
    }

    public function testStoreReplacesPreviousAvatar(): void
    {
        $tmp1 = $this->tmpFile($this->pngContent());
        $this->storage->store(7, $tmp1);
        $this->assertFileExists($this->dsPath . '/branding/avatars/7.jpg');

        // Simulace staré přípony (z dřívější verze nebo fallback kopie).
        file_put_contents($this->dsPath . '/branding/avatars/7.png', 'stale');

        $tmp2 = $this->tmpFile($this->pngContent());
        $this->storage->store(7, $tmp2);

        $this->assertFileExists($this->dsPath . '/branding/avatars/7.jpg');
        $this->assertFileDoesNotExist($this->dsPath . '/branding/avatars/7.png');
    }

    public function testStoreIsolatesUsers(): void
    {
        $tmpA = $this->tmpFile($this->pngContent());
        $tmpB = $this->tmpFile($this->pngContent());
        $this->storage->store(1, $tmpA);
        $this->storage->store(2, $tmpB);

        $this->assertFileExists($this->dsPath . '/branding/avatars/1.jpg');
        $this->assertFileExists($this->dsPath . '/branding/avatars/2.jpg');

        // Smazání jednoho uživatele nesmí sebrat druhého.
        $this->storage->deleteUserFiles(1);
        $this->assertFileDoesNotExist($this->dsPath . '/branding/avatars/1.jpg');
        $this->assertFileExists($this->dsPath . '/branding/avatars/2.jpg');
    }

    public function testDeleteUserFilesRemovesAllExtensions(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $this->storage->store(99, $tmp);
        file_put_contents($this->dsPath . '/branding/avatars/99.png', 'x');
        file_put_contents($this->dsPath . '/branding/avatars/99.webp', 'y');

        $this->storage->deleteUserFiles(99);

        $this->assertFileDoesNotExist($this->dsPath . '/branding/avatars/99.jpg');
        $this->assertFileDoesNotExist($this->dsPath . '/branding/avatars/99.png');
        $this->assertFileDoesNotExist($this->dsPath . '/branding/avatars/99.webp');
    }
}
