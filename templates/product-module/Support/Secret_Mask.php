<?php
/**
 * The Secret_Mask support helper template.
 *
 * Copy this file into a product's MCP module (e.g. `Support/Secret_Mask.php`) and update:
 *   1. Namespace: `ShapedPlugin\Template\Mcp\Support` -> `ShapedPlugin\{Product}\Mcp\Support`
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
 */
class Secret_Mask {

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
	 * @param mixed $value Stored secret string.
	 *
	 * @return array{configured: bool, last4: string|null}
	 */
	public static function describe( $value ) {
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
			'last4'      => strlen( $value ) >= self::MIN_LENGTH_FOR_HINT ? substr( $value, -self::HINT_LENGTH ) : null,
		);
	}
}
