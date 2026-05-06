<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Default Document class for docs_core_heads in Phase 1/2.
 *
 * Inherits all logic from DocDocument unchanged. In Phase 6 the registry
 * will switch to typeColumn-based polymorphism with type-specific subclasses
 * (IssuedInvoiceDocument in docs.invoicesOut, ReceivedInvoiceDocument in
 * docs.invoicesIn).
 */
class DocsHeadsDocument extends DocDocument
{
    // Empty body — pure inheritance.
}
