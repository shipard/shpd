<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Doménová chyba OIDC flow. `errorCode` je stabilní kód pro redirect na
 * login obrazovku (`/app/?login_error={errorCode}`) a i18n klíč
 * `login.error.{errorCode}` na frontendu:
 *
 *   oidc_denied            uživatel odmítl na straně IdP
 *   oidc_invalid_state     neznámý/expirovaný/použitý state
 *   oidc_provider_error    discovery/exchange/validace id_tokenu selhaly
 *   oidc_no_account        identita nemá účet a auto-link/JIT nejsou povolené
 *   oidc_email_ambiguous   e-mail v DS není unikátní — auto-link zakázán
 *   oidc_account_inactive  propojený uživatel je deaktivovaný
 *   oidc_login_conflict    JIT: login (= e-mail) už existuje
 */
class OidcException extends \RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message,
	) {
		parent::__construct($message);
	}
}
