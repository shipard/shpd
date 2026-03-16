<?php
declare(strict_types=1);

namespace Shipard\Api\Exception;

class UnknownHostException extends \RuntimeException
{
	public function __construct(string $host)
	{
		parent::__construct("Unknown host: {$host}");
	}
}
