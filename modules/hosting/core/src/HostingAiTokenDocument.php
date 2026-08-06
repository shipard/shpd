<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Document třída pro `hosting_core_ai_tokens` (D5).
 *
 * Šifruje `token_encrypted` přes DsSecretCipher v `beforeSave()` — stejná
 * třístavová sémantika jako HostingDataSourceDocument:
 *   - sloupec chybí v $data → UPDATE ho nezahrne
 *   - null nebo ''          → unset (placeholder submit beze změny)
 *   - hodnota               → encrypt; cipher injektuje CLI přes
 *                             setSecretCipher(), HTTP save path se odvodí
 *                             lazy z dsConfig — never silently write plaintext
 *
 * Token (prefix + hash + encrypted plaintext) vydává CLI `hosting-ai-token`
 * nebo lazy mint v queue payloadu; form edituje jen metadata (note, active).
 */
class HostingAiTokenDocument extends Document
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

        if (array_key_exists('token_encrypted', $data)) {
            $value = $data['token_encrypted'];
            if ($value === null || $value === '') {
                unset($data['token_encrypted']);
            } else {
                $data['token_encrypted'] = $this->secretCipher()->encrypt((string) $value);
            }
        }

        if ($isNew && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }

    /**
     * Cipher z CLI injektáže, jinak lazy z dsConfig (HTTP save path —
     * TableGateway injektuje dsConfig, ne cipher).
     */
    private function secretCipher(): DsSecretCipher
    {
        if ($this->cipher !== null) {
            return $this->cipher;
        }
        if ($this->dsConfig !== null) {
            $this->cipher = DsSecretCipher::forConfig($this->dsConfig);
            return $this->cipher;
        }
        throw new \RuntimeException(
            'HostingAiTokenDocument: cannot save an encrypted column '
            . 'without DsSecretCipher. Call setSecretCipher() before saving.',
        );
    }
}
