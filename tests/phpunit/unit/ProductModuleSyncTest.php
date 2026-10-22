<?php
/**
 * The shared-file sync (`tools/sync-product-module.php`), run against real git repos in a temporary folder.
 *
 * The script writes into other plugins' repos, so a bug here can overwrite someone's uncommitted work, write outside
 * the module folder, or spread a broken copy into every product at once (PRD §19.9, E200 to E210). The fixtures
 * live under a folder whose path contains a space, as this machine's does (E206). The real template is used.
 *
 * Risk covered (TESTING.md): data changed wrongly (rule 2), a failure that stays silent (rule 4), a fix coming
 * back (rule 6).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/tools/sync-product-module.php';

/**
 * Class ProductModuleSyncTest
 */
class ProductModuleSyncTest extends TestCase {

	/**
	 * The Kit's template folder.
	 *
	 * @var string
	 */
	private $template;

	/**
	 * Temporary folder that plays the plugins folder.
	 *
	 * @var string
	 */
	private $plugins;

	/**
	 * A fresh plugins folder with Location Weather Free and Pro as committed git repos on the MCP branch.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->template = dirname( __DIR__, 3 ) . '/templates/product-module';
		$this->plugins  = sys_get_temp_dir() . '/shaped kit sync ' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->plugins );

		foreach ( array( 'location-weather', 'location-weather-pro' ) as $plugin ) {
			$this->product_repo( $plugin, 'includes/Mcp', array( 'Support/Pagination.php', 'Support/Secret_Mask.php', 'Support/WriteGuard.php' ) );
		}
	}

	/**
	 * Remove the temporary folder.
	 */
	protected function tearDown(): void {
		$this->remove( $this->plugins );
		parent::tearDown();
	}

