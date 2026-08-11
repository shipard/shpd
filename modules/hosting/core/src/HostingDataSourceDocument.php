<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Utils\IdGenerator;

/**
 * Document třída pro `hosting_core_data_sources`.
 *
 * Zodpovědnosti navíc proti DefaultDocument:
 *
 * 1. Šifrování `oidc_client_secret` a `mail_token` přes DsSecretCipher
 *    v `beforeSave()` (vzor AIBackendDocument, viz docs/operations/secrets.md):
 *      - sloupec chybí v $data      → UPDATE ho nezahrne
 *      - null nebo ''               → unset (placeholder submit beze změny)
 *      - hodnota                    → encrypt; cipher injektuje CLI přes
 *                                     setSecretCipher(), HTTP save path se
 *                                     odvodí lazy z dsConfig — never
 *                                     silently write plaintext
 *
 * 2. Požadavek na nový DS (D3): insert s `lifecycle = request` vygeneruje
 *    prázdné `ds_id` (formát IdGeneratoru, unikátnost proti evidenci),
 *    `oidc_client_secret` a z `web_id` + settingu `hosting.baseDomain`
 *    odvodí `url_app` + `oidc_redirect_uri`. Validace vyžaduje web_id,
 *    server, install_module a owner (aktivní uživatel hostingu — U1).
 */
class HostingDataSourceDocument extends Document
{
    private ?DsSecretCipher $cipher = null;

    public function setSecretCipher(DsSecretCipher $cipher): void
    {
        $this->cipher = $cipher;
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $isNew = empty($data['id']);
        if (!$isNew || (string) ($data['lifecycle'] ?? '') !== 'request') {
            return $result;
        }

        foreach (['web_id' => 'Web ID', 'install_module' => 'Install modul'] as $column => $label) {
            if (trim((string) ($data[$column] ?? '')) === '') {
                $result->addError($column, "{$label} je pro požadavek povinný.", 'REQUIRED');
            }
        }
        if (empty($data['server'])) {
            $result->addError('server', 'Server je pro požadavek povinný.', 'REQUIRED');
        }

        if (empty($data['owner'])) {
            $result->addError('owner', 'Vlastník je pro požadavek povinný.', 'REQUIRED');
        } elseif ($this->db !== null) {
            $owner = $this->db->fetchSingle(
                'SELECT id FROM core_system_users WHERE id = %i AND is_active = 1',
                (int) $data['owner'],
            );
            if ($owner === null || $owner === false) {
                $result->addError('owner', 'Vlastník musí být existující aktivní uživatel.', 'INVALID');
            }
        }

        // beforeSave odvozuje url_app z web_id + hosting.baseDomain — bez
        // settingu by request vznikl s nesmyslnou URL.
        if (trim((string) ($data['url_app'] ?? '')) === '' && $this->settingValue('hosting.baseDomain') === null) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Nastavení hosting.baseDomain není vyplněné — bez něj nelze odvodit URL aplikace.',
                'MISSING_BASE_DOMAIN',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        // Příprava požadavku: nový řádek ve stavu request, NEBO přechod
        // existujícího řádku do request s prázdným ds_id (= řádek založený
        // omylem v jiném stavu; adoptované DS mají ds_id vždy vyplněné).
        $wantsRequest = (string) ($data['lifecycle'] ?? '') === 'request';
        $dsIdEmpty = (string) ($data['ds_id'] ?? ($originalData['ds_id'] ?? '')) === '';
        if ($wantsRequest && ($isNew || $dsIdEmpty)) {
            $this->prepareRequest($data);
        }

        foreach (['oidc_client_secret', 'mail_token'] as $secretColumn) {
            if (array_key_exists($secretColumn, $data)) {
                $value = $data[$secretColumn];
                if ($value === null || $value === '') {
                    unset($data[$secretColumn]);
                } else {
                    $data[$secretColumn] = $this->secretCipher()->encrypt((string) $value);
                }
            }
        }

        if ($isNew && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }

    /**
     * Doplní generovaná pole nového požadavku (D3) — respektuje předvyplněné
     * hodnoty, generuje jen prázdné.
     */
    private function prepareRequest(array &$data): void
    {
        if (trim((string) ($data['ds_id'] ?? '')) === '') {
            $data['ds_id'] = $this->generateUniqueDsId();
        }

        if (($data['oidc_client_secret'] ?? '') === '' || $data['oidc_client_secret'] === null) {
            // Stejný formát jako hosting-oidc-client --generate (43 znaků).
            $data['oidc_client_secret'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        if (trim((string) ($data['url_app'] ?? '')) === '') {
            $baseDomain = trim((string) ($this->settingValue('hosting.baseDomain') ?? ''));
            if ($baseDomain === '') {
                // validate() to hlásí uživateli; sem se lze dostat jen mimo
                // standardní save flow.
                throw new \RuntimeException(
                    'HostingDataSourceDocument: hosting.baseDomain setting is required to derive url_app.',
                );
            }
            $data['url_app'] = 'https://' . trim((string) ($data['web_id'] ?? '')) . '.' . $baseDomain;
        }

        if (trim((string) ($data['oidc_redirect_uri'] ?? '')) === '') {
            $data['oidc_redirect_uri'] = rtrim((string) $data['url_app'], '/') . '/api/v1/_auth/oidc/callback';
        }
    }

    private function generateUniqueDsId(): string
    {
        do {
            $id = IdGenerator::randomId();
            $existing = $this->db?->fetchSingle(
                'SELECT id FROM hosting_core_data_sources WHERE ds_id = %s',
                $id,
            );
        } while ($existing !== null && $existing !== false);

        return $id;
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
            'HostingDataSourceDocument: cannot save an encrypted column '
            . 'without DsSecretCipher. Call setSecretCipher() before saving.',
        );
    }

    /** Hodnota settings klíče přes dibi — Document nemá DataSourceConnection. */
    private function settingValue(string $key): mixed
    {
        if ($this->db === null) {
            return null;
        }
        $raw = $this->db->fetchSingle(
            'SELECT `value` FROM `core_system_settings` WHERE `key` = %s',
            $key,
        );
        return is_string($raw) ? json_decode($raw, true) : null;
    }
}
