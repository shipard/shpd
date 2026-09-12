<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class VatRegistrationDocument extends Document
{
    public const TABLE = 'economy_codebooks_vat_registrations';

    /** Hodnoty musí korespondovat s `economy.codebooks.vatTaxpayerKinds` cfgItem. */
    private const VALID_TAXPAYER_KINDS = [0, 1];

    /** Hodnoty musí korespondovat s `economy.codebooks.vatPeriodKinds` cfgItem. */
    private const VALID_PERIOD_KINDS = [1, 2];

    /** Živé stavy osoby (`core.system.docStatesArchive`) — správce daně musí být jedna z nich. */
    private const PERSON_LIVE_STATES = [10, 40, 80];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        // Uložení může být částečné: správce daně smí přibýt i k registraci
        // ve stavu V pořádku a formulář ho pak posílá samotný
        // (TableForm::getReadOnlyEditableColumns). Co payload nenese, drží
        // uložený řádek — bez merge by částečná aktualizace padala na
        // povinných polích.
        $current   = !empty($data['id']) ? $this->loadCurrent((int) $data['id']) : null;
        $effective = array_merge($current ?? [], $data);

        if (empty($effective['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }
        if (empty($effective['region'])) {
            $result->addError('region', 'Region je povinný', 'required');
        }
        if (empty($effective['country'])) {
            $result->addError('country', 'Země je povinná', 'required');
        }

        $taxpayerKind = $effective['taxpayer_kind'] ?? null;
        if ($taxpayerKind === null || $taxpayerKind === '') {
            $result->addError('taxpayer_kind', 'Druh plátce je povinný', 'required');
        } elseif (!in_array((int) $taxpayerKind, self::VALID_TAXPAYER_KINDS, true)) {
            $result->addError('taxpayer_kind', 'Neznámý druh plátce', 'invalid_value');
        }

        $taxPeriodKind = $effective['tax_period_kind'] ?? null;
        if ($taxPeriodKind === null || $taxPeriodKind === '') {
            $result->addError('tax_period_kind', 'Frekvence přiznání DPH je povinná', 'required');
        } elseif (!in_array((int) $taxPeriodKind, self::VALID_PERIOD_KINDS, true)) {
            $result->addError('tax_period_kind', 'Neplatná frekvence přiznání DPH', 'invalid_value');
        }

        $csPeriodKind = $effective['cs_period_kind'] ?? null;
        if ($csPeriodKind === null || $csPeriodKind === '') {
            $result->addError('cs_period_kind', 'Frekvence kontrolního hlášení je povinná', 'required');
        } elseif (!in_array((int) $csPeriodKind, self::VALID_PERIOD_KINDS, true)) {
            $result->addError('cs_period_kind', 'Neplatná frekvence kontrolního hlášení', 'invalid_value');
        }

        $rsPeriodKind = $effective['rs_period_kind'] ?? null;
        if ($rsPeriodKind === null || $rsPeriodKind === '') {
            $result->addError('rs_period_kind', 'Frekvence souhrnného hlášení je povinná', 'required');
        } elseif (!in_array((int) $rsPeriodKind, self::VALID_PERIOD_KINDS, true)) {
            $result->addError('rs_period_kind', 'Neplatná frekvence souhrnného hlášení', 'invalid_value');
        }

        $validFrom = self::isoDate($effective['valid_from'] ?? null);
        $validTo   = self::isoDate($effective['valid_to'] ?? null);
        if ($validFrom === null) {
            $result->addError('valid_from', 'Začátek platnosti je povinný', 'required');
        }
        if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
            $result->addError(
                'valid_to',
                'Konec platnosti musí být později nebo stejný den jako začátek.',
                'invalid_range',
            );
        }

        // Správce daně (#55 D30): ručně vybraná živá osoba. Kontroluje se
        // jen hodnota z payloadu — uložený odkaz na mezitím smazanou osobu
        // nemá blokovat jiné úpravy registrace.
        if (array_key_exists('tax_office_person', $data)
            && $data['tax_office_person'] !== null
            && $data['tax_office_person'] !== ''
        ) {
            $personId = (int) $data['tax_office_person'];
            if ($personId <= 0 || !$this->personIsLive($personId)) {
                $result->addError('tax_office_person', 'Správce daně musí být existující osoba.', 'invalid_value');
            }
        }

        return $result;
    }

    /** Datum v ISO tvaru — DB vrací DateTime, formulář řetězec; prázdné = null. */
    private static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return ?array<string, mixed> */
    protected function loadCurrent(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
        return $row !== null ? $row->toArray() : null;
    }

    /** Bez DB (unit test bez seamu) se osoba nekontroluje. */
    protected function personIsLive(int $personId): bool
    {
        if ($this->db === null) {
            return true;
        }
        $state = $this->db->fetchSingle('SELECT [docState] FROM [base_persons_persons] WHERE [id] = %i', $personId);
        return $state !== null && $state !== false
            && in_array((int) $state, self::PERSON_LIVE_STATES, true);
    }
}
