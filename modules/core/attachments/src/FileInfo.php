<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

/**
 * Immutable data class returned by FileStorage::store().
 */
readonly class FileInfo
{
    public function __construct(
        /** Relative directory path (e.g. 2026/04/15/base_persons_persons) */
        public string $filePath,
        /** Filename on disk (e.g. faktura-a7k2m.pdf) */
        public string $fileName,
        /** File size in bytes */
        public int $fileSize,
        /** SHA-256 checksum of the file */
        public string $checksum,
    ) {}
}
