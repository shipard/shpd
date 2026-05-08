<?php
declare(strict_types=1);

namespace Shipard\Core\Logging;

/**
 * Reads the last N lines of a file by reading chunks from the end.
 * Memory-bounded: never loads more than ~chunkSize × (limit/avg-line-length) bytes.
 */
final class LogTail
{
	public function __construct(
		private readonly string $path,
		private readonly int $chunkSize = 8192,
	) {}

	/**
	 * @return list<string> Last $limit non-empty lines (in original order).
	 *                      Returns [] when file does not exist or is empty.
	 */
	public function readLast(int $limit): array
	{
		if ($limit <= 0) return [];
		if (!is_file($this->path) || !is_readable($this->path)) return [];

		$size = filesize($this->path);
		if ($size === false || $size === 0) return [];

		$fp = fopen($this->path, 'rb');
		if ($fp === false) return [];

		$buffer = '';
		$pos = $size;
		$foundNewlines = 0;

		try {
			while ($pos > 0) {
				$readSize = (int) min($this->chunkSize, $pos);
				$pos -= $readSize;
				if (fseek($fp, $pos) !== 0) break;
				$chunk = fread($fp, $readSize);
				if ($chunk === false) break;
				$buffer = $chunk . $buffer;
				$foundNewlines = substr_count($buffer, "\n");

				// Have enough complete lines? +1 because the first line in
				// buffer may be partial until we reach BOF.
				if ($foundNewlines >= $limit + 1) break;
			}
		} finally {
			fclose($fp);
		}

		$allLines = explode("\n", $buffer);
		$allLines = array_values(array_filter(
			$allLines,
			static fn(string $l): bool => trim($l) !== '',
		));

		return array_slice($allLines, -$limit);
	}
}
