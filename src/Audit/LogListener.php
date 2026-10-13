<?php
/**
 * Event listener for recording MCP tool executions into the audit log.
 *
 * Implements FR-S3 and listens to `shaped_kit/mcp_tool_called` actions fired by product modules.
 * Also tracks the authenticated Application Password name via core's `application_password_did_authenticate`.
 *
 * @package ShapedKit\Audit
 */

namespace ShapedKit\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Ingests tool execution telemetry into {$wpdb->prefix}shaped_kit_mcp_log.
 */
final class LogListener {

	/**
	 * Name of the currently authenticated Application Password for this request.
	 *
	 * @var string
	 */
	private static $current_app_password_name = '';

	/**
	 * Registers event hooks for audit logging.
	 *
	 * Verified source:
	 * - application_password_did_authenticate in wp-includes/user.php:497.
	 * - shaped_kit/mcp_tool_called per PRD FR-S3.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'on_app_password_authenticated' ), 10, 2 );
		add_action( 'shaped_kit/mcp_tool_called', array( __CLASS__, 'on_tool_called' ), 10, 1 );
	}

	/**
	 * Captures the Application Password name upon successful authentication.
	 *
	 * @param \WP_User $user Authenticated user.
	 * @param array    $item Application password details array containing 'name' and 'uuid'.
	 *
	 * @return void
	 */
	public static function on_app_password_authenticated( $user, array $item ): void {
		if ( ! empty( $item['name'] ) && is_string( $item['name'] ) ) {
			self::$current_app_password_name = sanitize_text_field( $item['name'] );
		}
	}

	/**
	 * Returns the currently captured application password name (or empty string).
	 *
	 * @return string
	 */
	public static function get_current_app_password_name(): string {
		return self::$current_app_password_name;
	}

	/**
	 * Resets in-memory request state (useful for tests and sub-requests).
	 *
	 * @return void
	 */
	public static function reset_state(): void {
		self::$current_app_password_name = '';
	}

	/**
	 * Handles the `shaped_kit/mcp_tool_called` action and inserts an audit log row.
	 *
	 * Payload expected from products:
	 * array(
	 *     'product'           => (string) Product slug, e.g. 'location-weather',
	 *     'ability'           => (string) Fully-qualified ability name, e.g. 'location-weather/get-context',
	 *     'outcome'           => (string) 'ok' | 'error' | 'denied' | 'confirmation_required' | 'confirmed',
	 *     'user_id'           => (int, optional) Defaults to get_current_user_id(),
	 *     'app_password_name' => (string, optional) Defaults to self::$current_app_password_name,
	 *     'error_id'          => (string|null, optional) Nullable error identifier,
	 *     'duration_ms'       => (int, optional) Duration in milliseconds,
	 *     'arguments'         => (array, optional) Raw arguments passed to the tool. ONLY KEYS ARE STORED.
	 *     'argument_keys'     => (array, optional) Pre-extracted keys.
	 * )
	 *
	 * Safe guard: All execution wrapped in try / catch(\Throwable) so database errors never disrupt
	 * the caller or crash the tool execution (PRD §19 E32).
	 *
	 * @param mixed $payload Tool call metadata.
	 *
	 * @return bool True if logged successfully, false otherwise.
	 */
	public static function on_tool_called( $payload ): bool {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		try {
			return self::insert_log( $payload );
		} catch ( \Throwable $e ) {
			// Fail silently: logging must never crash the tool response (PRD E32).
			return false;
		}
	}

	/**
	 * Prepares and inserts a log row into the table.
	 *
	 * @param array $payload Validated tool telemetry payload.
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function insert_log( array $payload ): bool {
		global $wpdb;

		$product = isset( $payload['product'] ) ? sanitize_key( (string) $payload['product'] ) : '';
		$ability = isset( $payload['ability'] ) ? sanitize_text_field( (string) $payload['ability'] ) : '';

		if ( '' === $product || '' === $ability ) {
			return false;
		}

		$allowed_outcomes = array( 'ok', 'error', 'denied', 'confirmation_required', 'confirmed' );
		$outcome          = isset( $payload['outcome'] ) && in_array( $payload['outcome'], $allowed_outcomes, true )
			? $payload['outcome']
			: 'ok';

		$user_id = isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : get_current_user_id();

		$app_password_name = ! empty( $payload['app_password_name'] ) && is_string( $payload['app_password_name'] )
			? sanitize_text_field( $payload['app_password_name'] )
			: self::$current_app_password_name;

		$error_id = isset( $payload['error_id'] ) && is_string( $payload['error_id'] )
			? sanitize_text_field( substr( $payload['error_id'], 0, 64 ) )
			: null;

		$duration_ms = isset( $payload['duration_ms'] ) ? absint( $payload['duration_ms'] ) : 0;

		// Extract argument keys ONLY — NEVER store argument values to prevent PII leaks (PRD FR-S3, §19 E36).
		$argument_keys = array();
		if ( isset( $payload['argument_keys'] ) && is_array( $payload['argument_keys'] ) ) {
			$argument_keys = array_values( array_map( 'strval', $payload['argument_keys'] ) );
		} elseif ( isset( $payload['arguments'] ) && is_array( $payload['arguments'] ) ) {
			$argument_keys = array_values( array_map( 'strval', array_keys( $payload['arguments'] ) ) );
		}

		$created_at = current_time( 'mysql', true ); // UTC datetime per PRD FR-S3.

		$table_name = LogTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into custom audit log table.
		$result = $wpdb->insert(
			$table_name,
			array(
				'created_at'        => $created_at,
				'user_id'           => $user_id,
				'app_password_name' => $app_password_name,
				'product'           => $product,
				'ability'           => $ability,
				'outcome'           => $outcome,
				'error_id'          => $error_id,
				'duration_ms'       => $duration_ms,
				'argument_keys'     => (string) wp_json_encode( $argument_keys ),
			),
			array(
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				null === $error_id ? '%s' : '%s',
				'%d',
				'%s',
			)
		);

		return false !== $result;
	}
}
