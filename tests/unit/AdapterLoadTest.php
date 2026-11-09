<?php
/**
 * Loader tests for AdapterBootstrap::maybe_load(): given a site state, does the Kit load its bundled
 * adapter or step aside? No WordPress, no third-party packages. Each scenario runs in a fresh PHP
 * process because classes and constants cannot be undefined once set.
 *
 * Run: php tests/unit/AdapterLoadTest.php
 *
 * @package ShapedKit
 */

// ---------------------------------------------------------------------------------------------
// Child process: one scenario against a throwaway site tree.
// ---------------------------------------------------------------------------------------------
if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
	$scenario = json_decode( $argv[2], true );
	$root     = $argv[3];

	define( 'ABSPATH', $root . '/' );
	define( 'WP_PLUGIN_DIR', $root . '/plugins' );
	define( 'SHAPED_KIT_DIR', $root . '/' . $scenario['kit'] . '/' );

	if ( ! empty( $scenario['disable'] ) ) {
		define( 'SHAPED_KIT_DISABLE_BUNDLED_MCP_ADAPTER', true );
	}

	$GLOBALS['wp_version']     = $scenario['wp_version'];
	$GLOBALS['active_files']   = array_map(
		function ( $plugin ) {
			return WP_PLUGIN_DIR . '/' . $plugin;
		},
		$scenario['active']
	);
	$GLOBALS['filters']        = array();
	$GLOBALS['entry_required'] = false;
	$_REQUEST                  = $scenario['request'];

	if ( ! empty( $scenario['cli'] ) ) {
		define( 'WP_CLI', true );
		$GLOBALS['cli_runner'] = (object) array(
			'arguments'  => $scenario['cli'][0],
			'assoc_args' => $scenario['cli'][1],
		);
		eval( 'class WP_CLI { public static function get_runner() { return $GLOBALS["cli_runner"]; } }' ); // phpcs:ignore Squiz.PHP.Eval -- simulates WP-CLI's runner.
	}

	if ( ! empty( $scenario['cli_argv'] ) ) {
		define( 'WP_CLI', true ); // No WP_CLI class: forces the raw-argv fallback.
		$GLOBALS['argv'] = $scenario['cli_argv'];
	}

	$GLOBALS['adapter_started'] = 0;

	if ( ! empty( $scenario['autoload_own'] ) ) {
		// Another plugin's autoloader that resolves the adapter class to the Kit's own bundled file.
		spl_autoload_register(
			function ( $name ) {
				if ( 'WP\MCP\Core\McpAdapter' === $name ) {
					require SHAPED_KIT_DIR . 'libs/mcp-adapter/includes/Core/McpAdapter.php';
				}
			}
		);
	}

	if ( ! empty( $scenario['preloaded'] ) && in_array( $scenario['preloaded'], array( 'unstarted', 'started', 'unstarted-no-version', 'no-singleton' ), true ) ) {
		// A copy with the singleton every released adapter has: instance() sets it once.
		$version  = 'unstarted-no-version' === $scenario['preloaded'] ? '' : 'const VERSION = "0.1.0";';
		$property = 'no-singleton' === $scenario['preloaded'] ? '' : 'private static self $instance;';
		$body     = 'no-singleton' === $scenario['preloaded']
			? '++$GLOBALS["adapter_started"]; return new self();'
			: 'if ( ! isset( self::$instance ) ) { self::$instance = new self(); ++$GLOBALS["adapter_started"]; } return self::$instance;';
		eval( 'namespace WP\MCP\Core; final class McpAdapter { ' . $version . ' ' . $property . ' public static function instance(): self { ' . $body . ' } }' ); // phpcs:ignore Squiz.PHP.Eval -- simulates another plugin's copy.
		if ( 'started' === $scenario['preloaded'] ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	} elseif ( ! empty( $scenario['preloaded'] ) ) {
		if ( 'no-version' === $scenario['preloaded'] ) {
			eval( 'namespace WP\MCP\Core; class McpAdapter {}' ); // phpcs:ignore Squiz.PHP.Eval -- a copy that reports no version.
		} else {
			$version = is_string( $scenario['preloaded'] ) ? $scenario['preloaded'] : '0.4.1';
			eval( 'namespace WP\MCP\Core; class McpAdapter { const VERSION = "' . $version . '"; }' ); // phpcs:ignore Squiz.PHP.Eval -- simulates another plugin's copy.
		}
	}

	// The WordPress functions the loader touches.
	function add_filter( $hook, $callback, $priority = 10 ) {
		$GLOBALS['filters'][ $hook ] = $priority;
		return true;
	}
	function remove_filter( $hook ) {
		unset( $GLOBALS['filters'][ $hook ] );
		return true;
	}
	function add_action( $hook, $callback, $priority = 10 ) {
		$GLOBALS['actions'][ $hook ] = array( $callback, $priority );
		return true;
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function is_multisite() {
		return false;
	}
	function wp_get_active_and_valid_plugins() {
		return $GLOBALS['active_files'];
	}
	function wp_unslash( $value ) {
		return $value;
	}

	require __DIR__ . '/../../src/Mcp/AdapterBootstrap.php';

	$GLOBALS['actions'] = array();

	$loaded            = \ShapedKit\Mcp\AdapterBootstrap::maybe_load();
	$filter_registered = isset( $GLOBALS['filters']['mcp_adapter_create_default_server'] );

	// What the late check registered, and what it does when its hook fires.
	$watch          = null;
	$started_before = $GLOBALS['adapter_started'];
	foreach ( array( 'rest_api_init', 'init' ) as $hook ) {
		if ( isset( $GLOBALS['actions'][ $hook ] ) ) {
			$watch = $hook . '@' . $GLOBALS['actions'][ $hook ][1];
			$GLOBALS['actions'][ $hook ][0]();
		}
	}

	echo json_encode(
		array(
			'loaded'             => $loaded,
			'entry_required'     => $GLOBALS['entry_required'],
			'filter_registered'  => $filter_registered,
			'default_server'     => \ShapedKit\Mcp\AdapterBootstrap::filter_default_server( true ),
			'status'             => \ShapedKit\Mcp\AdapterBootstrap::status(),
			'watch'              => $watch,
			'started_by_watch'   => $GLOBALS['adapter_started'] - $started_before,
			'filter_after_watch' => isset( $GLOBALS['filters']['mcp_adapter_create_default_server'] ),
		)
	);
	exit( 0 );
}

// ---------------------------------------------------------------------------------------------
// Parent process: build the site tree, run every scenario, compare.
// ---------------------------------------------------------------------------------------------
$root = sys_get_temp_dir() . '/shaped-kit-load-test-' . getmypid();

$entry_with_class    = "<?php\nnamespace WP\\MCP\\Core;\n\$GLOBALS['entry_required'] = true;\nclass McpAdapter { const VERSION = '0.7.0'; }\n";
$entry_without_class = "<?php\n\$GLOBALS['entry_required'] = true;\n";

// The Kit's copy when another plugin's autoloader already resolved the class to it: the entry
// starts the loaded class, as the real one does through Plugin::instance().
$own_class = "<?php\nnamespace WP\\MCP\\Core;\nfinal class McpAdapter { const VERSION = '0.7.1'; private static self \$instance; public static function instance(): self { if ( ! isset( self::\$instance ) ) { self::\$instance = new self(); ++\$GLOBALS['adapter_started']; } return self::\$instance; } }\n";
$own_entry = "<?php\n\$GLOBALS['entry_required'] = true;\n\\WP\\MCP\\Core\\McpAdapter::instance();\n";

$tree = array(
	'kit-auto/libs/mcp-adapter/mcp-adapter.php'           => $own_entry,
	'kit-auto/libs/mcp-adapter/includes/Core/McpAdapter.php' => $own_class,
	'kit-ok/libs/mcp-adapter/mcp-adapter.php'             => $entry_with_class,
	'kit-noclass/libs/mcp-adapter/mcp-adapter.php'        => $entry_without_class,
	'kit-missing/shaped-kit.php'                          => "<?php\n",
	'plugins/akismet/akismet.php'                         => "<?php\n",
	'plugins/wp-mcp-adapter/mcp-adapter.php'              => "<?php\n",
	'plugins/wp-mcp-adapter/includes/Core/McpAdapter.php' => "<?php\n",
);

foreach ( $tree as $path => $contents ) {
	$file = $root . '/' . $path;
	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0777, true );
	}
	file_put_contents( $file, $contents );
}

