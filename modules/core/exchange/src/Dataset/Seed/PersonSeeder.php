<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Seed;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Person\PersonApplier;

/**
 * `persons/*.jsonc` přes `PersonApplier`; stav 70/80 (applier cílí jen
 * 10/40) se po uložení dorovná.
 */
final class PersonSeeder extends ApplierSeeder
{
    public function __construct(
        private readonly PersonApplier $applier,
    ) {}

    public function section(): string
    {
        return 'persons';
    }

    protected function apply(array $canonical): ApplyResult
    {
        return $this->applier->apply($canonical);
    }

    protected function afterApply(SeedContext $ctx, array $canonical, int $savedId): void
    {
        $state = $canonical['status']['docState'] ?? null;
        $ctx->restoreArchiveState('base_persons_persons', $savedId, is_int($state) ? $state : null);
    }
}
