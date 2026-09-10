<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\StructuredFields\StructuredSchema;

/**
 * Hlavička podání DPH — výběr schématu per typ tvrzení a předvyplnění
 * jejích hodnot (issue #55, Fáze 3, X2/X15).
 *
 * Schéma se liší podle toho, co která písemnost ve větě P a D žádá:
 * přiznání má navíc skupinu „Údaje o podání" (`typ_platce`, `c_okec`,
 * `trans`, `kod_zo`, `d_por_dod`), souhrnné hlášení nemá kontakt, ale má
 * dodatek obchodního jména. Výběr je **jedna statická metoda**, aby ho
 * dokumentová i formulářová vrstva sdílely — `Document::structuredSchemaFor()`
 * a `TableForm::structuredSchemaFor()` musí odpovědět stejně, jinak se
 * formulář kreslí podle jiné sady polí, než se ukládá
 * (docs/structured-fields.md § 5).
 *
 * Předvyplnění řídí **schéma, ne seznam v kódu**: kopírují se jen ty klíče
 * profilu podatele, které cílové schéma zná. Kontrolní hlášení tak nikdy
 * nedostane `c_okec` a souhrnné `email`, aniž by to tu bylo vyjmenované.
 *
 * Čistá třída bez DB — hodnoty dodá volající (`FilingComposer`).
 */
final class FilingHeaderSchema
{
    public const COLUMN = 'header';

    /** Typ tvrzení (`economy.vat.reportTypes`) → cfgItem schématu hlavičky. */
    public const CFG_ITEM_BY_TYPE = [
        'return' => 'economy.vat.filingHeaderCzDp3',
        'cs'     => 'economy.vat.filingHeaderCzKh1',
        'rs'     => 'economy.vat.filingHeaderCzShv',
    ];

    /**
     * Klíče profilu podatele, které do hlavičky nepatří ani tehdy, když je
     * schéma zná pod stejným jménem. Dnes prázdné — profil a hlavička se
     * překrývají čistě; konstanta je tu jako pojmenované místo pro výjimku.
     *
     * @var list<string>
     */
    private const PROFILE_EXCLUDED = [];

    /**
     * cfgItem schématu pro typ tvrzení; `null` = neznámý typ, ať rozhodne
     * statický atribut sloupce (přiznání).
     */
    public static function forReportType(mixed $reportType): ?string
    {
        $type = is_string($reportType) ? $reportType : '';
        return self::CFG_ITEM_BY_TYPE[$type] ?? null;
    }

    /**
     * Hodnoty hlavičky nového podání: profil podatele → věta P, identita
     * z vlastní firmy, DIČ z registrace, defaulty věty D.
     *
     * Vrací jen klíče, které schéma zná a které mají hodnotu — zbytek
     * zůstane prázdný, aby uživatel viděl, co musí doplnit.
     *
     * @param array<string, mixed> $profile     `filing_profile` registrace
     * @param array<string, mixed> $identity    `zkrobchjm` / `prijmeni` / `jmeno` / `titul` z vlastní firmy
     * @param array<string, mixed> $filingDefaults `dic`, `trans`, … per podání
     * @return array<string, mixed>
     */
    public static function prefill(
        StructuredSchema $schema,
        array $profile,
        array $identity,
        array $filingDefaults,
    ): array {
        $values = [];

        foreach (array_keys($schema->fields) as $fieldId) {
            if (in_array($fieldId, self::PROFILE_EXCLUDED, true)) {
                continue;
            }
            $value = $profile[$fieldId] ?? null;
            if (self::hasValue($value)) {
                $values[$fieldId] = $value;
            }
        }

        // Identita subjektu a údaje o podání profil nenese — přebijí ho
        // (u fyzické osoby zůstane obchodní jméno prázdné a naopak, to řeší
        // volající podle `typ_ds`).
        foreach ([$identity, $filingDefaults] as $source) {
            foreach ($source as $fieldId => $value) {
                if ($schema->field($fieldId) === null || !self::hasValue($value)) {
                    continue;
                }
                $values[$fieldId] = $value;
            }
        }

        // Defaulty schématu za to, co nikdo nedodal (`typ_platce` = P).
        foreach ($schema->fields as $fieldId => $field) {
            if (!array_key_exists($fieldId, $values) && self::hasValue($field->default)) {
                $values[$fieldId] = $field->default;
            }
        }

        return $values;
    }

    /** `false` a `0` jsou hodnoty, prázdný řetězec a null ne. */
    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