$base = array(
	'kit'        => 'kit-ok',
	'wp_version' => '7.1.3',
	'active'     => array( 'akismet/akismet.php' ),
	'request'    => array(),
	'disable'    => false,
	'preloaded'  => false,
);

$scenarios = array(
	'loads when nothing else supplies an adapter'          => array( array(), array( true, true, true, false ) ),
	'disable constant stops it'                            => array( array( 'disable' => true ), array( false, false, false, false ) ),
	'WordPress older than 6.9 stops it'                    => array( array( 'wp_version' => '6.8.3' ), array( false, false, false, false ) ),
	'a 6.9 pre-release still loads'                        => array( array( 'wp_version' => '6.9-RC2' ), array( true, true, true, false ) ),
	'an adapter another plugin loaded wins'                => array( array( 'preloaded' => true ), array( false, false, false, false ) ),
	'an active adapter plugin in any folder wins'          => array( array( 'active' => array( 'akismet/akismet.php', 'wp-mcp-adapter/mcp-adapter.php' ) ), array( false, false, false, false ) ),
	'activating an adapter plugin skips this request'      => array(
		array(
			'request' => array(
				'action' => 'activate',
				'plugin' => 'wp-mcp-adapter/mcp-adapter.php',
			),
		),
		array( false, false, false, false ),
	),
	'bulk-activating an adapter plugin skips this request' => array(
		array(
			'request' => array(
				'action2' => 'activate-selected',
				'checked' => array( 'akismet/akismet.php', 'wp-mcp-adapter/mcp-adapter.php' ),
			),
		),
		array( false, false, false, false ),
	),
	'activating an unrelated plugin still loads'           => array(
		array(
			'request' => array(
				'action' => 'activate',
				'plugin' => 'akismet/akismet.php',
			),
		),
		array( true, true, true, false ),
	),
	'wp plugin activate <adapter> skips'                   => array( array( 'cli' => array( array( 'plugin', 'activate', 'wp-mcp-adapter' ), array() ) ), array( false, false, false, false ) ),
	'wp plugin activate --all with an adapter skips'       => array( array( 'cli' => array( array( 'plugin', 'activate' ), array( 'all' => true ) ) ), array( false, false, false, false ) ),
	'another wp-cli command still loads'                   => array( array( 'cli' => array( array( 'cache', 'flush' ), array() ) ), array( true, true, true, false ) ),
	'wp-cli without a runner falls back to argv'           => array( array( 'cli_argv' => array( 'wp', 'plugin', 'activate', 'wp-mcp-adapter/mcp-adapter.php', '--network' ) ), array( false, false, false, false ) ),
	'a missing bundled copy loads nothing'                 => array( array( 'kit' => 'kit-missing' ), array( false, false, false, false ) ),
	'an entry file that defines no adapter rolls back'     => array( array( 'kit' => 'kit-noclass' ), array( false, true, false, false ) ),
);

