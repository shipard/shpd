<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `hosting_core_servers`.
 *
 * `provision_default` (hosting-08, D1): výchozí server pro self-service
 * zakládání DS z portálu. Smí ho mít jen server s `can_provision`; nejvýše
 * jeden — uložení řádku s příznakem shodí příznak všem ostatním (poslední
 * uložený vyhrává, bez chybové hlášky). Existence default serveru = zapnutí
 * self-service.
 */
class HostingServerDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (!empty($data['provision_default']) && empty($data['can_provision'])) {
            $result->addError(
                'provision_default',
                'Výchozí server pro nové DS musí mít povoleno zakládání DS.',
                'INVALID',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        if (empty($data['id']) && empty($data['created'])) {
            $data['created'] = $now;
        }
        $data['modified'] = $now;
    }

    public function afterPersist(array $data): void
    {
        // Jediný default: běží uvnitř transakce save, aby nikdy neexistovaly
        // dva default servery současně.
        if (empty($data['provision_default']) || empty($data['id'])) {
            return;
        }

        $this->clearOtherDefaults((int) $data['id']);
    }

    protected function clearOtherDefaults(int $id): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->query(
            'UPDATE [hosting_core_servers] SET [provision_default] = 0
             WHERE [provision_default] = 1 AND [id] != %i',
            $id,
        );
    }
}
