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
	 * @param string $name Name to show in the profile. Falls back to the default; made unique.
	 *
	 * @return array{password: string, username: string, name: string, uuid: string}|WP_Error
	 */
	public static function create( $name = '' ) {
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
		$name = self::unique_name( $name, self::existing_names( $user->ID ) );

		$created = \WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );

		if ( is_wp_error( $created ) ) {
			return new WP_Error( 'shaped_kit_app_password_failed', __( 'WordPress could not create the application password.', 'shaped-kit' ), array( 'status' => 500 ) );
		}

		return array(
			'password' => self::chunk( (string) $created[0] ),
			'username' => $user->user_login,
			'name'     => $name,
			'uuid'     => isset( $created[1]['uuid'] ) ? (string) $created[1]['uuid'] : '',
		);
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
	 * @param int $user_id User ID.
	 *
	 * @return string[]
	 */
	private static function existing_names( $user_id ) {
		$existing = \WP_Application_Passwords::get_user_application_passwords( $user_id );

		return is_array( $existing ) ? array_map( 'strval', wp_list_pluck( $existing, 'name' ) ) : array();
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
				return __( 'Application passwords are off on this site. WordPress needs HTTPS for them unless the site runs in a local environment.', 'shaped-kit' );
			case 'user_disabled':
				return __( 'Application passwords are off for your user account.', 'shaped-kit' );
			case 'unsupported':
				return __( 'This WordPress version does not support application passwords.', 'shaped-kit' );
			default:
				return __( 'Application passwords are not available right now.', 'shaped-kit' );
		}
	}
}
