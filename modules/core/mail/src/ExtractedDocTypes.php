<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Čtení cfgItem `core.mail.extractedDocTypes` — jediné místo, které
 * interpretuje `target` a `docKind` typu extrahovaného dokumentu. Sdílí
 * ExtractedDocumentApplier, AnalysisController (ingest/preview) a
 * MailSuggestionsSource, aby fallback logika nežila v kopiích.
 */
final class ExtractedDocTypes
{
    public const TARGET_DOCS = 'docs';
    public const TARGET_REGISTRY = 'registry';

    /**
     * Target typu: `extractedDocTypes[docType]['target'] ?? 'docs'`.
     * Chybějící compiled config, neznámý typ i typ bez `target` degradují
     * na `docs` (zpětná kompatibilita se staršími analýzami).
     */
    public static function targetFor(?ConfigRuntime $config, string $docType): string
    {
        $type = self::typeConfig($config, $docType);
        $target = $type['target'] ?? null;
        return is_string($target) && $target !== '' ? $target : self::TARGET_DOCS;
    }

    /** Klíč do `base.registry.docKinds` u registry typů; jinak null. */
    public static function docKindFor(?ConfigRuntime $config, string $docType): ?string
    {
        $type = self::typeConfig($config, $docType);
        $docKind = $type['docKind'] ?? null;
        return is_string($docKind) && $docKind !== '' ? $docKind : null;
    }

    /** @return array<string, mixed> */
    private static function typeConfig(?ConfigRuntime $config, string $docType): array
    {
        if ($docType === '' || $config === null) {
            return [];
        }
        $types = $config->cfgItem('core.mail.extractedDocTypes');
        $type = is_array($types) ? ($types[$docType] ?? null) : null;
        return is_array($type) ? $type : [];
    }
}
