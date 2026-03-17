<?php
declare(strict_types=1);

namespace Shipard\Api\Exception;

class UnknownDataSourceException extends \RuntimeException
{
	public function __construct(public readonly string $dsId)
	{
		parent::__construct("Unknown data source: {$dsId}");
	}
}
