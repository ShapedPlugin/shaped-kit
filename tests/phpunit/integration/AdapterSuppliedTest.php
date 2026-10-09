<?php
/**
 * Smoke test: inside a real WordPress with no other adapter, the Kit supplies its bundled adapter.
 *
 * @package ShapedKit
 */

use ShapedKit\Mcp\AdapterBootstrap;

/**
 * The isolated test install (.wordpress/) has no Rank Math, WooCommerce or standalone adapter, so the
 * Kit's own copy must be the one running (PRD §16.10, "Kit supplies").
 */
final class AdapterSuppliedTest extends WP_UnitTestCase {

	/**
	 * The Kit loaded its bundled adapter and reports it as compatible.
	 *
	 * @return void
	 */
	public function test_kit_supplies_the_adapter(): void {
		$status = AdapterBootstrap::status();

		$this->assertTrue( $status['abilities_api'], 'WordPress 6.9+ ships the Abilities API.' );
		$this->assertTrue( $status['loaded'] );
		$this->assertTrue( $status['compatible'] );
		$this->assertSame( AdapterBootstrap::SOURCE_KIT, $status['source'] );
	}

	/**
	 * Installing the Kit enables nothing by itself, including the adapter's shared default server (FR-S1).
	 *
	 * @return void
	 */
	public function test_default_server_stays_off(): void {
		$this->assertFalse( (bool) apply_filters( 'mcp_adapter_create_default_server', true ) );
	}
}
