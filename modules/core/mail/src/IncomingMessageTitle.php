<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Lidský titulek došlé zprávy — pravidlo D3
 * (tasks/mail-message-title-partner.md).
 *
 * `subject` je RFC822 hlavička a nepřepisuje se (D1); `ai_title` je
 * AI-vlastněný titulek odvozený z obsahu. Zobrazí se **místo** předmětu jen
 * když předmět nic neříká:
 *
 *   - předmět je prázdný,
 *   - zpráva vznikla ručně (`source_type = 1` — ruční pořízení / nahrání
 *     z počítače; předmět je typicky název souboru),
 *   - předmět odpovídá některému vzoru cfgItem
 *     `core.mail.genericSubjectPatterns` (skenery, MFP, automatické názvy).
 *
 * U běžného e-mailu uživatel hledá podle předmětu, který zná ze svého
 * klienta — ten zůstává. Detekce skenu jde výhradně přes vzory, nikdy přes
 * `source_type` e-mailu (skener posílá běžný e-mail, P1). Čistá třída bez
 * DB — používá ji viewer, formulář (`header_info`), Spisovna (název
 * dokumentu ze zprávy) a všude, kde se dnes bere `subject` jako lidský
 * název zprávy.
 */
final class IncomingMessageTitle
{
    public const CFG_ITEM = 'core.mail.genericSubjectPatterns';

    /** `source_type` ručně pořízené / nahrané zprávy (IncomingMessageDocument). */
    public const SOURCE_MANUAL = 1;

    /** Vzory, které už selhaly kompilací — warn jednou per proces. */
    private static array $warnedPatterns = [];

    /**
     * Titulek k zobrazení. Bez `ai_title` vždy předmět (i prázdný —
     * fallback „(bez předmětu)" řeší volající).
     *
     * @param list<string> $patterns PCRE bez delimiterů ({@see patternsFrom()})
     */
    public static function display(string $subject, ?string $aiTitle, int $sourceType, array $patterns): string
    {
        $subject = trim($subject);
        return self::usesAiTitle($subject, $aiTitle, $sourceType, $patterns)
            ? trim((string) $aiTitle)
            : $subject;
    }

    /**
     * True, když `display()` vrátí `ai_title` místo předmětu — UI pak
     * původní předmět ukáže v technických údajích.
     *
     * @param list<string> $patterns
     */
    public static function usesAiTitle(string $subject, ?string $aiTitle, int $sourceType, array $patterns): bool
    {
        if (trim((string) $aiTitle) === '') {
            return false;
        }
        $subject = trim($subject);
        return $subject === ''
            || $sourceType === self::SOURCE_MANUAL
            || self::isGeneric($subject, $patterns);
    }

    /**
     * Předmět odpovídá některému generickému vzoru (case-insensitive,
     * unicode). Vadný vzor se přeskočí s jednorázovým warningem — chybná
     * konfigurace nesmí shodit seznam zpráv.
     *
     * @param list<string> $patterns
     */
    public static function isGeneric(string $subject, array $patterns): bool
    {
        $subject = trim($subject);
        if ($subject === '') {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            $regex = '~' . str_replace('~', '\~', $pattern) . '~iu';
            $match = @preg_match($regex, $subject);
            if ($match === false) {
                if (!isset(self::$warnedPatterns[$pattern])) {
                    self::$warnedPatterns[$pattern] = true;
                    ErrorLogger::warn('IncomingMessageTitle: invalid generic subject pattern skipped', [
                        'pattern' => $pattern,
                        'error' => preg_last_error_msg(),
                    ]);
                }
                continue;
            }
            if ($match === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vzory z cfgItem `core.mail.genericSubjectPatterns` (pole řetězců);
     * bez compiled configu prázdné — titulek se pak uplatní jen u prázdného
     * předmětu a ručních zpráv.
     *
     * @return list<string>
     */
    public static function patternsFrom(?ConfigRuntime $config): array
    {
        $cfg = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($cfg)) {
            return [];
        }
        return array_values(array_filter($cfg, static fn(mixed $p): bool => is_string($p) && $p !== ''));
    }
}
