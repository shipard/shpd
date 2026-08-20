<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Čtečka souboru účetní historie (`docs/booking-history-format.md`).
 *
 * Hlavička se validuje při {@see open()} — soubor s rozbitou hlavičkou
 * nemá smysl číst dál. Záznamy se čtou **streamovaně** generátorem
 * ({@see records()}), takže velikost souboru nelimituje RAM; každý
 * konzument (kvalita, seed, sběr textů) dostane záznamy v jednom průchodu
 * přes {@see BookingHistoryAnalyzer}.
 *
 * Prázdné řádky se ignorují. Nevalidní JSON i nevalidní záznam skončí
 * {@see BookingHistoryFormatException} s číslem řádku.
 */
final class BookingHistoryFile
{
    private function __construct(
        public readonly string $path,
        public readonly BookingHistoryHeader $header,
    ) {}

    /**
     * @throws BookingHistoryFormatException když soubor nelze číst nebo má rozbitou hlavičku
     */
    public static function open(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new BookingHistoryFormatException(0, "soubor \"{$path}\" neexistuje nebo není čitelný");
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new BookingHistoryFormatException(0, "soubor \"{$path}\" nelze otevřít");
        }
        try {
            $lineNo = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                $decoded = self::decodeLine($line, $lineNo);
                if ($decoded === null) {
                    continue; // prázdný řádek před hlavičkou
                }
                return new self($path, BookingHistoryHeader::fromArray($decoded, $lineNo));
            }
        } finally {
            fclose($handle);
        }

        throw new BookingHistoryFormatException(1, 'soubor je prázdný — chybí hlavička');
    }

    /**
     * Streamované čtení záznamů. Generátor lze projít vícekrát — každé
     * zavolání otevře soubor znovu.
     *
     * @return \Generator<int, BookingHistoryRecord>
     * @throws BookingHistoryFormatException
     */
    public function records(): \Generator
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new BookingHistoryFormatException(0, "soubor \"{$this->path}\" nelze otevřít");
        }
        try {
            $lineNo = 0;
            $headerSeen = false;
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                $decoded = self::decodeLine($line, $lineNo);
                if ($decoded === null) {
                    continue;
                }
                if (!$headerSeen) {
                    $headerSeen = true; // hlavičku už zvalidoval open()
                    continue;
                }
                yield BookingHistoryRecord::fromArray($decoded, $lineNo);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null null = prázdný řádek k přeskočení
     * @throws BookingHistoryFormatException
     */
    private static function decodeLine(string $line, int $lineNo): ?array
    {
        // BOM na prvním řádku je běžný artefakt tabulkových exportů.
        if ($lineNo === 1) {
            $line = (string) preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new BookingHistoryFormatException(
                $lineNo,
                'nevalidní JSON (' . json_last_error_msg() . ')',
            );
        }
        if (array_is_list($decoded)) {
            throw new BookingHistoryFormatException($lineNo, 'očekáván JSON objekt, přišlo pole');
        }
        return $decoded;
    }
}
