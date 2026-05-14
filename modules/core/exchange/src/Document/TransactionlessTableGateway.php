<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Core\Document\TableGateway;

/**
 * TableGateway variant that does NOT manage its own DB transaction —
 * begin/commit/rollback are no-ops. Used by DocumentApplier so the
 * Applier can own the outer transaction (side-creates + doc save +
 * lineage update must be atomic, see docs/exchange-format.md §10).
 *
 * Reason this can't be solved with nested $db->begin(): MariaDB has no
 * concept of nested START TRANSACTION — a second begin implicitly
 * commits the outer one. Dibi exposes savepoints (`begin('name')`) but
 * TableGateway doesn't pass any savepoint name through, so when Applier
 * calls a TableGateway-internal transaction, the outer Applier
 * transaction would silently commit and lineage update would never
 * roll back together with the doc save.
 *
 * Centralising transaction control in the Applier sidesteps that.
 */
class TransactionlessTableGateway extends TableGateway
{
    protected function beginTransaction(): void {}
    protected function commitTransaction(): void {}
    protected function rollbackTransaction(): void {}
}
