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
 * Tolerance je vědomě jen pro **zdrojový soubor**. Otagování živých položek
 * DS (`--tag-items`, D34) jede na přesnou shodu: účty položek pocházejí
 * z naší osnovy, takže syntetika nic nepřidá a zápis do dat uživatele na
 * slabší signál nepatří.
 */
final class AccountTagMap
{
    /** @var array<string, list<string>> číslo účtu → štítky (z nabídky) */
    private array $byAccount;

    /** @var array<string, list<string>> syntetika (3 číslice) → štítky */
    private array $bySynthetic;

    /** @param array<string, list<string>> $byAccount */
    public function __construct(array $byAccount)
    {
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
    }

    public static function fromOffer(AccountingItemsOffer $offer, string $variant): self
    {
        return new self($offer->tagsByAccount($variant));
    }

    public function isEmpty(): bool
    {
        return $this->byAccount === [];
    }

    public function resolve(?string $account): AccountTagMatch
    {
        $account = $account !== null ? trim($account) : '';
        if ($account === '') {
            return new AccountTagMatch(null, AccountTagMatch::KIND_NO_ACCOUNT);
        }

        $exact = $this->byAccount[$account] ?? null;
        if ($exact !== null) {
            // Kolizní účet zůstává kolizní — syntetika by ho „rozhodla"
            // náhodou, ne informací.
            return count($exact) === 1
                ? new AccountTagMatch($exact[0], AccountTagMatch::KIND_EXACT)
                : new AccountTagMatch(null, AccountTagMatch::KIND_AMBIGUOUS, $exact);
        }

        $synthetic = self::synthetic($account);
        $tags = $synthetic !== null ? ($this->bySynthetic[$synthetic] ?? []) : [];
        if (count($tags) === 1) {
            return new AccountTagMatch($tags[0], AccountTagMatch::KIND_SYNTHETIC, $tags);
        }
        return $tags !== []
            ? new AccountTagMatch(null, AccountTagMatch::KIND_AMBIGUOUS, $tags)
            : new AccountTagMatch(null, AccountTagMatch::KIND_UNMAPPED);
    }

    /** První 3 číslice čísla účtu, nebo null když číslo tak nevypadá. */
    public static function synthetic(string $account): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $account);
        return strlen($digits) >= 3 ? substr($digits, 0, 3) : null;
    }
}
