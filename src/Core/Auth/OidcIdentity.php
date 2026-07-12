<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Ověřená identita z validovaného `id_token`. Klíčem uživatele je vždy
 * dvojice (issuer, subject) — e-mail se u IdP může měnit.
 */
final readonly class OidcIdentity
{
	public function __construct(
		public string $issuer,
		public string $subject,
		public ?string $email,
		public bool $emailVerified,
		public ?string $name,
	) {}
}