$failures = 0;

foreach ( $scenarios as $label => $case ) {
	list( $overrides, $expected ) = $case;

	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child '
		. escapeshellarg( json_encode( array_merge( $base, $overrides ) ) ) . ' ' . escapeshellarg( $root ) . ' 2>&1';
	$output  = shell_exec( $command );
	$result  = json_decode( (string) $output, true );

	$actual = is_array( $result )
		? array( $result['loaded'], $result['entry_required'], $result['filter_registered'], $result['default_server'] )
		: null;

	if ( $actual === $expected ) {
		echo "ok    {$label}\n";
		continue;
	}

	++$failures;
	echo "FAIL  {$label}\n      expected [loaded, entry_required, filter_registered, default_server] = "
		. json_encode( $expected ) . "\n      got " . ( null === $actual ? trim( (string) $output ) : json_encode( $actual ) ) . "\n";
}

// A copy that is loaded before the Kit but never started (a must-use or network-active plugin that
// starts its copy only when its own feature is on): the Kit starts it just before the adapter would
// initialise, with the shared default server off. A copy someone else started stays theirs.
// Columns: [loaded, entry_required, watch hook, started by the watch, default-server filter after].
$watch_scenarios = array(
	'an unstarted copy is started by the late check, default server off' => array( array( 'preloaded' => 'unstarted' ), array( false, false, 'rest_api_init@14', 1, true ) ),
	'under wp-cli the late check runs on init'             => array(
		array(
			'preloaded' => 'unstarted',
			'cli'       => array( array( 'cache', 'flush' ), array() ),
		),
		array( false, false, 'init@19', 1, true ),
	),
	'a copy another plugin started is left alone'          => array( array( 'preloaded' => 'started' ), array( false, false, 'rest_api_init@14', 0, false ) ),
	'an unstarted copy with no version is not started'     => array( array( 'preloaded' => 'unstarted-no-version' ), array( false, false, 'rest_api_init@14', 0, false ) ),
	'a copy without the singleton property is not started' => array( array( 'preloaded' => 'no-singleton' ), array( false, false, 'rest_api_init@14', 0, false ) ),
	'the Kit\'s own copy resolved by another autoloader loads as usual' => array(
		array(
			'kit'          => 'kit-auto',
			'autoload_own' => true,
		),
		array( true, true, 'rest_api_init@14', 0, true ),
	),
	'the disable constant also stops the late check'       => array(
		array(
			'preloaded' => 'unstarted',
			'disable'   => true,
		),
		array( false, false, null, 0, false ),
	),
	'nothing is watched when the Kit supplies the adapter' => array( array(), array( true, true, null, 0, true ) ),
);

