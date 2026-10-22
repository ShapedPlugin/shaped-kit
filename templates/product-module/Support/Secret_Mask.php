<?php
/**
 * The Secret_Mask template file: describes stored credentials without leaking them, and names the keys that hold one.
 *
 * Copy this file into a product's MCP module. See README.md.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Safely describes secrets (API keys, license credentials, OAuth tokens) without leaking them.
 *
 * Security Rules (E16 / E80):
 * - An AI agent only needs to know whether a credential is configured.
 * - Raw secrets must NEVER cross the wire in a tool response.
 * - Masking rules:
 *   - Empty: configured = false, last4 = null.
 *   - Configured: configured = true.
 *   - Last 4 characters are revealed ONLY if the secret has length >= 12 (MIN_LENGTH_FOR_HINT),
 *     guaranteeing that 4 characters never reveal a substantial portion of a short key.
 *   - A licence key never reveals any characters: pass `$allow_hint = false` (E170, E183).
 * - Write tools treat every key `is_secret_key()` matches as read-only, so a masked value an agent echoes back can
 *   never replace the stored secret (E184, E223).
 */
class Secret_Mask {

	/**
	 * Setting keys that hold a credential, matched at the end of the key so labels such as `passwordFormTitle` stay
	 * writable. An optional version suffix (`_v3`) is allowed. Public values (a reCAPTCHA site key, a nonce) do not match.
	 */
	const SECRET_KEY_PATTERN = '/(?:api[_-]?keys?|access[_-]?token|auth[_-]?token|refresh[_-]?token|client[_-]?secret|secret(?:[_-]?key)?|password|licen[cs]e[_-]?key)(?:[_-]?v\d+)?$/i';

	/**
	 * Minimum secret length required before showing trailing 4 characters.
	 */
	const MIN_LENGTH_FOR_HINT = 12;

	/**
	 * Number of trailing characters to show for identification.
	 */
	const HINT_LENGTH = 4;

	/**
	 * Describes a stored secret value.
	 *
	 * @param mixed $value      Stored secret string.
	 * @param bool  $allow_hint Whether the last 4 characters may be shown. Always false for a licence key.
	 *
	 * @return array{configured: bool, last4: string|null}
	 */
	public static function describe( $value, $allow_hint = true ) {
		if ( ! is_string( $value ) ) {
			return array(
				'configured' => ! empty( $value ),
				'last4'      => null,
			);
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return array(
				'configured' => false,
				'last4'      => null,
			);
		}

		return array(
			'configured' => true,
			'last4'      => ( true === $allow_hint && strlen( $value ) >= self::MIN_LENGTH_FOR_HINT ) ? substr( $value, -self::HINT_LENGTH ) : null,
		);
	}

	/**
	 * Whether a setting or attribute key holds a credential.
	 *
	 * @param mixed $key Key, or a dotted path whose last part is checked.
	 *
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			return false;
		}

		$parts = explode( '.', (string) $key );

		return 1 === preg_match( self::SECRET_KEY_PATTERN, (string) end( $parts ) );
	}
}
