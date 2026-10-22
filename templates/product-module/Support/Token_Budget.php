<?php
/**
 * The Token_Budget template file: measures what a set of tools costs an AI client on every message.
 *
 * Copy this file into a product's MCP module. See README.md.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The size of served tools, so a product can pin the cost of what is on by default (E126).
 *
 * An MCP client receives every served tool's name, description and input schema in `tools/list` and carries them
 * with every message. A tool added to a default-on toolset is therefore a standing cost on every site, which is
 * why large tools belong in an opt-in toolset. This class gives the measure; the product decides the ceiling.
 *
 * Use it in a test:
 *
 *     $size = Token_Budget::total(
 *         Abilities_Registrar::definitions(),
 *         Abilities_Registrar::ability_toolset_map(),
 *         array( 'core', 'management' )
 *     );
 *     $this->assertLessThanOrEqual( 5200, $size );
 */
class Token_Budget {

	/**
	 * Characters per token, a rule of thumb and not a tokenizer. Good enough to compare, not to bill.
	 */
	const CHARS_PER_TOKEN = 4;

	/**
	 * Characters of one tool: name, description and input schema.
	 *
	 * @param string               $name       Ability name.
	 * @param array<string, mixed> $definition The tool's definition.
	 *
	 * @return int
	 */
	public static function size( $name, array $definition ) {
		$schema = isset( $definition['input_schema'] ) ? (string) wp_json_encode( $definition['input_schema'] ) : '';

		return strlen( (string) $name ) + strlen( (string) ( $definition['description'] ?? '' ) ) + strlen( $schema );
	}

	/**
	 * Characters of every tool whose toolset is in `$toolsets`. A tool missing from the map counts as `core`.
	 *
	 * @param array<string, array<string, mixed>> $definitions Definitions keyed by ability name.
	 * @param array<string, string>               $map         Ability name => toolset id.
	 * @param string[]                            $toolsets    Toolset ids to add up.
	 *
	 * @return int
	 */
	public static function total( array $definitions, array $map, array $toolsets ) {
		$total = 0;

		foreach ( $definitions as $name => $definition ) {
			if ( in_array( $map[ $name ] ?? 'core', $toolsets, true ) ) {
				$total += self::size( $name, $definition );
			}
		}

		return $total;
	}

	/**
	 * Characters turned into tokens by the rule of thumb.
	 *
	 * @param int $characters Characters.
	 *
	 * @return int
	 */
	public static function tokens( $characters ) {
		return (int) ceil( max( 0, (int) $characters ) / self::CHARS_PER_TOKEN );
	}
}
