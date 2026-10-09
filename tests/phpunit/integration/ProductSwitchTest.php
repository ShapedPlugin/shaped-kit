<?php
/**
 * ProductSwitch: switching a product through the handler it registered.
 *
 * Risk covered: a handler that refuses but is reported as a success, a product switched by a made-up
 * slug, an exception from another plugin leaking its message, and the Kit writing state itself.
 *
 * @package ShapedKit
 */

use ShapedKit\Mcp\ProductSwitch;

/**
 * Real WordPress (WP_Error, translations); the product lists are passed in, as the filters would return them.
 */
final class ProductSwitchTest extends WP_UnitTestCase {

	/**
	 * Whether the fake product is on.
	 *
	 * @var bool
	 */
	private $state = false;

	/**
	 * One product entry.
	 *
	 * @return array
	 */
	private function products(): array {
		return array(
			array(
				'slug' => 'location-weather',
				'name' => 'Location Weather',
			),
		);
	}

	/**
	 * Handlers for a product that stores its own state.
	 *
	 * @param callable|null $set Replacement for `set_enabled`.
	 *
	 * @return array
	 */
	private function handlers( ?callable $set = null ): array {
		return array(
			'location-weather' => array(
				'get_enabled' => function () {
					return $this->state;
				},
				'set_enabled' => $set ?? function ( $enabled ) {
					$this->state = $enabled;
					return true;
				},
			),
		);
	}

	public function test_the_product_is_switched_through_its_own_handler(): void {
		$this->assertTrue( ProductSwitch::apply( 'location-weather', true, $this->products(), $this->handlers() ) );
		$this->assertTrue( $this->state );

		$this->assertTrue( ProductSwitch::apply( 'location-weather', false, $this->products(), $this->handlers() ) );
		$this->assertFalse( $this->state );
	}

	public function test_a_slug_that_is_not_listed_is_refused_and_nothing_is_called(): void {
		$called   = false;
		$handlers = $this->handlers(
			function () use ( &$called ) {
				$called = true;
				return true;
			}
		);

		$result = ProductSwitch::apply( 'made-up', true, $this->products(), $handlers );

		$this->assertWPError( $result );
		$this->assertSame( 'shaped_kit_unknown_product', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertFalse( $called );
	}

	public function test_a_product_without_a_switch_is_refused(): void {
		$result = ProductSwitch::apply( 'location-weather', true, $this->products(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'shaped_kit_not_toggleable', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_a_refusal_returned_as_false_is_not_reported_as_success(): void {
		$result = ProductSwitch::apply(
			'location-weather',
			true,
			$this->products(),
			$this->handlers(
				static function () {
					return false;
				}
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'shaped_kit_toggle_failed', $result->get_error_code() );
	}

	public function test_a_false_is_a_failure_even_when_the_state_cannot_be_read_back(): void {
		$handlers = array(
			'location-weather' => array(
				'set_enabled' => static function () {
					return false;
				},
			),
		);

		$this->assertWPError( ProductSwitch::apply( 'location-weather', true, $this->products(), $handlers ) );
	}

	public function test_a_handler_that_says_true_but_did_not_change_is_caught(): void {
		$result = ProductSwitch::apply(
			'location-weather',
			true,
			$this->products(),
			$this->handlers(
				static function () {
					return true;
				}
			)
		);

		$this->assertWPError( $result, 'The state is read back, so a handler that lied is found out.' );
		$this->assertFalse( $this->state );
	}

	public function test_a_handlers_own_refusal_keeps_its_code_and_loses_its_markup(): void {
		$result = ProductSwitch::apply(
			'location-weather',
			true,
			$this->products(),
			$this->handlers(
				static function () {
					return new WP_Error( 'splw_mcp_toggle_forbidden', '<b>Not allowed</b><script>x()</script>', array( 'status' => 403 ) );
				}
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'splw_mcp_toggle_forbidden', $result->get_error_code() );
		$this->assertSame( 'Not allowed', $result->get_error_message(), 'Tags go, and a script goes with its contents.' );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_a_handler_that_throws_does_not_leak_its_message(): void {
		$result = ProductSwitch::apply(
			'location-weather',
			true,
			$this->products(),
			$this->handlers(
				static function () {
					throw new RuntimeException( 'SQLSTATE secret table name' );
				}
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'shaped_kit_toggle_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'SQLSTATE', $result->get_error_message() );
	}

	public function test_a_product_that_cannot_be_read_back_is_trusted_on_a_clear_success(): void {
		$handlers = array(
			'location-weather' => array(
				'set_enabled' => static function () {
					return null;
				},
			),
		);

		$this->assertTrue( ProductSwitch::apply( 'location-weather', true, $this->products(), $handlers ), 'A void handler that did not fail is a success.' );
	}
}
