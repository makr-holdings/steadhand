<?php
/**
 * PHPUnit bootstrap: loads WordPress, the MCP Adapter and Steadhand.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

$steadhand_root = dirname( __DIR__ );

require_once $steadhand_root . '/vendor/autoload.php';

$steadhand_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $steadhand_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the tests with `composer test` (inside wp-env).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $steadhand_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $steadhand_root ): void {
		// The MCP Adapter is a required plugin; wp-env installs it next to Steadhand.
		require_once dirname( $steadhand_root ) . '/mcp-adapter/mcp-adapter.php';
		require_once $steadhand_root . '/steadhand.php';
	}
);

require $steadhand_tests_dir . '/includes/bootstrap.php';

// The test suite loads the plugin without activating it, so run the activation step once.
\Steadhand\Roles::install();
\Steadhand\Log\Schema::install();
