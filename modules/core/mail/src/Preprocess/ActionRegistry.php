<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

/**
 * Registr akcí předzpracování: klíč z plánu → implementace. Neznámý klíč
 * (překlep v pravidle, akce z Fáze 2) runner zapíše jako selhanou akci,
 * nikdy nespadne.
 */
final class ActionRegistry
{
    /** @var array<string, PreprocessAction> */
    private array $actions = [];

    public function register(string $key, PreprocessAction $action): self
    {
        $this->actions[$key] = $action;
        return $this;
    }

    public function get(string $key): ?PreprocessAction
    {
        return $this->actions[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->actions);
    }
}
