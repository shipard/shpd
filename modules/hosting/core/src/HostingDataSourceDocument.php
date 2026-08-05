<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Document třída pro `hosting_core_data_sources`.
 *
 * Jediná zodpovědnost navíc proti DefaultDocument: šifrování
 * `oidc_client_secret` přes DsSecretCipher v `beforeSave()` (vzor
 * AIBackendDocument, viz docs/operations/secrets.md):
 *   - sloupec chybí v $data      → UPDATE ho nezahrne
 *   - null nebo ''               → unset (placeholder submit beze změny)
 *   - hodnota                    → encrypt; bez injektovaného cipheru výjimka
 *                                  — never silently write plaintext
 */
class HostingDataSourceDocument extends Document
{
    private ?DsSecretCipher $cipher = null;

    public function setSecretCipher(DsSecretCipher $cipher): void
    {
        $this->cipher = $cipher;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if (array_key_exists('oidc_client_secret', $data)) {
            $value = $data['oidc_client_secret'];
            if ($value === null || $value === '') {
                unset($data['oidc_client_secret']);
            } else {
                if ($this->cipher === null) {
                    throw new \RuntimeException(
                        'HostingDataSourceDocument: cannot save oidc_client_secret '
                        . 'without DsSecretCipher. Call setSecretCipher() before saving.',
                    );
                }
                $data['oidc_client_secret'] = $this->cipher->encrypt((string) $value);
            }
        }

        if ($isNew && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }
}
