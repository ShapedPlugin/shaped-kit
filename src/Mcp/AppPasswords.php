<?php
/**
 * The AppPasswords file.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Mcp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates an Application Password for the signed-in user, so an AI client can connect without a
 * separate login. Uses core's own `WP_Application_Passwords`; the Kit adds nothing of its own.
 *
 * Three rules:
 *
 * - **Only for the current user.** The user is never taken from a request, so one administrator cannot
 *   mint a credential for another.
 * - **Shown once, never kept.** WordPress stores only a hash. The plain password is returned to the
 *   caller and not logged, stored, cached or put in an error message.
 * - **Says why when it cannot.** WordPress turns these off on a site without HTTPS (unless it is a
 *   local environment), and a plugin or a user setting can turn them off too. The dashboard needs the
 *   reason: a dead button with no explanation is worse than a link to the profile page.
 */
class AppPasswords {

	/**
	 * The name given to a password made here. It shows in the user's profile.
	 */
	const DEFAULT_NAME = 'Shaped Kit MCP';

	/**
	 * Fixed identifier stored as the `app_id` of every password made here. Core keeps it as given and
	 * never validates it (wp-includes/class-wp-application-passwords.php:103), so it is how a password
	 * made here is told apart from one the user made by hand, whatever either was named.
	 */
	const APP_ID = '0dfc9a4a-34a0-4287-823d-6ae60227d247';

	/**
	 * Whether the current user can create one here, and if not, why.
	 *
	 * Reason codes: `no_user`, `unsupported`, `site_disabled`, `user_disabled`.
	 *
	 * @return array{available: bool, reason: string, user_login: string, manage_url: string}
	 */
	public static function support() {
		$support = array(
			'available'  => false,
			'reason'     => '',
			'user_login' => '',
			'manage_url' => admin_url( 'profile.php#application-passwords-section' ),
		);

		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			$support['reason'] = 'no_user';

			return $support;
		}

		$support['user_login'] = $user->user_login;

		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			$support['reason'] = 'unsupported';

