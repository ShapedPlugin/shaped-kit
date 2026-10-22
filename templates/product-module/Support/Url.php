<?php
/**
 * The Url template file: strict address cleaning for tool arguments.
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
 * Cleans an address an AI client sent as a tool argument.
 *
 * `esc_url_raw()` is not enough on its own: it puts `http://` in front of any text that has no scheme, so
 * "not a url" is stored as a link (found in a shipped product, E126). Use this for every website, video,
 * social or image address a write tool accepts.
 */
class Url {

	/**
	 * A full http or https address, or an empty string.
	 *
	 * @param mixed $value Address as sent.
	 *
	 * @return string The cleaned address, or '' when it is not a full http(s) address.
	 */
	public static function clean( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		// A real address first: with a scheme, and no spaces.
		if ( 1 !== preg_match( '#^https?://\S+$#i', $value ) ) {
			return '';
		}

		$url = esc_url_raw( $value );

		// esc_url_raw() can still strip the scheme of a value it finds unsafe.
		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
