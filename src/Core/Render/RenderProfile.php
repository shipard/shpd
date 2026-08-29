<?php

declare(strict_types=1);

namespace Shipard\Core\Render;

/**
 * Profil renderu (D5) — mapuje na výchozí omezení a parametry requestu.
 *
 * - Untrusted: e-mailová HTML těla a jiný cizí obsah. Header/footer a
 *   printBackground zakázány, timeout tvrdě stropovaný. JS je vypnutý
 *   globálně flagem kontejneru (--chromium-disable-javascript), odchozí
 *   síť Chromia zakázaná env CHROMIUM_DENY_PRIVATE_IPS/PUBLIC_IPS — to
 *   profil nevynucuje, jen na to spoléhá (viz docs/operations/render-service.md).
 * - Report: vlastní server-side šablony — header/footer, printBackground,
 *   plný timeout z konfigurace.
 */
enum RenderProfile: string
{
    case Untrusted = 'untrusted';
    case Report = 'report';

    public function allowsHeaderFooter(): bool
    {
        return $this === self::Report;
    }

    public function allowsPrintBackground(): bool
    {
        return $this === self::Report;
    }

    /** Efektivní timeout requestu: Untrusted stropuje na 30 s. */
    public function effectiveTimeoutSec(int $configuredTimeoutSec): int
    {
        return match ($this) {
            self::Untrusted => min($configuredTimeoutSec, 30),
            self::Report => $configuredTimeoutSec,
        };
    }

    /** Výchozí okraj stránky (všechny čtyři strany), když PdfOptions nedá jiný. */
    public function defaultMargin(): string
    {
        return match ($this) {
            self::Untrusted => '1cm',
            self::Report => '1.6cm',
        };
    }
}
