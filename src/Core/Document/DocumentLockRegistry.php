<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Registry providerů zámku záznamu (`documentLockProviders`, #55 D24).
 *
 * Registrace {table, class} nese DocumentRegistry (plní DocumentLoader ze
 * všech resolvovaných modulů), takže každá TableGateway — a každý další
 * konzument s DocumentRegistry v ruce — má stejnou sadu providerů bez
 * dalšího zapojování. Instance registry je per gateway/kontroler: nese DB
 * a konfiguraci, které providerům injektuje (lazy instanciace, jedna
 * instance třídy per registry).
 *
 * Více providerů na tabulku se volá v pořadí registrace (= pořadí
 * resolvovaných modulů), důvody se sčítají.
 */
final class DocumentLockRegistry
{
    /** Kód chyby formuláře (`_form`) i doménové chyby při mazání / REST. */
    public const ERROR_CODE = 'locked';
    public const DOMAIN_CODE = 'DOCUMENT_LOCKED';

    /** @var array<string, list<class-string>> table → třídy providerů */
    private array $registrations = [];

    /** @var array<string, DocumentLockProvider> class → instance */
    private array $instances = [];

    /**
     * @param list<array{table: string, class: string}> $registrations
     */
    public function __construct(
        array $registrations = [],
        private readonly ?\Dibi\Connection $db = null,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {
        foreach ($registrations as $reg) {
            $this->registrations[$reg['table']][] = $reg['class'];
        }
    }

    /**
     * Registry nad providery z DocumentRegistry — jediná konstrukce mimo
     * testy. Bez DocumentRegistry (degradace v testech) je prázdná.
     */
    public static function forDocuments(
        ?DocumentRegistry $documents,
        ?\Dibi\Connection $db,
        ?ConfigRuntime $config = null,
        ?DataSourceConfig $dsConfig = null,
    ): self {
        return new self($documents?->getLockProviders() ?? [], $db, $config, $dsConfig);
    }

    public function hasProviders(string $tableId): bool
    {
        return isset($this->registrations[$tableId]);
    }

    /**
     * Sjednocené důvody všech providerů tabulky. Výjimka providera se
     * propaguje — volající zápis odmítne, nikdy tiše nepovolí.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $original
     * @return list<DocumentLockReason>
     */
    public function reasons(string $tableId, array $data, ?array $original): array
    {
        $out = [];
        foreach ($this->registrations[$tableId] ?? [] as $className) {
            foreach ($this->instantiate($className)->lockReasons($tableId, $data, $original) as $reason) {
                if (!$reason instanceof DocumentLockReason) {
                    throw new \LogicException(
                        "DocumentLockProvider {$className} returned a non-DocumentLockReason item",
                    );
                }
                $out[] = $reason;
            }
        }
        return $out;
    }

    /**
     * UI kontrakt nad uloženým řádkem (form meta, detail vieweru):
     * `{locked: bool, reasons: [{source, title, message, params, …}]}`.
     *
     * @param array<string, mixed> $row
     * @return array{locked: bool, reasons: list<array<string, mixed>>}
     */
    public function describe(string $tableId, array $row): array
    {
        $reasons = $this->hasProviders($tableId) ? $this->reasons($tableId, $row, $row) : [];
        return [
            'locked'  => $reasons !== [],
            'reasons' => array_map(static fn(DocumentLockReason $r): array => $r->toArray(), $reasons),
        ];
    }

    /**
     * Jednořádkový text pro doménovou chybu / výjimku: tituly důvodů.
     *
     * @param list<DocumentLockReason> $reasons
     */
    public static function summarize(array $reasons): string
    {
        return implode('; ', array_map(static fn(DocumentLockReason $r): string => $r->title, $reasons));
    }

    private function instantiate(string $className): DocumentLockProvider
    {
        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        $provider = new $className();
        if (!$provider instanceof DocumentLockProvider) {
            throw new \LogicException("Class {$className} does not implement DocumentLockProvider");
        }

        if ($provider instanceof AbstractDocumentLockProvider) {
            if ($this->db !== null) {
                $provider->setDb($this->db);
            }
            if ($this->config !== null) {
                $provider->setConfig($this->config);
            }
            if ($this->dsConfig !== null) {
                $provider->setDsConfig($this->dsConfig);
            }
        }

        return $this->instances[$className] = $provider;
    }
}
