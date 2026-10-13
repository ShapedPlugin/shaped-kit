<?php
/**
 * Offline release tooling script to generate Ed25519 keypairs and sign/verify catalog.json.
 *
 * Uses PHP ext-sodium (sodium_crypto_sign_*).
 *
 * Usage:
 *   php tools/sign-catalog.php keygen [--out-dir=tools/keys]
 *   php tools/sign-catalog.php sign <catalog.json> <private.key> [--out=<catalog.json.sig>]
 *   php tools/sign-catalog.php verify <catalog.json> <catalog.json.sig> <public.key>
 *
 * Source citations:
 * - sodium_crypto_sign_keypair(): PHP core ext-sodium
 * - sodium_crypto_sign_secretkey(): PHP core ext-sodium
 * - sodium_crypto_sign_publickey(): PHP core ext-sodium
 * - sodium_crypto_sign_detached(): PHP core ext-sodium
 * - sodium_crypto_sign_verify_detached(): PHP core ext-sodium
 *
 * @package ShapedKit
 */

if ( php_sapi_name() !== 'cli' ) {
	echo "CLI only.\n";
	exit( 1 );
}

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	fwrite( STDERR, "Error: ext-sodium is required for Ed25519 signing.\n" );
	exit( 1 );
}

/**
 * Generates an Ed25519 keypair and outputs base64-encoded keys.
 *
 * @return array{public: string, private: string}
 */
function shaped_kit_generate_keypair() {
	$keypair = sodium_crypto_sign_keypair();
	$secret  = sodium_crypto_sign_secretkey( $keypair );
	$public  = sodium_crypto_sign_publickey( $keypair );

	return array(
		'public'  => base64_encode( $public ),
		'private' => base64_encode( $secret ),
	);
}

/**
 * Signs a catalog payload with an Ed25519 secret key.
 *
 * @param string $payload Canonical catalog JSON content.
 * @param string $secret_key_base64 Base64-encoded secret key (64 bytes).
 * @return string Base64-encoded detached signature (64 bytes).
 * @throws InvalidArgumentException When key length is invalid.
 */
function shaped_kit_sign_catalog( $payload, $secret_key_base64 ) {
	$secret = base64_decode( trim( $secret_key_base64 ), true );
	if ( false === $secret || strlen( $secret ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
		throw new InvalidArgumentException( 'Invalid Ed25519 secret key length.' );
	}

	$signature = sodium_crypto_sign_detached( $payload, $secret );
	return base64_encode( $signature );
}

/**
 * Verifies a detached Ed25519 signature against catalog payload.
 *
 * @param string $payload Catalog JSON content.
 * @param string $signature_base64 Base64-encoded detached signature.
 * @param string $public_key_base64 Base64-encoded public key (32 bytes).
 * @return bool True if signature is valid.
 */
function shaped_kit_verify_catalog_signature( $payload, $signature_base64, $public_key_base64 ) {
	$signature = base64_decode( trim( $signature_base64 ), true );
	$public    = base64_decode( trim( $public_key_base64 ), true );

	if ( false === $signature || strlen( $signature ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
		return false;
	}

	if ( false === $public || strlen( $public ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
		return false;
	}

	return sodium_crypto_sign_verify_detached( $signature, $payload, $public );
}

// CLI Command Router
if ( isset( $argc ) && isset( $argv ) && $argc > 1 ) {
	$command = $argv[1];

	if ( 'keygen' === $command ) {
		$keys = shaped_kit_generate_keypair();
		echo "Ed25519 Keypair Generated:\n";
		echo "Public Key (base64):\n" . $keys['public'] . "\n\n";
		echo "Private Key (base64 - KEEP CONFIDENTIAL):\n" . $keys['private'] . "\n";
		exit( 0 );
	}

	if ( 'sign' === $command ) {
		if ( $argc < 4 ) {
			fwrite( STDERR, "Usage: php tools/sign-catalog.php sign <catalog.json> <private_key_file_or_base64> [out_sig_file]\n" );
			exit( 1 );
		}

		$catalog_file = $argv[2];
		$key_input    = $argv[3];
		$out_file     = isset( $argv[4] ) ? $argv[4] : $catalog_file . '.sig';

		if ( ! file_exists( $catalog_file ) ) {
			fwrite( STDERR, "Error: Catalog file not found: $catalog_file\n" );
			exit( 1 );
		}

		$payload    = file_get_contents( $catalog_file );
		$secret_key = file_exists( $key_input ) ? file_get_contents( $key_input ) : $key_input;

		try {
			$sig = shaped_kit_sign_catalog( $payload, $secret_key );
			file_put_contents( $out_file, $sig );
			echo "Catalog signed successfully -> $out_file\n";
			exit( 0 );
		} catch ( Exception $e ) {
			fwrite( STDERR, "Signing error: " . $e->getMessage() . "\n" );
			exit( 1 );
		}
	}

	if ( 'verify' === $command ) {
		if ( $argc < 5 ) {
			fwrite( STDERR, "Usage: php tools/sign-catalog.php verify <catalog.json> <signature_file_or_base64> <public_key_file_or_base64>\n" );
			exit( 1 );
		}

		$catalog_file = $argv[2];
		$sig_input    = $argv[3];
		$key_input    = $argv[4];

		if ( ! file_exists( $catalog_file ) ) {
			fwrite( STDERR, "Error: Catalog file not found: $catalog_file\n" );
			exit( 1 );
		}

		$payload    = file_get_contents( $catalog_file );
		$signature  = file_exists( $sig_input ) ? file_get_contents( $sig_input ) : $sig_input;
		$public_key = file_exists( $key_input ) ? file_get_contents( $key_input ) : $key_input;

		$valid = shaped_kit_verify_catalog_signature( $payload, $signature, $public_key );
		if ( $valid ) {
			echo "Signature VALID.\n";
			exit( 0 );
		} else {
			fwrite( STDERR, "Signature INVALID or corrupted.\n" );
			exit( 1 );
		}
	}
}
