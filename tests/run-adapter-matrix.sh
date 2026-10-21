#!/usr/bin/env bash
#
# Run the adapter suite (a product's MCP prompts and tools on the wire) once per MCP adapter copy,
# per product, per edition and per Builder-toolset state.
#
# A product is a profile in tests/phpunit/adapter/products/ (data: which plugin, which names). By default
# every profile runs: Real Testimonials (needs the sibling plugins) and `template`, the product-module
# template itself (needs nothing but the Kit). Choose with SHAPED_KIT_PRODUCTS.
#
# One PHP process can load only one adapter copy (they share class names), and the server reads the
# toolsets while it is built, so every combination gets its own process. With no arguments it looks
# at the copies the plugins next to the Kit bundle, plus the Kit's own. Pass directories (each the
# folder holding includes/) to test specific ones:
#
#   tests/run-adapter-matrix.sh
#   tests/run-adapter-matrix.sh /path/to/mcp-adapter /path/to/another
#   tests/run-adapter-matrix.sh /path/to/adapter-tag:/path/to/its/php-mcp-schema/src
#
# A bare checkout of a release tag has no schema package, and each adapter release needs the exact
# schema release it pins in composer.lock (a mismatch is a fatal class-compatibility error), so a
# schema directory can follow a colon.
#
# Environment: WP_TESTS_DIR (default .wordpress-tests-lib in the Kit), SHAPED_KIT_PRODUCTS (default
# "real-testimonials template"), SHAPED_KIT_EDITIONS (default: the profile's editions),
# SHAPED_KIT_BUILDER_STATES (default "on off"). The earlier SHAPED_KIT_RT_EDITIONS and
# SHAPED_KIT_RT_BUILDER_STATES still work.
#
# Copies older than 0.5.0 have no MCP prompts: the tests that need prompts are left out for them,
# and the rest prove the server still serves its tools and not the prompt.
set -u

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS_DIR="$(dirname "$PLUGIN_DIR")"
export WP_TESTS_DIR="${WP_TESTS_DIR:-$PLUGIN_DIR/.wordpress-tests-lib}"
PRODUCTS="${SHAPED_KIT_PRODUCTS:-real-testimonials wp-carousel template}"
EDITIONS_OVERRIDE="${SHAPED_KIT_EDITIONS:-${SHAPED_KIT_RT_EDITIONS:-}}"
STATES="${SHAPED_KIT_BUILDER_STATES:-${SHAPED_KIT_RT_BUILDER_STATES:-on off}}"
FIRST_WITH_PROMPTS="0.5.0"

if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
	echo "WordPress test library not found at $WP_TESTS_DIR (set WP_TESTS_DIR)." >&2
	exit 2
fi

if [ "$#" -gt 0 ]; then
	COPIES=("$@")
else
	COPIES=()
	for d in "$PLUGINS_DIR"/*/vendor/wordpress/mcp-adapter "$PLUGIN_DIR"/libs/mcp-adapter; do
		[ -d "$d/includes" ] && COPIES+=("$d")
	done
fi

if [ "${#COPIES[@]}" -eq 0 ]; then
	echo "No MCP adapter copies found." >&2
	exit 2
fi

version_of() {
	grep -E "const VERSION *=" "$1/includes/Core/McpAdapter.php" | head -1 | sed -E "s/.*'([^']+)'.*/\1/"
}

older_than() {
	[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" != "$2" ]
}

FAILED=0
printf '%-9s %-18s %-8s %-8s %-7s %s\n' "ADAPTER" "PRODUCT" "EDITION" "BUILDER" "RESULT" "COPY"
for entry in "${COPIES[@]}"; do
	dir="${entry%%:*}"
	schema=""
	[ "$entry" != "$dir" ] && schema="${entry#*:}"
	version="$(version_of "$dir")"

	for product in $PRODUCTS; do
		suite="$(php "$PLUGIN_DIR/tests/phpunit/adapter/profile.php" "$product" suite)" || exit 2
		editions="${EDITIONS_OVERRIDE:-$(php "$PLUGIN_DIR/tests/phpunit/adapter/profile.php" "$product" editions)}"

		for edition in $editions; do
			for state in $STATES; do
				filter=(--group "builder-$state")
				if older_than "$version" "$FIRST_WITH_PROMPTS"; then
					filter+=(--exclude-group needs-prompts)
				fi

				output="$(cd "$PLUGIN_DIR" && SHAPED_KIT_MCP_SCHEMA_DIR="$schema" SHAPED_KIT_MCP_ADAPTER_DIR="$dir" SHAPED_KIT_PRODUCT="$product" SHAPED_KIT_EDITION="$edition" SHAPED_KIT_BUILDER="$state" SHAPED_KIT_PHPUNIT_BOOTSTRAP=adapter \
					tools/phpunit/vendor/bin/phpunit -c phpunit.xml.dist --testsuite "$suite" "${filter[@]}" 2>&1)"
				summary="$(echo "$output" | grep -E "^(OK|Tests:)" | tail -2 | tr '\n' ' ')"

				# A clean "OK (n tests ...)" only: "OK, but ... skipped" means a test did not run, which
				# is not a pass for a matrix whose point is that every row ran.
				if echo "$output" | grep -qE "^OK \("; then
					result="pass"
				else
					result="FAIL"
					FAILED=1
				fi

				printf '%-9s %-18s %-8s %-8s %-7s %s\n' "$version" "$product" "$edition" "$state" "$result" "${dir#"$PLUGINS_DIR"/}"
				if [ "$result" = "FAIL" ]; then
					echo "$output" | grep -E "^[0-9]+\) |Fatal|Error:" | sed 's/^/            /'
					[ -n "$summary" ] && echo "            $summary"
				fi
			done
		done
	done
done

exit "$FAILED"
