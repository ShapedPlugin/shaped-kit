#!/usr/bin/env bash
#
# Installs what the integration suite needs: WordPress core and the wordpress-develop test library, both pinned,
# and a wp-tests-config.php for the database named in the environment.
#
#   core          pinned by the SHA-256 of its tarball
#   test library  pinned by the commit its tag points at (a moved tag is refused)
#
# The test library DROPS and recreates every table in the database on each run. The database name must contain
# "test" so this cannot be pointed at a real site by mistake.
#
# Usage:
#   DB_NAME=shaped_kit_tests DB_USER=root DB_PASS=root DB_HOST=127.0.0.1 tools/install-wp-tests.sh
#
# Optional: WP_CORE_DIR and WP_TESTS_DIR (default: .wordpress and .wordpress-tests-lib in the plugin folder).
#
# To move to another WordPress version, change the three pins below together: the version, the tarball's SHA-256
# (sha256sum of https://wordpress.org/wordpress-<version>.tar.gz) and the commit of the tag
# (git ls-remote --tags https://github.com/WordPress/wordpress-develop.git <version>).

set -euo pipefail

WP_VERSION="7.1.3"
WP_CORE_SHA256="d2a09acb6a15e3b9c471d72557753c266d6f41a79ed19bfe04cbfe49e283b2a5"
WP_DEVELOP_SHA="ab1bc858a3f02a99c175d9e93c019254a4d5692c"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_CORE_DIR="${WP_CORE_DIR:-$ROOT/.wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-$ROOT/.wordpress-tests-lib}"

fail() {
	echo "install-wp-tests: $*" >&2
	exit 1
}

: "${DB_NAME:?Set DB_NAME (a throwaway database whose name contains \"test\").}"
: "${DB_USER:?Set DB_USER.}"
: "${DB_PASS?Set DB_PASS (it may be empty).}"
: "${DB_HOST:?Set DB_HOST (for example 127.0.0.1 or localhost:/path/to/mysqld.sock).}"

case "$DB_NAME" in
	*test*) ;;
	*) fail "DB_NAME \"$DB_NAME\" does not contain \"test\". The test library drops every table in it; refusing." ;;
esac

sha256_of() {
	if command -v sha256sum > /dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

# A value as a PHP single-quoted string body.
php_str() {
	printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# WordPress core.
if grep -q "wp_version = '$WP_VERSION'" "$WP_CORE_DIR/wp-includes/version.php" 2> /dev/null; then
	echo "WordPress $WP_VERSION is already in $WP_CORE_DIR"
else
	curl -fsSL --retry 3 -o "$WORK/core.tar.gz" "https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
	[ "$(sha256_of "$WORK/core.tar.gz")" = "$WP_CORE_SHA256" ] || fail "The WordPress $WP_VERSION tarball does not match the pinned SHA-256. Not installing it."
	mkdir "$WORK/core"
	tar -xzf "$WORK/core.tar.gz" -C "$WORK/core"
	rm -rf "$WP_CORE_DIR"
	mkdir -p "$(dirname "$WP_CORE_DIR")"
	mv "$WORK/core/wordpress" "$WP_CORE_DIR"
	echo "Installed WordPress $WP_VERSION in $WP_CORE_DIR"
fi

# The test library: only the two folders the bootstrap reads.
if [ -f "$WP_TESTS_DIR/includes/functions.php" ] && [ -d "$WP_TESTS_DIR/data" ] && [ -f "$WP_TESTS_DIR/.pinned-$WP_DEVELOP_SHA" ]; then
	echo "The test library is already in $WP_TESTS_DIR"
else
	git clone --quiet --depth 1 --branch "$WP_VERSION" --filter=blob:none --sparse \
		https://github.com/WordPress/wordpress-develop.git "$WORK/develop"
	git -C "$WORK/develop" sparse-checkout set tests/phpunit/includes tests/phpunit/data
	[ "$(git -C "$WORK/develop" rev-parse HEAD)" = "$WP_DEVELOP_SHA" ] || fail "The wordpress-develop tag $WP_VERSION no longer points at the pinned commit. Not installing it."
	rm -rf "$WP_TESTS_DIR"
	mkdir -p "$WP_TESTS_DIR"
	mv "$WORK/develop/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	mv "$WORK/develop/tests/phpunit/data" "$WP_TESTS_DIR/data"
	: > "$WP_TESTS_DIR/.pinned-$WP_DEVELOP_SHA"
	echo "Installed the test library for $WP_VERSION in $WP_TESTS_DIR"
fi

# The config. Test-only keys; nothing here protects real data.
cat > "$WP_TESTS_DIR/wp-tests-config.php" << PHP
<?php
// Written by tools/install-wp-tests.sh. Not tracked.
define( 'ABSPATH', '$(php_str "$WP_CORE_DIR")/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '$(php_str "$DB_NAME")' );
define( 'DB_USER', '$(php_str "$DB_USER")' );
define( 'DB_PASSWORD', '$(php_str "$DB_PASS")' );
define( 'DB_HOST', '$(php_str "$DB_HOST")' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'AUTH_KEY', 'shaped-kit-tests-auth-key' );
define( 'SECURE_AUTH_KEY', 'shaped-kit-tests-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'shaped-kit-tests-logged-in-key' );
define( 'NONCE_KEY', 'shaped-kit-tests-nonce-key' );
define( 'AUTH_SALT', 'shaped-kit-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'shaped-kit-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'shaped-kit-tests-logged-in-salt' );
define( 'NONCE_SALT', 'shaped-kit-tests-nonce-salt' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
// The Kit's isolated PHPUnit toolchain, read by the library's includes/bootstrap.php.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', '$(php_str "$ROOT")/tools/phpunit/vendor/yoast/phpunit-polyfills' );
PHP

echo "Wrote $WP_TESTS_DIR/wp-tests-config.php for database $DB_NAME"
