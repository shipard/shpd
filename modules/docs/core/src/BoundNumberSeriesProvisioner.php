<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní seed řad vázaných na entitu (docTypes[].series_binding).
 *
 * Pro každý typ dokladu se `series_binding` a každou aktivní entitu
 * (pokladna / sklad ve stavu 40) zajistí existenci řady (doc_type, FK)
 * mimo stav Smazáno. Nová řada: název „{typ} — {kód entity}", kód řady
 * (%C) = kód entity, vzorec z `doc_number_pattern_default`, restart per
 * fiskální rok, rovnou V pořádku (40/3). Přejmenování entity se do
 * `doc_number_code` NEpropaguje (záměr, #59 D3) — čísla už vydaných
 * dokladů se nesmí měnit.
 *
 * Běží z `ds-upgrade` i pod skipProvisioning (řady jsou infrastruktura,
 * kterou import dokladů potřebuje — D12) a z CashDeskSeriesEventHandler
 * po uložení pokladny do stavu 40. Vazby jsou obecné přes
 * NumberSeriesDocument::BINDINGS — sklad nevyžaduje žádný další kód.
 */
final class BoundNumberSeriesProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * Všechny vázané typy × všechny aktivní entity.
     *
     * @return array{created: int, existing: int}
     */
    public function provision(): array
    {
        $created = 0;
        $existing = 0;

        foreach ($this->boundDocTypes() as $docTypeKey => [$docType, $binding]) {
            $entities = $this->db->fetchAll(
                'SELECT id, code FROM ' . NumberSeriesDocument::BINDINGS[$binding]['table']
                . ' WHERE docState = %i ORDER BY id',
                40,
            );
            foreach ($entities as $entity) {
                $this->ensureSeries($docTypeKey, $docType, $binding, (int) $entity['id'], (string) $entity['code'])
                    ? $created++
                    : $existing++;
            }
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * Jedna entita (po jejím uložení): řady všech typů s daným bindingem.
     * Entita mimo stav 40 nebo neexistující → no-op.
     *
     * @return array{created: int, existing: int}
     */
    public function provisionForEntity(string $binding, int $entityId): array
    {
        if (!isset(NumberSeriesDocument::BINDINGS[$binding])) {
            throw new \InvalidArgumentException("Unknown series binding '{$binding}'");
        }

        $entity = $this->db->fetchRow(
            'SELECT id, code, docState FROM ' . NumberSeriesDocument::BINDINGS[$binding]['table']
            . ' WHERE id = %i',
            $entityId,
        );
        if ($entity === null || (int) $entity['docState'] !== 40) {
            return ['created' => 0, 'existing' => 0];
        }

        $created = 0;
        $existing = 0;
        foreach ($this->boundDocTypes() as $docTypeKey => [$docType, $typeBinding]) {
            if ($typeBinding !== $binding) {
                continue;
            }
            $this->ensureSeries($docTypeKey, $docType, $binding, $entityId, (string) $entity['code'])
                ? $created++
                : $existing++;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /** @return array{created: int, existing: int} */
    public function provisionForCashDesk(int $cashDeskId): array
    {
        return $this->provisionForEntity('cash_desk', $cashDeskId);
    }

    /**
     * Typy dokladu se známým `series_binding`.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>  key → [docType, binding]
     */
    private function boundDocTypes(): array
    {
        $docTypes = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes)) {
            return [];
        }

        $out = [];
        foreach ($docTypes as $key => $docType) {
            if (!is_string($key) || !is_array($docType)) {
                continue;
            }
            $binding = $docType['series_binding'] ?? null;
            if (!is_string($binding) || !isset(NumberSeriesDocument::BINDINGS[$binding])) {
                continue;
            }
            $out[$key] = [$docType, $binding];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $docType
     * @return bool true = řada vytvořena, false = už existovala
     */
    private function ensureSeries(string $docTypeKey, array $docType, string $binding, int $entityId, string $code): bool
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series
             WHERE doc_type = %s AND ' . $binding . ' = %i AND docState != %i
             LIMIT 1',
            $docTypeKey,
            $entityId,
            90,
        );
        if ($row !== null) {
            return false;
        }

        $typeName = (string) ($docType['name:cs'] ?? $docType['name'] ?? $docTypeKey);
        $code     = trim($code);
        $name     = $code !== '' ? "{$typeName} — {$code}" : $typeName;
        $pattern  = (string) ($docType['doc_number_pattern_default'] ?? '%D%C%y%5');

        $this->db->insertRow('docs_core_number_series', [
            'doc_type'           => $docTypeKey,
            $binding             => $entityId,
            'name'               => mb_substr($name, 0, 100),
            'doc_number_code'    => $code !== '' ? $code : null,
            'doc_number_pattern' => $pattern,
            'reset_scope'        => 'fiscal_year',
            'docState'           => 40,
            'docStateMain'       => 3,
        ]);
        return true;
    }
}
