<?php
/**
 * The Audit template file: reports every tool call to Shaped Kit's audit log.
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
 * Reports every tool call to Shaped Kit's audit log.
 *
 * Without this call a product's tools never reach Shaped Kit's audit log, and nothing says so (TESTING.md rule 5).
 * Only the argument keys are sent, each with a null value: any plugin can listen to this action, and values can
 * be personal data (an email, a phone) or a credential (a signed image address). The Kit's listener reads the
 * keys only (E159).
 *
 * It lives here, not in the registrar, so the keys-only rule is shared code that every product keeps in step (S7).
 */
class Audit {

	/**
	 * Error codes recorded as a refusal rather than a failure.
	 */
	const DENIED_CODES = array( 'forbidden', 'rest_forbidden', 'unauthorized' );

	/**
	 * Emits the audit event for one tool call.
	 *
	 * @param string      $product     Product slug, for example `Module::SLUG`.
	 * @param string      $tool_name   Ability name.
	 * @param mixed       $params      Input parameters.
	 * @param mixed       $result      Tool execution result.
	 * @param string|null $error_id    Sanitized error tracking id if an exception occurred.
	 * @param int         $duration_ms Execution time in milliseconds.
	 *
	 * @return void
	 */
	public static function report( $product, $tool_name, $params, $result, $error_id, $duration_ms ) {
		/**
		 * Emits telemetry event for audit logging.
		 * Listened to by Shaped Kit if active.
		 *
		 * @param array $payload Telemetry metadata.
		 */
		do_action(
			'shaped_kit/mcp_tool_called',
			array(
				'product'     => (string) $product,
				'ability'     => $tool_name,
				'outcome'     => self::outcome( $params, $result, $error_id ),
				'error_id'    => $error_id,
				'duration_ms' => $duration_ms,
				'arguments'   => is_array( $params ) ? array_fill_keys( array_map( 'strval', array_keys( $params ) ), null ) : array(),
			)
		);
	}

	/**
	 * The outcome Shaped Kit's audit log accepts for this call.
	 *
	 * @param mixed       $params   Input parameters.
	 * @param mixed       $result   Tool execution result.
	 * @param string|null $error_id Error id when the tool threw.
	 *
	 * @return string One of ok, error, denied, confirmation_required, confirmed.
	 */
	public static function outcome( $params, $result, $error_id ) {
		if ( null !== $error_id ) {
			return 'error';
		}

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			if ( 'confirmation_required' === $code ) {
				return 'confirmation_required';
			}

			return in_array( $code, self::DENIED_CODES, true ) ? 'denied' : 'error';
		}

		if ( is_array( $result ) && isset( $result['status'] ) && 'confirmation_required' === $result['status'] ) {
			return 'confirmation_required';
		}

		if ( is_array( $params ) && ( ! empty( $params['confirmation_token'] ) || ! empty( $params['confirm_token'] ) ) ) {
			return 'confirmed';
		}

		return 'ok';
	}
}
