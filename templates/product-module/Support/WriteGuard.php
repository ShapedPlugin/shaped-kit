<?php
/**
 * WriteGuard safety rails template for mutating and destructive MCP tools.
 *
 * Copy this file into a product's MCP module (e.g. `Support/WriteGuard.php`) and update:
 *   1. Namespace: `ShapedPlugin\Template\Mcp\Support` -> `ShapedPlugin\{Product}\Mcp\Support`
 *   2. Text domain: `'shaped-kit'` -> product text domain
 *
 * Implements FR-S2 (Level 1 server-side confirmation round-trip):
 * 1. Dry-run preview + cryptographically secure 32-byte confirmation token (300s TTL).
 * 2. Strict three-way binding: User ID + Canonical Argument Hash + Entity State Fingerprint.
 * 3. Atomic single-use token consumption via delete_transient row check.
 * 4. Idempotency key replay protection (24h TTL).
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class WriteGuard
 */
class WriteGuard {

	/**
	 * Confirmation token time-to-live in seconds (5 minutes).
	 *
	 * @var int
	 */
	const CONFIRM_TTL = 300;

	/**
	 * Idempotency record time-to-live in seconds (24 hours).
	 *
	 * @var int
	 */
	const IDEM_TTL = 86400;

	/**
	 * Build a confirmation preview response with a token bound to entity state and arguments.
	 *
	 * @param string $ability     Ability/tool name (e.g. "real-testimonials/delete-testimonial").
	 * @param string $entity_key  Stable identifier of the target entity (e.g. "testimonial:42").
	 * @param string $fingerprint String capturing the entity's current mutable state.
	 * @param array  $preview     Human and agent-readable preview payload.
	 * @param array  $arguments   Tool arguments to bind canonically to the token.
	 * @param int    $user_id     Optional user ID. Defaults to get_current_user_id().
	 * @return array Confirmation required response payload.
	 */
	public static function preview( $ability, $entity_key, $fingerprint, array $preview, array $arguments = array(), $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : (int) get_current_user_id();

		$token    = bin2hex( random_bytes( 16 ) );
		$arg_hash = self::canonical_arg_hash( $arguments );

		$payload = array(
			'token'       => $token,
			'fingerprint' => (string) $fingerprint,
			'arg_hash'    => $arg_hash,
			'user_id'     => $uid,
			'created_at'  => time(),
		);

		set_transient( self::confirm_key( $uid, $ability, $entity_key ), $payload, self::CONFIRM_TTL );

		return array(
			'status'             => 'confirmation_required',
			'preview'            => $preview,
			'confirmation_token' => $token,
			'expires_in'         => self::CONFIRM_TTL,
			'next_step'          => 'Call this tool again with the same parameters plus confirmation_token to execute.',
		);
	}

	/**
	 * Validate and atomically consume a confirmation token.
	 *
	 * @param string $ability             Ability name.
	 * @param string $entity_key          Target entity key.
	 * @param string $current_fingerprint Current mutable state fingerprint of the target entity.
	 * @param string $token               Confirmation token supplied by caller.
	 * @param array  $arguments           Current tool arguments to verify against preview.
	 * @param int    $user_id             Optional user ID. Defaults to get_current_user_id().
	 * @return true|\WP_Error True if confirmation succeeds, or WP_Error on rejection.
	 */
	public static function confirm( $ability, $entity_key, $current_fingerprint, $token, array $arguments = array(), $user_id = 0 ) {
		if ( empty( $token ) ) {
			return new \WP_Error(
				'confirmation_required',
				__( 'This action makes permanent changes. Call again without confirmation_token to preview, then supply the returned confirmation_token to execute.', 'shaped-kit' ),
				array(
					'status'    => 400,
					'next_step' => 'preview first without confirmation_token',
				)
			);
		}

		$uid       = $user_id ? (int) $user_id : (int) get_current_user_id();
		$trans_key = self::confirm_key( $uid, $ability, $entity_key );
		$stored    = get_transient( $trans_key );

		if ( ! is_array( $stored ) || empty( $stored['token'] ) ) {
			return new \WP_Error(
				'confirmation_expired',
				__( 'Your confirmation token has expired or was already used. Run a fresh call to preview and obtain a new confirmation_token.', 'shaped-kit' ),
				array(
					'status'    => 410,
					'next_step' => 'preview again',
				)
			);
		}

		if ( ! hash_equals( (string) $stored['token'], (string) $token ) ) {
			return new \WP_Error(
				'confirmation_invalid',
				__( 'The confirmation_token does not match. Run a fresh call to preview.', 'shaped-kit' ),
				array(
					'status'    => 403,
					'next_step' => 'preview again',
				)
			);
		}

		if ( isset( $stored['arg_hash'] ) ) {
			$current_arg_hash = self::canonical_arg_hash( $arguments );
			if ( ! hash_equals( (string) $stored['arg_hash'], (string) $current_arg_hash ) ) {
				return new \WP_Error(
					'confirmation_invalid',
					__( 'The arguments provided do not match the previewed action. Run a fresh call to preview.', 'shaped-kit' ),
					array(
						'status'    => 400,
						'next_step' => 'preview again',
					)
				);
			}
		}

		if ( (string) $stored['fingerprint'] !== (string) $current_fingerprint ) {
			delete_transient( $trans_key );
			return new \WP_Error(
				'state_changed',
				__( 'The record changed since you previewed it. Run a fresh call to inspect current state before executing.', 'shaped-kit' ),
				array(
					'status'    => 409,
					'next_step' => 'preview again',
				)
			);
		}

		$consumed = delete_transient( $trans_key );
		if ( ! $consumed ) {
			return new \WP_Error(
				'confirmation_already_consumed',
				__( 'This confirmation token has already been consumed by another concurrent request.', 'shaped-kit' ),
				array(
					'status'    => 409,
					'next_step' => 'preview again',
				)
			);
		}

		return true;
	}

