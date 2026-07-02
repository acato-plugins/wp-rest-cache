<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WP_Rest_Cache_Plugin
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-rest-cache.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Set a 200 response code before the WP test lib's bootstrap echoes anything — once output
// starts we can't set it anymore, and Endpoint_Api::save_cache short-circuits if
// http_response_code() doesn't equal 200. Tests that need to simulate a non-200 status branch
// drive that through `$result['data']['status']` instead.
http_response_code( 200 );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// Shared base test case (loaded after WP test lib so WP_UnitTestCase exists).
require_once __DIR__ . '/class-caching-test-case.php';
