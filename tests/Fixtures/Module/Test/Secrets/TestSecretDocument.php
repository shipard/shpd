<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Test\Secrets;

use Shipard\Core\Document\DefaultDocument;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Canary Document — exercises the encrypted_text save/load pattern from
 * tasks/ds-encrypted-secrets.md §7.1 / §7.2. Used only by
 * tests/Unit/Core/Security/CanaryTableTest.php.
 */
class TestSecretDocument extends DefaultDocument
{
    private DsSecretCipher $cipher;

    public function setCipher(DsSecretCipher $cipher): void
    {
        $this->cipher = $cipher;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        parent::beforeSave($data);

        if (array_key_exists('api_key', $data)
            && $data['api_key'] !== null
            && $data['api_key'] !== ''
        ) {
            $data['api_key'] = $this->cipher->encrypt($data['api_key']);
        }
    }

    /**
     * Mimics the controller-side decryption pattern from §7.2 — call this
     * with a row freshly loaded from the database to obtain plaintext.
     */
    public function decryptApiKey(array $data): ?string
    {
        if (!array_key_exists('api_key', $data) || $data['api_key'] === null) {
            return null;
        }
        return $this->cipher->decrypt((string) $data['api_key']);
    }
}
