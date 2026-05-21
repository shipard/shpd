<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * Common base for all registry client errors. Callers that do not need
 * to distinguish between the three concrete subclasses (Unavailable /
 * NotFound / InvalidResponse) can catch this and treat any failure
 * uniformly.
 *
 * Subclass guide:
 *   - {@see RegistryUnavailableException} — network failure, timeout,
 *     or HTTP 5xx. Retry later may succeed.
 *   - {@see RegistryNotFoundException} — the requested entity does not
 *     exist in the registry (HTTP 404 or `status: 0` response).
 *     Retrying will not help.
 *   - {@see RegistryInvalidResponseException} — registry responded but
 *     the payload is malformed or does not match the expected canonical
 *     shape. Likely a registry-side bug; log + alert.
 */
abstract class RegistryException extends \RuntimeException
{
}
