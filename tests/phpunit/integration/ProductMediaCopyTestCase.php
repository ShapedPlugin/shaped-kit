<?php
/**
 * The media tools as a product serves them (PRD D22, §20 rule 6: every edition, every product).
 *
 * The behaviour itself is tested on the template (`ProductModuleMediaTest`). Here, for each product edition: its copy
 * is the template's, name for name, so a fix made in one place cannot be missing in another; the tools are wired to
 * the product's toolsets; a refusal works through the product's own classes; the audit hook carries keys only.
 *
 * Needs the sibling plugins, so every subclass is excluded from the CI suite.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class ProductMediaCopyTestCase
 */
abstract class ProductMediaCopyTestCase extends WP_UnitTestCase {

	/**
	 * Editions: plugin folder, namespace part, text domain, package name, ability slug.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	abstract public function editions();

	/**
	 * Load an edition and return its MCP namespace. A product that needs constants or post types defines them here.
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace part.
	 * @return string
	 */
	protected function load( $plugin, $ns ) {
		$prefix = 'ShapedPlugin\\' . $ns . '\\Mcp';
		$file   = dirname( __DIR__, 4 ) . '/' . $plugin . '/vendor/autoload.php';

		if ( ! class_exists( $prefix . '\\Media\\Media_Tools' ) && file_exists( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( $prefix . '\\Media\\Media_Tools' ) ) {
			$this->markTestSkipped( $plugin . ' is not available next to the Kit.' );
		}

		return $prefix;
	}

	/**
	 * An administrator, and no outbound request leaves.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Blocked in tests.' );
			}
		);
	}

	/**
	 * Each media file of the edition is the template's, with only the names a product changes.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin  Plugin folder.
	 * @param string $ns      Namespace part.
	 * @param string $domain  Text domain.
	 * @param string $package Package name.
	 */
	public function test_the_editions_media_files_are_the_templates_name_for_name( $plugin, $ns, $domain, $package ) {
		$this->load( $plugin, $ns );

		$template = dirname( __DIR__, 3 ) . '/templates/product-module/Media/';
		$copy     = dirname( __DIR__, 4 ) . '/' . $plugin . '/src/Mcp/Media/';
		$files    = glob( $template . '*.php' );

		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$name     = basename( $file );
			$expected = str_replace(
				array(
					'ShapedPlugin\\Template\\Mcp',
					"'template-domain'",
					"Copy the whole `Media/` folder into a product's MCP module. See README.md in this folder.",
					'ability definitions helper template.',
					'@package ShapedPlugin',
				),
				array(
					'ShapedPlugin\\' . $ns . '\\Mcp',
					"'" . $domain . "'",
					'Copied from the Shaped Kit product-module template (`templates/product-module/Media/`): keep the copies in step.',
					'ability definitions.',
					'@package ' . $package,
				),
				(string) file_get_contents( $file ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
			$expected = preg_replace( '/ \* The (\w+) template file: /', ' * $1: ', $expected );

			$this->assertFileExists( $copy . $name );
			$this->assertSame( $expected, file_get_contents( $copy . $name ), "{$plugin}/src/Mcp/Media/{$name} has drifted from the template." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	/**
	 * Finding is on by default; importing waits for Media Import; the dashboard lists the switch.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin  Plugin folder.
	 * @param string $ns      Namespace part.
	 * @param string $domain  Text domain.
	 * @param string $package Package name.
	 * @param string $slug    Ability slug.
	 */
	public function test_find_media_is_served_by_default_and_import_image_only_with_media_import_on( $plugin, $ns, $domain, $package, $slug ) {
		unset( $domain, $package );
		$prefix    = $this->load( $plugin, $ns );
		$registrar = $prefix . '\\Abilities_Registrar';
		$gate      = $prefix . '\\Permission_Gate';

		delete_option( $gate::TOOLSETS_OPTION );
		$this->assertContains( $slug . '/find-media', $registrar::names() );
		$this->assertNotContains( $slug . '/import-image', $registrar::names() );

		$gate::set_active_toolsets( array( 'core', 'management', 'media' ) );
		$this->assertContains( $slug . '/import-image', $registrar::names() );

		$media = wp_list_filter( $registrar::toolsets(), array( 'id' => 'media' ) );
		$this->assertSame( 1, reset( $media )['tools_count'] );
		$this->assertTrue( reset( $media )['enabled'] );

		$definitions = $registrar::definitions();
		$this->assertSame( array( $prefix . '\\Media\\Media_Tools', 'can_use' ), $definitions[ $slug . '/import-image' ]['permission_callback'] );
		delete_option( $gate::TOOLSETS_OPTION );
	}

	/**
	 * A private address is refused by the edition's own classes, before any request.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace part.
	 */
	public function test_the_edition_refuses_a_private_address_before_any_request( $plugin, $ns ) {
		$prefix   = $this->load( $plugin, $ns );
		$tools    = $prefix . '\\Media\\Media_Tools';
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$requests ) {
				++$requests;
				return $pre;
			},
			1
		);

		$result = $tools::import_image( array( 'images' => array( array( 'url' => 'https://169.254.169.254/latest/meta-data/' ) ) ) );

		$this->assertSame( 'failed', $result['images'][0]['status'] );
		$this->assertSame( 'unsafe_address', $result['images'][0]['error']['code'] );
		$this->assertSame( 0, $requests );
	}

	/**
	 * The audit hook any plugin can listen to carries argument keys, never a value.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin  Plugin folder.
	 * @param string $ns      Namespace part.
	 * @param string $domain  Text domain.
	 * @param string $package Package name.
	 * @param string $slug    Ability slug.
	 */
	public function test_the_audit_hook_carries_argument_keys_never_values( $plugin, $ns, $domain, $package, $slug ) {
		unset( $domain, $package );
		$prefix    = $this->load( $plugin, $ns );
		$registrar = $prefix . '\\Abilities_Registrar';
		$heard     = array();
		add_action(
			'shaped_kit/mcp_tool_called',
			static function ( $payload ) use ( &$heard ) {
				$heard[] = $payload;
			}
		);

		$wrapped = $registrar::wrap(
			$slug . '/test-tool',
			static function () {
				return array( 'ok' => true );
			}
		);
		$wrapped(
			array(
				'reviewer_email' => 'sarah@example.com',
				'url'            => 'https://cdn.example/a.png?X-Amz-Signature=secret',
			)
		);

		$this->assertCount( 1, $heard );
		$this->assertSame(
			array(
				'reviewer_email' => null,
				'url'            => null,
			),
			$heard[0]['arguments']
		);
	}
}
