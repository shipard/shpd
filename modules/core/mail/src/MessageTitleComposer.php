<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Deterministický titulek zprávy z canonical návrhu
 * (tasks/mail-message-title-partner.md D2, fáze 2).
 *
 * Používá ho `/result` jako **fallback**, když analyzer
 * `message_classification.title` neposlal (starší prompt / analyzer bez
 * pass-through), a `IsdocImportService` jako **primární zdroj** (ISDOC
 * obchází AI). Tvar:
 *
 *   docs:     `{label typu} {docNumber} — {supplier.name}, {totalAmount} {currency}`
 *             (chybějící části se vynechají; label typu jen s ConfigRuntime —
 *             `core.mail.primaryTypes[type].name`)
 *   registry: `title` canonicalu
 *   bez dokumentu / forenzní wrapper: null
 *
 * Jazyk labelu: titulek je DS-wide data v **jazyce AI profilu** (D2), ne
 * v jazyce requestu — intake od mail-routeru žádný `Accept-Language`
 * nenese a padal by na výchozí jazyk DS (bez `defaultLanguage`
 * v main.json angličtina). Produkční wiring proto jde přes
 * {@see forDataSource()}: compiled config v jazyce profilu běhu, jinak
 * výchozího aktivního profilu DS, fallback výchozí jazyk DS.
 */
final class MessageTitleComposer
{
    /** Délka sloupce `ai_title`. */
    public const MAX_LENGTH = 200;

    private const PROFILES_TABLE = 'core_mail_ai_profiles';

    public function __construct(
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * Composer pro zdroj dat: labely typů v jazyce AI profilu (profil běhu
     * dle `$profileNdx`, jinak výchozí aktivní profil DS), fallback výchozí
     * jazyk DS. Bez compiled configu v daném jazyce → composer bez labelu
     * typu (titulek zůstane smysluplný), jen warning.
     */
    public static function forDataSource(
        DataSourceConnection $db,
        DataSourceConfig $dsConfig,
        ?int $profileNdx = null,
    ): self {
        $language = self::profileLanguage($db, $profileNdx) ?? $dsConfig->getDefaultLanguage();
        try {
            return new self(ConfigRuntime::load($dsConfig->getDataSourceDir(), $language));
        } catch (\Throwable $e) {
            ErrorLogger::warn('MessageTitleComposer: compiled config unavailable — titles without type label', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
            return new self(null);
        }
    }

    /**
     * Jazyk AI profilu: profil běhu (`$profileNdx`), jinak výchozí aktivní
     * profil DS (stejná kritéria jako `AnalysisController::resolveProfile`).
     * Null = profil neexistuje, bez jazyka, nebo DB nedostupná.
     */
    public static function profileLanguage(DataSourceConnection $db, ?int $profileNdx = null): ?string
    {
        try {
            $row = $profileNdx !== null && $profileNdx > 0
                ? $db->fetchRow(
                    'SELECT language FROM %n WHERE id = %i LIMIT 1',
                    self::PROFILES_TABLE, $profileNdx,
                )
                : $db->fetchRow(
                    'SELECT language FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
                    self::PROFILES_TABLE, 1, 1,
                );
        } catch (\Throwable) {
            return null;
        }
        $language = trim((string) ($row['language'] ?? ''));
        return $language !== '' ? $language : null;
    }

    /**
     * @param array<string, mixed>|null $canonical parsovaný canonical návrhu
     * @param string                    $proposedType klíč `core.mail.primaryTypes`
     */
    public function compose(?array $canonical, string $proposedType): ?string
    {
        if ($canonical === null || isset($canonical['_validationError'])) {
            return null;
        }

        if (PrimaryTypes::targetFor($this->config, $proposedType) === PrimaryTypes::TARGET_REGISTRY) {
            return self::clean($canonical['title'] ?? null);
        }

        $party = MessagePartnerWriter::partyOf($canonical, $proposedType, $this->config);

        $head = trim(implode(' ', array_filter([
            $this->typeLabel($proposedType),
            self::clean($canonical['docNumber'] ?? null),
        ], static fn(?string $s): bool => $s !== null && $s !== '')));

        $tail = implode(', ', array_filter([
            self::clean($party['name'] ?? null),
            self::formatAmount($canonical['totals']['totalAmount'] ?? null, $canonical['currency'] ?? null),
        ], static fn(?string $s): bool => $s !== null && $s !== ''));

        $title = match (true) {
            $head !== '' && $tail !== '' => $head . ' — ' . $tail,
            $head !== ''                 => $head,
            $tail !== ''                 => $tail,
            default                      => '',
        };

        return $title === '' ? null : mb_substr($title, 0, self::MAX_LENGTH);
    }

    /**
     * Normalizace titulku (i toho od AI): trim, sjednocení bílých znaků,
     * oříznutí na délku sloupce; prázdné → null.
     */
    public static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $text === '' ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }

    /**
     * Částka s tisícovými mezerami a měnou: `13 105 CZK`, `1 234,50 EUR`.
     * Bez měny jen číslo; nečíselná hodnota → null.
     */
    public static function formatAmount(mixed $amount, mixed $currency): ?string
    {
        if (!is_numeric($amount)) {
            return null;
        }
        $formatted = number_format((float) $amount, 2, ',', ' ');
        $formatted = (string) preg_replace('/,00$/', '', $formatted);

        $code = is_string($currency) ? strtoupper(trim($currency)) : '';
        return $code !== '' ? $formatted . ' ' . $code : $formatted;
    }

    /**
     * Lokalizovaný název typu z `core.mail.primaryTypes` (v jazyce configu,
     * viz forDataSource) — bez configu se label vynechá, titulek zůstane
     * smysluplný.
     */
    private function typeLabel(string $type): ?string
    {
        $types = $this->config?->cfgItem('core.mail.primaryTypes');
        $name = is_array($types) ? ($types[$type]['name'] ?? null) : null;
        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
