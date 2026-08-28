<?php

declare(strict_types=1);

namespace Shipard\Core\Render;

/**
 * Druh selhání renderu — provozní stavy vrací RenderClient jako
 * RenderResult, nikdy výjimkou (vzor degradace pdfdetach/TextExtractor,
 * viz docs/render.md).
 */
enum RenderErrorKind: string
{
    /** Klíč `render` v server.json chybí — služba není nakonfigurována. */
    case Unconfigured = 'unconfigured';

    /** Služba neodpovídá (spojení odmítnuto, DNS, žádná HTTP odpověď). */
    case Unreachable = 'unreachable';

    /** Render nedoběhl v časovém limitu. */
    case Timeout = 'timeout';

    /** Engine vrátil chybu (HTTP >= 400) nebo selhal post-processing krok. */
    case EngineError = 'engineError';

    /** Nevalidní kombinace vstupů (např. header/footer u profilu Untrusted). */
    case InvalidInput = 'invalidInput';
}