	/**
	 * A product repo with an MCP module, the listed files holding old content, committed on the MCP branch.
	 *
	 * @param string   $plugin Plugin folder name.
	 * @param string   $module Module folder.
	 * @param string[] $files  Files to create inside the module.
	 */
	private function product_repo( $plugin, $module, array $files ) {
		$dir = $this->plugins . '/' . $plugin;
		mkdir( $dir . '/' . $module . '/Support', 0777, true );
		mkdir( $dir . '/' . $module . '/Media', 0777, true );

		foreach ( $files as $file ) {
			file_put_contents( $dir . '/' . $module . '/' . $file, "<?php\n// old copy\n" );
		}
		file_put_contents( $dir . '/' . $module . '/Module.php', "<?php\n// product plumbing\n" );

		$this->git( $dir, array( 'init', '-q', '-b', 'feat/mcp-module' ) );
		$this->git( $dir, array( 'add', '-A' ) );
		$this->git( $dir, array( '-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'start' ) );
	}

	/**
	 * Run git in a repo.
	 *
	 * @param string   $dir  Repo.
	 * @param string[] $args Arguments.
	 */
	private function git( $dir, array $args ) {
		$result = shaped_kit_sync_run( array_merge( array( 'git' ), $args ), $dir );
		$this->assertSame( 0, $result['status'], 'git ' . implode( ' ', $args ) . ': ' . $result['output'] );
	}

	/**
	 * Every file under a folder with its hash, to prove nothing changed.
	 *
	 * @param string $dir Folder.
	 *
	 * @return array<string, string>
	 */
	private function snapshot( $dir ) {
		$hashes = array();
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( false === strpos( $file->getPathname(), '/.git/' ) ) {
				$hashes[ substr( $file->getPathname(), strlen( $dir ) ) ] = md5_file( $file->getPathname() );
			}
		}
		ksort( $hashes );

		return $hashes;
	}

	/**
	 * Delete a folder tree without a shell.
	 *
	 * @param string $dir Folder.
	 */
	private function remove( $dir ) {
		if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
			return;
		}

		if ( is_link( $dir ) || is_file( $dir ) ) {
			unlink( $dir );
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $dir . '/' . $entry );
			}
		}
		rmdir( $dir );
	}

	public function test_every_template_file_becomes_a_clean_copy_for_every_edition(): void {
		$checked = 0;

		foreach ( shaped_kit_sync_products() as $product ) {
			foreach ( $product['editions'] as $edition ) {
				foreach ( $product['files'] as $file ) {
					$output = shaped_kit_sync_transform( (string) file_get_contents( $this->template . '/' . $file ), dirname( $file ), $product, $edition );

					$this->assertSame( array(), shaped_kit_sync_output_problems( $output ), "{$edition['plugin']} {$file}" );
					$this->assertStringContainsString( 'namespace ShapedPlugin\\' . $edition['namespace'] . '\\Mcp\\', $output );
					$this->assertStringContainsString( '@package ' . $edition['package'], $output );
					++$checked;
				}
			}
		}

		$this->assertGreaterThan( 40, $checked );
	}

	public function test_only_exact_placeholders_are_replaced_and_an_unmapped_one_is_caught(): void {
		$products = shaped_kit_sync_products();
		$product  = $products['location-weather'];
		$source   = "<?php\n/**\n * The Demo template file: a weather template for a Template block.\n */\nnamespace ShapedPlugin\\Template\\Mcp\\Support;\n"
			. "\$a = __( 'x', 'template-domain' );\n\$b = 'template_prefix_mcp_cf_';\n\$c = \"template-slug/delete\";\n\$d = 'template';\n";
		$output   = shaped_kit_sync_transform( $source, 'Support', $product, $product['editions']['pro'] );

		$this->assertStringContainsString( ' * Demo: a weather template for a Template block.', $output, 'Bare words stay (E204).' );
		$this->assertStringContainsString( "\$d = 'template';", $output );
		$this->assertStringContainsString( "'location-weather'", $output );
		$this->assertStringContainsString( "'splw_mcp_cf_'", $output );
		$this->assertStringContainsString( '"location-weather/delete"', $output );
		$this->assertSame( array(), shaped_kit_sync_output_problems( $output ) );

		$unmapped = shaped_kit_sync_transform( "<?php\n\$hook = 'template_prefix/mcp_loaded';\n", 'Support', $product, $product['editions']['pro'] );
		$this->assertSame( array( 'still holds the template name template_prefix' ), shaped_kit_sync_output_problems( $unmapped ), 'A placeholder the map does not know is refused, not shipped (E203).' );
	}

	public function test_a_third_party_name_in_a_comment_is_refused_but_not_in_code(): void {
		$this->assertSame(
			array( 'names a third-party product in a comment (Rank Math)' ),
			shaped_kit_sync_output_problems( "<?php\n// As Rank Math bundles it.\n\$slug = 'woocommerce';\n" ),
			'C8 covers comments; a dependency slug in code is data (E210).'
		);
		$this->assertSame( array(), shaped_kit_sync_output_problems( "<?php\n\$deps = array( 'woocommerce' );\n" ) );
	}

	public function test_a_template_file_with_windows_line_endings_or_a_byte_order_mark_is_refused(): void {
		$this->assertSame( array( 'has Windows line endings' ), shaped_kit_sync_source_problems( "<?php\r\n" ) );
		$this->assertSame( array( 'starts with a byte-order mark' ), shaped_kit_sync_source_problems( "\xEF\xBB\xBF<?php\n" ) );
		$this->assertSame( array(), shaped_kit_sync_source_problems( "<?php\n" ) );
	}

	public function test_a_copy_that_does_not_parse_is_refused(): void {
		$this->assertSame( 'does not parse', shaped_kit_sync_lint( "<?php\nfunction (\n" ) );
		$this->assertSame( '', shaped_kit_sync_lint( "<?php\n\$a = 1;\n" ) );
	}

	public function test_a_dry_run_writes_nothing_and_a_write_makes_both_editions_identical(): void {
		$before = $this->snapshot( $this->plugins );
		$plan   = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather' );

		$this->assertSame( array(), $plan['problems'] );
		$this->assertSame( array( 'changed' ), array_values( array_unique( array_column( $plan['files'], 'status' ) ) ) );
		$this->assertSame( $before, $this->snapshot( $this->plugins ), 'Planning writes nothing.' );

		$this->assertSame( array(), shaped_kit_sync_write( $plan ) );

		foreach ( array( 'Pagination', 'Secret_Mask', 'WriteGuard' ) as $name ) {
			$free = $this->plugins . "/location-weather/includes/Mcp/Support/{$name}.php";
			$this->assertSame( md5_file( $free ), md5_file( $this->plugins . "/location-weather-pro/includes/Mcp/Support/{$name}.php" ), "Both editions share one namespace, so the copies are byte-identical (E216): {$name}." );
		}

		$this->assertSame( "<?php\n// product plumbing\n", file_get_contents( $this->plugins . '/location-weather/includes/Mcp/Module.php' ), 'A file not on the list is never touched.' );
		$this->assertSame( array(), glob( $this->plugins . '/location-weather/includes/Mcp/Support/*.sync-*' ), 'No temporary file is left behind.' );

		$this->git( $this->plugins . '/location-weather', array( 'add', '-A' ) );
		$this->git( $this->plugins . '/location-weather', array( '-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'sync' ) );
		$this->git( $this->plugins . '/location-weather-pro', array( 'add', '-A' ) );
		$this->git( $this->plugins . '/location-weather-pro', array( '-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'sync' ) );

		$again = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather' );
		$this->assertSame( array( 'same' ), array_values( array_unique( array_column( $again['files'], 'status' ) ) ), 'A second run finds nothing to change.' );
	}

	public function test_uncommitted_work_in_one_edition_blocks_both_and_nothing_is_written(): void {
		file_put_contents( $this->plugins . '/location-weather-pro/includes/Mcp/Support/Pagination.php', "<?php\n// someone's unsaved edit\n" );
		$before = $this->snapshot( $this->plugins );

		$plan = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather' );

		$this->assertCount( 1, $plan['problems'] );
		$this->assertStringContainsString( 'location-weather-pro has uncommitted changes in a target file', $plan['problems'][0] );
		$this->assertSame( array( 'the plan has problems; nothing written' ), shaped_kit_sync_write( $plan ) );
		$this->assertSame( $before, $this->snapshot( $this->plugins ), 'The Free edition, which was clean, was not written either (E201, E205).' );
	}

	public function test_an_untracked_file_where_a_copy_would_go_is_protected_too(): void {
		$path = $this->plugins . '/location-weather/includes/Mcp/Support/Secret_Mask.php';
		$this->git( $this->plugins . '/location-weather', array( 'rm', '-q', '--cached', 'includes/Mcp/Support/Secret_Mask.php' ) );
		$this->git( $this->plugins . '/location-weather', array( '-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'untrack' ) );
		file_put_contents( $path, "<?php\n// a new file nobody committed yet\n" );

		$plan = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather' );

		$this->assertCount( 1, $plan['problems'] );
		$this->assertStringContainsString( 'location-weather has uncommitted changes in a target file: ?? includes/Mcp/Support/Secret_Mask.php', $plan['problems'][0] );
		$this->assertSame( "<?php\n// a new file nobody committed yet\n", file_get_contents( $path ) );
	}

	public function test_a_repo_on_another_branch_or_not_a_repo_is_refused(): void {
		$this->git( $this->plugins . '/location-weather', array( 'checkout', '-q', '-b', 'master-copy' ) );
		$this->remove( $this->plugins . '/location-weather-pro/.git' );

		$problems = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather' )['problems'];

		$this->assertContains( 'location-weather is on branch master-copy, not feat/mcp-module', $problems );
		$this->assertContains( 'location-weather-pro is not a git repository', $problems );
	}

	public function test_a_symlink_a_missing_folder_or_a_missing_edition_is_refused(): void {
		$outside = $this->plugins . '/outside';
		mkdir( $outside );
		$this->remove( $this->plugins . '/location-weather/includes/Mcp/Support' );
		symlink( $outside, $this->plugins . '/location-weather/includes/Mcp/Support' );

		$link_file = $this->plugins . '/location-weather-pro/includes/Mcp/Support/WriteGuard.php';
		$this->remove( $link_file );
		symlink( $outside . '/target.php', $link_file );

		$problems = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather', false )['problems'];

		$this->assertContains( 'location-weather/includes/Mcp/Support/Pagination.php: a folder on the way is a symlink', $problems );
		$this->assertContains( 'location-weather-pro/includes/Mcp/Support/WriteGuard.php: the file is a symlink', $problems );
		$this->assertFileDoesNotExist( $outside . '/target.php' );

		$this->remove( $this->plugins . '/location-weather-pro' );
		$missing = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather', false )['problems'];
		$this->assertContains( 'location-weather-pro/includes/Mcp/Support/Pagination.php: the plugin folder does not exist', $missing, 'One edition alone is never synced (E205).' );
		$this->assertDirectoryDoesNotExist( $this->plugins . '/location-weather-pro', 'No folder is created.' );
	}

	public function test_a_module_folder_that_is_missing_is_never_created(): void {
		$this->remove( $this->plugins . '/location-weather/includes/Mcp/Support' );

		$problems = shaped_kit_sync_plan( $this->template, $this->plugins, 'location-weather', false )['problems'];

		$this->assertContains( 'location-weather/includes/Mcp/Support/WriteGuard.php: the folder does not exist (the script creates none)', $problems );
		$this->assertDirectoryDoesNotExist( $this->plugins . '/location-weather/includes/Mcp/Support', 'A product without the folder is a wrong map or a wrong product, never a reason to create one (E200).' );
	}

	public function test_an_unknown_product_is_refused(): void {
		$plan = shaped_kit_sync_plan( $this->template, $this->plugins, 'shaped-cart' );

		$this->assertSame( array(), $plan['files'] );
		$this->assertStringStartsWith( 'unknown product shaped-cart', $plan['problems'][0] );
	}
}
