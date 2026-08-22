<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Hosting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro testy lookup API AI analyzerů
 * (HostingAiAnalyzerController). Interpretuje jen SQL, které kontroler
 * skutečně posílá (match podle podřetězců). Instancuje se přes
 * ReflectionClass::newInstanceWithoutConstructor(). Záměrně nesdílí
 * InMemoryHostingMailDb — jiná sada dotazů (konvence per-controller).
 */
class InMemoryHostingAiAnalyzerDb extends DataSourceConnection
{
    /** @var array<int, array> */
    public array $analyzers = [];
    /** @var array<int, array> */
    public array $dataSources = [];
    private int $nextId = 1;

    public static function create(): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $db */
        $db = $ref->newInstanceWithoutConstructor();
        return $db;
    }

    public function addAnalyzer(array $row): int
    {
        return $this->insert($this->analyzers, $row + [
            'name' => 'Analyzer',
            'api_key_prefix' => null,
            'api_key_hash' => null,
            'last_seen' => null,
            'docState' => 40,
        ]);
    }

    public function addDataSource(array $row): int
    {
        return $this->insert($this->dataSources, $row + [
            'lifecycle' => 'active',
            'docState' => 40,
            'web_id' => null,
            'url_app' => '',
            'analyzer_token' => null,
        ]);
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'hosting_core_ai_analyzers') && str_contains($sql, 'api_key_prefix =')) {
            foreach ($this->analyzers as $row) {
                if ($row['api_key_prefix'] === $args[1]) {
                    return $row;
                }
            }
            return null;
        }

        throw new \LogicException("InMemoryHostingAiAnalyzerDb: unexpected fetchRow: {$sql}");
    }

    public function fetchAll(mixed ...$args): array
    {
        $sql = (string) $args[0];

        // lookup: WHERE lifecycle = %s AND docState IN %in AND analyzer_token IS NOT NULL
        //         ORDER BY ds_id ASC
        if (str_contains($sql, 'hosting_core_data_sources') && str_contains($sql, 'analyzer_token IS NOT NULL')) {
            $out = [];
            foreach ($this->dataSources as $row) {
                if ($row['lifecycle'] === $args[1]
                    && in_array((int) $row['docState'], (array) $args[2], true)
                    && $row['analyzer_token'] !== null
                ) {
                    $out[] = $row;
                }
            }
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['ds_id'], (string) $b['ds_id']));
            return $out;
        }

        throw new \LogicException("InMemoryHostingAiAnalyzerDb: unexpected fetchAll: {$sql}");
    }

    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        if ($table !== 'hosting_core_ai_analyzers') {
            throw new \LogicException("InMemoryHostingAiAnalyzerDb: unexpected update of {$table}");
        }
        $id = (int) ($whereParams[0] ?? 0);
        if (isset($this->analyzers[$id])) {
            $this->analyzers[$id] = array_merge($this->analyzers[$id], $data);
        }
    }

    private function insert(array &$storage, array $data): int
    {
        $id = $this->nextId++;
        $data['id'] = $id;
        $storage[$id] = $data;
        return $id;
    }
}
