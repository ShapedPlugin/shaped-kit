<?php
/**
 * The Remote_Fetch template file: the one guarded request behind `import-image`.
 *
 * Copy the whole `Media/` folder into a product's MCP module. See README.md in this folder.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Media;

use WP_Error;
use WpOrg\Requests\Exception as Requests_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Downloads one image to a temporary file through `wp_safe_remote_get()`, pinned to checked addresses.
 *
 * How the pin holds against other plugins:
 * - `http_api_curl` (fired just before cURL runs, Curl.php:174) is hooked at PHP_INT_MAX. It reads the address cURL
 *   is about to fetch (`CURLINFO_EFFECTIVE_URL`, the real hop, not the URL WordPress reports) and either sets the
 *   options of `Remote_Guard::curl_options()` for a checked host, or throws, which WordPress turns into a WP_Error
 *   (class-wp-http.php:424).
 * - Certificate checks are restored in `requests-requests.before_request` (Requests.php:455), because Requests applies
 *   its `verify` options after `curl.before_send` and a plugin can turn them off for every request.
 * - Redirects are followed by Requests (Requests.php:787-811). Each new address is checked in
 *   `requests-requests.before_redirect` before it is allowed, at most `Remote_Guard::MAX_HOPS` of them.
 * - A request that never reached our cURL hook (another plugin answered `pre_http_request`, or no cURL transport)
 *   is refused afterwards, because nothing pinned it.
 */
class Remote_Fetch {

	/**
	 * Seconds one request may take, lowered to what is left of the call's deadline.
	 */
	const TIMEOUT = 10;

	/**
	 * The request in flight: temp file, checked hosts, hops, whether cURL was pinned, and a refusal.
	 *
	 * @var array|null
	 */
	private static $active = null;

	/**
	 * Temp files to delete at shutdown if a call dies part-way (a fatal error still runs shutdown functions).
	 *
	 * @var string[]
	 */
	private static $temp_files = array();

	/**
	 * Whether the shutdown cleanup is registered.
	 *
	 * @var bool
	 */
	private static $cleanup_registered = false;

	/**
	 * Download an image address into a temporary file.
	 *
	 * @param string $url      Address, already passed through `Remote_Guard::check_url()`.
	 * @param float  $deadline Unix time with microseconds by which the whole call must end.
	 * @return array{file: string, size: int}|WP_Error The caller owns the file and must delete it (`forget()`).
	 */
	public static function fetch( $url, $deadline ) {
		if ( ! Remote_Guard::can_pin() ) {
			return new WP_Error( 'transport_unsafe', __( 'This site cannot make a pinned request (cURL is missing), so images cannot be imported.', 'template-domain' ) );
		}

		$checked = Remote_Guard::check_url( $url, Remote_Guard::own_hosts() );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		if ( self::proxy_applies( $url ) ) {
			return new WP_Error( 'proxy_in_use', __( 'This site sends requests through a proxy, which would undo the address check, so images cannot be imported.', 'template-domain' ) );
		}

		$ip = Remote_Guard::resolve( $checked['host'] );
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		$left = (int) floor( $deadline - microtime( true ) );
		if ( $left < 2 ) {
			return new WP_Error( 'deadline_reached', __( 'There was no time left in this call.', 'template-domain' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = wp_tempnam( 'mcp-image' );
		if ( ! $file ) {
			return new WP_Error( 'temp_failed', __( 'A temporary file could not be created.', 'template-domain' ) );
		}
		self::track( $file );

		$max   = Remote_Guard::max_bytes();
		$state = self::begin( array( $checked['host'] => $ip ), $file, $max, $deadline );

		self::hooks( true );

		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => min( self::TIMEOUT, $left ),
					'redirection'         => Remote_Guard::MAX_HOPS,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => $max + 1,
					// Identity: decoding is off (the size cap counts raw bytes), so a compressed body would be refused.
					'headers'             => array(
						'Accept'          => 'image/jpeg, image/png, image/gif, image/webp',
						'Accept-Encoding' => 'identity',
					),
				)
			);
		} finally {
			self::hooks( false );
			$state = self::end();
		}

		$error = self::outcome( $response, $state, $max );
		if ( is_wp_error( $error ) ) {
			self::forget( $file );
			return $error;
		}

		clearstatcache( true, $file );

		return array(
			'file' => $file,
			'size' => (int) filesize( $file ),
		);
	}

	/**
	 * Whether WordPress would send this address through its proxy (WP_PROXY_* constants). A proxy resolves the host
	 * itself, so the pin would not hold: such a site is refused with a clear reason. The refusal is not the only
	 * barrier: a proxy is set on Requests' own `curl.before_send` hook (Proxy/Http.php:99-116), before WordPress
	 * relays `http_api_curl` (class-wp-http-requests-hooks.php:52-58), so `pin_request()` clears it in any case.
	 *
	 * @param string              $url   Address.
	 * @param \WP_HTTP_Proxy|null $proxy Proxy settings. Default: the site's.
	 * @return bool
	 */
	public static function proxy_applies( $url, $proxy = null ) {
		$proxy = $proxy instanceof \WP_HTTP_Proxy ? $proxy : new \WP_HTTP_Proxy();

		return $proxy->is_enabled() && $proxy->send_through_proxy( $url );
	}

