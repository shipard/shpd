<?php
declare(strict_types=1);

namespace Shipard\Api;

readonly class Route
{
	public function __construct(
		public string $controller,
		public string $action,
		public ?string $table = null,
		public ?int $id = null,
		public ?int $secondaryId = null,
	) {}
}
