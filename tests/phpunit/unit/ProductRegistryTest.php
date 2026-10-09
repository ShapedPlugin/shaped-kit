<?php
/**
 * ProductRegistry: what the dashboard is allowed to see of what products register.
 *
 * Risk covered: another plugin's malformed or hostile entry reaching the dashboard, a switch that reads
 * "on" when the product is off, and two cards for one product.
 *
 * @package ShapedKit
 */

use PHPUnit\Framework\TestCase;
use ShapedKit\Mcp\ProductRegistry;

/**
 * Pure logic: entries and handlers are passed in, as the two filters would return them.
 */
final class ProductRegistryTest extends TestCase {

	/**
	 * A well-formed entry, as Location Weather registers it.
	 *
	 * @param array $overrides Fields to change.
	 *
	 * @return array
	 */
	private function entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'slug'            => 'location-weather',
				'name'            => 'Location Weather',
				'edition'         => 'free',
				'mcp_enabled'     => true,
				'tools_count'     => 5,
				'endpoint_url'    => 'https://site.test/wp-json/location-weather/mcp',
				'status'          => 'ready',
				'message'         => '',
				'adapter_version' => '0.4.1',
			),
			$overrides
		);
	}

	public function test_a_well_formed_entry_comes_through_unchanged(): void {
		$product = ProductRegistry::normalise( $this->entry() );

		$this->assertSame( 'location-weather', $product['slug'] );
		$this->assertSame( 'Location Weather', $product['name'] );
		$this->assertSame( 'free', $product['edition'] );
		$this->assertTrue( $product['enabled'] );
		$this->assertSame( 5, $product['tools_count'] );
		$this->assertSame( 'https://site.test/wp-json/location-weather/mcp', $product['endpoint_url'] );
		$this->assertSame( 'ready', $product['status'] );
		$this->assertSame( '0.4.1', $product['adapter_version'] );
	}

	public function test_an_entry_that_cannot_be_shown_is_dropped(): void {
		$this->assertNull( ProductRegistry::normalise( 'location-weather' ), 'Not an array.' );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'name' => '' ) ) ) );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'name' => '<b></b>' ) ) ), 'Nothing left after tags.' );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'name' => array( 'x' ) ) ) ) );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'slug' => '' ) ) ) );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'slug' => 'Location Weather' ) ) ), 'A slug is never repaired.' );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'slug' => '../x' ) ) ) );
		$this->assertNull( ProductRegistry::normalise( $this->entry( array( 'slug' => array( 'a' ) ) ) ) );
	}

	public function test_a_hostile_address_or_label_is_not_passed_on(): void {
		$product = ProductRegistry::normalise(
			$this->entry(
				array(
					'name'            => '<script>alert(1)</script>Evil',
					'endpoint_url'    => 'javascript:alert(1)',
					'status'          => 'ready"><img src=x>',
					'adapter_version' => '<b>1</b>',
					'edition'         => 'enterprise',
				)
			)
		);

		$this->assertSame( 'alert(1)Evil', $product['name'], 'Tags are removed.' );
		$this->assertSame( '', $product['endpoint_url'] );
		$this->assertSame( 'unknown', $product['status'] );
		$this->assertSame( '', $product['adapter_version'] );
		$this->assertSame( '', $product['edition'] );
	}

	public function test_the_string_false_is_off_not_on(): void {
		$product = ProductRegistry::normalise( $this->entry( array( 'mcp_enabled' => 'false' ) ) );

		$this->assertFalse( $product['enabled'] );
	}

	public function test_a_product_that_is_off_never_reads_ready(): void {
		$product = ProductRegistry::normalise(
			$this->entry(
				array(
					'mcp_enabled' => false,
					'status'      => 'ready',
				)
			)
		);

		$this->assertSame( 'disabled', $product['status'] );
	}

	public function test_the_products_own_answer_beats_what_its_entry_claimed(): void {
		$handlers = array(
			'location-weather' => array(
				'get_enabled' => static function () {
					return false;
				},
				'set_enabled' => static function () {
					return true;
				},
			),
		);

		$product = ProductRegistry::normalise( $this->entry( array( 'mcp_enabled' => true ) ), $handlers );

		$this->assertFalse( $product['enabled'] );
		$this->assertSame( 'disabled', $product['status'] );
		$this->assertTrue( $product['toggleable'] );
	}

	public function test_a_handler_that_throws_counts_as_off(): void {
		$handlers = array(
			'location-weather' => array(
				'get_enabled' => static function () {
					throw new RuntimeException( 'boom' );
				},
			),
		);

		$product = ProductRegistry::normalise( $this->entry( array( 'mcp_enabled' => true ) ), $handlers );

		$this->assertFalse( $product['enabled'], 'A product that fails to answer is never shown as on.' );
	}

	public function test_a_product_without_a_working_switch_is_not_toggleable(): void {
		$this->assertFalse( ProductRegistry::normalise( $this->entry() )['toggleable'] );
		$this->assertFalse(
			ProductRegistry::normalise( $this->entry(), array( 'location-weather' => array( 'set_enabled' => 'no_such_function' ) ) )['toggleable'],
			'A callable that does not exist is not a switch.'
		);
		$this->assertFalse(
			ProductRegistry::normalise( $this->entry(), array( 'another-product' => array( 'set_enabled' => 'strlen' ) ) )['toggleable'],
			'Another product\'s handler is not this product\'s.'
		);
	}

	public function test_the_first_entry_for_a_slug_wins_and_bad_neighbours_do_not_matter(): void {
		$products = ProductRegistry::collect(
			array(
				'junk',
				$this->entry( array( 'name' => 'First' ) ),
				$this->entry( array( 'name' => 'Second' ) ),
				$this->entry(
					array(
						'slug' => 'real-testimonials',
						'name' => 'Real Testimonials',
					)
				),
			),
			'not an array'
		);

		$this->assertSame( array( 'First', 'Real Testimonials' ), array_column( $products, 'name' ) );
		$this->assertSame( array( 0, 1 ), array_keys( $products ), 'A plain list, so it encodes as a JSON array.' );
		$this->assertSame( array(), ProductRegistry::collect( 'not an array', array() ) );
	}

	public function test_a_negative_or_text_tool_count_becomes_a_safe_number(): void {
		$this->assertSame( 0, ProductRegistry::normalise( $this->entry( array( 'tools_count' => -3 ) ) )['tools_count'] );
		$this->assertSame( 0, ProductRegistry::normalise( $this->entry( array( 'tools_count' => 'many' ) ) )['tools_count'] );
		$this->assertSame( 7, ProductRegistry::normalise( $this->entry( array( 'tools_count' => '7' ) ) )['tools_count'] );
	}
}
