<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Seed;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Item\ItemApplier;

/**
 * `items/*.jsonc` přes `ItemApplier`; stav 70/80 se po uložení dorovná.
 */
final class ItemSeeder extends ApplierSeeder
{
    public function __construct(
        private readonly ItemApplier $applier,
    ) {}

    public function section(): string
    {
        return 'items';
    }

    protected function apply(array $canonical): ApplyResult
    {
        return $this->applier->apply($canonical);
    }

    protected function afterApply(SeedContext $ctx, array $canonical, int $savedId): void
    {
        $state = $canonical['status']['docState'] ?? null;
        $ctx->restoreArchiveState('economy_items', $savedId, is_int($state) ? $state : null);
    }
}
