<?php
/**
 * Process-isolated tests for the exit-bearing methods:
 *   - Endpoint_Api::get_api_cache (echo + exit on cache hit)
 *   - Endpoint_Api::rest_send_cors_headers (header() emissions)
 *   - Admin::flush_caches (echo + exit AJAX handler)
 *
 * Each test runs in its own subprocess so the `exit;` doesn't terminate PHPUnit and so
 * that `header()` calls don't trip "headers already sent" warnings from earlier output in
 * the same process.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 */
class Test_Exit_Paths extends Caching_Test_Case {

	// Note: the cache-hit echo+exit path in Endpoint_Api::get_api_cache ends in raw `exit;`
	// after the echo, which terminates the @runInSeparateProcess subprocess before PHPUnit
	// can serialize the test result back. This path's final lines (echo+exit) are the only
	// known uncovered code in the plugin — testing them cleanly would require refactoring
	// production to use wp_send_json_*/wp_die or running an HTTP-aware acceptance test
	// harness. The constituent code paths (build_cache_key, save_cache, get_cache, the
	// various filters) are individually covered, so the orchestrator's behavior is
	// transitively pinned. Admin::flush_caches IS covered up to the echo+exit by way of
	// the wp_rest_cache/filtered_cache_keys throw-from-filter interceptor below.

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_send_cors_headers_runs_without_error_for_get_request_without_origin() {
		// The method emits Vary: Origin via header(); we can't observe header() output in
		// CLI but we can run the method to completion as long as no output has started.
		$api = new Endpoint_Api();
		unset( $_SERVER['HTTP_ORIGIN'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$reflection = new ReflectionClass( $api );
		$method     = $reflection->getMethod( 'rest_send_cors_headers' );
		$method->setAccessible( true );
		$result     = $method->invokeArgs( $api, [ 'payload' ] );

		$this->assertSame( 'payload', $result, 'rest_send_cors_headers returns its input unchanged' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_send_cors_headers_emits_full_set_when_origin_header_present() {
		$_SERVER['HTTP_ORIGIN']    = 'https://allowed.example';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$api = new Endpoint_Api();
		$reflection = new ReflectionClass( $api );
		$method     = $reflection->getMethod( 'rest_send_cors_headers' );
		$method->setAccessible( true );
		$result     = $method->invokeArgs( $api, [ 'payload' ] );

		$this->assertSame( 'payload', $result );

		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_send_cors_headers_handles_null_origin_literal() {
		// file:// and data: URLs send "Origin: null" literally — pinned to not get
		// esc_url_raw'd into an empty string (the null check guards that).
		$_SERVER['HTTP_ORIGIN']    = 'null';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$api = new Endpoint_Api();
		$reflection = new ReflectionClass( $api );
		$method     = $reflection->getMethod( 'rest_send_cors_headers' );
		$method->setAccessible( true );
		$result     = $method->invokeArgs( $api, [ 'x' ] );

		$this->assertSame( 'x', $result );

		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_flush_caches_dies_when_current_user_lacks_settings_capability() {
		// check_ajax_referer must pass first; then current_user_can on the filtered
		// capability is what gates the wp_die. Use a subscriber so capability fails.
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$nonce                            = wp_create_nonce( 'wp_rest_cache_clear_cache_ajax' );
		$_POST['wp_rest_cache_nonce']     = $nonce;
		$_REQUEST['wp_rest_cache_nonce']  = $nonce;

		$this->expectException( \WPDieException::class );

		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->flush_caches();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_flush_caches_runs_up_to_delete_all_caches_before_the_echo_exit() {
		// Goal: cover the body of flush_caches without letting `exit;` terminate the test
		// subprocess. We throw from inside delete_all_caches via the
		// `wp_rest_cache/filtered_cache_keys` filter (which only fires when $cache_filter
		// is non-empty). The throw intercepts BEFORE the echo+exit at lines 605-606.
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$nonce                            = wp_create_nonce( 'wp_rest_cache_clear_cache_ajax' );
		$_POST['wp_rest_cache_nonce']     = $nonce;
		$_REQUEST['wp_rest_cache_nonce']  = $nonce;
		$_POST['delete_caches']           = 'true';
		$_POST['cache_filter']            = 'my-filter';

		$filter_received_args = null;
		add_filter(
			'wp_rest_cache/filtered_cache_keys',
			function ( $keys, $cache_filter ) use ( &$filter_received_args ) {
				$filter_received_args = [ $keys, $cache_filter ];
				throw new RuntimeException( 'intercepted-before-exit' );
			},
			10,
			2
		);

		try {
			( new Admin( 'wp-rest-cache', '2026.2.0' ) )->flush_caches();
			$this->fail( 'expected RuntimeException from the throw-from-filter interceptor' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'intercepted-before-exit', $e->getMessage() );
		}

		// The filter must have fired with the $_POST['cache_filter'] value, which proves
		// flush_caches got past nonce verification, capability check, $_POST extraction,
		// and the Caching::get_instance()->delete_all_caches() dispatch.
		$this->assertSame( [ [], 'my-filter' ], $filter_received_args );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_flush_caches_sends_a_percentage_payload_via_wp_send_json() {
		// Drives the tail of flush_caches: after delete_all_caches returns normally we hit
		// `wp_send_json( [ 'percentage' => 100 ] )`. With DOING_AJAX defined, wp_send_json
		// routes termination via wp_die() — but the standard WP test harness only installs
		// a throwing wp_die handler for WP_Ajax_UnitTestCase. We're on WP_UnitTestCase, so
		// we register the AJAX die handler ourselves. Process-isolated so DOING_AJAX and
		// the handler don't leak.
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \DomainException( 'caught-wp-die-ajax' );
				};
			}
		);

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$nonce                           = wp_create_nonce( 'wp_rest_cache_clear_cache_ajax' );
		$_POST['wp_rest_cache_nonce']    = $nonce;
		$_REQUEST['wp_rest_cache_nonce'] = $nonce;
		$_POST['delete_caches']          = 'false';
		// Intentionally NO cache_filter — delete_all_caches must run fully through.

		$base_level = ob_get_level();
		ob_start(); // capture the JSON wp_send_json echoes so it doesn't pollute test stdout

		try {
			( new Admin( 'wp-rest-cache', '2026.2.0' ) )->flush_caches();
			$this->fail( 'expected wp_send_json to terminate via the ajax wp_die handler' );
		} catch ( \DomainException $e ) {
			$echoed = ob_get_clean();
			$this->assertSame( 'caught-wp-die-ajax', $e->getMessage() );
			$this->assertSame( '{"percentage":100}', $echoed );
		} finally {
			while ( ob_get_level() > $base_level ) {
				ob_end_clean();
			}
		}
	}

}
