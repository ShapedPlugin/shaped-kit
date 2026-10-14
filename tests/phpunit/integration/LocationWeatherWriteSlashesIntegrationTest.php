<?php
/**
 * Regression test for PRD E120: the Location Weather write tools keep backslashes.
 *
 * WordPress unslashes what wp_insert_post(), wp_update_post() and
 * update_post_meta() are given. Without slashing, create-view and update-view-settings drop the
 * backslashes an agent writes, and duplicate-view re-saves the copied settings
 * without theirs. Both editions share the namespace and the code (checked with
 * a normalised diff), so Pro's copy is the one loaded here.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedPlugin\Weather\Mcp\Tools\Entry_Tools;
use WP_UnitTestCase;

/**
 * Class LocationWeatherWriteSlashesIntegrationTest
 */
class LocationWeatherWriteSlashesIntegrationTest extends WP_UnitTestCase {

	/**
	 * Text with backslashes.
	 */
	const TEXT = 'Path C:\\Users\\me ¯\\_(ツ)_/¯';

	/**
	 * Load Location Weather and register its entry type.
	 */
	public function setUp(): void {
		parent::setUp();

		$autoload = dirname( __DIR__, 4 ) . '/location-weather-pro/vendor/autoload.php';
		if ( file_exists( $autoload ) && ! class_exists( Entry_Tools::class ) ) {
			require_once $autoload;
		}
		if ( ! class_exists( Entry_Tools::class ) ) {
			$this->markTestSkipped( 'location-weather-pro is not available next to the Kit.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! post_type_exists( 'location_weather' ) ) {
			register_post_type(
				'location_weather',
				array(
					'public'       => true,
					'supports'     => array( 'title', 'editor' ),
					'map_meta_cap' => true,
				)
			);
		}
	}

	/**
	 * Clean up the user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The create-view and update-view-settings tools keep backslashes in the title.
	 */
	public function test_create_and_update_keep_backslashes() {
		$created = Entry_Tools::create_entry(
			array(
				'title'       => self::TEXT,
				'location_by' => 'city_name',
				'city_name'   => 'Oslo, NO',
			)
		);
		$this->assertSame( self::TEXT, get_post( $created['id'] )->post_title );

		Entry_Tools::update_entry_settings(
			array(
				'id'    => $created['id'],
				'title' => 'Updated ' . self::TEXT,
			)
		);
		$this->assertSame( 'Updated ' . self::TEXT, get_post( $created['id'] )->post_title );
	}

	/**
	 * The duplicate-view tool copies stored settings with their backslashes, and keeps them in the new title.
	 */
	public function test_duplicate_keeps_backslashes_in_copied_settings() {
		$source = self::factory()->post->create(
			array(
				'post_type'  => 'location_weather',
				'post_title' => 'Source',
			)
		);
		update_post_meta( $source, 'sp_location_weather_generator', wp_slash( array( 'lw-city-name' => self::TEXT ) ) );

		$dup = Entry_Tools::duplicate_entry(
			array(
				'id'    => $source,
				'title' => self::TEXT,
			)
		);

		$meta = get_post_meta( $dup['id'], 'sp_location_weather_generator', true );
		$this->assertSame( self::TEXT, $meta['lw-city-name'] );
		$this->assertSame( self::TEXT, get_post( $dup['id'] )->post_title );
	}
}
