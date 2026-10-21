<?php
/**
 * The Remote_Guard template file: the pure checks behind `import-image`.
 *
 * Copy the whole `Media/` folder into a product's MCP module. See README.md in this folder.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Decides whether a remote image address may be fetched, and how cURL must fetch it.
 *
 * Nothing here makes a request. Core's own check (`wp_http_validate_url()`) is not enough on its own, because any
 * plugin can relax it for every request on the site (`http_request_host_is_external`, `http_allowed_safe_ports`,
 * `http_request_args` turning `reject_unsafe_urls` off), it reads only the first IPv4 record, and it lets the site's
 * own host through. These checks take no filter.
 */
class Remote_Guard {

	/**
	 * Largest file fetched, before the site's own upload limits lower it.
	 */
	const MAX_BYTES = 5242880;

	/**
	 * Longest address accepted.
	 */
	const MAX_URL_LENGTH = 2048;

	/**
	 * Redirects followed, each one checked like the first address.
	 */
	const MAX_HOPS = 3;

	/**
	 * The only port allowed: https on its standard port.
	 */
	const PORT = 443;

	/**
	 * Check an address before anything is resolved or fetched.
	 *
	 * @param mixed    $url        Address as sent.
	 * @param string[] $own_hosts  Hosts of this site (home and site URL), refused: a site's own images are found with
	 *                             `find-media`, and core skips its address check for them (http.php:584-587).
	 * @return array{url: string, host: string}|WP_Error
	 */
	public static function check_url( $url, array $own_hosts ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > self::MAX_URL_LENGTH || preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) {
			return new WP_Error( 'invalid_url', __( 'That is not a usable image address.', 'template-domain' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_url', __( 'That is not a usable image address.', 'template-domain' ) );
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return new WP_Error( 'https_required', __( 'Only https image addresses can be imported.', 'template-domain' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'credentials_in_url', __( 'An address with a user name or password in it is refused.', 'template-domain' ) );
		}

		// ASCII host names and IPv4 only: IPv6 literals and non-punycode names are refused (core: http.php:579). A
		// trailing dot is refused too: the pin is keyed by the plain name, and whether cURL matches `host.` to it
		// depends on its version (8.7.1 does).
		$host = strtolower( $parts['host'] );
		if ( '' === $host || '.' === substr( $host, -1 ) || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return new WP_Error( 'invalid_url', __( 'That is not a usable image address.', 'template-domain' ) );
		}

		if ( isset( $parts['port'] ) && self::PORT !== (int) $parts['port'] ) {
			return new WP_Error( 'port_not_allowed', __( 'Only the standard https port is allowed.', 'template-domain' ) );
		}

		foreach ( $own_hosts as $own ) {
			if ( is_string( $own ) && '' !== $own && strtolower( rtrim( $own, '.' ) ) === $host ) {
				return new WP_Error( 'own_site', __( 'That image is already on this site. Use find-media to get its id.', 'template-domain' ) );
			}
		}

		return array(
			'url'  => $url,
			'host' => $host,
		);
	}

	/**
	 * The hosts of this site, which `check_url()` refuses.
	 *
	 * @return string[]
	 */
	public static function own_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $address ) {
			$host = wp_parse_url( $address, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Whether an address is a public IPv4 one. The ranges are core's (http.php:609-621), without its filter.
	 *
	 * @param mixed $ip Address.
	 * @return bool
	 */
	public static function is_public_ipv4( $ip ) {
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		$p = array_map( 'intval', explode( '.', $ip ) );

		return ! (
			127 === $p[0] || 10 === $p[0] || 0 === $p[0]
			|| ( 172 === $p[0] && 16 <= $p[1] && 31 >= $p[1] )
			|| ( 192 === $p[0] && 168 === $p[1] )
			|| ( 192 === $p[0] && 0 === $p[1] && 0 === $p[2] )
			|| ( 192 === $p[0] && 0 === $p[1] && 2 === $p[2] )
			|| ( 192 === $p[0] && 88 === $p[1] && 99 === $p[2] )
			|| ( 198 === $p[0] && 51 === $p[1] && 100 === $p[2] )
			|| ( 203 === $p[0] && 0 === $p[1] && 113 === $p[2] )
			|| ( 169 === $p[0] && 254 === $p[1] )
			|| ( 100 === $p[0] && 64 <= $p[1] && 127 >= $p[1] )
			|| ( 198 === $p[0] && 18 <= $p[1] && 19 >= $p[1] )
			|| ( 224 <= $p[0] && 239 >= $p[0] )
			|| 240 <= $p[0]
		);
	}

	/**
	 * Resolve a host to the one address the request is pinned to. Every IPv4 record must be public: a host with one
	 * public and one private record is refused, because a later lookup could return the private one. The result is
	 * always judged, never the host text, so `0x7f.1` or `2130706433` are refused once they resolve to loopback.
	 *
	 * @param string        $host     Host name or IPv4 literal.
	 * @param callable|null $resolver Lookup returning a list of IPv4 strings or false. Default `gethostbynamel()`.
	 * @return string|WP_Error
	 */
	public static function resolve( $host, $resolver = null ) {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ips = array( $host );
		} else {
			$ips = null === $resolver ? gethostbynamel( $host ) : call_user_func( $resolver, $host );
		}

		if ( ! is_array( $ips ) || array() === $ips ) {
			return new WP_Error( 'dns_failed', __( 'The image host could not be found.', 'template-domain' ) );
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ipv4( $ip ) ) {
				return new WP_Error( 'unsafe_address', __( 'That address points to a private or local network, which is refused.', 'template-domain' ) );
			}
		}

		return (string) reset( $ips );
	}

	/**
	 * Whether this PHP can pin a request: the cURL options the fetch sets must all exist.
	 *
	 * @return bool
	 */
	public static function can_pin() {
		foreach ( array( 'CURLOPT_RESOLVE', 'CURLOPT_IPRESOLVE', 'CURL_IPRESOLVE_V4', 'CURLOPT_MAXFILESIZE', 'CURLOPT_HTTP_CONTENT_DECODING', 'CURLOPT_PROXY', 'CURLOPT_NOPROXY', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_TIMEOUT_MS' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				return false;
			}
		}

		return function_exists( 'curl_setopt' );
	}

	/**
	 * The cURL options set on every hop, last, so no other plugin's setting survives (each one proven by a probe).
	 *
	 * @param string $host       Host of this hop.
	 * @param string $ip         Address checked for it.
	 * @param int    $max_bytes  Size cap.
	 * @param int    $timeout_ms Time left for this hop. Requests gives every redirect hop the full `timeout` again
	 *                           (each hop is a new request, Requests.php:809), so the call's deadline is enforced here.
	 * @return array<int, mixed>
	 */
	public static function curl_options( $host, $ip, $max_bytes, $timeout_ms ) {
		return array(
			// Connect to the checked address only, over IPv4 (a dual-stack host otherwise goes over IPv6).
			CURLOPT_RESOLVE               => array( $host . ':' . self::PORT . ':' . $ip ),
			CURLOPT_IPRESOLVE             => CURL_IPRESOLVE_V4,
			// Requests asks for any encoding (Curl.php:110), and the size cap counts compressed bytes: keep the body raw.
			CURLOPT_HTTP_CONTENT_DECODING => false,
			CURLOPT_MAXFILESIZE           => (int) $max_bytes,
			// No proxy from WordPress or from the environment: a proxy resolves the host itself and undoes the pin.
			CURLOPT_PROXY                 => '',
			CURLOPT_NOPROXY               => '*',
			// Against another plugin's own cURL options. Requests' `verify` option is applied after this hook
			// (Curl.php:192-203), so `Remote_Fetch::enforce_tls()` restores that one before the hop.
			CURLOPT_SSL_VERIFYPEER        => true,
			CURLOPT_SSL_VERIFYHOST        => 2,
			CURLOPT_TIMEOUT_MS            => max( 1, (int) $timeout_ms ),
		);
	}

	/**
	 * Size cap for one image: the smallest of our cap, the site's upload limit and, on multisite, the network's
	 * per-file limit (`fileupload_maxk`, 1500 KB by default; core checks it in check_upload_size(), ms.php:44).
	 *
	 * @return int Bytes.
	 */
	public static function max_bytes() {
		$caps = array( self::MAX_BYTES );

		$site = (int) wp_max_upload_size();
		if ( $site > 0 ) {
			$caps[] = $site;
		}

		if ( is_multisite() ) {
			$caps[] = KB_IN_BYTES * (int) get_site_option( 'fileupload_maxk', 1500 );
		}

		return (int) min( $caps );
	}

	/**
	 * The address in the form its hash is taken from: scheme and host lowercased, the port (443 is the only one
	 * allowed) and the fragment dropped.
	 *
	 * @param string $url Checked address.
	 * @return string
	 */
	public static function normalize( $url ) {
		$parts = wp_parse_url( $url );
		$out   = 'https://' . strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		$out  .= isset( $parts['path'] ) ? $parts['path'] : '/';
		$out  .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $out;
	}

	/**
	 * Hash of an address, stored on the attachment instead of the address itself (a signed address carries a
	 * credential in its query string).
	 *
	 * @param string $url Checked address.
	 * @return string
	 */
	public static function source_hash( $url ) {
		return hash( 'sha256', self::normalize( $url ) );
	}
}
