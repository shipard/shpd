<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Reverz **číslo účtu → obsahový štítek** pro zpracování účetní historie
 * (`tasks/booking-history-import.md`, Scope 3).
 *
 * Zdrojem je nabídka účetních položek dané varianty osnovy
 * ({@see AccountingItemsOffer::tagsByAccount()}) — tedy tentýž zdroj, ze
 * kterého čerpají reverzní návrhy v Nastavení (D15/D27). Rozdíl je
 * **prefixová tolerance**: cizí systémy vedou vlastní analytiky
 * (`518202`), které v nabídce nejsou, ale jejich syntetika (`518`) ano.
 * Proto:
 *
 *  1. přesná shoda čísla účtu — nese-li účet v nabídce právě jeden štítek,
 *  2. jinak shoda syntetiky (první 3 číslice), pokud je napříč nabídkou
 *     jednoznačná,
 *  3. jinak žádný štítek — a to poctivě rozlišeně (kolizní účet vs. účet
 *     mimo nabídku), aby report ukázal, co za tím je.
 *
 * **Sanity check názvů (D36).** Na `chartVariant: unknown` je přesná shoda
 * čísla slabší signál, než se zdá: analytiky si každý systém vede po svém.
 * Pilotní export vedl pod `518201`–`518203` finanční leasing, kde naše
 * nabídka má telefon / internet / poštovné — přesná shoda tam vyráběla
 * falešné štítky. Se `strictNames` se proto přesná shoda přijme jen tehdy,
 * když se název položky záznamu podobá názvu položky nabídky (práh
 * {@see NAME_SIMILARITY_MIN}); jinak se výsledek počítá dál, **jako by
 * přesná shoda nebyla** (degradace na syntetickou úroveň), a nese flag
 * `degradedExact`. Záznam bez názvu položky degraduje také — nemáme čím
 * ověřit.
 *
 * Degradace tedy nemusí končit bez štítku: když je syntetika jednoznačná,
 * vyjde tentýž štítek jako `synthetic`. To je záměr — syntetická úroveň je
 * hrubší, ale legitimní signál (`503xxx` = pohonné hmoty bez ohledu na
 * analytiku).
 *
 * U deklarované osnovy (`default`/`npo`) se kontrola nespouští: kdo osnovu
 * pojmenoval, tomu analytiky věříme.
 *
 * Tolerance i kontrola názvů jsou vědomě jen pro **zdrojový soubor**.
 * Otagování živých položek DS (`--tag-items=offer`, D34) jede na přesnou
 * shodu: účty položek pocházejí z naší osnovy.
 */
final class AccountTagMap
{
    /** Minimální podobnost názvů (similar_text), aby přesná shoda prošla. */
    public const NAME_SIMILARITY_MIN = 0.5;

    /** Kratší normalizovaný název se na podřetězec neporovnává (šum). */
    private const SUBSTRING_MIN_LENGTH = 3;

    /** Kratší slova se do tokenové shody nepočítají (spojky, čísla). */
    private const TOKEN_MIN_LENGTH = 4;

    /** Délka porovnávaného prefixu tokenu — pokrývá české skloňování. */
    private const TOKEN_PREFIX = 5;

    /** @var array<string, list<string>> číslo účtu → štítky (z nabídky) */
    private array $byAccount;

    /** @var array<string, list<string>> syntetika (3 číslice) → štítky */
    private array $bySynthetic;

    /** @var array<string, array<string, list<string>>> účet → štítek → normalizované názvy */
    private array $namesByAccount = [];

    /**
     * @param array<string, list<string>> $byAccount
     * @param array<string, array<string, list<string>>> $namesByAccount účet → štítek → názvy z nabídky
     */
    public function __construct(
        array $byAccount,
        array $namesByAccount = [],
        private readonly bool $strictNames = false,
    ) {
        $this->byAccount = $byAccount;

        $bySynthetic = [];
        foreach ($byAccount as $account => $tags) {
            $synthetic = self::synthetic((string) $account);
            if ($synthetic === null) {
                continue;
            }
            foreach ($tags as $tag) {
                if (!in_array($tag, $bySynthetic[$synthetic] ?? [], true)) {
                    $bySynthetic[$synthetic][] = $tag;
                }
            }
        }
        $this->bySynthetic = $bySynthetic;

        // Názvy se normalizují jednou při stavbě mapy — resolve() běží nad
        // každým záznamem souboru, normalizace v něm by se tisíckrát opakovala.
        foreach ($namesByAccount as $account => $byTag) {
            foreach ($byTag as $tag => $names) {
                foreach ($names as $name) {
                    $normalized = self::normalizeName($name);
                    if ($normalized !== ''
                        && !in_array($normalized, $this->namesByAccount[$account][$tag] ?? [], true)
                    ) {
                        $this->namesByAccount[(string) $account][(string) $tag][] = $normalized;
                    }
                }
            }
        }
    }

    /**
     * @param bool $strictNames zapnout kontrolu názvů (D36) — volá se s
     *        `true` při `chartVariant: unknown`
     */
    public static function fromOffer(
        AccountingItemsOffer $offer,
        string $variant,
        bool $strictNames = false,
    ): self {
        return new self(
            $offer->tagsByAccount($variant),
            $strictNames ? $offer->namesByAccountTag($variant) : [],
            $strictNames,
        );
    }

    public function isEmpty(): bool
    {
        return $this->byAccount === [];
    }

    public function strictNames(): bool
    {
        return $this->strictNames;
    }

    /**
     * Štítky, které nabídka váže přímo na tohle číslo účtu (bez syntetiky).
     * Report jimi u degradovaných shod ukazuje, co by se bylo naštítkovalo.
     *
     * @return list<string>
     */
    public function tagsForAccount(string $account): array
    {
        return $this->byAccount[trim($account)] ?? [];
    }

