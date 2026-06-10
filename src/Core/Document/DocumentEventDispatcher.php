<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Dispatch událostí dokumentů na registrované handlery
 * (`documentEventHandlers` z module.jsonc, sbírá DocumentEventHandlerLoader).
 *
 * Více handlerů na tabulku je dovoleno — volají se v pořadí registrace
 * (= pořadí resolvovaných modulů). Instanciace je lazy, služby se injektují
 * stejně jako u dokumentů (TableGateway::injectDocServices).
 *
 * Chybová sémantika (viz DocumentEventHandler):
 *  - stateChanged: výjimka handleru se zaloguje a spolkne, další handlery
 *    se volají dál — commit už proběhl, odpověď klienta nesmí spadnout.
 *  - beforeDelete: výjimka se propaguje — gateway rollbackne delete transakci.
 */
final class DocumentEventDispatcher
{
    /** @var array<string, list<array{class: string, events: list<string>}>> table → registrations */
    private array $registrations = [];

    /** @var array<string, DocumentEventHandler> class → instance */
    private array $instances = [];

    /**
     * @param list<array{table: string, class: string, events: list<string>}> $registrations
     */
    public function __construct(
        array $registrations = [],
        private readonly ?\Dibi\Connection $db = null,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {
        foreach ($registrations as $reg) {
            $this->registrations[$reg['table']][] = [
                'class'  => $reg['class'],
                'events' => $reg['events'],
            ];
        }
    }

    public function dispatchStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        foreach ($this->handlersFor($tableId, 'stateChanged') as $handler) {
            try {
                $handler->onStateChanged($tableId, $data, $oldState, $newState);
            } catch (\Throwable $e) {
                ErrorLogger::logException(
                    $e,
                    sprintf(
                        'DocumentEventHandler %s::onStateChanged failed for %s #%s (%d → %d)',
                        $handler::class,
                        $tableId,
                        (string) ($data['id'] ?? '?'),
                        $oldState,
                        $newState,
                    ),
                );
            }
        }
    }

    public function dispatchBeforeDelete(string $tableId, array $data): void
    {
        foreach ($this->handlersFor($tableId, 'beforeDelete') as $handler) {
            $handler->onBeforeDelete($tableId, $data);
        }
    }

    /**
     * @return list<DocumentEventHandler>
     */
    private function handlersFor(string $tableId, string $event): array
    {
        $handlers = [];
        foreach ($this->registrations[$tableId] ?? [] as $reg) {
            if (!in_array($event, $reg['events'], true)) {
                continue;
            }
            $handlers[] = $this->instantiate($reg['class']);
        }
        return $handlers;
    }

    private function instantiate(string $className): DocumentEventHandler
    {
        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        $handler = new $className();
        if (!$handler instanceof DocumentEventHandler) {
            throw new \LogicException(
                "Class {$className} does not implement DocumentEventHandler",
            );
        }

        if ($handler instanceof AbstractDocumentEventHandler) {
            if ($this->db !== null) {
                $handler->setDb($this->db);
            }
            if ($this->config !== null) {
                $handler->setConfig($this->config);
            }
            if ($this->dsConfig !== null) {
                $handler->setDsConfig($this->dsConfig);
            }
        }

        return $this->instances[$className] = $handler;
    }
}