	/**
	 * Run a mutation at most once per idempotency key (per user + ability + entity).
	 *
	 * @param string   $ability         Ability name.
	 * @param string   $entity_key      Target entity key.
	 * @param string   $idempotency_key Optional idempotency key from caller.
	 * @param callable $callback        Mutating callable to execute.
	 * @param int      $user_id         Optional user ID. Defaults to get_current_user_id().
	 * @return mixed Cached or executed result.
	 */
	public static function idempotent( $ability, $entity_key, $idempotency_key, callable $callback, $user_id = 0 ) {
		if ( empty( $idempotency_key ) ) {
			return $callback();
		}

		$uid       = $user_id ? (int) $user_id : (int) get_current_user_id();
		$cache_key = self::idem_key( $uid, $ability, $entity_key, $idempotency_key );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			if ( is_array( $cached ) ) {
				$cached['idempotent_replay'] = true;
			}
			return $cached;
		}

		$result = $callback();

		if ( ! is_wp_error( $result ) ) {
			set_transient( $cache_key, $result, self::IDEM_TTL );
		}

		return $result;
	}

	/**
	 * Computes a deterministic SHA-256 hash of canonical arguments.
	 *
	 * @param array $arguments Input arguments.
	 * @return string SHA-256 hash.
	 */
	public static function canonical_arg_hash( array $arguments ) {
		$stripped = self::strip_control_keys( $arguments );
		$sorted   = self::ksort_recursive( $stripped );

		$json = wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', (string) $json );
	}

	/**
	 * Computes a fingerprint string for a WordPress post.
	 *
	 * @param \WP_Post|int $post         Post object or ID.
	 * @param array        $extra_fields Optional additional fields (e.g. meta/tax values).
	 * @return string Fingerprint string.
	 */
	public static function fingerprint_post( $post, array $extra_fields = array() ) {
		$post_obj = is_numeric( $post ) ? get_post( (int) $post ) : $post;

		if ( ! ( $post_obj instanceof \WP_Post ) ) {
			return 'post:0';
		}

		$data = array(
			'id'       => (int) $post_obj->ID,
			'modified' => (string) $post_obj->post_modified_gmt,
			'status'   => (string) $post_obj->post_status,
		);

		if ( ! empty( $extra_fields ) ) {
			$data['extra'] = self::ksort_recursive( $extra_fields );
		}

		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/**
	 * Transient key for confirmation tokens.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $ability    Ability name.
	 * @param string $entity_key Entity identifier.
	 * @return string Transient key.
	 */
	public static function confirm_key( $user_id, $ability, $entity_key ) {
		return 'sk_mcp_cf_' . (int) $user_id . '_' . md5( $ability . '|' . $entity_key );
	}

	/**
	 * Transient key for idempotency caches.
	 *
	 * @param int    $user_id         User ID.
	 * @param string $ability         Ability name.
	 * @param string $entity_key      Entity identifier.
	 * @param string $idempotency_key Caller-supplied idempotency key.
	 * @return string Transient key.
	 */
	public static function idem_key( $user_id, $ability, $entity_key, $idempotency_key ) {
		return 'sk_mcp_id_' . (int) $user_id . '_' . md5( $ability . '|' . $entity_key . '|' . $idempotency_key );
	}

	/**
	 * Recursively remove control parameters.
	 *
	 * @param array $data Input array.
	 * @return array Cleaned array.
	 */
	private static function strip_control_keys( array $data ) {
		$ignored = array(
			'confirmation_token' => true,
			'confirm_token'      => true,
			'idempotency_key'    => true,
			'dry_run'            => true,
		);

		$cleaned = array();
		foreach ( $data as $k => $v ) {
			if ( isset( $ignored[ $k ] ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$cleaned[ $k ] = self::strip_control_keys( $v );
			} else {
				$cleaned[ $k ] = $v;
			}
		}

		return $cleaned;
	}

	/**
	 * Recursively sort associative arrays by key.
	 *
	 * @param mixed $data Input array or scalar.
	 * @return mixed Sorted data.
	 */
	private static function ksort_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_assoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );
		if ( $is_assoc ) {
			ksort( $data );
		}

		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = self::ksort_recursive( $v );
			}
		}

		return $data;
	}
}