    /**
     * @param ?string $itemName název položky ze záznamu — vstup sanity checku
     *        (D36); bez něj přesná shoda při `strictNames` neprojde
     */
    public function resolve(?string $account, ?string $itemName = null): AccountTagMatch
    {
        $account = $account !== null ? trim($account) : '';
        if ($account === '') {
            return new AccountTagMatch(null, AccountTagMatch::KIND_NO_ACCOUNT);
        }

        $degradedExact = false;
        $exact = $this->byAccount[$account] ?? null;
        if ($exact !== null && count($exact) === 1) {
            if (!$this->strictNames || $this->nameMatches($account, $exact[0], $itemName)) {
                return new AccountTagMatch($exact[0], AccountTagMatch::KIND_EXACT);
            }
            // Přesná shoda zamítnuta názvem — dál se počítá, jako by nebyla.
            $degradedExact = true;
        } elseif ($exact !== null) {
            // Kolizní účet zůstává kolizní — syntetika by ho „rozhodla"
            // náhodou, ne informací. Názvy tu nemají co ověřovat (bez štítku).
            return new AccountTagMatch(null, AccountTagMatch::KIND_AMBIGUOUS, $exact);
        }

        $synthetic = self::synthetic($account);
        $tags = $synthetic !== null ? ($this->bySynthetic[$synthetic] ?? []) : [];
        if (count($tags) === 1) {
            return new AccountTagMatch($tags[0], AccountTagMatch::KIND_SYNTHETIC, $tags, $degradedExact);
        }
        return $tags !== []
            ? new AccountTagMatch(null, AccountTagMatch::KIND_AMBIGUOUS, $tags, $degradedExact)
            : new AccountTagMatch(null, AccountTagMatch::KIND_UNMAPPED, [], $degradedExact);
    }

    /**
     * Podobá se název položky ze záznamu názvu položky nabídky?
     *
     * Nabídka nemusí u účtu názvy mít (mapa postavená bez nich) — pak není
     * co ověřovat a kontrola projde. Chybějící název **v záznamu** je proti
     * tomu skutečné selhání: neumíme potvrdit, že cizí analytika znamená
     * totéž co naše.
     */
    private function nameMatches(string $account, string $tag, ?string $itemName): bool
    {
        $offerNames = $this->namesByAccount[$account][$tag] ?? [];
        if ($offerNames === []) {
            return true;
        }

        $needle = self::normalizeName((string) $itemName);
        if ($needle === '') {
            return false;
        }

        foreach ($offerNames as $offerName) {
            if (mb_strlen($needle) >= self::SUBSTRING_MIN_LENGTH
                && mb_strlen($offerName) >= self::SUBSTRING_MIN_LENGTH
                && (str_contains($offerName, $needle) || str_contains($needle, $offerName))
            ) {
                return true;
            }
            similar_text($offerName, $needle, $percent);
            if ($percent / 100 >= self::NAME_SIMILARITY_MIN) {
                return true;
            }
            if (self::tokenOverlap($offerName, $needle) >= self::NAME_SIMILARITY_MIN) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tokenová podobnost — podíl slov, která mají v druhém názvu protějšek
     * se stejným pětiznakovým prefixem.
     *
     * Proč vedle `similar_text`: ta porovnává znaky v pořadí, takže
     * „Připojení k internetu" × „Internetové připojení" spadne pod práh,
     * i když jde zřejmě o totéž. Prefix místo přesné shody slova pokrývá
     * skloňování („internetu" × „internetové"). Bere se maximum z obou
     * směrů, aby delší popisný název neztrácel body za slova navíc.
     */
    private static function tokenOverlap(string $a, string $b): float
    {
        $tokensA = self::tokens($a);
        $tokensB = self::tokens($b);
        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $matchedA = 0;
        foreach ($tokensA as $tokenA) {
            foreach ($tokensB as $tokenB) {
                if (self::tokensAlike($tokenA, $tokenB)) {
                    $matchedA++;
                    break;
                }
            }
        }
        $matchedB = 0;
        foreach ($tokensB as $tokenB) {
            foreach ($tokensA as $tokenA) {
                if (self::tokensAlike($tokenA, $tokenB)) {
                    $matchedB++;
                    break;
                }
            }
        }

        return max($matchedA / count($tokensA), $matchedB / count($tokensB));
    }

    private static function tokensAlike(string $a, string $b): bool
    {
        return mb_substr($a, 0, self::TOKEN_PREFIX) === mb_substr($b, 0, self::TOKEN_PREFIX);
    }

    /** @return list<string> slova délky aspoň TOKEN_MIN_LENGTH */
    private static function tokens(string $normalized): array
    {
        $tokens = [];
        foreach (explode(' ', $normalized) as $token) {
            if (mb_strlen($token) >= self::TOKEN_MIN_LENGTH) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /**
     * Názvy na srovnatelný tvar: bez diakritiky, lowercase, bez interpunkce,
     * jedna mezera mezi slovy. Diakritika ručně, ne přes iconv//TRANSLIT —
     * ten je závislý na locale a umí vracet „?" i „'a".
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = strtr($name, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
            'ß' => 'ss', 'ł' => 'l', 'ą' => 'a', 'ę' => 'e', 'ć' => 'c', 'ś' => 's',
            'ź' => 'z', 'ż' => 'z', 'ô' => 'o', 'ĺ' => 'l', 'ŕ' => 'r',
        ]);
        $name = (string) preg_replace('/[^a-z0-9]+/u', ' ', $name);
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /** První 3 číslice čísla účtu, nebo null když číslo tak nevypadá. */
    public static function synthetic(string $account): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $account);
        return strlen($digits) >= 3 ? substr($digits, 0, 3) : null;
    }
}