foreach ( $watch_scenarios as $label => $case ) {
	list( $overrides, $expected ) = $case;

	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child '
		. escapeshellarg( json_encode( array_merge( $base, $overrides ) ) ) . ' ' . escapeshellarg( $root ) . ' 2>&1';
	$output  = shell_exec( $command );
	$result  = json_decode( (string) $output, true );

	$actual = is_array( $result ) && array_key_exists( 'watch', $result )
		? array( $result['loaded'], $result['entry_required'], $result['watch'], $result['started_by_watch'], $result['filter_after_watch'] )
		: null;

	if ( $actual === $expected ) {
		echo "ok    {$label}\n";
		continue;
	}

	++$failures;
	echo "FAIL  {$label}\n      expected [loaded, entry_required, watch, started_by_watch, filter_after_watch] = "
		. json_encode( $expected ) . "\n      got " . ( null === $actual ? trim( (string) $output ) : json_encode( $actual ) ) . "\n";
}

// What `status()` reports for an adapter another plugin loaded: its version, and whether products
// accept it. Each runs in a fresh process, because the class cannot be undefined.
$version_scenarios = array(
	'a copy reporting 0.1.0 (WooCommerce) is compatible' => array( '0.1.0', array( '0.1.0', true ) ),
	'a copy reporting 0.4.1 (Rank Math) is compatible'   => array( '0.4.1', array( '0.4.1', true ) ),
	'a copy reporting 0.7.0 is compatible'               => array( '0.7.0', array( '0.7.0', true ) ),
	'a copy reporting 0.10.0 is above 0.7.0'             => array( '0.10.0', array( '0.10.0', true ) ),
	'a copy below the floor is not compatible'           => array( '0.0.9', array( '0.0.9', false ) ),
	'a copy that reports no version is never compatible' => array( 'no-version', array( null, false ) ),
);

foreach ( $version_scenarios as $label => $case ) {
	list( $preloaded, $expected ) = $case;

	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child '
		. escapeshellarg( json_encode( array_merge( $base, array( 'preloaded' => $preloaded ) ) ) ) . ' ' . escapeshellarg( $root ) . ' 2>&1';
	$output  = shell_exec( $command );
	$result  = json_decode( (string) $output, true );

	$actual = is_array( $result ) && isset( $result['status'] )
		? array( $result['status']['version'], $result['status']['compatible'] )
		: null;

	if ( $actual === $expected ) {
		echo "ok    {$label}\n";
		continue;
	}

	++$failures;
	echo "FAIL  {$label}\n      expected [version, compatible] = "
		. json_encode( $expected ) . "\n      got " . ( null === $actual ? trim( (string) $output ) : json_encode( $actual ) ) . "\n";
}

// Remove the throwaway tree.
$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $items as $item ) {
	$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
}
rmdir( $root );

echo "\n" . ( count( $scenarios ) + count( $watch_scenarios ) + count( $version_scenarios ) ) . " scenarios, {$failures} failed\n";

exit( $failures > 0 ? 1 : 0 );
