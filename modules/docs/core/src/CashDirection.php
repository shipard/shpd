<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Směr pokladního dokladu — backed enum s int hodnotami odpovídajícími
 * sloupci docs_core_heads.cash_dir (enumInt, cfgItem docs.core.cashDirections).
 *
 * Pro typy dokladu s `trade_dir: 0` + `trade_dir_column: "cash_dir"` z něj
 * DocDocument::resolveTradeDir() odvozuje směr obchodu: Receipt → 1
 * (my dodavatel), Disbursement → 2 (my odběratel).
 */
enum CashDirection: int
{
    case NotApplicable = 0;
    case Receipt = 1;
    case Disbursement = 2;

    /** Směr obchodu (`trade_dir`) odpovídající směru pokladního dokladu; null pro NotApplicable. */
    public function tradeDir(): ?int
    {
        return match ($this) {
            self::Receipt      => 1,
            self::Disbursement => 2,
            self::NotApplicable => null,
        };
    }
}