	/**
	 * Judge a finished request: refusals first, then transport errors, then the file.
	 *
	 * @param array|WP_Error $response Response of wp_safe_remote_get().
	 * @param array          $state    State left by the request.
	 * @param int            $max      Size cap.
	 * @return true|WP_Error
	 */
	private static function outcome( $response, array $state, $max ) {
		if ( $state['refusal'] instanceof WP_Error ) {
			return $state['refusal'];
		}

		if ( is_wp_error( $response ) ) {
			// Raw cURL text can name resolved addresses: only the error number is read, never passed on.
			$message = (string) $response->get_error_message();
			if ( false !== strpos( $message, 'cURL error 63:' ) ) {
				return self::too_large( $max );
			}
			if ( false !== strpos( $message, 'cURL error 28:' ) ) {
				return new WP_Error( 'timeout', __( 'The image host did not answer in time.', 'template-domain' ) );
			}
			if ( preg_match( '/cURL error (35|51|58|59|60|77|83|90|91):/', $message ) ) {
				return new WP_Error( 'tls_failed', __( 'The image host has no valid https certificate.', 'template-domain' ) );
			}
			return new WP_Error( 'fetch_failed', __( 'The image could not be downloaded.', 'template-domain' ) );
		}

		if ( ! $state['pinned'] ) {
			return new WP_Error( 'transport_unsafe', __( 'The request did not go through the pinned connection, so its result is not used.', 'template-domain' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'http_error', __( 'The image host answered with an error.', 'template-domain' ), array( 'status' => $code ) );
		}

		clearstatcache( true, $state['file'] );
		$size = file_exists( $state['file'] ) ? (int) filesize( $state['file'] ) : 0;
		if ( 0 === $size ) {
			return new WP_Error( 'empty_file', __( 'The image host sent an empty file.', 'template-domain' ) );
		}

		// Requests cuts a body at `limit_response_size` and keeps going (Curl.php:542-553): one byte over is the sign.
		if ( $size > $max ) {
			return self::too_large( $max );
		}

		return true;
	}

	/**
	 * The size refusal.
	 *
	 * @param int $max Size cap.
	 * @return WP_Error
	 */
	private static function too_large( $max ) {
		return new WP_Error(
			'too_large',
			sprintf(
				/* translators: %s: size limit, for example "5 MB". */
				__( 'The image is larger than %s.', 'template-domain' ),
				size_format( $max )
			)
		);
	}

	/**
	 * Start tracking a request. Public for tests of the hook callbacks, which cannot be reached without a request.
	 *
	 * @param array<string, string> $allowed  Host => checked address.
	 * @param string                $file     Temp file the response streams into.
	 * @param int                   $max      Size cap.
	 * @param float                 $deadline End of the call (Unix time with microseconds).
	 * @return array The new state.
	 */
	public static function begin( array $allowed, $file, $max, $deadline ) {
		self::$active = array(
			'allowed'  => $allowed,
			'file'     => (string) $file,
			'max'      => (int) $max,
			'deadline' => (float) $deadline,
			'hops'     => 0,
			'pinned'   => false,
			'refusal'  => null,
		);

		return self::$active;
	}

	/**
	 * Stop tracking and return what the request left.
	 *
	 * @return array
	 */
	public static function end() {
		$state        = is_array( self::$active ) ? self::$active : array(
			'pinned'  => false,
			'refusal' => null,
			'file'    => '',
		);
		self::$active = null;

		return $state;
	}

	/**
	 * Add or remove the three listeners of a request, all at PHP_INT_MAX so they run after any other plugin's.
	 * Public for tests, which drive the listeners through WordPress's own hook relay.
	 *
	 * @param bool $on True to add, false to remove.
	 * @return void
	 */
	public static function hooks( $on ) {
		$listeners = array(
			'requests-requests.before_request'  => array( 'enforce_tls', 5 ),
			'http_api_curl'                     => array( 'pin_request', 3 ),
			'requests-requests.before_redirect' => array( 'check_redirect', 1 ),
		);

		foreach ( $listeners as $hook => $listener ) {
			if ( $on ) {
				add_action( $hook, array( __CLASS__, $listener[0] ), PHP_INT_MAX, $listener[1] );
			} else {
				remove_action( $hook, array( __CLASS__, $listener[0] ), PHP_INT_MAX );
			}
		}
	}

	/**
	 * `requests-requests.before_request`, at PHP_INT_MAX: certificate checks back on for our request.
	 *
	 * Requests applies `verify` and `verifyname` after `curl.before_send` (Curl.php:192-203), so a plugin that turns
	 * them off (`https_ssl_verify`, class-wp-http.php:398, or `sslverify` through `http_request_args`) would undo a
	 * cURL option set in `pin_request()` (live probe L1b: an expired certificate was accepted). They are restored here,
	 * on the options Requests passes by reference before each hop (Requests.php:455).
	 *
	 * @param string $url     Address.
	 * @param array  $headers Headers.
	 * @param mixed  $data    Body.
	 * @param string $type    Method.
	 * @param array  $options Requests options, by reference.
	 * @return void
	 */
	public static function enforce_tls( &$url, &$headers, &$data, &$type, &$options ) {
		unset( $url, $headers, $data, $type );

		if ( null === self::$active || ! is_array( $options ) || ! isset( $options['filename'] ) || self::$active['file'] !== $options['filename'] ) {
			return;
		}

		// A site's own CA bundle (a string) is kept; "off" becomes WordPress's bundle.
		if ( ! isset( $options['verify'] ) || ! is_string( $options['verify'] ) || '' === $options['verify'] ) {
			$options['verify'] = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		}
		$options['verifyname'] = true;
	}

	/**
	 * `http_api_curl`, at PHP_INT_MAX: pin our request's hop to its checked address, or stop it.
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 * @param array                $args   Request arguments (WP_Http parsed args).
	 * @param string               $url    Address WordPress was asked for (not the hop: read from the handle).
	 * @return void
	 * @throws Requests_Exception When the hop is not a checked host.
	 */
	public static function pin_request( $handle, $args = array(), $url = '' ) {
		unset( $url );

		// Another request made while ours is in flight is not ours: our temp file identifies ours.
		if ( null === self::$active || ! is_array( $args ) || ! isset( $args['filename'] ) || self::$active['file'] !== $args['filename'] ) {
			return;
		}

		$hop  = (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- The hop cURL is about to fetch is on the raw handle only.
		$host = strtolower( rtrim( (string) wp_parse_url( $hop, PHP_URL_HOST ), '.' ) );

		if ( '' === $host || ! isset( self::$active['allowed'][ $host ] ) || 'https' !== strtolower( (string) wp_parse_url( $hop, PHP_URL_SCHEME ) ) ) {
			self::refuse( 'unsafe_address', __( 'The request was sent to an address that was not checked.', 'template-domain' ) );
		}

		$left_ms = (int) floor( ( self::$active['deadline'] - microtime( true ) ) * 1000 );
		if ( $left_ms < 1000 ) {
			self::refuse( 'deadline_reached', __( 'There was no time left in this call.', 'template-domain' ) );
		}

		foreach ( Remote_Guard::curl_options( $host, self::$active['allowed'][ $host ], self::$active['max'], $left_ms ) as $option => $value ) {
			curl_setopt( $handle, $option, $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- The pin needs the raw handle.
		}

		self::$active['pinned'] = true;
	}

	/**
	 * `requests-requests.before_redirect`, at PHP_INT_MAX: check each redirect like the first address.
	 *
	 * @param string $location Address the redirect points to (already absolute, Requests.php:795-799).
	 * @return void
	 * @throws Requests_Exception When the redirect is refused.
	 */
	public static function check_redirect( $location ) {
		if ( null === self::$active ) {
			return;
		}

		++self::$active['hops'];
		if ( self::$active['hops'] > Remote_Guard::MAX_HOPS ) {
			self::refuse( 'too_many_redirects', __( 'The image address redirects too many times.', 'template-domain' ) );
		}

		$checked = Remote_Guard::check_url( $location, Remote_Guard::own_hosts() );
		if ( is_wp_error( $checked ) ) {
			self::refuse( $checked->get_error_code(), $checked->get_error_message() );
		}

		$ip = Remote_Guard::resolve( $checked['host'] );
		if ( is_wp_error( $ip ) ) {
			self::refuse( $ip->get_error_code(), $ip->get_error_message() );
		}

		self::$active['allowed'][ $checked['host'] ] = $ip;
	}

	/**
	 * Record why a request was stopped, then stop it.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message for the AI client.
	 * @return void
	 * @throws Requests_Exception Always.
	 */
	private static function refuse( $code, $message ) {
		if ( is_array( self::$active ) && null === self::$active['refusal'] ) {
			self::$active['refusal'] = new WP_Error( $code, $message );
		}

		throw new Requests_Exception( 'Refused by the image import guard.', 'shaped_mcp_image_refused' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; WordPress turns it into a WP_Error.
	}

	/**
	 * Remember a temp file so it is deleted even if the call dies.
	 *
	 * @param string $file Path.
	 * @return void
	 */
	private static function track( $file ) {
		if ( ! self::$cleanup_registered ) {
			register_shutdown_function( array( __CLASS__, 'cleanup' ) );
			self::$cleanup_registered = true;
		}
		self::$temp_files[ $file ] = true;
	}

	/**
	 * Delete a temp file now, if it is still there, and stop tracking it.
	 *
	 * @param string $file Path.
	 * @return void
	 */
	public static function forget( $file ) {
		if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
		unset( self::$temp_files[ $file ] );
	}

	/**
	 * Shutdown: delete every temp file still tracked.
	 *
	 * @return void
	 */
	public static function cleanup() {
		foreach ( array_keys( self::$temp_files ) as $file ) {
			self::forget( $file );
		}
	}
}