			return $support;
		}

		if ( ! wp_is_application_passwords_available() ) {
			$support['reason'] = 'site_disabled';

			return $support;
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$support['reason'] = 'user_disabled';

			return $support;
		}

		$support['available'] = true;

		return $support;
	}

	/**
	 * Create one for the current user and return the plain password, once.
	 *
	 * With `$replace_previous` the earlier passwords made here are revoked, but only after the new one is
	 * saved: if saving fails the user still has the old one, never none.
	 *
	 * @param string $name             Name to show in the profile. Falls back to the default; made unique.
	 * @param bool   $replace_previous Whether to revoke the user's earlier Shaped Kit passwords once the new one exists.
	 *
	 * @return array{password: string, username: string, name: string, uuid: string, revoked: int}|WP_Error
	 */
	public static function create( $name = '', $replace_previous = false ) {
		$support = self::support();

		if ( ! $support['available'] ) {
			return new WP_Error(
				'shaped_kit_app_password_unavailable',
				self::unavailable_message( $support['reason'] ),
				array(
					'status' => 409,
					'reason' => $support['reason'],
				)
			);
		}

		$user = wp_get_current_user();
		$name = sanitize_text_field( (string) $name );
		$name = '' === $name ? self::DEFAULT_NAME : $name;

		// Names about to be revoked do not count as taken, so a replaced password does not push the name up to
		// "Shaped Kit MCP 2", "3" and so on. Core allows two passwords with the same name for the moment between.
		$taken = self::existing_names( $user->ID, $replace_previous );
		$name  = self::unique_name( $name, $taken );

		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name'   => $name,
				'app_id' => self::APP_ID,
			)
		);

		if ( is_wp_error( $created ) ) {
			return new WP_Error( 'shaped_kit_app_password_failed', __( 'WordPress could not create the application password.', 'shaped-kit' ), array( 'status' => 500 ) );
		}

		$uuid    = isset( $created[1]['uuid'] ) ? (string) $created[1]['uuid'] : '';
		$revoked = $replace_previous && '' !== $uuid ? self::revoke_previous_passwords( $uuid ) : 0;

		return array(
			'password' => self::chunk( (string) $created[0] ),
			'username' => $user->user_login,
			'name'     => $name,
			'uuid'     => $uuid,
			'revoked'  => $revoked,
		);
	}

	/**
	 * Whether a stored password was made here. The `app_id` says so; the default name, bare or with the
	 * " 2", " 3" suffix, covers the ones made before `app_id` was set. A password the user made by hand is
	 * never matched, even if it starts with the same words.
	 *
	 * @param array $item One entry from `WP_Application_Passwords::get_user_application_passwords()`.
	 *
	 * @return bool
	 */
	private static function is_kit_password( $item ) {
		if ( isset( $item['app_id'] ) && self::APP_ID === $item['app_id'] ) {
			return true;
		}

		return isset( $item['name'] ) && 1 === preg_match( '/^' . preg_quote( self::DEFAULT_NAME, '/' ) . '( \d+)?$/', (string) $item['name'] );
	}

	/**
	 * Revoke the current user's Shaped Kit application passwords.
	 *
	 * Verified source: WP_Application_Passwords::delete_application_password in wp-includes/class-wp-application-passwords.php:374,
	 * which returns true or a WP_Error. Only a real deletion is counted.
	 *
	 * @param string $except_uuid Optional UUID to keep.
	 *
	 * @return int Number of passwords revoked.
	 */
	public static function revoke_previous_passwords( $except_uuid = '' ) {
		$user = wp_get_current_user();

		if ( ! $user->exists() || ! class_exists( '\WP_Application_Passwords' ) ) {
			return 0;
		}

		$existing = \WP_Application_Passwords::get_user_application_passwords( $user->ID );

		if ( ! is_array( $existing ) ) {
			return 0;
		}

		$revoked = 0;

		foreach ( $existing as $item ) {
			if ( empty( $item['uuid'] ) || ( '' !== $except_uuid && $item['uuid'] === $except_uuid ) ) {
				continue;
			}

			if ( self::is_kit_password( $item ) && true === \WP_Application_Passwords::delete_application_password( $user->ID, (string) $item['uuid'] ) ) {
				++$revoked;
			}
		}

		return $revoked;
	}

	/**
	 * A name that is not already taken: WordPress refuses a duplicate, so add " 2", " 3" and so on.
	 *
	 * @param string   $name  Wanted name.
	 * @param string[] $taken Names the user already has.
	 *
	 * @return string
	 */
	public static function unique_name( $name, $taken ) {
		$candidate = $name;
		$suffix    = 2;

		while ( in_array( $candidate, $taken, true ) ) {
			$candidate = $name . ' ' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Names of the user's existing passwords.
	 *
	 * @param int  $user_id      User ID.
	 * @param bool $skip_ours    Leave out the ones made here (they are about to be revoked).
	 *
	 * @return string[]
	 */
	private static function existing_names( $user_id, $skip_ours = false ) {
		$existing = \WP_Application_Passwords::get_user_application_passwords( $user_id );

		if ( ! is_array( $existing ) ) {
			return array();
		}

		if ( $skip_ours ) {
			$existing = array_filter(
				$existing,
				static function ( $item ) {
					return ! self::is_kit_password( $item );
				}
			);
		}

		return array_map( 'strval', wp_list_pluck( $existing, 'name' ) );
	}

	/**
	 * Group the password in fours, the way the profile page shows it. Authentication ignores the spaces,
	 * so either form works; this only makes it look like one made on the profile page.
	 *
	 * @param string $password The 24-character password from core.
	 *
	 * @return string
	 */
	private static function chunk( $password ) {
		if ( method_exists( '\WP_Application_Passwords', 'chunk_password' ) ) {
			return \WP_Application_Passwords::chunk_password( $password );
		}

		return trim( chunk_split( preg_replace( '/[^a-z\d]/i', '', $password ), 4, ' ' ) );
	}

	/**
	 * The sentence for each reason.
	 *
	 * @param string $reason Reason code from `support()`.
	 *
	 * @return string
	 */
	private static function unavailable_message( $reason ) {
		switch ( $reason ) {
			case 'site_disabled':
				return __( 'Application passwords are off on this site. WordPress needs HTTPS for them, or on a dev machine set WP_ENVIRONMENT_TYPE=local in wp-config.php or environment variables. If behind a reverse proxy terminating SSL, forward X-Forwarded-Proto and configure wp-config.php.', 'shaped-kit' );
			case 'user_disabled':
				return __( 'Application passwords are off for your user account.', 'shaped-kit' );
			case 'unsupported':
				return __( 'This WordPress version does not support application passwords.', 'shaped-kit' );
			default:
				return __( 'Application passwords are not available right now.', 'shaped-kit' );
		}
	}
}
