<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Cluster záznamů se stejným normalizovaným textem řádku — jednotka
 * vyhodnocení obsahu: LLM klasifikuje distinct texty (ne záznamy), takže
 * jeden cluster = jedno místo v cache i jeden řádek v matici konzistence.
 *
 * Mutable akumulátor (na rozdíl od {@see BookingHistoryRecord}): plní se
 * v průchodu souborem a LLM štítek do něj přiteče až po klasifikaci.
 * Reprezentativní `text` je varianta s nejvíc řádky (D30 říká totéž
 * o exportu, tady to platí i pro clustery napříč záznamy).
 */
final class TextCluster
{
    public int $rows = 0;
    public int $docs = 0;
    public float $amount = 0.0;

    /** @var array<string, int> číslo účtu → řádky */
    public array $accounts = [];

    /** @var array<string, int> reverzní štítek → řádky */
    public array $reverseTags = [];

    /** LLM štítek; null = model štítek nenašel, nebo klasifikace neproběhla. */
    public ?string $llmTag = null;

    /** Prošel cluster klasifikací? Odlišuje „model řekl nic" od „nebyl dotázán". */
    public bool $llmClassified = false;

    private int $representativeRows = -1;

    public function __construct(
        public readonly string $norm,
        public string $text,
    ) {}

    public function observe(BookingHistoryRecord $record, AccountTagMatch $match): void
    {
        $this->rows += $record->rowCount;
        $this->docs += $record->docCount;
        $this->amount += $record->totalAmount ?? 0.0;

        if ($record->rowCount > $this->representativeRows && $record->rowText !== null) {
            $this->representativeRows = $record->rowCount;
            $this->text = $record->rowText;
        }

        if ($record->account !== null) {
            $this->accounts[$record->account] = ($this->accounts[$record->account] ?? 0) + $record->rowCount;
        }
        if ($match->tag !== null) {
            $this->reverseTags[$match->tag] = ($this->reverseTags[$match->tag] ?? 0) + $record->rowCount;
        }
    }

    /**
     * Nejčetnější reverzní štítek clusteru; null = žádný reverz nezasáhl.
     * Remíza se rozhoduje abecedně — jde o report, ne o zápis do dat.
     */
    public function dominantReverseTag(): ?string
    {
        if ($this->reverseTags === []) {
            return null;
        }
        $tags = $this->reverseTags;
        ksort($tags);
        return (string) array_key_first(array_filter(
            $tags,
            static fn (int $rows): bool => $rows === max($tags),
        ));
    }
}
