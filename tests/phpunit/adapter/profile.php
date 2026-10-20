<?php
/**
 * Prints one fact from a product's wire-suite profile, for `tests/run-adapter-matrix.sh`.
 *
 * Plain PHP, no WordPress: the matrix reads the profile (data) instead of repeating it in shell. Examples:
 * `php tests/phpunit/adapter/profile.php real-testimonials editions` prints "pro free", and
 * `php tests/phpunit/adapter/profile.php template suite` prints "adapter-template".
 *
 * @package ShapedKit
 */

$product = isset( $argv[1] ) ? preg_replace( '/[^a-z0-9-]/', '', $argv[1] ) : '';
$what    = isset( $argv[2] ) ? $argv[2] : '';
$file    = __DIR__ . '/products/' . $product . '.php';

if ( '' === $product || ! is_readable( $file ) ) {
	fwrite( STDERR, "No wire-suite profile for '{$product}'.\n" );
	exit( 2 );
}

$profile = require $file;

switch ( $what ) {
	case 'editions':
		echo implode( ' ', array_keys( $profile['editions'] ) );
		break;
	case 'suite':
		echo $profile['suite'];
		break;
	default:
		fwrite( STDERR, "Unknown fact '{$what}' (editions, suite).\n" );
		exit( 2 );
}
