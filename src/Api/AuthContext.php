<?php
declare(strict_types=1);

namespace Shipard\Api;

readonly class AuthContext
{
	public function __construct(
		public bool $isAuthenticated,
		public ?int $userId = null,
		public ?string $tokenType = null, // 'api_key' | 'session'
		public ?string $token = null,     // raw token string (for refresh/logout)
		public bool $isAdmin = false,
	) {}

	public static function anonymous(): self
	{
		return new self(false);
	}
}
